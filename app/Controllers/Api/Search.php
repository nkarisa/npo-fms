<?php

namespace App\Controllers\Api;

use App\Libraries\Ledger;
use App\Libraries\Navigation;
use App\Libraries\Prototype;

/**
 * "Search everything" in the top bar.
 *
 * Runs server-side. The handoff README is explicit that list search must not be
 * the prototype's filter-in-memory — the ledger and journal tables reach hundreds
 * of thousands of rows — so the palette sends the query here rather than loading
 * datasets into the browser.
 */
class Search extends BaseApiController
{
    /** Results per group; the palette is for jumping, not browsing. */
    private const PER_GROUP = 6;

    /** Below this the query matches too much to be useful. */
    private const MIN_LENGTH = 2;

    public function index()
    {
        $q = strtolower(trim((string) ($this->request->getGet('q') ?? '')));

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return $this->json(['query' => $q, 'groups' => [], 'count' => 0]);
        }

        $hit = static fn (array $fields) => str_contains(strtolower(implode(' ', array_map('strval', $fields))), $q);

        $groups = array_values(array_filter([
            $this->group('Journals', Prototype::load('JOURNALS'), $hit,
                static fn ($j) => [$j['ref'], $j['narration'] ?? '', $j['memo'] ?? '', $j['preparer'], $j['type'], $j['status']],
                static fn ($j) => [
                    'ref'   => $j['ref'],
                    'title' => ($j['narration'] ?? '') ?: ($j['memo'] ?? '') ?: $j['type'],
                    'sub'   => $j['status'] . ' · ' . $j['date'] . ' · prepared by ' . $j['preparer'],
                    'value' => Prototype::fmt(array_sum(array_map(static fn ($l) => $l['dr'] ?? 0, $j['lines']))),
                    'href'  => '/journals/' . rawurlencode($j['ref']),
                ]),

            $this->group('Chart of accounts', Ledger::leaves(), $hit,
                static fn ($a) => [$a['code'], $a['name'], $a['fund']],
                static fn ($a) => [
                    'ref'   => $a['code'],
                    'title' => $a['name'],
                    'sub'   => $a['type'] . ' · ' . $a['fund'] . ' · ' . $a['restriction'],
                    'value' => Prototype::fmt($a['balance']),
                    'href'  => '/gl?account=' . rawurlencode($a['code']),
                ]),

            $this->group('Payables', Prototype::load('BILLS'), $hit,
                static fn ($b) => [$b['no'], $b['supplier'], $b['pin'], $b['category'], $b['status']],
                static fn ($b) => [
                    'ref'   => $b['no'],
                    'title' => $b['supplier'],
                    'sub'   => $b['status'] . ' · ' . $b['category'] . ' · due ' . $b['dueDate'],
                    'value' => Prototype::fmt(Payables::totals($b)['net']),
                    'href'  => Navigation::url('payables'),
                ]),

            $this->group('Receivables', Prototype::load('AR'), $hit,
                static fn ($i) => [$i['no'], $i['donor'], $i['grantRef'], $i['status'], $i['type']],
                static fn ($i) => [
                    'ref'   => $i['no'],
                    'title' => $i['donor'],
                    'sub'   => $i['status'] . ' · ' . $i['grantRef'],
                    'value' => Prototype::fmt($i['amount']),
                    'href'  => Navigation::url('receivables'),
                ]),

            $this->group('Procurement', Prototype::load('PQ'), $hit,
                static fn ($p) => [$p['no'], $p['title'], $p['requester'], $p['status'], $p['supplier'] ?? ''],
                static fn ($p) => [
                    'ref'   => $p['no'],
                    'title' => $p['title'],
                    'sub'   => $p['status'] . ' · ' . $p['requester'],
                    'value' => Prototype::fmt($p['amount']),
                    'href'  => Navigation::url('procure'),
                ]),

            $this->group('Asset register', Prototype::load('ASSETS'), $hit,
                static fn ($a) => [$a['tag'], $a['name'], $a['cls'], $a['funder'], $a['custodian']],
                static fn ($a) => [
                    'ref'   => $a['tag'],
                    'title' => $a['name'],
                    'sub'   => $a['cls'] . ' · ' . $a['fund'] . ' · acquired ' . $a['acquired'],
                    'value' => Prototype::fmt($a['cost']),
                    'href'  => Navigation::url('assets'),
                ]),

            // Staff are findable by name and role, but pay is never shown here.
            // Payroll is personal data under Kenya's Data Protection Act 2019 and
            // a search palette open on a shared screen is the wrong place for a
            // salary; the prototype shows basic pay, the README restricts it.
            $this->group('Payroll', Prototype::load('PSTAFF'), $hit,
                static fn ($s) => [$s['no'], $s['name'], $s['role'], $s['grade']],
                static fn ($s) => [
                    'ref'   => $s['no'],
                    'title' => $s['name'],
                    'sub'   => $s['role'] . ' · ' . $s['grade'],
                    'value' => '',
                    'href'  => Navigation::url('payroll'),
                ]),

            $this->group('Awards', Prototype::load('GRANTS'), $hit,
                static fn ($g) => [$g['ref'], $g['title'], $g['funder'], $g['program']],
                static fn ($g) => [
                    'ref'   => $g['ref'],
                    'title' => $g['title'],
                    'sub'   => $g['funder'] . ' · ' . $g['program'] . ' · ' . $g['status'],
                    'value' => Prototype::fmt($g['value']),
                    'href'  => Navigation::url('grants'),
                ]),

            $this->group('Funds', Prototype::load('FUNDS'), $hit,
                static fn ($f) => [$f['code'], $f['name'], $f['funder'], $f['grant']],
                static fn ($f) => [
                    'ref'   => $f['code'],
                    'title' => $f['name'],
                    'sub'   => $f['cls'] . ' · ' . $f['funder'],
                    'value' => Prototype::fmt(Ledger::fundClose($f)),
                    'href'  => Navigation::url('funds'),
                ]),
        ]));

        return $this->json([
            'query'  => $q,
            'groups' => $groups,
            'count'  => array_sum(array_map(static fn ($g) => count($g['items']), $groups)),
        ]);
    }

    /** @return array{label:string,items:list<array>,more:int}|null */
    private function group(string $label, array $rows, callable $hit, callable $fields, callable $present): ?array
    {
        $matches = array_values(array_filter($rows, static fn ($r) => $hit($fields($r))));
        if ($matches === []) {
            return null;
        }

        return [
            'label' => $label,
            'items' => array_map($present, array_slice($matches, 0, self::PER_GROUP)),
            'more'  => max(0, count($matches) - self::PER_GROUP),
        ];
    }
}
