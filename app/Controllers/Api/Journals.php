<?php

namespace App\Controllers\Api;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use App\Repositories\ApprovalPolicy;
use App\Repositories\AttachmentRepository;
use App\Repositories\GrantRepository;
use App\Repositories\JournalRepository;
use App\Repositories\PeriodRepository;
use App\Repositories\RecurringTemplateRepository;
use App\Repositories\RuleViolation;

class Journals extends BaseApiController
{
    /** Rows per page of the register. */
    private const PAGE_SIZE = 10;

    private const STATUSES = ['All', 'Draft', 'Pending approval', 'Posted', 'Reversed'];

    public static function validateAllocationFundTransfer(array $lines): string
    {
        if ($lines === []) {
            return 'Allocation journals must include at least two fund movements.';
        }

        $fundTotals = [];
        foreach ($lines as $line) {
            $fund = trim((string) ($line['fund'] ?? 'General Fund'));
            if ($fund === '') {
                $fund = 'General Fund';
            }

            $fundTotals[$fund] = (float) ($fundTotals[$fund] ?? 0) + ((float) ($line['dr'] ?? 0) - (float) ($line['cr'] ?? 0));
        }

        if (count($fundTotals) < 2) {
            return 'Allocation journals must involve at least two funds and their transfers must sum to zero.';
        }

        $netMovement = array_sum(array_values($fundTotals));
        if (abs($netMovement) > 0.0001) {
            return 'Allocation entries must sum to zero across fund transfers. Current fund movement totals are ' . Prototype::fmt(abs($netMovement)) . ' out of balance.';
        }

        $hasPositive = false;
        $hasNegative = false;
        foreach ($fundTotals as $fund => $total) {
            if ($total > 0) {
                $hasPositive = true;
            }
            if ($total < 0) {
                $hasNegative = true;
            }
        }

        if (!$hasPositive || !$hasNegative) {
            return 'Allocation entries must include both receiving and releasing fund movements so the transfer sums to zero.';
        }

        return '';
    }

    /**
     * The register, filtered by status, type and search, one page at a time. The
     * stats, tabs and hint are computed over every journal, not the page.
     */
    public function index()
    {
        $all    = (new JournalRepository())->all();
        $status = in_array($this->request->getGet('status'), self::STATUSES, true) ? $this->request->getGet('status') : 'All';
        $type   = $this->request->getGet('type') ?: 'All types';
        $q      = mb_strtolower(trim($this->request->getGet('q') ?? ''));
        $page   = max(1, (int) ($this->request->getGet('page') ?: 1));

        $sumDr = fn ($lines) => array_sum(array_map(fn ($l) => $l['dr'] ?? 0, $lines));
        $sumCr = fn ($lines) => array_sum(array_map(fn ($l) => $l['cr'] ?? 0, $lines));
        $grantLabels = fn ($lines) => array_values(array_unique(array_filter(array_map(fn ($l) => $l['grant'] ?? '', $lines))));

        $filtered = array_values(array_filter($all, function ($j) use ($status, $type, $q, $grantLabels) {
            if ($status !== 'All' && $j['status'] !== $status) {
                return false;
            }
            if ($type !== 'All types' && $j['type'] !== $type) {
                return false;
            }
            $haystack = $j['ref'] . ' ' . $j['narration'] . ' ' . $j['preparer'] . ' ' . $j['type'] . ' ' . implode(' ', $grantLabels($j['lines']));

            return $q === '' || str_contains(mb_strtolower($haystack), $q);
        }));

        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page  = min($page, $pages);

        // One value if every line shares it, otherwise the number of segments.
        $segment = function (array $lines, string $key) {
            $values = array_values(array_unique(array_map(fn ($l) => $l[$key], $lines)));

            return count($values) === 1 ? $values[0] : count($values) . ' segments';
        };

        $rows = array_map(function ($j) use ($sumDr, $segment, $grantLabels) {
            $grants = $grantLabels($j['lines']);

            return [
                'ref'       => $j['ref'],
                'date'      => substr($j['date'], 0, 6),
                'type'      => $j['type'],
                'narration' => $j['narration'],
                'status'    => $j['status'],
                'fund'      => $j['lines'] === [] ? '—' : $segment($j['lines'], 'fund'),
                'program'   => $j['lines'] === [] ? '—' : $segment($j['lines'], 'program'),
                'grant'     => $grants === [] ? '—' : (count($grants) === 1 ? $grants[0] : count($grants) . ' awards'),
                // A restricted line without an award cannot be claimed on a donor report.
                'grantMissing' => array_filter($j['lines'], fn ($l) => $l['restricted'] && $l['grantRef'] === '') !== [],
                'lines'     => count($j['lines']),
                'amount'    => Prototype::fmt($sumDr($j['lines'])),
                'preparer'  => $j['preparer'],
            ];
        }, array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE));

        $countBy = fn ($s) => count(array_filter($all, fn ($j) => $j['status'] === $s));
        $unbalancedDrafts = count(array_filter($all, fn ($j) => $j['status'] === 'Draft' && $sumDr($j['lines']) !== $sumCr($j['lines'])));

        $pending = array_filter(array_column(array_filter($all, fn ($j) => $j['status'] === 'Pending approval'), 'submittedAt'));
        $oldest  = $pending === [] ? null : -Clock::daysUntil(min($pending));

        $period = (new PeriodRepository())->current();
        $periodName = $period['name'] ?? '';

        return $this->json([
            'rows'        => $rows,
            'total'       => count($all),
            'filtered'    => count($filtered),
            'page'        => $page,
            'pages'       => $pages,
            'pageSize'    => self::PAGE_SIZE,
            'typeOptions' => array_merge(['All types'], JournalRepository::types()),
            'tabs'        => array_map(fn ($s) => ['label' => $s, 'count' => $s === 'All' ? count($all) : $countBy($s)], self::STATUSES),
            'hint'        => $unbalancedDrafts > 0 ? $unbalancedDrafts . ' draft out of balance' : 'All drafts balance',
            'footer'      => count($filtered) . ' of ' . count($all) . ' journals · ' . $countBy('Pending approval') . ' awaiting your approval',
            'policy'      => ($rule = (new ApprovalPolicy())->rule('journal')) === null ? 'Two-person rule enforced'
                : 'Approval threshold KES ' . Prototype::fmt($rule['threshold']) . ' · above it the ' . ($rule['escalation'] ?? $rule['authority'] ?? $rule['approver']) . ' approves · two-person rule enforced',
            'stats' => [
                ['label' => 'Awaiting approval', 'value' => (string) $countBy('Pending approval'), 'note' => $oldest === null ? 'none waiting' : 'oldest ' . $oldest . ($oldest === 1 ? ' day' : ' days')],
                ['label' => 'Drafts', 'value' => (string) $countBy('Draft'), 'note' => $unbalancedDrafts . ' out of balance'],
                ['label' => 'Posted this period', 'value' => (string) count(array_filter($all, fn ($j) => $j['status'] === 'Posted' && $j['period'] === $periodName)),
                    'note' => $period === null ? '—' : date('F Y', strtotime($period['starts_on']))],
                ['label' => 'Value posted YTD', 'value' => Prototype::fmt(array_sum(array_map(fn ($j) => $sumDr($j['lines']), array_filter($all, fn ($j) => $j['status'] === 'Posted' && !$j['opening'])))), 'note' => 'KES, all funds'],
                ['label' => 'Reversals', 'value' => (string) $countBy('Reversed'), 'note' => 'requires memo'],
            ],
        ]);
    }

    public function show($ref)
    {
        $journal = (new JournalRepository())->find($ref);

        return $journal === null
            ? $this->response->setStatusCode(404)->setJSON(['error' => 'not found'])
            : $this->json($journal);
    }

    /** What the journal editor offers the acting user. */
    public function form()
    {
        return $this->json((new JournalRepository())->formOptions($this->actor()));
    }

    /** Downloads one of a journal's supporting documents. */
    public function attachment($ref, $id)
    {
        $file = (new JournalRepository())->attachment((string) $ref, (int) $id);
        if ($file === null || !is_file($file['path'])) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        }

        return $this->response->download($file['path'], null)->setFileName($file['filename'])->setContentType($file['mime_type']);
    }

    /**
     * Creates a new journal as a Draft or submits it straight for approval.
     *
     * Accepts JSON, or a multipart form whose `payload` field is that JSON and whose
     * `attachments[]` are the supporting documents.
     */
    public function create()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot raise journal entries. Switch to a preparer in the account menu.');
        }

        try {
            [$journal, $files] = $this->submission(null);
            $journal = (new JournalRepository())->create($journal, $this->actorId(), $files);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->response->setStatusCode(201)->setJSON(['journal' => $journal]);
    }

    /**
     * Saves changes to a draft or an entry awaiting approval, as a draft or
     * submitted for approval. Takes the same body as create(), plus
     * `removeAttachments`: ids of supporting documents to remove.
     */
    public function update($ref)
    {
        $repo = new JournalRepository();
        $existing = $repo->find((string) $ref);
        if ($existing === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot change journal entries. Switch to a preparer in the account menu.');
        }

        try {
            [$journal, $files, $body] = $this->submission($existing);
            $removed = array_map('intval', (array) ($body['removeAttachments'] ?? []));

            return $this->json(['journal' => $repo->update((string) $ref, $journal, $this->actorId(), $files, $removed)]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Discards a draft. Posted entries are corrected by reversal, never deleted. */
    public function discard($ref)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot discard journal entries.');
        }

        try {
            (new JournalRepository())->discard((string) $ref, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['ref' => $ref]);
    }

    /** Raises the linked contra journal of a posted entry and sends it for approval. */
    public function reverse($ref)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot raise entries. Switch to a preparer to reverse ' . $ref . '.');
        }

        try {
            return $this->response->setStatusCode(201)->setJSON(['journal' => (new JournalRepository())->reverse((string) $ref, $this->actorId())]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Approves and posts a journal entry from Pending approval status, as the acting user. */
    public function approve($ref)
    {
        if ((new JournalRepository())->find($ref) === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' has no posting rights. ' . $ref . ' stays pending.');
        }

        try {
            return $this->json(['journal' => (new JournalRepository())->approve($ref, $this->actorId())]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Rejects a journal entry, returning it to Draft with the reason on record. */
    public function reject($ref)
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));

        if ((new JournalRepository())->find($ref) === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot reject entries awaiting approval.');
        }

        try {
            return $this->json(['journal' => (new JournalRepository())->reject($ref, $this->actorId(), $reason)]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    // ---- Recurring templates ----

    /** The templates, and the recent entries a new template can be created from. */
    public function recurring()
    {
        $templates = (new RecurringTemplateRepository())->all();
        $active = count(array_filter($templates, fn ($t) => $t['status'] === 'Active'));

        // An entry a template already generated is not offered again.
        $runs = array_merge([], ...array_map(fn ($t) => array_column($t['runs'], 'ref'), $templates));
        $recent = array_slice(array_filter((new JournalRepository())->all(), fn ($j) => !$j['opening'] && !in_array($j['ref'], $runs, true)), 0, 8);

        return $this->json([
            'summary'   => $active . ' active · ' . (count($templates) - $active) . ' paused · nothing posts without approval',
            'templates' => $templates,
            'journals'  => array_values(array_map(fn ($j) => ['ref' => $j['ref'], 'label' => $j['ref'] . ' · ' . ($j['narration'] ?: ($j['memo'] ?: $j['type']))], $recent)),
        ]);
    }

    /** Creates a monthly template from an entry: `{ref}`. */
    public function createRecurring()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot create templates.');
        }
        $ref = trim((string) (($this->request->getJSON(true) ?? [])['ref'] ?? ''));

        try {
            return $this->response->setStatusCode(201)->setJSON(['template' => (new RecurringTemplateRepository())->fromJournal($ref, $this->actorId())]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Generates a template's next entry now. */
    public function runRecurring($code)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot raise entries. Switch to a preparer to run this template.');
        }

        try {
            $journal = (new RecurringTemplateRepository())->run((string) $code, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->response->setStatusCode(201)->setJSON(['journal' => $journal, 'template' => (new RecurringTemplateRepository())->find((string) $code)]);
    }

    /** Pauses or resumes a template. */
    public function toggleRecurring($code)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot change templates.');
        }

        try {
            return $this->json(['template' => (new RecurringTemplateRepository())->toggle((string) $code, $this->actorId())]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    // ---- Helpers ----

    private function forbidden(string $message)
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => $message]);
    }

    /**
     * Reads and checks an editor submission: the entry as the repository takes it,
     * the uploaded supporting documents, and the raw body.
     *
     * @param array|null $existing the journal being changed, or null for a new entry
     * @return array{0: array, 1: list<array>, 2: array}
     */
    private function submission(?array $existing): array
    {
        $multipart = str_starts_with($this->request->getHeaderLine('Content-Type'), 'multipart/form-data');
        $body = $multipart ? (json_decode((string) $this->request->getPost('payload'), true) ?? []) : ($this->request->getJSON(true) ?? []);

        $attachments = new AttachmentRepository();
        $files = array_map([$attachments, 'accept'], $multipart ? ($this->request->getFileMultiple('attachments') ?? []) : []);

        $status = (string) ($body['status'] ?? 'Draft');
        if (!in_array($status, ['Draft', 'Pending approval'], true)) {
            $status = 'Draft';
        }
        $type = (string) ($body['type'] ?? 'Standard');
        $narration = trim((string) ($body['narration'] ?? ''));

        $validTypes = JournalRepository::types();
        if (!in_array($type, $validTypes, true)) {
            throw new RuleViolation('Invalid journal type. Must be one of: ' . implode(', ', $validTypes) . '.');
        }

        // A reversing entry names the posted entry it reverses; an existing reversal keeps its link.
        $reversalOf = $existing['reversalOf'] ?? trim((string) ($body['reversalOf'] ?? ''));
        if ($type === 'Reversing' && $existing === null) {
            $this->checkReversal($reversalOf);
        }

        $lines = array_values(array_filter($body['lines'] ?? [], function ($l) {
            return trim((string) ($l['code'] ?? '')) !== '' || (float) ($l['dr'] ?? 0) !== 0.0 || (float) ($l['cr'] ?? 0) !== 0.0;
        }));

        $grants = [];
        foreach ((new GrantRepository())->all() as $grant) {
            $grants[$grant['ref']] = $grant;
        }
        // The fund and programme are the preparer's choice; the repository holds them to what the award covers.
        foreach ($lines as $line) {
            $grantRef = trim((string) ($line['grantRef'] ?? ''));
            if ($grantRef !== '') {
                if (!isset($grants[$grantRef])) {
                    throw new RuleViolation('Selected grant ' . $grantRef . ' does not exist.');
                }
                if (!in_array($grants[$grantRef]['status'], ['Active', 'Closing'], true)) {
                    throw new RuleViolation('Selected grant ' . $grantRef . ' is not available for journal postings.');
                }
            }
        }

        $lines = array_map(fn ($l) => [
            'code'     => trim((string) ($l['code'] ?? '')),
            'desc'     => trim((string) ($l['desc'] ?? '')),
            'fund'     => $l['fund'] ?? 'General Fund',
            'program'  => $l['program'] ?? 'Shared services',
            'grantRef' => trim((string) ($l['grantRef'] ?? '')),
            'dr'       => round((float) ($l['dr'] ?? 0)),
            'cr'       => round((float) ($l['cr'] ?? 0)),
        ], $lines);

        if (count($lines) < 2) {
            throw new RuleViolation('A journal needs at least two lines.');
        }

        $sumDr = array_sum(array_map(fn ($l) => $l['dr'], $lines));
        $sumCr = array_sum(array_map(fn ($l) => $l['cr'], $lines));

        // Every journal — draft or not — must balance: total debits always equal total credits.
        if ($sumDr !== $sumCr || $sumDr === 0.0) {
            throw new RuleViolation('Entry is out of balance by ' . Prototype::fmt(abs($sumDr - $sumCr)) . ' — debits must equal credits before it can be saved.');
        }

        if ($type === 'Allocation') {
            $allocationError = self::validateAllocationFundTransfer($lines);
            if ($allocationError !== '') {
                throw new RuleViolation($allocationError);
            }
        }

        if ($status !== 'Draft' && $narration === '') {
            throw new RuleViolation('A narration is required before the entry leaves draft.');
        }

        foreach ($lines as $l) {
            if (($l['dr'] > 0) === ($l['cr'] > 0)) {
                throw new RuleViolation('Each line needs either a debit or a credit — ' . ($l['code'] ?: 'a line') . ' has ' . ($l['dr'] > 0 ? 'both' : 'neither') . '.');
            }
        }

        $journal = [
            'date'      => trim((string) ($body['date'] ?? '')) ?: Clock::date(),
            'type'      => $type,
            'period'    => $body['period'] ?? (new PeriodRepository())->currentName(),
            'status'    => $status,
            'docLink'   => trim((string) ($body['docLink'] ?? 'auto')),
            'memo'      => trim((string) ($body['memo'] ?? '')),
            'narration' => $narration,
            'lines'     => $lines,
        ];

        if ($type === 'Reversing' && $existing === null) {
            $journal['reversalOf'] = $reversalOf;
        }

        return [$journal, $files, $body];
    }

    /** A new reversing entry must name a posted entry that has not already been reversed. */
    private function checkReversal(string $reversalOf): void
    {
        if ($reversalOf === '') {
            throw new RuleViolation('Reversing entries must reference an original entry. Provide the original journal reference in "reversalOf".');
        }

        $all = (new JournalRepository())->all();
        $original = current(array_filter($all, fn ($j) => $j['ref'] === $reversalOf));
        if ($original === false) {
            throw new RuleViolation('Referenced entry ' . $reversalOf . ' does not exist.');
        }
        if ($original['status'] !== 'Posted') {
            throw new RuleViolation('Cannot reverse ' . $reversalOf . ' — only posted entries can be reversed. Current status: ' . $original['status'] . '.');
        }
        if (array_filter($all, fn ($j) => ($j['reversalOf'] ?? '') === $reversalOf && $j['status'] === 'Posted') !== []) {
            throw new RuleViolation('Entry ' . $reversalOf . ' has already been reversed.');
        }
    }
}
