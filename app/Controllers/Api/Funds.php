<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\FundRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

class Funds extends BaseApiController
{
    public static function closing(array $f): float
    {
        return $f['opening'] + $f['income'] - $f['spend'] + $f['transfers'];
    }

    public static function available(array $f): float
    {
        return $f['opening'] + $f['income'] + ($f['transfers'] > 0 ? $f['transfers'] : 0);
    }

    public static function pct(array $f): int
    {
        $a = self::available($f);
        return $a > 0 ? (int) min(100, round($f['spend'] / $a * 100)) : 0;
    }

    public function index()
    {
        $all    = (new FundRepository())->all();
        $cls    = $this->request->getGet('class') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($f) use ($cls, $q) {
            if ($cls !== 'All' && $f['cls'] !== $cls) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($f['name'] . ' ' . $f['funder'] . ' ' . $f['grant'] . ' ' . $f['code']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(fn ($f) => array_merge($f, [
            'closing'    => self::closing($f),
            'closingFmt' => Prototype::fmt(self::closing($f)),
            'pct'        => self::pct($f),
            'expiringSoon' => $f['daysLeft'] <= 90,
        ]), $filtered);

        $byCls = fn ($c) => array_sum(array_map(fn ($f) => self::closing($f), array_filter($all, fn ($f) => $f['cls'] === $c)));
        $expiring = array_values(array_filter($all, fn ($f) => $f['daysLeft'] <= 90 && $f['cls'] === 'Restricted'));
        $restricted = array_values(array_filter($all, fn ($f) => $f['cls'] === 'Restricted'));
        $restrictedPct = (int) round(array_sum(array_map(fn ($f) => $f['spend'], $restricted)) / max(1, array_sum(array_map(fn ($f) => self::available($f), $restricted))) * 100);

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($c) => ['label' => $c, 'count' => $c === 'All' ? count($all) : count(array_filter($all, fn ($f) => $f['cls'] === $c))], ['All', 'Unrestricted', 'Restricted', 'Endowment']),
            'stats' => [
                ['label' => 'Total fund balance', 'value' => Prototype::fmt(array_sum(array_map(fn ($f) => self::closing($f), $all))), 'note' => count($all) . ' funds'],
                ['label' => 'Unrestricted', 'value' => Prototype::fmt($byCls('Unrestricted')), 'note' => 'free for core costs'],
                ['label' => 'Restricted', 'value' => Prototype::fmt($byCls('Restricted')), 'note' => $restrictedPct . '% utilised'],
                ['label' => 'Endowment', 'value' => Prototype::fmt($byCls('Endowment')), 'note' => 'permanently maintained'],
                ['label' => 'Closing within 90 days', 'value' => Prototype::fmt(array_sum(array_map(fn ($f) => self::available($f) - $f['spend'], $expiring))), 'note' => count($expiring) . ' funds at risk'],
            ],
        ]);
    }

    /**
     * Opens a fund. Body: {code, name, restriction, ledgerGroup, funder, purpose,
     * deedRef, startsOn, spendBy, conditions}. Finance Manager only — a fund is the
     * first coding every posting carries.
     */
    public function create()
    {
        $actor = $this->actor();
        if (!in_array('settings.manage', $actor['permissions'] ?? [], true)) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => $actor['role'] . ' cannot open a fund. Only the Finance Manager can — every posting is coded to one.',
            ]);
        }

        try {
            $fund = (new FundRepository())->create($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        // The register as Settings lists it, so the screen has no second call to make.
        return $this->json([
            'message' => $fund['code'] . ' ' . $fund['name'] . ' is open. Postings can be coded to it now.',
            'fund'    => $fund,
            'funds'   => (new SettingsRepository())->funds(),
        ]);
    }

    public function show($code)
    {
        foreach ((new FundRepository())->all() as $f) {
            if ($f['code'] === $code) {
                $f['closing'] = self::closing($f);
                $f['pct']     = self::pct($f);
                return $this->json($f);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
    }
}
