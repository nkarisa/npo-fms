<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;
use App\Libraries\Clock;
use App\Libraries\I18n;

/**
 * How a string with no approved translation is shown becomes an organisation
 * setting, held on the head office like the one that keeps numbers, dates and
 * currency in the reporting locale.
 *
 * It used to be a cookie each browser kept for itself, which meant two people
 * reading the same French screen could not agree on whether a label had been
 * approved — and that anybody could change it for themselves, with nothing in the
 * audit log to say so. Settings → Language and translation now sets it once for
 * everyone, through the settings permission and the audit log like any other
 * change (App\Libraries\I18n).
 *
 * An instance already running takes the recommended setting: the English source,
 * marked as untranslated — what the cookie defaulted to, so nobody's screen reads
 * differently the morning after. A fresh instance gets the row from the installer,
 * and the demonstration data from OrganisationSeeder.
 */
class HoldTranslationFallback extends SchemaMigration
{
    public function up(): void
    {
        $entity = $this->headOfficeId();
        if ($entity === null || $this->held($entity)) {
            return;
        }

        $this->db->table('settings')->insert([
            'entity_id'  => $entity,
            'key'        => I18n::FALLBACK_KEY,
            'kind'       => I18n::FALLBACK_KIND,
            'value'      => I18n::DEFAULT_FALLBACK,
            'label'      => I18n::FALLBACK_LABEL,
            'note'       => I18n::FALLBACK_NOTE,
            'created_at' => Clock::timestamp(),
        ]);
    }

    public function down(): void
    {
        $this->db->table('settings')->where('key', I18n::FALLBACK_KEY)->delete();
    }

    /** The entity the organisation's settings are held on, or null before there is one. */
    private function headOfficeId(): ?int
    {
        $row = $this->db->table('entities')->select('id')->where('parent_id', null)->orderBy('id')->limit(1)->get()->getRow();

        return $row === null ? null : (int) $row->id;
    }

    private function held(int $entity): bool
    {
        return $this->db->table('settings')->where('entity_id', $entity)->where('key', I18n::FALLBACK_KEY)->countAllResults() > 0;
    }
}
