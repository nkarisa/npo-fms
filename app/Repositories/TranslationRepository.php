<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The interface-language catalogue: locales, source strings, translations,
 * locked terminology, reviewers' area notes and the queue of raised wording.
 *
 * A locale's coverage is the share of the language catalogue it has a
 * translation for. An area's coverage is the reviewer's assessment, as recorded.
 */
final class TranslationRepository extends Repository
{
    public const CATALOGUE_AREA = 'Language catalogue';

    private const REQUEST_KINDS = ['suggestion' => 'Suggestion', 'unlock_request' => 'Unlock request', 'report' => 'Report'];

    private const REQUEST_STATUSES = ['raised' => 'Raised', 'with_reviewer' => 'With reviewer', 'approved' => 'Approved', 'rejected' => 'Declined'];

    public function locales(): array
    {
        return $this->cached('locales', function () {
            $catalogue = count($this->catalogue());
            $translated = array_column($this->rows(
                'SELECT t.locale_id, COUNT(*) AS n FROM {translations} t JOIN {translation_strings} s ON s.id = t.translation_string_id
                 WHERE s.area = ? GROUP BY t.locale_id',
                [self::CATALOGUE_AREA]
            ), 'n', 'locale_id');

            return array_map(static function ($l) use ($catalogue, $translated) {
                $locale = [
                    'code'     => $l['code'],
                    'label'    => $l['label'],
                    'native'   => $l['native_name'],
                    'dir'      => $l['direction'],
                    'coverage' => $l['is_source'] ? 100 : (int) round(($translated[$l['id']] ?? 0) / max(1, $catalogue) * 100),
                    'reviewer' => $l['reviewer'] ?? '',
                    'status'   => self::label($l['status']),
                ];

                return $l['is_source'] ? array_slice($locale, 0, 4) + ['source' => true] + array_slice($locale, 4) : $locale;
            }, $this->rows('SELECT * FROM {locales} ORDER BY is_source DESC, id'));
        });
    }

    /** @return list<string> the strings the interface treats as translatable */
    public function catalogue(): array
    {
        return $this->cached('catalogue', function () {
            $strings = array_column($this->rows('SELECT source_text FROM {translation_strings} WHERE area = ?', [self::CATALOGUE_AREA]), 'source_text');
            // Byte order, not the database collation, so the list reads the same on every driver.
            sort($strings, SORT_STRING);

            return $strings;
        });
    }

    /** @return array<string, string> every translated string in a language, by source text */
    public function strings(string $locale): array
    {
        return $this->cached("strings:{$locale}", fn () => array_column($this->rows(
            'SELECT s.source_text, t.text FROM {translations} t JOIN {translation_strings} s ON s.id = t.translation_string_id
             JOIN {locales} l ON l.id = t.locale_id WHERE l.code = ?',
            [$locale]
        ), 'text', 'source_text'));
    }

    public function translation(string $locale, string $source): ?string
    {
        return $this->strings($locale)[$source] ?? null;
    }

    /** @return array<string, list<array{area: string, coverage: int, note: string}>> by locale */
    public function areas(): array
    {
        return $this->cached('areas', function () {
            $out = [];
            foreach ($this->rows('SELECT r.*, l.code FROM {locale_area_reviews} r JOIN {locales} l ON l.id = r.locale_id ORDER BY r.id') as $r) {
                $out[$r['code']][] = ['area' => $r['area'], 'coverage' => (int) round((float) $r['coverage_pct']), 'note' => $r['note'] ?? ''];
            }

            return $out;
        });
    }

    /** Locked terms with their approved wording in each language. */
    public function locked(): array
    {
        return $this->cached('locked', function () {
            $tr = [];
            foreach ($this->rows(
                'SELECT t.translation_string_id, t.text, l.code FROM {translations} t JOIN {locales} l ON l.id = t.locale_id
                 JOIN {translation_strings} s ON s.id = t.translation_string_id WHERE s.is_locked = 1'
            ) as $t) {
                $tr[(int) $t['translation_string_id']][$t['code']] = $t['text'];
            }

            return array_map(static fn ($s) => [
                'term' => $s['source_text'], 'reason' => $s['lock_reason'] ?? '', 'unlock' => $s['unlock_role'] ?? '', 'tr' => $tr[(int) $s['id']] ?? [],
            ], $this->rows(
                'SELECT s.*, r.name AS unlock_role FROM {translation_strings} s LEFT JOIN {roles} r ON r.id = s.unlock_role_id WHERE s.is_locked = 1 ORDER BY s.id'
            ));
        });
    }

    /** Raised wording, newest first. The raiser is named from the history when they have no account. */
    public function requests(): array
    {
        return $this->cached('requests', function () {
            $lookups = new Lookups();
            $trails  = $this->trails('translation_request');

            return array_map(static function ($q) use ($lookups, $trails) {
                $raisedBy = $lookups->shortName((int) $q['requested_by']);
                foreach ($trails[(int) $q['id']] ?? [] as $entry) {
                    if (preg_match('/^Raised by (.+)$/', $entry['what'], $m) === 1) {
                        $raisedBy = $m[1];
                    }
                }

                $request = [
                    'id' => $q['reference'], 'str' => $q['source_text'], 'area' => $q['area'], 'locale' => $q['locale'],
                    'kind' => self::REQUEST_KINDS[$q['kind']], 'what' => $q['proposed_text'] ?? '', 'who' => $raisedBy,
                    'when' => date('d M H:i', strtotime($q['created_at'])), 'status' => self::REQUEST_STATUSES[$q['status']],
                ];

                return $q['reviewed_by'] === null ? $request : $request + [
                    'decidedBy' => $lookups->shortName((int) $q['reviewed_by']), 'decidedAt' => date('d M H:i', strtotime($q['reviewed_at'])),
                ];
            }, $this->rows(
                'SELECT q.*, s.source_text, s.area, l.code AS locale FROM {translation_requests} q
                 JOIN {translation_strings} s ON s.id = q.translation_string_id JOIN {locales} l ON l.id = q.locale_id
                 ORDER BY q.created_at DESC, q.id DESC'
            ));
        });
    }

    public function findRequest(string $ref): ?array
    {
        foreach ($this->requests() as $q) {
            if ($q['id'] === $ref) {
                return $q;
            }
        }

        return null;
    }

    /** Raises wording against a string. Someone without an account is named in the history. */
    public function raise(string $source, string $locale, string $kind, string $text, string $who, int $actorId): array
    {
        $ref = $this->transaction(function () use ($source, $locale, $kind, $text, $who, $actorId) {
            $max = 0;
            foreach ($this->rows('SELECT reference FROM {translation_requests}') as $r) {
                $max = max($max, (int) substr($r['reference'], 3));
            }
            $ref = 'RQ-' . ($max + 1);

            $raiser = (new Lookups())->userId($who) ?? $actorId;
            $id = $this->insert('translation_requests', [
                'reference' => $ref,
                'translation_string_id' => $this->value('SELECT id FROM {translation_strings} WHERE source_text = ?', [$source]),
                'locale_id' => $this->value('SELECT id FROM {locales} WHERE code = ?', [$locale]),
                'kind' => array_search($kind, self::REQUEST_KINDS, true), 'proposed_text' => $text, 'status' => 'raised',
                'requested_by' => $raiser, 'created_at' => Clock::timestamp(),
            ]);
            $this->audit('translation_request', $id, $ref, 'Raised by ' . ($who !== '' ? $who : (new Lookups())->shortName($actorId)), $actorId);

            return $ref;
        });

        return $this->findRequest($ref);
    }

    /** Approves or declines an open request. Approving a suggestion publishes the wording. */
    public function decide(string $ref, bool $approved, int $actorId): array
    {
        $q = $this->row('SELECT * FROM {translation_requests} WHERE reference = ?', [$ref]);
        if ($q === null) {
            throw new RuleViolation($ref . ' was not found in the translation queue.');
        }
        if (!in_array($q['status'], ['raised', 'with_reviewer'], true)) {
            throw new RuleViolation($ref . ' has already been decided. Current status: ' . self::REQUEST_STATUSES[$q['status']] . '.');
        }
        if ((int) $q['requested_by'] === $actorId) {
            throw new RuleViolation((new Lookups())->shortName($actorId) . ' raised ' . $ref . ' and cannot also decide it. It needs the reviewer.');
        }

        $this->transaction(function () use ($q, $approved, $actorId) {
            $now = Clock::timestamp();
            $this->db->table('translation_requests')->where('id', $q['id'])->update([
                'status' => $approved ? 'approved' : 'rejected', 'reviewed_by' => $actorId, 'reviewed_at' => $now, 'updated_at' => $now,
            ]);

            if ($approved && $q['kind'] === 'suggestion' && trim((string) $q['proposed_text']) !== '') {
                $existing = $this->value('SELECT id FROM {translations} WHERE translation_string_id = ? AND locale_id = ?', [$q['translation_string_id'], $q['locale_id']]);
                $row = ['text' => $q['proposed_text'], 'status' => 'published', 'reviewed_by' => $actorId, 'reviewed_at' => $now, 'updated_at' => $now];
                $existing === null
                    ? $this->insert('translations', $row + ['translation_string_id' => $q['translation_string_id'], 'locale_id' => $q['locale_id'], 'created_at' => $now])
                    : $this->db->table('translations')->where('id', $existing)->update($row);
            }

            $this->audit('translation_request', (int) $q['id'], $q['reference'], ($approved ? 'Approved' : 'Declined') . ' by ' . (new Lookups())->shortName($actorId), $actorId);
        });

        return $this->findRequest($ref);
    }
}
