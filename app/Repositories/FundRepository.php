<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The fund register with each fund's movement from the posted ledger: fund
 * accounts (opening balances and transfers), income and expenditure. The closing
 * balance is the fund's net assets.
 */
final class FundRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** What a fund may be, and the column of the ledger each rolls up to. */
    public const RESTRICTIONS = ['unrestricted', 'restricted', 'designated', 'endowment'];

    public const LEDGER_GROUPS = ['general', 'grant', 'capital', 'endowment'];

    /**
     * Opens a fund.
     *
     * A fund is the first coding every posting carries, so an instance cannot post
     * anything until it has at least one. What it may be charged with follows from
     * its restriction and the ledger column it rolls up to, and the two have to
     * agree: an endowment is reported as one and rolls up as one, and money held for
     * a donor belongs in the grant or capital column where awards are reported.
     *
     * @param array{code: string, name: string, restriction: string, ledgerGroup: string,
     *              funder?: string, purpose?: string, deedRef?: string, startsOn?: string,
     *              spendBy?: string, conditions?: string} $input
     */
    public function create(array $input, int $actorId): array
    {
        $code = mb_strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $restriction = mb_strtolower(trim((string) ($input['restriction'] ?? '')));
        $group = mb_strtolower(trim((string) ($input['ledgerGroup'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $code) !== 1) {
            throw new RuleViolation('A fund code is 2 to 20 letters, digits and hyphens, e.g. FND-100.');
        }
        if ($name === '') {
            throw new RuleViolation('Give the fund a name. It is what the statements and every donor report call it.');
        }
        if (!in_array($restriction, self::RESTRICTIONS, true)) {
            throw new RuleViolation('A fund is ' . self::list(self::RESTRICTIONS) . ', not "' . $restriction . '".');
        }
        if (!in_array($group, self::LEDGER_GROUPS, true)) {
            throw new RuleViolation('A fund rolls up to the ' . self::list(self::LEDGER_GROUPS) . ' column, not "' . $group . '".');
        }
        if (($restriction === 'endowment') !== ($group === 'endowment')) {
            throw new RuleViolation('An endowment is reported as one and rolls up to the endowment column. Set both, or neither.');
        }
        if ($restriction === 'unrestricted' && in_array($group, ['grant', 'capital'], true)) {
            throw new RuleViolation('The grant and capital columns report money held for a donor, so a fund in them cannot be unrestricted.');
        }
        foreach (['code' => $code, 'name' => $name] as $column => $value) {
            if ($this->value('SELECT id FROM {funds} WHERE LOWER(' . $column . ') = LOWER(?)', [$value]) !== null) {
                throw new RuleViolation('A fund with that ' . $column . ' already exists. Every fund is named once.');
            }
        }

        $funderId = null;
        if (trim((string) ($input['funder'] ?? '')) !== '') {
            $funderId = $this->lookups->funderId(trim((string) $input['funder']))
                ?? throw new RuleViolation(trim((string) $input['funder']) . ' is not on the funder register.');
        }

        $this->transaction(function () use ($code, $name, $restriction, $group, $funderId, $input, $actorId) {
            $id = $this->insert('funds', [
                'code' => $code, 'name' => $name, 'restriction' => $restriction, 'ledger_group' => $group,
                'funder_id' => $funderId, 'purpose' => trim((string) ($input['purpose'] ?? '')) ?: null,
                'deed_ref' => trim((string) ($input['deedRef'] ?? '')) ?: null,
                'starts_on' => self::dateOrNull($input['startsOn'] ?? null),
                'spend_by' => self::dateOrNull($input['spendBy'] ?? null),
                'conditions' => trim((string) ($input['conditions'] ?? '')) ?: null,
                'status' => 'active', 'created_at' => Clock::timestamp(),
            ]);
            $this->audit('settings:segments', $id, $code, $code . ' ' . $name . ' opened as a ' . $restriction
                . ' fund in the ' . $group . ' column', $actorId, 'settings.changed', $this->lookups->entityId());
        });

        return $this->find($code) ?? ['code' => $code, 'name' => $name];
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : date('Y-m-d', strtotime($text) ?: time());
    }

    private static function list(array $items): string
    {
        return implode(', ', array_slice($items, 0, -1)) . ' or ' . end($items);
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $movements = array_column($this->rows('SELECT * FROM {v_fund_balances}'), null, 'fund_id');
            $programmes = [];
            foreach ($this->rows('SELECT fp.fund_id, p.name FROM {fund_programmes} fp JOIN {programmes} p ON p.id = fp.programme_id ORDER BY p.code') as $r) {
                $programmes[(int) $r['fund_id']][] = $r['name'];
            }

            return array_map(function ($f) use ($movements, $programmes) {
                $id    = (int) $f['id'];
                $m     = $movements[$id] ?? ['income' => 0, 'expenditure' => 0, 'transfers_and_opening' => 0];
                $grant = $this->lookups->grantOfFund($id);

                return [
                    'code'       => $f['code'],
                    'name'       => $f['name'],
                    'cls'        => ucfirst($f['restriction']),
                    'funder'     => $f['funder_name'] ?? 'Own income',
                    'grant'      => $f['deed_ref'] ?? ($grant === null ? '—' : $this->lookups->grants()[$grant]['short_name']),
                    'purpose'    => $f['purpose'] ?? '',
                    'period'     => $f['starts_on'] && $f['spend_by'] ? self::dmy($f['starts_on']) . ' – ' . self::dmy($f['spend_by']) : 'Perpetual',
                    'spendBy'    => self::dmy($f['spend_by']),
                    'daysLeft'   => Clock::daysUntil($f['spend_by']) ?? 9999,
                    'conditions' => $f['conditions'] ?? '',
                    'programs'   => $programmes[$id] ?? [],
                    'ledgerFund' => self::FUND_GROUPS[$f['ledger_group']],
                    'opening'    => self::num($m['transfers_and_opening']),
                    'income'     => self::num($m['income']),
                    'spend'      => self::num($m['expenditure']),
                    'transfers'  => 0,
                ];
            }, $this->rows('SELECT f.*, fu.name AS funder_name FROM {funds} f LEFT JOIN {funders} fu ON fu.id = f.funder_id ORDER BY f.code'));
        });
    }

    public function find(string $code): ?array
    {
        foreach ($this->all() as $f) {
            if ($f['code'] === $code) {
                return $f;
            }
        }

        return null;
    }
}
