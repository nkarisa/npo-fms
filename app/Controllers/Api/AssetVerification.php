<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\AssetRepository;
use App\Repositories\RuleViolation;

/**
 * Physical verification of the asset register.
 *
 * A count is evidence, so a result is never just a tick: an asset that is not
 * produced or shows an impairment indicator carries a book value that has to be
 * written off or impaired before the period closes. The exceptions this screen
 * produces are what the auditor asks to see.
 */
class AssetVerification extends BaseApiController
{
    public const RESULTS = ['Sighted', 'Not found', 'Condition issue'];

    private const PENDING = 'Not yet checked';

    private static function resultOf(array $a, array $results): string
    {
        return $results[$a['tag']]['result'] ?? self::PENDING;
    }

    public function index()
    {
        $repo    = new AssetRepository();
        $assets  = $repo->countSheet();
        $results = $repo->results();
        $round   = $repo->round();

        $location = $this->request->getGet('location') ?: 'All locations';
        $filter   = $this->request->getGet('filter') ?: 'All';
        $q        = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($assets, function ($a) use ($results, $location, $filter, $q) {
            if ($location !== 'All locations' && $a['location'] !== $location) {
                return false;
            }
            if ($filter !== 'All' && self::resultOf($a, $results) !== $filter) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($a['tag'] . ' ' . $a['desc'] . ' ' . $a['custodian'] . ' ' . $a['cls']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(static fn ($a) => [
            'tag'       => $a['tag'],
            'desc'      => $a['desc'],
            'cls'       => $a['cls'],
            'location'  => $a['location'],
            'custodian' => $a['custodian'],
            'nbv'       => Prototype::fmt($a['nbv']),
            'result'    => self::resultOf($a, $results),
            'note'      => $results[$a['tag']]['note'] ?? '',
            'pending'   => self::resultOf($a, $results) === self::PENDING,
        ], $filtered);

        $countOf = static fn ($r) => count(array_filter($assets, static fn ($a) => self::resultOf($a, $results) === $r));
        $nbvOf   = static fn ($r) => array_sum(array_map(
            static fn ($a) => self::resultOf($a, $results) === $r ? $a['nbv'] : 0,
            $assets
        ));

        $checked   = count($assets) - $countOf(self::PENDING);
        $missing   = $countOf('Not found');
        $condition = $countOf('Condition issue');

        $locations = array_values(array_unique(array_map(static fn ($a) => $a['location'], $assets)));
        sort($locations);

        return $this->json([
            'rows'            => $rows,
            'total'           => count($assets),
            'round'           => $round['reference'] ?? '—',
            'location'        => $location,
            'locationOptions' => array_merge(['All locations'], $locations),
            'resultOptions'   => self::RESULTS,
            'tabs' => array_map(static fn ($f) => [
                'label' => $f,
                'count' => $f === 'All' ? count($assets) : $countOf($f),
            ], array_merge(['All'], self::RESULTS, [self::PENDING])),
            'progress' => [
                'checked' => $checked,
                'total'   => count($assets),
                'pct'     => count($assets) > 0 ? (int) round($checked / count($assets) * 100) : 0,
            ],
            'stats' => [
                ['label' => 'Round', 'value' => $round['reference'] ?? '—', 'note' => $round === null ? 'no count open' : strtolower($round['name']) . ', opened ' . date('d M', strtotime($round['opened_on']))],
                ['label' => 'Progress', 'value' => $checked . ' of ' . count($assets), 'note' => (count($assets) > 0 ? (int) round($checked / count($assets) * 100) : 0) . '% of the register checked'],
                ['label' => 'Sighted', 'value' => Prototype::fmt($nbvOf('Sighted')), 'note' => $countOf('Sighted') . ' assets agreed to the register'],
                ['label' => 'Not found', 'value' => Prototype::fmt($nbvOf('Not found')), 'note' => $missing . ' to write off if unresolved'],
                ['label' => 'Condition issues', 'value' => Prototype::fmt($nbvOf('Condition issue')), 'note' => $condition . ' impairment indicators'],
            ],
            'warning' => ($missing + $condition) > 0
                ? $missing . ' asset(s) not produced and ' . $condition . ' with impairment indicators, carrying '
                    . Prototype::fmt($nbvOf('Not found') + $nbvOf('Condition issue')) . ' in the register. '
                    . 'Both need a disposal or impairment journal before the period can close.'
                : '',
            'hint' => 'Count sheets are signed by custodian and verifier; variances clear through the asset register.',
        ]);
    }

    /** The exceptions report — the part of a count that has an accounting consequence. */
    public function exceptions()
    {
        $repo    = new AssetRepository();
        $assets  = $repo->countSheet();
        $results = $repo->results();

        $rows = [];
        foreach ($assets as $a) {
            $result = self::resultOf($a, $results);
            if ($result === 'Sighted' || $result === self::PENDING) {
                continue;
            }

            $rows[] = [
                'tag'       => $a['tag'],
                'desc'      => $a['desc'],
                'location'  => $a['location'],
                'custodian' => $a['custodian'],
                'nbv'       => Prototype::fmt($a['nbv']),
                'result'    => $result,
                'note'      => $results[$a['tag']]['note'] ?? '',
                'action'    => $result === 'Not found'
                    ? 'Write off to 5340 on Finance Manager approval, or recover from the custodian'
                    : 'Impair to recoverable amount and post the charge against the funding grant',
            ];
        }

        return $this->json([
            'rows'  => $rows,
            'round' => $repo->round()['reference'] ?? '—',
            'total' => count($rows),
            'value' => Prototype::fmt(array_sum(array_map(
                static fn ($a) => in_array(self::resultOf($a, $results), ['Not found', 'Condition issue'], true) ? $a['nbv'] : 0,
                $assets
            ))),
            'hint' => $rows === []
                ? 'No exceptions. Every asset counted agreed to the register.'
                : 'Unresolved exceptions carry into the audit trail and block the period close.',
        ]);
    }

    /**
     * Records the outcome of counting one asset.
     *
     * Asset tags carry slashes (ELOG/IT/0041), so the router hands them over as
     * separate segments; rejoin them rather than making callers encode the tag.
     */
    public function record(string ...$segments)
    {
        $tag = implode('/', $segments);

        $body   = $this->request->getJSON(true) ?? [];
        $result = (string) ($body['result'] ?? '');

        if (!in_array($result, self::RESULTS, true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => 'A count result must be one of: ' . implode(', ', self::RESULTS) . '.',
            ]);
        }

        $note = trim((string) ($body['note'] ?? ''));
        // An exception without a reason is not evidence — the auditor will ask why.
        if ($result !== 'Sighted' && $note === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => 'Recording "' . $result . '" needs a note saying what was found.',
            ]);
        }

        $repo = new AssetRepository();
        if (!in_array($tag, array_column($repo->countSheet(), 'tag'), true)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $tag . ' is not on the verification list for ' . ($repo->round()['reference'] ?? 'the open count') . '.']);
        }

        try {
            $recorded = $repo->record($tag, $result, $note !== '' ? $note : 'Sighted and tag verified', $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['tag' => $tag, 'result' => $recorded]);
    }
}
