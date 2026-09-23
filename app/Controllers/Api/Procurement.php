<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ProcurementRepository as Repo;
use App\Repositories\RuleViolation;
use App\Repositories\AttachmentRepository;

/**
 * Procurement (v5): requisition → quotation → purchase order → goods received → bill.
 *
 * The controls a donor audit asks about are enforced on the transition rather than
 * left to the UI to hide a button (see ProcurementRepository):
 *
 *  - Budget check. Available = approved budget − actual − committed. Approval is
 *    blocked, not warned, when the requisition exceeds what is left on its line.
 *  - Three-quote rule. Above the threshold a requisition cannot reach a purchase
 *    order without three quotations, or a single-source justification.
 *  - Commitment accounting. The purchase order commits the budget; goods received
 *    release the commitment and accrue the cost.
 *  - Segregation of duties. The requester cannot approve or reject their own requisition.
 */
class Procurement extends BaseApiController
{
    private const PAGE_SIZE = 10;

    public const VIEWS = ['Requisitions', 'Purchase orders', 'Goods received', 'Suppliers'];

    private const TABS = ['Open', 'Awaiting approval', 'Approved', 'RFQ issued', 'PO raised', 'Goods received', 'Closed', 'All'];

    public static function available(array $p): float
    {
        return Repo::available($p);
    }

    public static function isOverBudget(array $p): bool
    {
        return Repo::isOverBudget($p);
    }

    public static function needsQuotes(array $p): bool
    {
        return Repo::needsQuotes($p);
    }

    /** Whether the budget check still stands between this requisition and its purchase order. */
    public static function budgetCheckPending(array $p): bool
    {
        return Repo::stillLive($p) && Repo::isOverBudget($p);
    }

    /** The three-quote rule bites at the purchase order, so it is spent once one exists. */
    public static function quotesOutstanding(array $p): bool
    {
        return Repo::stillLive($p) && Repo::needsQuotes($p);
    }

    public function index()
    {
        $repo = new Repo();
        $all  = $repo->requisitions();
        $view = in_array($this->request->getGet('view'), self::VIEWS, true) ? $this->request->getGet('view') : self::VIEWS[0];
        $open = array_values(array_filter($all, static fn ($p) => in_array($p['status'], Repo::OPEN, true)));
        $actor = $this->actor();

        $data = [
            'view'  => $view,
            'views' => self::VIEWS,
            'threshold' => Repo::quoteThreshold(),
            'stats' => [
                ['label' => 'Open requisitions', 'value' => (string) count($open), 'note' => 'Across ' . count(array_unique(array_column($open, 'program'))) . ' programmes'],
                ['label' => $actor['canApprove'] ? 'Awaiting my approval' : 'Awaiting approval',
                    // An approver is shown what is theirs to sign, not everything waiting.
                    'value' => (string) count(array_filter($all, fn ($p) => $p['status'] === 'Awaiting approval'
                        && (!$actor['canApprove'] || (($p['approval']['mine'] ?? false) && $p['requestedBy'] !== $this->actorId())))),
                    'note' => 'Budget checked before approval'],
                ['label' => 'Value in progress', 'value' => Prototype::fmt(array_sum(array_column($open, 'amount'))), 'note' => 'Not yet invoiced'],
                ['label' => 'Committed on POs', 'value' => Prototype::fmt(array_sum(array_map(static fn ($p) => ($p['poStatus'] ?? '') === 'open' ? $p['amount'] : 0, $all))),
                    'note' => 'Reserved against budget lines'],
                ['label' => 'Over available budget', 'value' => (string) count(array_filter($open, [self::class, 'budgetCheckPending'])), 'note' => 'Need a budget revision first'],
            ],
        ];

        return $this->json($data + match ($view) {
            'Purchase orders' => $this->purchaseOrders($all),
            'Goods received'  => $this->goodsReceived($all),
            'Suppliers'       => $this->suppliers($repo),
            default           => $this->requisitions($all),
        });
    }

    private function requisitions(array $all): array
    {
        $tab  = in_array($this->request->getGet('tab'), self::TABS, true) ? $this->request->getGet('tab') : 'Open';
        $q    = mb_strtolower(trim($this->request->getGet('q') ?? ''));
        $page = max(1, (int) ($this->request->getGet('page') ?: 1));

        $filtered = array_values(array_filter($all, static function ($p) use ($tab, $q) {
            if ($tab === 'Open' ? !in_array($p['status'], Repo::OPEN, true) : ($tab !== 'All' && $p['status'] !== $tab)) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($p['no'] . ' ' . $p['title'] . ' ' . $p['requester'] . ' ' . $p['program'] . ' ' . implode(' ', array_column($p['lines'], 'desc'))), $q);
        }));
        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page  = min($page, $pages);

        return [
            'tabs'     => self::TABS,
            'tab'      => $tab,
            'rows'     => array_map(static fn ($p) => [
                'no' => $p['no'], 'title' => $p['title'], 'requester' => $p['requester'], 'program' => $p['program'], 'grant' => $p['grant'],
                'fund' => $p['fund'], 'raised' => $p['raised'], 'needBy' => $p['needBy'], 'amount' => $p['amount'],
                'available' => Repo::available($p), 'overBudget' => self::budgetCheckPending($p), 'status' => $p['status'],
                'approval' => $p['approval'] ?? null,
            ], array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)),
            'filtered' => count($filtered),
            'page'     => $page,
            'pages'    => $pages,
            'pageSize' => self::PAGE_SIZE,
            'footer'   => count($filtered) . ' of ' . count($all) . ' requisitions · three quotations required above ' . Prototype::fmt(Repo::quoteThreshold())
                . ' · goods received notes raise the supplier bill',
        ];
    }

    private function purchaseOrders(array $all): array
    {
        $orders = array_values(array_filter($all, static fn ($p) => !empty($p['po'])));

        return [
            'rows' => array_map(static fn ($p) => [
                'po' => $p['po'], 'no' => $p['no'], 'supplier' => $p['supplier'] ?: '—', 'title' => $p['title'], 'issued' => $p['poDate'],
                'expected' => $p['expected'] !== '—' ? $p['expected'] : $p['needBy'], 'value' => $p['amount'],
                'state' => match ($p['poStatus']) { 'open', 'part_received' => 'Awaiting delivery', 'received' => 'Received', default => 'Closed' },
                'match' => match (true) {
                    !empty($p['bill']) => 'PO · GRN · invoice matched',
                    !empty($p['grn'])  => 'PO · GRN matched, invoice awaited',
                    default            => 'PO only',
                },
            ], $orders),
            'footer' => count($orders) . ' purchase orders · ' . Prototype::fmt(array_sum(array_map(static fn ($p) => $p['poStatus'] === 'open' ? $p['amount'] : 0, $orders)))
                . ' committed and not yet received',
        ];
    }

    private function goodsReceived(array $all): array
    {
        $notes = array_values(array_filter($all, static fn ($p) => !empty($p['grn'])));

        return [
            'rows' => array_map(static fn ($p) => [
                'grn' => $p['grn'], 'po' => $p['po'], 'no' => $p['no'], 'supplier' => $p['supplier'] ?: '—', 'title' => $p['title'], 'note' => $p['grnNote'],
                'when' => $p['grnDate'], 'receivedBy' => $p['receivedBy'], 'value' => $p['amount'], 'bill' => $p['bill'] ?? '',
            ], $notes),
            'footer' => count($notes) . ' goods received notes · ' . count(array_filter($notes, static fn ($p) => empty($p['bill'])))
                . ' awaiting the supplier invoice for three-way matching',
        ];
    }

    private function suppliers(Repo $repo): array
    {
        $all = $repo->suppliers();

        return [
            'rows'   => $all,
            'can'    => $this->supplierRights(),
            'footer' => count($all) . ' suppliers · pre-qualification runs to calendar year end · lapsed suppliers cannot be selected on a purchase order',
        ];
    }

    /** One requisition for the drawer, with the actions open to the acting user. */
    public function show($no)
    {
        $p = (new Repo())->find((string) $no);
        if ($p === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in procurement.']);
        }

        $actor  = $this->actor();
        $mine   = $p['requestedBy'] === $this->actorId();
        $raiser = in_array('requisition.raise', $actor['permissions'], true);
        $awaiting = $p['status'] === 'Awaiting approval';

        return $this->json([
            'requisition' => $p + [
                'available'   => Repo::available($p),
                'overBudget'  => self::budgetCheckPending($p),
                'needsQuotes' => self::quotesOutstanding($p),
                'quoteDocs'   => count(array_filter($p['quotes'], static fn ($q) => $q['document'] !== null)) . ' of ' . count($p['quotes']) . ' quotations have a document on file',
                'quotesOnFile' => Repo::quotesOnFile($p),
            ],
            'can' => [
                'submit'  => $p['status'] === 'Draft' && ($mine || $raiser || $actor['canPrepare']),
                'approve' => $awaiting && $actor['canApprove'] && !$mine,
                'reject'  => $awaiting && $actor['canApprove'] && !$mine,
                'rfq'     => $p['status'] === 'Approved' && $actor['canPrepare'],
                'po'      => in_array($p['status'], ['Approved', 'RFQ issued'], true) && $actor['canPrepare'],
                'receive' => $p['status'] === 'PO raised' && $actor['canPrepare'],
                'bill'    => $p['status'] === 'Goods received' && $actor['canPrepare'],
                'note'    => $awaiting && $mine && $actor['canApprove'] ? 'You raised this requisition, so a second person must approve it.' : '',
            ],
        ]);
    }

    public function form()
    {
        return $this->json((new Repo())->formOptions() + ['me' => $this->actor()['email']]);
    }

    /**
     * Raises a requisition as a draft. Accepts JSON, or a multipart form whose `payload`
     * field is that JSON and whose `documents[]` are quotation documents, each quote
     * naming its document by index.
     */
    public function create()
    {
        $actor = $this->actor();
        if (!in_array('requisition.raise', $actor['permissions'], true)) {
            return $this->forbidden($actor['role'] . ' cannot raise requisitions.');
        }

        try {
            $multipart = str_starts_with($this->request->getHeaderLine('Content-Type'), 'multipart/form-data');
            $body = $multipart ? (json_decode((string) $this->request->getPost('payload'), true) ?? []) : ($this->request->getJSON(true) ?? []);
            $attachments = new AttachmentRepository();
            $files = array_map([$attachments, 'accept'], $multipart ? ($this->request->getFileMultiple('documents') ?? []) : []);

            $requisition = (new Repo())->create($body, $this->actorId(), $files);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['requisition' => $requisition])->setStatusCode(201);
    }

    public function submit($no)
    {
        $actor = $this->actor();
        if (!in_array('requisition.raise', $actor['permissions'], true) && !$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot submit requisitions.');
        }

        return $this->act(fn () => (new Repo())->submit((string) $no, $this->actorId()));
    }

    public function approve($no)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot approve requisitions.');
        }

        return $this->act(fn () => (new Repo())->approve((string) $no, $this->actorId()));
    }

    /** Body: {reason}. */
    public function reject($no)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot reject requisitions.');
        }
        $reason = (string) (($this->request->getJSON(true) ?? [])['reason'] ?? '');

        return $this->act(fn () => (new Repo())->reject((string) $no, $reason, $this->actorId()));
    }

    public function rfq($no)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot issue requests for quotation.');
        }

        return $this->act(fn () => (new Repo())->issueRfq((string) $no, $this->actorId()));
    }

    /** Body: {supplier?, waiver?, expected?}. */
    public function raisePo($no)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot raise purchase orders.');
        }
        $body = $this->request->getJSON(true) ?? [];

        return $this->act(fn () => (new Repo())->raisePurchaseOrder((string) $no, (string) ($body['supplier'] ?? ''), (string) ($body['waiver'] ?? ''), $body['expected'] ?? null, $this->actorId()));
    }

    /** Body: {receivedBy, note?}. */
    public function receive($no)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot record goods received.');
        }
        $body = $this->request->getJSON(true) ?? [];

        return $this->act(fn () => (new Repo())->receive((string) $no, (string) ($body['receivedBy'] ?? ''), trim((string) ($body['note'] ?? '')), $this->actorId()));
    }

    /** Body: {invoiceNo}. */
    public function bill($no)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot raise supplier bills.');
        }
        $body = $this->request->getJSON(true) ?? [];
        $invoiceNo = (string) ($body['invoiceNo'] ?? '');

        try {
            return $this->json((new Repo())->raiseBill((string) $no, $invoiceNo, $this->actorId(), $body['documents'] ?? []));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** What the supplier form offers, and what the acting user may change. */
    public function supplierForm()
    {
        return $this->json((new Repo())->supplierOptions() + ['can' => $this->supplierRights()]);
    }

    public function supplier($id)
    {
        $repo = new Repo();
        $supplier = $repo->supplier((int) $id);
        if ($supplier === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'There is no such supplier on the register.']);
        }

        return $this->json(['supplier' => $supplier] + $repo->supplierOptions() + ['can' => $this->supplierRights()]);
    }

    /** Body: {name, pin?, category, status?, prequalUntil?, rating?, whtRate?, whtBasis?, paymentDetails?}. */
    public function createSupplier()
    {
        return $this->saveSupplier(null);
    }

    /** Body as createSupplier. */
    public function updateSupplier($id)
    {
        return $this->saveSupplier((int) $id);
    }

    private function saveSupplier(?int $id)
    {
        $can = $this->supplierRights();
        if (!$can['register']) {
            return $this->forbidden($this->actor()['role'] . ' cannot change the supplier register.');
        }

        try {
            $supplier = (new Repo())->saveSupplier($this->request->getJSON(true) ?? [], $this->actorId(), $can['qualify'], $id);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['supplier' => $supplier])->setStatusCode($id === null ? 201 : 200);
    }

    /** Preparers register suppliers and keep their details; approvers pre-qualify, renew, block and restore them. */
    private function supplierRights(): array
    {
        $actor = $this->actor();

        return ['register' => $actor['canPrepare'] || $actor['canApprove'], 'qualify' => $actor['canApprove']];
    }

    public function document($no, $id)
    {
        return $this->sendDocument((new Repo())->document((string) $no, (int) $id));
    }

    private function act(callable $action)
    {
        try {
            return $this->json(['requisition' => $action()]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    private function forbidden(string $message)
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => $message]);
    }
}
