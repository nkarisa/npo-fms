<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * The interface-language catalogue: source strings and their translations, locked
 * terminology, the donor report cover wording, review notes per area, open
 * translation requests, and funders' project titles in each language.
 *
 * Sources: I18N_SOURCE, I18N, I18N_LOCKED, DR_COVER, I18N_AREAS, I18N_REQUESTS,
 * DR_FUNDERS.
 *
 * A translation takes its locale's status (published, in review, draft); locked
 * terms are reviewed wording and are published. Coverage percentages are not
 * stored; they are computed from the translations. Requests are raised by
 * reviewers without system accounts, so they are attributed to the system user
 * and the reviewer is named in the request's audit trail.
 */
class TranslationSeeder extends Seeder
{
    private const REQUEST_KINDS = ['Suggestion' => 'suggestion', 'Unlock request' => 'unlock_request', 'Report' => 'report'];

    private const REQUEST_STATUSES = ['Raised' => 'raised', 'With reviewer' => 'with_reviewer', 'Approved' => 'approved', 'Rejected' => 'rejected'];

    private SeedContext $ctx;

    private string $now;

    public function run(): void
    {
        $this->ctx = $ctx = SeedContext::get();
        $this->now = $ctx->now();

        $statuses = [];
        foreach ($ctx->data('LOCALES') as $l) {
            $statuses[$l['code']] = $l['status'] === 'Source' ? null : str_replace(' ', '_', strtolower($l['status']));
        }

        foreach ($ctx->data('I18N_LOCKED') as $t) {
            $string = $this->string($t['term'], 'Locked terminology', [
                'is_locked' => 1, 'lock_reason' => $t['reason'], 'unlock_role_id' => $ctx->require('roles', $t['unlock']),
            ]);
            foreach ($t['tr'] as $locale => $text) {
                $this->translation($string, $locale, $text, 'published');
            }
        }

        foreach ($ctx->data('I18N_SOURCE') as $source) {
            $string = $this->string($source, 'Language catalogue');
            foreach ($ctx->data('I18N') as $locale => $catalogue) {
                if (isset($catalogue[$source])) {
                    $this->translation($string, $locale, $catalogue[$source], $statuses[$locale]);
                }
            }
        }

        // The cover page of a donor report, in each delivery language. Its period line
        // is a formatted date, not wording, so it is not a string.
        $cover = $ctx->data('DR_COVER');
        foreach (['prefix', 'awardWord', 'labels', 'foot', 'org'] as $field) {
            foreach ((array) $cover['en-GB'][$field] as $i => $source) {
                $string = $this->string($source, 'Donor report cover');
                foreach ($cover as $locale => $wording) {
                    if ($locale !== 'en-GB') {
                        $this->translation($string, $locale, ((array) $wording[$field])[$i], $statuses[$locale]);
                    }
                }
            }
        }

        foreach ($ctx->data('I18N_AREAS') as $locale => $areas) {
            foreach ($areas as $a) {
                $ctx->insert('locale_area_reviews', [
                    'locale_id' => $ctx->require('locales', $locale), 'area' => $a['area'], 'note' => $a['note'],
                    'reviewed_at' => preg_match('/^Reviewed (.+)$/', $a['note'], $m) === 1 ? $ctx->date($m[1]) . ' 00:00:00' : null,
                    'created_at' => $this->now,
                ]);
            }
        }

        foreach ($ctx->data('I18N_REQUESTS') as $r) {
            $raisedAt = $ctx->datetime($r['when']);
            $id = $ctx->insert('translation_requests', [
                'reference' => $r['id'], 'translation_string_id' => $this->string($r['str'], $r['area']),
                'locale_id' => $ctx->require('locales', $r['locale']), 'kind' => self::REQUEST_KINDS[$r['kind']], 'proposed_text' => $r['what'],
                'status' => self::REQUEST_STATUSES[$r['status']], 'requested_by' => $ctx->userOrSystem($r['who']), 'created_at' => $raisedAt,
            ]);
            $ctx->writeTrail('translation_request', $id, $r['id'], [['when' => $r['when'], 'what' => 'Raised by ' . $r['who']]], null);
        }

        foreach ($ctx->data('DR_FUNDERS') as $f) {
            foreach ($f['project'] as $locale => $title) {
                $ctx->insert('content_translations', [
                    'object_type' => 'funder', 'object_id' => $ctx->require('funders', $f['funder']), 'field' => 'project_title',
                    'locale_id' => $ctx->require('locales', $locale), 'text' => $title, 'created_at' => $this->now,
                ]);
            }
        }
    }

    /** The id of a source string, created on first use. */
    private function string(string $source, string $area, array $extra = []): int
    {
        return $this->ctx->lookup('strings', $source) ?? (function () use ($source, $area, $extra) {
            $id = $this->ctx->insert('translation_strings', ['source_text' => $source, 'area' => $area, 'created_at' => $this->now] + $extra);
            $this->ctx->remember('strings', $source, $id);

            return $id;
        })();
    }

    /** Adds a translation unless the string already has one in that locale (locked wording wins). */
    private function translation(int $string, string $locale, string $text, ?string $status): void
    {
        if ($status === null || $this->ctx->lookup('translations', "{$string}:{$locale}") !== null) {
            return;
        }

        $this->ctx->remember('translations', "{$string}:{$locale}", $this->ctx->insert('translations', [
            'translation_string_id' => $string, 'locale_id' => $this->ctx->require('locales', $locale), 'text' => $text,
            'status' => $status, 'created_at' => $this->now,
        ]));
    }
}
