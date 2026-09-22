<?php

namespace App\Controllers\Api;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use App\Repositories\AssetAdditionRepository;
use App\Repositories\AssetRepository;
use App\Repositories\Lookups;
use App\Repositories\PostingAccounts;
use App\Repositories\RuleViolation;
use App\Repositories\AttachmentRepository;

/**
 * Asset register (v5): the register with its stats and tabs, the monthly
 * depreciation run, the asset drawer with its schedule and history, purchases
 * capitalised onto the register, donated and found assets proposed and approved,
 * disposals proposed and approved, and the register against the ledger. Every write answers
 * with the refreshed register.
 */
class Assets extends BaseApiController
{
    private const PAGE_SIZE = 10;

    private const TABS = ['All', 'In use', 'Donor-funded', 'Fully depreciated', 'Disposed'];

    public function index()
    {
        return $this->json($this->register());
    }

    public function show(string $tag)
    {
        $repo = new AssetRepository();
        $a = $repo->find($tag);
        if ($a === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $tag . ' was not found on the asset register.']);
        }
        $actor = $this->actor();
        $period = $repo->period();
        $disposal = $repo->disposals()[$tag] ?? null;
        $pending = $disposal !== null && $disposal['status'] === 'pending_approval';
        $posted = $disposal !== null && $disposal['status'] !== 'pending_approval';
        $fmt = static fn ($n) => Prototype::fmt($n);
        $monthly = AssetRepository::monthlyBase($a);
        $reverts = AssetRepository::titleReverts($a);

        $status = match (true) {
            $posted && $disposal['journal_ref'] !== null => 'Disposed ' . self::dmy($disposal['disposed_on']) . ' by ' . strtolower($disposal['methodLabel'])
                . ' · proceeds ' . $fmt((float) $disposal['proceeds']) . ', ' . self::result((float) $disposal['proceeds'] - (float) $disposal['carrying_amount']) . ' · ' . $disposal['journal_ref'],
            $posted => 'Disposed ' . self::dmy($disposal['disposed_on']) . ' by ' . strtolower($disposal['methodLabel']) . ' · proceeds ' . $fmt((float) $disposal['proceeds'])
                . ', ' . self::result((float) $disposal['proceeds'] - (float) $disposal['carrying_amount']),
            $pending => 'In use · disposal proposed ' . self::dmy($disposal['disposed_on']) . ', waiting for approval — the asset stays on the register and keeps depreciating until it is posted',
            default => $a['status'],
        };

        return $this->json([
            'tag' => $a['tag'], 'name' => $a['name'], 'doc' => $a['doc'], 'costAcct' => $a['costAcct'],
            'cost' => $a['cost'], 'accum' => $a['accum'], 'nbv' => $a['cost'] - $a['accum'],
            'canDispose' => $a['status'] !== 'Disposed' && $disposal === null && $actor['canPrepare'],
            'disposalNote' => match (true) {
                $pending => 'Disposal proposed, awaiting approval',
                $posted  => 'Disposed ' . self::dmy($disposal['disposed_on']) . ($disposal['journal_ref'] !== null ? ' · ' . $disposal['journal_ref'] : ''),
                default  => '',
            },
            'scheduleHint' => $fmt($monthly) . ' a month · ' . $a['life'] . ' years straight line',
            'schedule' => array_map(static fn ($y) => [
                'year' => $y['year'], 'opening' => $fmt($y['opening']), 'charge' => $fmt($y['charge']), 'closing' => $fmt($y['closing']), 'current' => $y['current'],
            ], $repo->schedule($a, $posted ? $disposal : null)),
            'ledger' => $this->ledger($repo, $a, $period, $posted ? $disposal : null),
            'trail' => $a['trail'],
            'documents' => (new AttachmentRepository())->for('asset', (int) $a['id']),
            'canAttach' => $actor['canPrepare'],
            'facts' => [
                ['label' => 'Class', 'value' => $a['cls']],
                ['label' => 'Acquired', 'value' => $a['acquired'] . ($a['doc'] !== '' ? ' · ' . $a['doc'] : '')],
                ['label' => 'Cost', 'value' => $fmt($a['cost'])],
                ['label' => 'Depreciation to date', 'value' => $fmt($a['accum'])],
                ['label' => $posted ? 'Net book value at disposal' : 'Net book value', 'value' => $fmt($posted ? (float) $disposal['carrying_amount'] : $a['cost'] - $a['accum'])],
                ['label' => 'Policy', 'value' => 'Straight line over ' . $a['life'] . ' years, ' . ($a['residual'] > 0 ? 'residual ' . $fmt($a['residual']) : 'nil residual') . ' · ' . $fmt($monthly) . ' a month'],
                ['label' => 'Fund and grant', 'value' => $a['fund'] . ' · ' . $a['grant']],
                ['label' => 'Programme', 'value' => $a['program']],
                ['label' => 'Title', 'value' => $a['title'] !== '' ? $a['title'] : '—'],
                ['label' => 'Custodian', 'value' => $a['custodian'] . ' · ' . $a['location']],
                ['label' => 'Status', 'value' => $status],
            ],
            // What the disposal form needs to show its summary, journal and blocks
            // as the user types; the API applies the same rules again.
            'dispose' => [
                'methods' => array_keys(AssetRepository::DISPOSAL_METHODS),
                'method' => $a['status'] === 'Fully depreciated' ? 'Write-off — beyond repair' : 'Public auction',
                'date' => $this->workingDate($period),
                'acquiredOn' => $a['acquiredOn'],
                'needsConsent' => $reverts,
                'funder' => $a['funder'],
                'consentNote' => $a['funder'] === '—' ? '' : ($reverts
                    ? 'This asset was bought with ' . $a['funder'] . ' money and title reverts at grant close. Any proceeds stay in ' . $a['fund'] . ' and must be reapplied to the award or returned.'
                    : 'Bought with ' . $a['funder'] . ' money under ' . $a['grant'] . '. ELOG holds title, but the disposal must still be reported in the next donor report.'),
                'costAccount' => $a['costAcct'] . ' · ' . $this->accountName($a['costAcct']),
                'accounts' => [
                    'accum' => PostingAccounts::of('accumulated') . ' · ' . $this->accountName(PostingAccounts::of('accumulated')),
                    'bank' => PostingAccounts::of('disposalProceeds') . ' · ' . $this->accountName(PostingAccounts::of('disposalProceeds')),
                    'gain' => PostingAccounts::of('disposalGain') . ' · ' . $this->accountName(PostingAccounts::of('disposalGain')),
                    'loss' => PostingAccounts::of('disposalLoss') . ' · ' . $this->accountName(PostingAccounts::of('disposalLoss')),
                ],
                'periods' => array_map(static fn ($p) => ['name' => $p['name'], 'min' => $p['starts_on'], 'max' => $p['ends_on'], 'closed' => $p['status'] === 'closed'],
                    (new Lookups())->periods()),
            ],
        ]);
    }

    /**
     * Capitalises a purchase already in the ledger:
     * {line, class, description, units, amount, life, location, custodian, title, serial}.
     */
    public function capitalise()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot capitalise assets. Switch to a preparer to put a purchase on the register.']);
        }
        $b = $this->request->getJSON(true) ?? [];
        try {
            $tags = (new AssetAdditionRepository())->capitalise((int) ($b['line'] ?? 0), [
                'class' => (string) ($b['class'] ?? ''), 'description' => (string) ($b['description'] ?? ''),
                'units' => (int) ($b['units'] ?? 1), 'amount' => self::amount($b['amount'] ?? 0), 'life' => (int) ($b['life'] ?? 0),
                'location' => (string) ($b['location'] ?? ''), 'custodian' => (string) ($b['custodian'] ?? ''),
                'title' => (string) ($b['title'] ?? ''), 'serial' => (string) ($b['serial'] ?? ''),
            ], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + [
            'message' => (count($tags) === 1 ? $tags[0] . ' is' : count($tags) . ' assets, ' . $tags[0] . ' to ' . end($tags) . ', are')
                . ' on the register — nothing new posts; the purchase already carries the cost. Depreciation starts the month after it was bought.',
            'tags' => $tags,
        ]);
    }

    /** Proposes a donated or found asset: {basis, tag, class, description, amount, date, source, reference, reason, life, location, custodian, title}. */
    public function add()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot add assets. Switch to a preparer to propose one.']);
        }
        $b = $this->request->getJSON(true) ?? [];
        try {
            $tag = (new AssetAdditionRepository())->propose([
                'basis' => (string) ($b['basis'] ?? ''), 'tag' => (string) ($b['tag'] ?? ''), 'class' => (string) ($b['class'] ?? ''),
                'description' => (string) ($b['description'] ?? ''), 'amount' => self::amount($b['amount'] ?? 0), 'date' => (string) ($b['date'] ?? ''),
                'source' => (string) ($b['source'] ?? ''), 'reference' => (string) ($b['reference'] ?? ''), 'reason' => (string) ($b['reason'] ?? ''),
                'life' => (int) ($b['life'] ?? 0), 'location' => (string) ($b['location'] ?? ''), 'custodian' => (string) ($b['custodian'] ?? ''),
                'title' => (string) ($b['title'] ?? ''), 'documents' => $b['documents'] ?? [],
            ], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + ['message' => $tag . ' submitted for approval — it comes onto the register and the ledger when a second person approves it.']);
    }

    public function withdrawAddition()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot change an addition.']);
        }
        $tag = (string) (($this->request->getJSON(true) ?? [])['tag'] ?? '');
        try {
            (new AssetAdditionRepository())->withdraw($tag, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + ['message' => 'Addition of ' . $tag . ' withdrawn.']);
    }

    public function approveAddition()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot approve an addition. Switch to an approver to post it.']);
        }
        $tag = (string) (($this->request->getJSON(true) ?? [])['tag'] ?? '');
        try {
            $d = (new AssetAdditionRepository())->approve($tag, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + [
            'message' => $d['journal'] . ' posted — ' . $tag . ' is on the register at ' . Prototype::fmt($d['amount']) . ', credited to '
                . ($d['credit'] === PostingAccounts::of('donatedAssets') ? $d['credit'] . ' donated assets.' : $d['credit'] . ' fund balance as a correction of the earlier omission.'),
        ]);
    }

    /** Posts the depreciation charge for the period the books are working in. */
    public function run()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot run depreciation. Switch to a preparer to post the monthly charge.']);
        }
        try {
            $run = (new AssetRepository())->runDepreciation($this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + [
            'message' => 'Depreciation of ' . Prototype::fmt($run['total']) . ' posted for ' . $run['period'] . ' — ' . $run['journal'] . ', debit '
                . PostingAccounts::of('depreciation') . ', credit ' . PostingAccounts::of('accumulated') . ' across ' . $run['assets'] . ' assets.',
        ]);
    }

    /** Proposes a disposal: {tag, method, date, proceeds, buyer, minute, consent, reason}. */
    public function dispose()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot propose a disposal. Switch to a preparer to propose one.']);
        }
        $body = $this->request->getJSON(true) ?? [];
        $tag = (string) ($body['tag'] ?? '');
        try {
            (new AssetRepository())->proposeDisposal($tag, [
                'method' => (string) ($body['method'] ?? ''), 'date' => (string) ($body['date'] ?? ''),
                'proceeds' => self::amount($body['proceeds'] ?? '0'),
                'buyer' => (string) ($body['buyer'] ?? ''), 'minute' => (string) ($body['minute'] ?? ''),
                'consent' => (string) ($body['consent'] ?? ''), 'reason' => (string) ($body['reason'] ?? ''),
            ], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + [
            'message' => 'Disposal of ' . $tag . ' submitted for approval — nothing leaves the register until it is approved.',
        ]);
    }

    public function withdraw()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot change a disposal.']);
        }
        $tag = (string) (($this->request->getJSON(true) ?? [])['tag'] ?? '');
        try {
            (new AssetRepository())->withdrawDisposal($tag, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + ['message' => 'Disposal proposal for ' . $tag . ' withdrawn — the asset stays on the register.']);
    }

    public function approve()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot approve a disposal. Switch to an approver to post it.']);
        }
        $tag = (string) (($this->request->getJSON(true) ?? [])['tag'] ?? '');
        try {
            $d = (new AssetRepository())->approveDisposal($tag, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($this->register() + [
            'message' => $d['journal'] . ' posted — ' . $tag . ' derecognised at cost of ' . Prototype::fmt($d['cost']) . ' with ' . Prototype::fmt($d['accum'])
                . ' of depreciation released, and a ' . ($d['result'] >= 0
                    ? 'gain of ' . Prototype::fmt($d['result']) . ' to ' . PostingAccounts::of('disposalGain') . '.'
                    : 'loss of ' . Prototype::fmt(-$d['result']) . ' to ' . PostingAccounts::of('disposalLoss') . '.'),
        ]);
    }

    /** The register as the page shows it, for the filter, search and page asked for. */
    private function register(): array
    {
        $repo = new AssetRepository();
        $actor = $this->actor();
        $all = $repo->register();
        $period = $repo->period();
        $periodName = $period['name'] ?? '—';
        $month = $period === null ? '' : date('F', strtotime($period['starts_on']));
        $run = $repo->run($period);
        $fmt = static fn ($n) => Prototype::fmt($n);

        $get = fn (string $k, string $default) => (string) ($this->request->getGet($k) ?? $default);
        $filter = in_array($get('filter', 'All'), self::TABS, true) ? $get('filter', 'All') : 'All';
        $q = strtolower(trim($get('q', '')));

        $donor = static fn ($a) => $a['funder'] !== '—' && $a['status'] !== 'Disposed';
        $filtered = array_values(array_filter($all, static function ($a) use ($filter, $q, $donor) {
            if ($q !== '' && !str_contains(strtolower(implode(' ', [$a['tag'], $a['name'], $a['funder'], $a['custodian'], $a['cls']])), $q)) {
                return false;
            }

            return match ($filter) {
                'Donor-funded' => $donor($a),
                'All'          => true,
                default        => $a['status'] === $filter,
            };
        }));
        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page = max(1, min($pages, (int) $get('page', '1')));

        $held = array_values(array_filter($all, static fn ($a) => $a['status'] !== 'Disposed'));
        $donors = array_values(array_filter($all, $donor));
        $cost = array_sum(array_column($held, 'cost'));
        $accum = array_sum(array_column($held, 'accum'));
        $charge = $repo->chargeFor($period);
        $spent = count(array_filter($all, static fn ($a) => $a['status'] === 'Fully depreciated'));

        $disposals = array_values(array_filter($repo->disposals(), static fn ($d) => in_array($d['status'], ['pending_approval', 'posted'], true)));
        $byTag = array_column($all, null, 'tag');
        $pending = count(array_filter($disposals, static fn ($d) => $d['status'] === 'pending_approval'));
        $postedCount = count($disposals) - $pending;

        $additions = new AssetAdditionRepository();
        $awaiting = $additions->awaiting();
        $pendingAdditions = array_values(array_filter($additions->additions(), static fn ($d) => $d['status'] === 'pending_approval'));
        $classes = $additions->classes();

        $tie = $repo->tie();
        $tied = $tie['registerCost'] == $tie['ledgerCost'] && $tie['registerAccum'] == $tie['ledgerAccum'];
        $ytd = (new Lookups())->balance(PostingAccounts::of('depreciation'));
        $diff = static fn (float $r, float $l) => round($r - $l, 2) == 0 ? 'nil' : $fmt($r - $l);

        return [
            'period' => $periodName,
            'kicker' => $run !== null ? 'Depreciation posted for ' . $periodName : 'Depreciation for ' . $periodName . ' not yet posted',
            'run' => [
                'done' => $run !== null,
                'label' => $run !== null ? 'Depreciation posted ✓' : 'Run depreciation for ' . $periodName,
                'can' => $run === null && $period !== null && $actor['canPrepare'],
                'ref' => $run['journal_ref'] ?? '',
            ],
            'stats' => [
                ['label' => 'Cost of assets held', 'value' => $fmt($cost), 'note' => count($held) . ' items on the register'],
                ['label' => 'Depreciation to date', 'value' => $fmt($accum), 'note' => ($cost > 0 ? round($accum / $cost * 100) : 0) . '% of cost written down'],
                ['label' => 'Net book value', 'value' => $fmt($cost - $accum), 'note' => 'carried in the statement of financial position'],
                ['label' => 'Charge for ' . $periodName, 'value' => $fmt($charge), 'note' => $run !== null ? 'posted to ' . PostingAccounts::of('depreciation') : 'not yet posted'],
                ['label' => 'Donor-funded book value', 'value' => $fmt(array_sum(array_map(static fn ($a) => $a['cost'] - $a['accum'], $donors))), 'note' => count($donors) . ' items, title may revert'],
            ],
            'tabs' => array_map(static fn ($k) => [
                'key' => $k,
                'label' => $k . ' (' . match ($k) {
                    'All' => count($all),
                    'Donor-funded' => count($donors),
                    default => count(array_filter($all, static fn ($a) => $a['status'] === $k)),
                } . ')',
            ], self::TABS),
            'filter' => $filter,
            'hint' => $month . ' charge of ' . $fmt($charge) . ($run !== null ? ' is in the ledger' : ' is calculated but not yet posted'),
            'rows' => array_map(fn ($a) => [
                'tag' => $a['tag'], 'name' => $a['name'], 'sub' => $a['cls'] . ' · ' . $a['life'] . ' years · acquired ' . $a['acquired'],
                'funder' => $a['funder'] === '—' ? 'Core funded' : $a['funder'],
                'cost' => $a['cost'], 'accum' => $a['accum'], 'nbv' => $a['cost'] - $a['accum'], 'monthly' => $repo->charge($a, $period),
                'status' => $a['status'],
            ], array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)),
            'page' => $page, 'pages' => $pages, 'pageSize' => self::PAGE_SIZE, 'filtered' => count($filtered), 'total' => count($all),
            'footer' => count($filtered) . ' of ' . count($all) . ' assets · ' . count($donors) . ' donor-funded · ' . $spent . ' fully written down',
            'disposals' => [
                'hint' => $pending > 0
                    ? $pending . ' awaiting approval'
                    : $postedCount . ($postedCount === 1 ? ' disposal posted' : ' disposals posted'),
                'rows' => array_map(static function ($d) use ($byTag, $fmt, $actor) {
                    $a = $byTag[$d['tag']];
                    $result = (float) $d['proceeds'] - (float) $d['carrying_amount'];

                    return [
                        'tag' => $d['tag'],
                        'title' => $d['tag'] . ' · ' . $a['name'],
                        'detail' => $d['methodLabel'] . ' · ' . self::dmy($d['disposed_on']) . ' · ' . ($d['buyer'] ?? 'recipient not named') . ' · minute ' . $d['board_minute']
                            . ($d['donor_consent_ref'] !== null ? ' · donor consent ' . $d['donor_consent_ref'] : ''),
                        'nbv' => $fmt((float) $d['carrying_amount']), 'proceeds' => $fmt((float) $d['proceeds']),
                        'resultLabel' => $result >= 0 ? 'Gain' : 'Loss', 'result' => $fmt(abs($result)), 'gain' => $result >= 0,
                        'pending' => $d['status'] === 'pending_approval', 'posted' => $d['status'] === 'posted', 'ref' => $d['journal_ref'] ?? '',
                        'canApprove' => $actor['canApprove'], 'canWithdraw' => $actor['canPrepare'] || $actor['canApprove'],
                    ];
                }, $disposals),
            ],
            'tie' => [
                'hint' => $periodName . ' · register is the subsidiary record',
                'rows' => [
                    ['label' => 'Cost of property and equipment', 'acct' => implode(' + ', $tie['costAccounts']), 'register' => $fmt($tie['registerCost']), 'ledger' => $fmt($tie['ledgerCost']),
                        'diff' => $diff($tie['registerCost'], $tie['ledgerCost']), 'agrees' => round($tie['registerCost'] - $tie['ledgerCost'], 2) == 0],
                    ['label' => 'Accumulated depreciation', 'acct' => PostingAccounts::of('accumulated'), 'register' => $fmt($tie['registerAccum']), 'ledger' => $fmt($tie['ledgerAccum']),
                        'diff' => $diff($tie['registerAccum'], $tie['ledgerAccum']), 'agrees' => round($tie['registerAccum'] - $tie['ledgerAccum'], 2) == 0],
                    ['label' => 'Net book value', 'acct' => 'derived', 'register' => $fmt($tie['registerCost'] - $tie['registerAccum']), 'ledger' => $fmt($tie['ledgerCost'] - $tie['ledgerAccum']),
                        'diff' => $diff($tie['registerCost'] - $tie['registerAccum'], $tie['ledgerCost'] - $tie['ledgerAccum']),
                        'agrees' => round(($tie['registerCost'] - $tie['registerAccum']) - ($tie['ledgerCost'] - $tie['ledgerAccum']), 2) == 0],
                ],
                'tied' => $tied,
                'awaiting' => $awaiting === [] ? '' : $fmt(array_sum(array_column($awaiting, 'remaining'))) . ' on ' . count($awaiting)
                    . (count($awaiting) === 1 ? ' purchase is' : ' purchases are') . ' in the ledger but not yet capitalised — capitalise ' . (count($awaiting) === 1 ? 'it' : 'them') . ' to bring the register into agreement.',
                'note' => $tied && $ytd > $charge
                    ? 'The ' . $fmt($ytd) . ' charged to ' . PostingAccounts::of('depreciation') . ' so far this year also covers assets now fully written down or disposed of, so it is higher than the current monthly run rate.'
                    : '',
            ],
            'capitalise' => [
                'hint' => $awaiting === [] ? 'Nothing waiting' : count($awaiting) . ' waiting · ' . $fmt(array_sum(array_column($awaiting, 'remaining'))),
                'rows' => array_map(static fn ($l) => [
                    'line' => $l['line'], 'ref' => $l['ref'], 'date' => $l['dateLabel'], 'iso' => $l['date'], 'account' => $l['account'], 'accountName' => $l['accountName'],
                    'desc' => $l['desc'] !== '' ? $l['desc'] : $l['narration'], 'doc' => $l['doc'], 'amount' => $l['amount'], 'remaining' => $l['remaining'],
                ], $awaiting),
            ],
            'uncapitalised' => array_map(static fn ($a) => [
                'tag' => $a['tag'], 'name' => $a['name'], 'cls' => $a['cls'], 'location' => $a['location'], 'value' => $a['cost'],
            ], $additions->uncapitalised()),
            'additions' => array_map(static fn ($d) => [
                'tag' => $d['tag'], 'title' => $d['tag'] . ' · ' . $d['description'],
                'detail' => AssetAdditionRepository::BASES[$d['basis']] . ' · ' . self::dmy($d['recognised_on']) . ' · ' . $d['source']
                    . ($d['reference'] !== null ? ' · ' . $d['reference'] : ''),
                'amount' => $fmt((float) $d['amount']), 'credit' => $d['credit_code'],
                'canApprove' => $actor['canApprove'], 'canWithdraw' => $actor['canPrepare'] || $actor['canApprove'],
            ], $pendingAdditions),
            // What the capitalise and add forms offer.
            'forms' => [
                'classes' => array_map(static fn ($c) => [
                    'name' => $c['name'], 'prefix' => $c['tag_prefix'], 'life' => (int) $c['useful_life_years'], 'account' => $c['cost_code'], 'accountName' => $c['cost_name'],
                ], array_values($classes)),
                'locations' => $additions->locations(),
                'today' => $this->workingDate($period),
                'bases' => AssetAdditionRepository::BASES,
                'credits' => [
                    'donation' => PostingAccounts::of('donatedAssets') . ' · ' . $this->accountName(PostingAccounts::of('donatedAssets')),
                    'found' => PostingAccounts::of('foundAssets') . ' · ' . $this->accountName(PostingAccounts::of('foundAssets')),
                ],
                'round' => (string) ((new AssetRepository())->round()['reference'] ?? ''),
            ],
            'can' => ['prepare' => $actor['canPrepare'], 'approve' => $actor['canApprove']],
        ];
    }

    /** Today, or the last day of the working period when today falls outside it. */
    private function workingDate(?array $period): string
    {
        return $period === null || ($period['starts_on'] <= Clock::date() && Clock::date() <= $period['ends_on']) ? Clock::date() : $period['ends_on'];
    }

    private static function amount(mixed $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }

    /**
     * The asset's share of accumulated depreciation and depreciation expense,
     * read from the runs it was charged in, so the drawer answers without
     * opening two ledgers that are posted by programme, not by asset.
     *
     * Accumulated depreciation is a balance: what came onto the register already
     * written down, plus every monthly charge, less what a disposal released.
     * Expense closes each year, so it is summarised for the financial year the
     * books are in, with life to date alongside.
     */
    private function ledger(AssetRepository $repo, array $a, ?array $period, ?array $disposal): array
    {
        $fmt = static fn ($n) => Prototype::fmt($n);
        $accumCode = PostingAccounts::of('accumulated');
        $expenseCode = PostingAccounts::of('depreciation');
        $charges = $repo->charges($a);
        $broughtForward = round($a['accum'] - array_sum(array_column($charges, 'amount')), 2);

        $rows = [];
        $balance = 0.0;
        if ($broughtForward > 0) {
            $balance = $broughtForward;
            $rows[] = ['kind' => 'bf', 'when' => '', 'what' => 'Brought forward',
                'journal' => '', 'expense' => '', 'accum' => $fmt($broughtForward), 'balance' => $fmt($balance)];
        }
        foreach ($charges as $c) {
            $balance += $c['amount'];
            $rows[] = ['kind' => 'run', 'when' => self::dmy($c['on']), 'what' => 'Depreciation ' . $c['period'],
                'journal' => $c['journal'] ?? '', 'expense' => $fmt($c['amount']), 'accum' => $fmt($c['amount']), 'balance' => $fmt($balance)];
        }
        $released = $disposal !== null;
        if ($released) {
            $rows[] = ['kind' => 'disposal', 'when' => self::dmy($disposal['disposed_on']), 'what' => 'Released on disposal',
                'journal' => $disposal['journal_ref'], 'expense' => '', 'accum' => '(' . $fmt($balance) . ')', 'balance' => $fmt(0)];
            $balance = 0.0;
        }

        $yearId = $period === null ? null : (int) $period['fiscal_year_id'];
        $year = array_values(array_filter($charges, static fn ($c) => $c['fiscalYearId'] === $yearId));
        $yearCharge = array_sum(array_column($year, 'amount'));
        $lifeCharge = array_sum(array_column($charges, 'amount'));
        $yearName = $yearId === null ? 'This year' : $repo->fiscalYearCode($yearId);

        return [
            'accumulated' => [
                'account' => $accumCode . ' · ' . $this->accountName($accumCode),
                'value' => $fmt($balance),
                'note' => $released ? 'Released to nil when the disposal posted'
                    : ($charges === [] ? 'All brought forward — written down before monthly runs were recorded'
                        : count($charges) . ' monthly ' . (count($charges) === 1 ? 'charge' : 'charges') . ($broughtForward > 0 ? ' on ' . $fmt($broughtForward) . ' brought forward' : '')),
            ],
            'expense' => [
                'account' => $expenseCode . ' · ' . $this->accountName($expenseCode),
                'value' => $fmt($yearCharge),
                'note' => $charges === [] ? $yearName . ' · no monthly run has charged it'
                    : $yearName . ' · ' . count($year) . ' ' . (count($year) === 1 ? 'month' : 'months') . ' · ' . $fmt($lifeCharge) . ' life to date',
            ],
            'rows' => $rows,
            'hint' => 'This asset’s share of each posting. Both accounts are posted by programme, so the ledger shows it pooled with other assets.',
        ];
    }

    private function accountName(string $code): string
    {
        return (new Lookups())->accounts()[$code]['name'] ?? $code;
    }

    private static function dmy(string $date): string
    {
        return date('d M Y', strtotime($date));
    }

    private static function result(float $result): string
    {
        return $result >= 0
            ? 'gain of ' . Prototype::fmt($result) . ' to ' . PostingAccounts::of('disposalGain')
            : 'loss of ' . Prototype::fmt(-$result) . ' to ' . PostingAccounts::of('disposalLoss');
    }
}
