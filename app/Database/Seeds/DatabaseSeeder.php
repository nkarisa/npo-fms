<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Libraries\EntityCalendar;
use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * Loads the baseline reference data and then the prototype data (app/Data/OLD/*.json)
 * into an empty database — the demonstration organisation, ELOG.
 *
 * A real instance is stood up with BaselineSeeder and `spark install` instead; see
 * BaselineSeeder for what the two paths share.
 *
 *     php spark migrate
 *     php spark db:seed DatabaseSeeder
 *
 * Everything runs in one transaction: a failure leaves the database empty rather
 * than half-seeded. Posted journals and the audit log cannot be deleted (by
 * design), so to seed again, rebuild the schema first with
 * `php spark migrate:refresh` and then run this seeder.
 */
class DatabaseSeeder extends Seeder
{
    /** In dependency order. */
    private const SEEDERS = [
        // The reference data any instance needs, demonstration or not. Seeding it here
        // rather than inside the prototype seeders keeps one definition of it.
        BaselineSeeder::class,
        OrganisationSeeder::class,
        ReferenceDataSeeder::class,
        ChartAndDimensionsSeeder::class,
        // Before the ledger: it posts each claim's issue and receipts.
        ReceivablesSeeder::class,
        LedgerSeeder::class,
        RecurringTemplateSeeder::class,
        ProcurementSeeder::class,
        PayablesSeeder::class,
        BudgetSeeder::class,
        AssetSeeder::class,
        PayrollSeeder::class,
        AdvancesSeeder::class,
        BankAndCashflowSeeder::class,
        DonorReportSeeder::class,
        TranslationSeeder::class,
        NotificationAndSettingsAuditSeeder::class,
        RecordedWriteOffSeeder::class,
        PeriodCloseSeeder::class,
    ];

    public function run(): void
    {
        if ($this->db->table('entities')->countAllResults() > 0) {
            throw new RuntimeException('The database already holds data. Run `php spark migrate:refresh` first, then seed again.');
        }

        SeedContext::start($this->db);

        $this->db->transException(true)->transStart();

        foreach (self::SEEDERS as $seeder) {
            $this->call($seeder);
        }
        // The branches keep their books on the head office's calendar, as it now stands.
        EntityCalendar::fill($this->db);

        $this->db->transComplete();
    }
}
