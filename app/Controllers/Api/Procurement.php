<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

/**
 * Requisition → quotation → purchase order → goods received → bill.
 *
 * The controls here are the ones a donor audit asks about, so they are enforced
 * on the transition rather than left to the UI to hide a button:
 *
 *  - Budget check. Available = approved budget − actual − committed. Approval is
 *    blocked, not warned, when a line exceeds what is left on its account.
 *  - Three-quote rule. Above the threshold a requisition cannot reach PO without
 *    three quotations recorded, or a documented single-source waiver.
 *  - Commitment accounting. Raising the PO commits the budget; committed money is
 *    not yet actual but is no longer available.
 *  - Segregation of duties. The requester cannot approve their own requisition.
 */
class Procurement extends BaseApiController
{
    /** Above this value three quotations are required before a PO can be raised. */
    private const QUOTE_THRESHOLD = 500000;

    /** Statuses that still represent work in progress. */
    private const OPEN = ['Draft', 'Awaiting approval', 'Approved', 'RFQ issued', 'PO raised'];

    public const VIEWS = ['Requisitions', 'Purchase orders', 'Goods received', 'Suppliers'];

    /** Budget still spendable on this line: approved less actual less committed. */
    public static function available(array $p): float
    {
        return $p['budget'] - $p['spent'] - $p['committed'];
    }

    public static function isOverBudget(array $p): bool
    {
        return $p['amount'] > self::available($p);
    }

    public static function needsQuotes(array $p): bool
    {
        return $p['amount'] > self::QUOTE_THRESHOLD && count($p['quotes']) < 3;
    }

    /** A requisition that can still be stopped by one of the controls. */
    private static function stillLive(array $p): bool
    {
        // Once the PO exists the requisition's own amount sits inside `committed`,
        // so testing it against what is left would count that money twice and
        // report the requisition as over budget for the budget it itself reserved.
        return empty($p['po']) && !in_array($p['status'], ['Rejected', 'Closed'], true);
    }

    /**
     * Whether the budget check still stands between this requisition and its
     * purchase order. True right through approval and RFQ, because none of those
     * states has committed the money yet.
     */
    public static function budgetCheckPending(array $p): bool
    {
        return self::stillLive($p) && self::isOverBudget($p);
    }

    /** The three-quote rule bites at PO raising, so it is spent once one exists. */
    public static function quotesOutstanding(array $p): bool
    {
        return self::stillLive($p) && self::needsQuotes($p);
    }

    public function index()
    {
        $view = $this->request->getGet('view') ?: self::VIEWS[0];

        return match ($view) {
            'Purchase orders' => $this->json($this->purchaseOrders()),
            'Goods received'  => $this->json($this->goodsReceived()),
            'Suppliers'       => $this->json($this->suppliers()),
            default           => $this->json($this->requisitions()),
        };
    }

    private function viewBlock(): array
    {
        $view = $this->request->getGet('view') ?: self::VIEWS[0];

        return [
            'view'        => $view,
            'viewOptions' => self::VIEWS,
            'threshold'   => self::QUOTE_THRESHOLD,
        ];
    }

    private function requisitions(): array
    {
        $all = Prototype::load('PQ');
        $tab = $this->request->getGet('tab') ?: 'Open';
        $q   = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($p) use ($tab, $q) {
            if ($tab === 'Open' && !in_array($p['status'], self::OPEN, true)) {
                return false;
            }
            if ($tab !== 'Open' && $tab !== 'All' && $p['status'] !== $tab) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($p['no'] . ' ' . $p['title'] . ' ' . $p['requester'] . ' ' . $p['program']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(fn ($p) => [
            'no'          => $p['no'],
            'title'       => $p['title'],
            'requester'   => $p['requester'],
            'program'     => $p['program'],
            'fund'        => $p['fund'],
            'code'        => $p['code'],
            'raised'      => $p['raised'],
            'needBy'      => $p['needBy'],
            'amount'      => Prototype::fmt($p['amount']),
            'available'   => Prototype::fmt(self::available($p)),
            'status'      => $p['status'],
            'overBudget'  => self::budgetCheckPending($p),
            'needsQuotes' => self::quotesOutstanding($p),
            'quotes'      => count($p['quotes']),
            'supplier'    => $p['supplier'] ?? '',
            'po'          => $p['po'] ?? '',
            'grn'         => $p['grn'] ?? '',
            'bill'        => $p['bill'] ?? '',
        ], $filtered);

        $open      = array_values(array_filter($all, fn ($p) => in_array($p['status'], self::OPEN, true)));
        $awaiting  = array_values(array_filter($all, fn ($p) => $p['status'] === 'Awaiting approval'));
        $blocked   = array_values(array_filter($open, fn ($p) => self::budgetCheckPending($p)));
        $committed = array_sum(array_map(fn ($p) => $p['committed'], $all));

        return $this->viewBlock() + [
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($t) => [
                'label' => $t,
                'count' => match ($t) {
                    'Open'  => count($open),
                    'All'   => count($all),
                    default => count(array_filter($all, fn ($p) => $p['status'] === $t)),
                },
            ], ['Open', 'All', 'Draft', 'Awaiting approval', 'Approved', 'RFQ issued', 'PO raised', 'Goods received', 'Closed', 'Rejected']),
            'stats' => [
                ['label' => 'Open requisitions', 'value' => (string) count($open), 'note' => 'across five programmes'],
                ['label' => 'Awaiting approval', 'value' => (string) count($awaiting), 'note' => 'above the delegated threshold'],
                ['label' => 'Value in progress', 'value' => Prototype::fmt(array_sum(array_map(fn ($p) => $p['amount'], $open))), 'note' => 'not yet invoiced'],
                ['label' => 'Committed on POs', 'value' => Prototype::fmt($committed), 'note' => 'reserved against budget lines'],
                ['label' => 'Over available budget', 'value' => (string) count($blocked), 'note' => count($blocked) ? 'need a budget revision first' : 'every line is within budget'],
            ],
        ];
    }

    private function purchaseOrders(): array
    {
        $withPo = array_values(array_filter(Prototype::load('PQ'), fn ($p) => !empty($p['po'])));

        $rows = array_map(fn ($p) => [
            'po'        => $p['po'],
            'poDate'    => $p['poDate'],
            'no'        => $p['no'],
            'title'     => $p['title'],
            'supplier'  => $p['supplier'],
            'program'   => $p['program'],
            'fund'      => $p['fund'],
            'code'      => $p['code'],
            'amount'    => Prototype::fmt($p['amount']),
            'expected'  => $p['expected'] ?? $p['needBy'],
            'status'    => $p['status'],
            'received'  => !empty($p['grn']),
            'billed'    => !empty($p['bill']),
        ], $withPo);

        $outstanding = array_values(array_filter($withPo, fn ($p) => $p['status'] === 'PO raised'));

        return $this->viewBlock() + [
            'rows'  => $rows,
            'total' => count($withPo),
            'stats' => [
                ['label' => 'Purchase orders raised', 'value' => (string) count($withPo), 'note' => 'this financial year'],
                ['label' => 'Awaiting delivery', 'value' => (string) count($outstanding), 'note' => 'ordered, not yet received'],
                ['label' => 'Committed and not received', 'value' => Prototype::fmt(array_sum(array_map(fn ($p) => $p['amount'], $outstanding))), 'note' => 'reduces available budget'],
                ['label' => 'Received, not yet billed', 'value' => (string) count(array_filter($withPo, fn ($p) => !empty($p['grn']) && empty($p['bill']))), 'note' => 'accrue at period end'],
            ],
        ];
    }

    private function goodsReceived(): array
    {
        $withGrn = array_values(array_filter(Prototype::load('PQ'), fn ($p) => !empty($p['grn'])));

        $rows = array_map(fn ($p) => [
            'grn'        => $p['grn'],
            'grnDate'    => $p['grnDate'],
            'po'         => $p['po'],
            'no'         => $p['no'],
            'title'      => $p['title'],
            'supplier'   => $p['supplier'],
            'receivedBy' => $p['receivedBy'],
            'note'       => $p['grnNote'],
            'amount'     => Prototype::fmt($p['amount']),
            'bill'       => $p['bill'] ?? '',
            'billed'     => !empty($p['bill']),
        ], $withGrn);

        $unbilled = array_values(array_filter($withGrn, fn ($p) => empty($p['bill'])));

        return $this->viewBlock() + [
            'rows'  => $rows,
            'total' => count($withGrn),
            'stats' => [
                ['label' => 'Goods received notes', 'value' => (string) count($withGrn), 'note' => 'signed by store'],
                ['label' => 'Not yet billed', 'value' => (string) count($unbilled), 'note' => 'supplier payable recognised'],
                ['label' => 'Value awaiting invoice', 'value' => Prototype::fmt(array_sum(array_map(fn ($p) => $p['amount'], $unbilled))), 'note' => 'accrued at period end'],
            ],
        ];
    }

    private function suppliers(): array
    {
        $all = Prototype::load('SUPPLIERS');
        $q   = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter(
            $all,
            fn ($s) => $q === '' || str_contains(strtolower($s['name'] . ' ' . $s['pin'] . ' ' . $s['category']), $q)
        ));

        $rows = array_map(fn ($s) => $s + [
            'spendFmt' => Prototype::fmt($s['spend']),
            'eligible' => $s['status'] === 'Pre-qualified',
        ], $filtered);

        $countBy = fn ($k) => count(array_filter($all, fn ($s) => $s['status'] === $k));

        return $this->viewBlock() + [
            'rows'  => $rows,
            'total' => count($all),
            'stats' => [
                ['label' => 'Pre-qualified', 'value' => (string) $countBy('Pre-qualified'), 'note' => 'eligible to be awarded a PO'],
                ['label' => 'Expiring', 'value' => (string) $countBy('Expiring'), 'note' => 're-qualify before award'],
                ['label' => 'Lapsed', 'value' => (string) $countBy('Lapsed'), 'note' => 'cannot be awarded until renewed'],
                ['label' => 'Spend this year', 'value' => Prototype::fmt(array_sum(array_map(fn ($s) => $s['spend'], $all))), 'note' => count($all) . ' suppliers on the register'],
            ],
        ];
    }

    public function show($no)
    {
        foreach (Prototype::load('PQ') as $p) {
            if ($p['no'] !== $no) {
                continue;
            }

            $p['available']   = self::available($p);
            $p['overBudget']  = self::budgetCheckPending($p);
            $p['needsQuotes'] = self::quotesOutstanding($p);
            $p['canApprove']  = $p['status'] === 'Awaiting approval';
            $p['canRaisePo']  = $p['status'] === 'Approved';
            $p['canReceive']  = $p['status'] === 'PO raised';
            $p['canBill']     = $p['status'] === 'Goods received';
            $p['lineTotal']   = array_sum(array_map(static fn ($l) => $l['amount'], $p['lines']));

            return $this->json($p);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in procurement.']);
    }

    public function budgetLines()
    {
        $lines = Prototype::load('PQ_BUDGET_LINES');
        $reqs  = Prototype::load('PQ');

        $rows = array_map(function ($l) use ($reqs) {
            // Commitments live on the requisitions, so read them back per account.
            $committed = array_sum(array_map(
                static fn ($p) => $p['code'] === $l['code'] ? $p['committed'] : 0,
                $reqs
            ));
            $available = $l['budget'] - $l['spent'] - $committed;

            return [
                'code'      => $l['code'],
                'name'      => $l['name'],
                'budget'    => Prototype::fmt($l['budget']),
                'spent'     => Prototype::fmt($l['spent']),
                'committed' => Prototype::fmt($committed),
                'available' => Prototype::fmt($available),
                'exhausted' => $available <= 0,
                'pct'       => $l['budget'] > 0 ? (int) round(($l['spent'] + $committed) / $l['budget'] * 100) : 0,
            ];
        }, $lines);

        return $this->json([
            'rows' => $rows,
            'note' => 'Available budget is the approved amount less actual spend and less commitments on open purchase orders. A requisition is blocked at approval when it exceeds what is left.',
        ]);
    }

    /**
     * Approves a requisition — the transition that enforces the budget check and
     * segregation of duties. A blocked approval names the line, the account and
     * the shortfall rather than returning a generic failure.
     */
    public function approve($no)
    {
        $body     = $this->request->getJSON(true) ?? [];
        $approver = trim((string) ($body['approver'] ?? 'W. Kamau'));

        $all = Prototype::load('PQ');
        foreach ($all as $idx => $p) {
            if ($p['no'] !== $no) {
                continue;
            }

            if ($p['status'] !== 'Awaiting approval') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'Only a requisition awaiting approval can be approved. ' . $no . ' is ' . $p['status'] . '.',
                ]);
            }

            // Preparer ≠ approver. The requester field carries "Name · Role".
            $requester = trim(explode('·', $p['requester'])[0]);
            if ($this->sameActor($requester, $approver)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => $approver . ' raised ' . $no . ' and cannot also approve it. It needs a second approver.',
                ]);
            }

            if (self::isOverBudget($p)) {
                $short = $p['amount'] - self::available($p);

                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'Blocked on budget: ' . $p['title'] . ' asks ' . Prototype::fmt($p['amount'])
                        . ' against account ' . $p['code'] . ' (' . $p['program'] . ' · ' . $p['fund'] . '), which has '
                        . Prototype::fmt(self::available($p)) . ' available. Short by ' . Prototype::fmt($short)
                        . '. Raise a budget revision or reallocate before approving.',
                ]);
            }

            $all[$idx]['status']  = 'Approved';
            $all[$idx]['trail'][] = ['when' => date('d M'), 'what' => 'Approved by ' . $approver . ' within delegated limit'];
            Prototype::save('PQ', $all);

            return $this->json(['requisition' => $all[$idx]]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in procurement.']);
    }

    /**
     * Raises the purchase order. This is the point the budget is committed, and
     * the point the three-quote rule bites.
     */
    public function raisePo($no)
    {
        $body     = $this->request->getJSON(true) ?? [];
        $supplier = trim((string) ($body['supplier'] ?? ''));
        $waiver   = trim((string) ($body['waiver'] ?? ''));

        $all = Prototype::load('PQ');
        foreach ($all as $idx => $p) {
            if ($p['no'] !== $no) {
                continue;
            }

            if ($p['status'] !== 'Approved') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'A purchase order can only be raised against an approved requisition. ' . $no . ' is ' . $p['status'] . '.',
                ]);
            }
            if ($supplier === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Name the supplier the order is being placed with.']);
            }
            if (self::needsQuotes($p) && $waiver === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => $no . ' is ' . Prototype::fmt($p['amount']) . ', above the ' . Prototype::fmt(self::QUOTE_THRESHOLD)
                        . ' threshold, and has ' . count($p['quotes']) . ' of the 3 quotations required. Record the missing quotations '
                        . 'or attach a documented single-source waiver.',
                ]);
            }

            $supplierRecord = $this->supplierNamed($supplier);
            if ($supplierRecord !== null && $supplierRecord['status'] !== 'Pre-qualified') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => $supplier . ' is ' . strtolower($supplierRecord['status']) . ' — pre-qualification must be current before an order is placed.',
                ]);
            }

            $seq = 115 + count(array_filter($all, static fn ($x) => !empty($x['po'])));

            $all[$idx]['status']    = 'PO raised';
            $all[$idx]['supplier']  = $supplier;
            $all[$idx]['po']        = 'PO-26-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $all[$idx]['poDate']    = date('d M');
            $all[$idx]['expected']  = $body['expected'] ?? $p['needBy'];
            // Commitment accounting: the money is not spent, but it is no longer available.
            $all[$idx]['committed'] = $p['committed'] + $p['amount'];
            $all[$idx]['trail'][]   = [
                'when' => date('d M'),
                'what' => $all[$idx]['po'] . ' issued to ' . $supplier . ($waiver !== '' ? ' · single-source waiver: ' . $waiver : ''),
            ];

            Prototype::save('PQ', $all);

            return $this->json(['requisition' => $all[$idx]]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in procurement.']);
    }

    /**
     * Posts the goods received note. Receipt is what recognises the supplier
     * payable, so it releases the commitment and moves the value to actual.
     */
    public function receive($no)
    {
        $body = $this->request->getJSON(true) ?? [];

        $all = Prototype::load('PQ');
        foreach ($all as $idx => $p) {
            if ($p['no'] !== $no) {
                continue;
            }

            if ($p['status'] !== 'PO raised') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'Goods can only be received against an open purchase order. ' . $no . ' is ' . $p['status'] . '.',
                ]);
            }
            $receivedBy = trim((string) ($body['receivedBy'] ?? ''));
            if ($receivedBy === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'A goods received note must name who took delivery.']);
            }

            $seq = 89 + count(array_filter($all, static fn ($x) => !empty($x['grn'])));

            $all[$idx]['status']     = 'Goods received';
            $all[$idx]['grn']        = 'GRN-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $all[$idx]['grnDate']    = date('d M');
            $all[$idx]['receivedBy'] = $receivedBy;
            $all[$idx]['grnNote']    = trim((string) ($body['note'] ?? '')) ?: 'Received in full';
            // The commitment converts to actual expenditure on receipt.
            $all[$idx]['committed']  = max(0, $p['committed'] - $p['amount']);
            $all[$idx]['spent']      = $p['spent'] + $p['amount'];
            $all[$idx]['trail'][]    = [
                'when' => date('d M'),
                'what' => 'Goods received note ' . $all[$idx]['grn'] . ' signed by ' . $receivedBy . ' · supplier payable recognised',
            ];

            Prototype::save('PQ', $all);

            return $this->json(['requisition' => $all[$idx]]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in procurement.']);
    }

    private function supplierNamed(string $name): ?array
    {
        foreach (Prototype::load('SUPPLIERS') as $s) {
            if ($s['name'] === $name) {
                return $s;
            }
        }

        return null;
    }

    /** "G. Wambui" and "Grace Wambui" are the same person to the segregation check. */
    private function sameActor(string $a, string $b): bool
    {
        $surname = static fn (string $n) => strtolower(trim((string) array_slice(explode(' ', trim($n)), -1)[0]));

        return $surname($a) !== '' && $surname($a) === $surname($b);
    }
}
