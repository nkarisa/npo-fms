<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ApprovalPolicy;
use App\Repositories\PayablesRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;
use App\Repositories\TaxRepository;

/**
 * Payables (v5): supplier bills with their ageing, filtered by status, ageing bucket,
 * fund and search, ten a page; the bill drawer; bill capture; approval, scheduling
 * and payment of one bill or a selection; and withholding tax remittance.
 */
class Payables extends BaseApiController
{
    private const PAGE_SIZE = 10;

    private const TABS = ['All', 'Awaiting approval', 'Approved', 'Scheduled', 'Paid', 'Overdue'];

    private const BUCKETS = ['Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days'];

    /** A bill's tax and settlement figures, as stored on the bill. */
    public static function totals(array $b): array
    {
        return ['vat' => $b['vat'], 'gross' => $b['gross'], 'wht' => $b['wht'], 'net' => $b['net']];
    }

    private static function bucket(array $b): string
    {
        $d = $b['dueIn'];

        return match (true) {
            $d >= 0   => 'Current',
            $d >= -30 => '1–30 days',
            $d >= -60 => '31–60 days',
            $d >= -90 => '61–90 days',
            default   => 'Over 90 days',
        };
    }

    private static function isOpen(array $b): bool
    {
        return !in_array($b['status'], ['Paid', 'Rejected'], true);
    }

    private static function overdue(array $b): bool
    {
        return self::isOpen($b) && $b['dueIn'] < 0;
    }

    private static function net(array $bills): float
    {
        return array_sum(array_column($bills, 'net'));
    }

    public function index()
    {
        $repo   = new PayablesRepository();
        $all    = $repo->all();
        $status = in_array($this->request->getGet('status'), self::TABS, true) ? $this->request->getGet('status') : 'All';
        $age    = in_array($this->request->getGet('age'), self::BUCKETS, true) ? $this->request->getGet('age') : 'All';
        $fund   = $this->request->getGet('fund') ?: 'All funds';
        $q      = mb_strtolower(trim($this->request->getGet('q') ?? ''));
        $page   = max(1, (int) ($this->request->getGet('page') ?: 1));

        $filtered = array_values(array_filter($all, function ($b) use ($status, $age, $fund, $q) {
            if ($status === 'Overdue' ? !self::overdue($b) : ($status !== 'All' && $b['status'] !== $status)) {
                return false;
            }
            if ($age !== 'All' && (!self::isOpen($b) || self::bucket($b) !== $age)) {
                return false;
            }
            if ($fund !== 'All funds' && $b['fund'] !== $fund) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($b['no'] . ' ' . $b['supplier'] . ' ' . $b['pin'] . ' ' . $b['category'] . ' ' . $b['invoiceNo']), $q);
        }));

        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page  = min($page, $pages);

        $rows = array_map(static fn ($b) => [
            'no' => $b['no'], 'supplier' => $b['supplier'], 'subtitle' => $b['category'] . ' · PIN ' . $b['pin'],
            'invDate' => $b['invDate'], 'dueDate' => $b['dueDate'], 'fund' => $b['fund'], 'program' => $b['program'],
            'gross' => $b['gross'], 'wht' => $b['wht'], 'net' => $b['net'], 'status' => $b['status'],
            'age' => $b['status'] === 'Paid' ? '—' : ($b['dueIn'] > 0 ? 'in ' . $b['dueIn'] . 'd' : ($b['dueIn'] === 0 ? 'today' : abs($b['dueIn']) . 'd late')),
            'overdue' => self::overdue($b),
            'approval' => $b['approval'] ?? null,
        ], array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE));

        $outstanding = array_values(array_filter($all, [self::class, 'isOpen']));
        $overdue     = array_values(array_filter($outstanding, [self::class, 'overdue']));
        $dueWeek     = array_values(array_filter($outstanding, static fn ($b) => $b['dueIn'] >= 0 && $b['dueIn'] <= 7));
        $awaiting    = array_values(array_filter($all, static fn ($b) => $b['status'] === 'Awaiting approval'));
        $wht         = $repo->whtHeld();
        $actor       = $this->actor();

        return $this->json([
            'rows'        => $rows,
            'total'       => count($all),
            'filtered'    => count($filtered),
            'page'        => $page,
            'pages'       => $pages,
            'pageSize'    => self::PAGE_SIZE,
            'fundOptions' => ['All funds', 'General Fund', 'Grant Fund', 'Capital Fund'],
            'aging'       => array_map(function ($k) use ($outstanding) {
                $in = array_values(array_filter($outstanding, static fn ($b) => self::bucket($b) === $k));

                return ['label' => $k, 'value' => Prototype::fmt(self::net($in)), 'count' => count($in) . (count($in) === 1 ? ' bill' : ' bills')];
            }, self::BUCKETS),
            'tabs' => array_map(static fn ($s) => [
                'label' => $s,
                'count' => match ($s) {
                    'All'     => count($all),
                    'Overdue' => count($overdue),
                    default   => count(array_filter($all, static fn ($b) => $b['status'] === $s)),
                },
            ], self::TABS),
            'hint'   => $age === 'All' ? count($overdue) . ' bills past due' : 'Aging filter: ' . $age,
            'vatRate' => (new TaxRepository())->inForce('vat', \App\Libraries\Clock::date())[0] ?? null,
            'footer' => count($filtered) . ' of ' . count($all) . ' bills · net payable ' . Prototype::fmt(self::net(array_filter($filtered, [self::class, 'isOpen']))),
            'stats'  => [
                ['label' => 'Total outstanding', 'value' => Prototype::fmt(self::net($outstanding)), 'note' => count($outstanding) . ' open bills'],
                ['label' => 'Overdue', 'value' => Prototype::fmt(self::net($overdue)), 'note' => count(array_unique(array_column($overdue, 'supplier'))) . ' suppliers waiting'],
                ['label' => 'Due within 7 days', 'value' => Prototype::fmt(self::net($dueWeek)), 'note' => count($dueWeek) . ' bills'],
                ['label' => 'Awaiting approval', 'value' => Prototype::fmt(self::net($awaiting)), 'note' => self::signOffNote($awaiting, $actor, $this->actorId())],
                ['label' => 'WHT to remit', 'value' => Prototype::fmt($wht['held']),
                    'note' => $wht['remittedThisMonth'] !== null && $wht['held'] == 0 ? 'remitted ' . $wht['remittedThisMonth'] : 'due to KRA by ' . $wht['dueBy']
                        . ($wht['pending'] > 0 ? ' · ' . Prototype::fmt($wht['pending']) . ' more on bills awaiting approval' : '')],
            ],
        ]);
    }

    /** One bill for the drawer, with the actions open to the acting user. */
    public function show($no)
    {
        $repo = new PayablesRepository();
        $b = $repo->find($no);
        if ($b === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Bill not found']);
        }

        $actor    = $this->actor();
        $actorId  = $this->actorId();
        $mine     = $b['preparedBy'] === $actorId;
        $awaiting = $b['status'] === 'Awaiting approval';
        $payable  = in_array($b['status'], ['Approved', 'Scheduled'], true);

        // What the approval rules say about this approver and this bill's value. A bill
        // paid on its own is a payment run of its net amount.
        $policy = new ApprovalPolicy();
        $approval = $awaiting && $actor['canApprove'] && !$mine
            ? $policy->refusal('bill', (float) $b['gross'], $actorId, $b['no'], null, 'bill', (int) $b['id'])
            : null;
        $release  = $payable && $actor['canApprove'] && !$mine ? $policy->refusal('payment_run', (float) $b['net'], $actorId, $b['no']) : null;

        return $this->json([
            'bill' => $b + [
                'overdue'   => self::overdue($b),
                'overdueBy' => abs((int) $b['dueIn']) . ' days',
            ],
            'can' => [
                'approve'  => $awaiting && $actor['canApprove'] && !$mine && $approval === null,
                'reject'   => $awaiting && $actor['canApprove'],
                'schedule' => $b['status'] === 'Approved' && ($actor['canPrepare'] || $actor['canApprove']),
                'pay'      => $payable && $actor['canApprove'] && !$mine && ($release === null || $release['needsAuthority']),
                // Releasing this payment needs the reference for an authority outside the system.
                'payNeedsAuthority' => $release !== null && $release['needsAuthority'],
                'method'   => self::isOpen($b) && ($actor['canPrepare'] || $actor['canApprove']),
                'attach'   => $actor['canPrepare'],
                // Why an approver sees no approve or pay button: their own bill, or a value
                // the approval rules give to another role.
                'sodNote'  => $mine && $actor['canApprove'] && ($awaiting || $payable)
                    ? 'You captured this bill, so a second person must ' . ($awaiting ? 'approve it.' : 'release its payment.')
                    : ($approval['message'] ?? ($release !== null && !$release['needsAuthority'] ? $release['message'] : '')),
            ],
            'authorityNote' => $release !== null && $release['needsAuthority'] ? $release['message'] : '',
            'methodOptions' => $repo->methods(),
        ]);
    }

    /**
     * "4 need sign-off · 2 need yours" — the second figure counts only the bills
     * whose open step this approver can sign and did not capture, so a Finance
     * Manager is not shown a queue that is the Director's to clear
     * (docs/approvals.md).
     *
     * @param list<array> $awaiting
     */
    private static function signOffNote(array $awaiting, array $actor, int $actorId): string
    {
        $note = count($awaiting) . ' need sign-off';
        if (!$actor['canApprove']) {
            return $note;
        }
        $mine = array_filter($awaiting, static fn ($b) => ($b['approval']['mine'] ?? false) && $b['preparedBy'] !== $actorId);

        return $note . ' · ' . (count($mine) === 0 ? 'none are yours' : count($mine) . ' need yours');
    }

    /** What bill capture offers: suppliers, spend categories, budget lines and payment methods. */
    public function form()
    {
        $repo = new PayablesRepository();

        return $this->json([
            'suppliers'   => $repo->suppliers(),
            'categories'  => $repo->categories(),
            'budgetLines' => array_map(static fn ($l) => array_intersect_key($l, array_flip(['id', 'code', 'name', 'fund', 'program', 'grant', 'annual', 'actual', 'onBills', 'remaining'])), $repo->budgetLines()),
            'methods'     => $repo->methods(),
            'terms'       => SettingsRepository::supplierTerms(),
            'defaultTerms' => SettingsRepository::defaultSupplierTerms(),
            // Dated, so the form can follow the invoice date across a change of rate.
            'taxRates'    => (new TaxRepository())->forForm(),
            'today'       => \App\Libraries\Clock::date(),
            'requireInvoice' => config(\Config\Documents::class)->requireBillInvoice,
            'prequalThreshold' => PayablesRepository::prequalThreshold(),
            // For the duplicate-invoice warning.
            'bills'       => array_map(static fn ($b) => ['no' => $b['no'], 'supplier' => $b['supplier'], 'taxable' => $b['taxable'], 'invoiceNo' => $b['invoiceNo']], $repo->all()),
        ]);
    }

    public function create()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot capture bills.');
        }

        try {
            $bill = (new PayablesRepository())->capture($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['bill' => $bill])->setStatusCode(201);
    }

    /** Body: {nos: [...]}. */
    public function approve()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot approve bills for payment.');
        }

        return $this->batch(fn (array $nos) => (new PayablesRepository())->approve($nos, $this->actorId()));
    }

    public function reject($no)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot reject bills.');
        }

        try {
            $bill = (new PayablesRepository())->reject($no, (string) (($this->request->getJSON(true) ?? [])['reason'] ?? ''), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['bill' => $bill]);
    }

    /** Body: {nos: [...]}. */
    public function schedule()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot schedule payments.');
        }

        return $this->batch(fn (array $nos) => (new PayablesRepository())->schedule($nos, $this->actorId()));
    }

    /** Body: {nos: [...]}. */
    public function pay()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot release payments.');
        }

        $authorityRef = (string) (($this->request->getJSON(true) ?? [])['authorityRef'] ?? '');

        return $this->batch(fn (array $nos) => (new PayablesRepository())->pay($nos, $this->actorId(), $authorityRef));
    }

    /** Body: {method: "M-Pesa B2B paybill"}. */
    public function method($no)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot change how a bill is paid.');
        }

        try {
            $bill = (new PayablesRepository())->setMethod($no, (string) (($this->request->getJSON(true) ?? [])['method'] ?? ''), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['bill' => $bill]);
    }

    public function remitWht()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot remit withholding tax.');
        }

        try {
            $remittance = (new PayablesRepository())->remitWht($this->actorId(), (string) (($this->request->getJSON(true) ?? [])['authorityRef'] ?? ''));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['remittance' => $remittance])->setStatusCode(201);
    }

    private function batch(callable $action)
    {
        $nos = ($this->request->getJSON(true) ?? [])['nos'] ?? [];

        try {
            return $this->json($action(is_array($nos) ? $nos : [$nos]));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    private function forbidden(string $message)
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => $message]);
    }
}
