<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * Loads the prototype data (app/Data/*.json) into an empty database.
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
        OrganisationSeeder::class,
        ReferenceDataSeeder::class,
        ChartAndDimensionsSeeder::class,
        LedgerSeeder::class,
        ProcurementSeeder::class,
        PayablesSeeder::class,
        ReceivablesSeeder::class,
        BudgetSeeder::class,
        AssetSeeder::class,
        PayrollSeeder::class,
        AdvancesSeeder::class,
        BankAndCashflowSeeder::class,
        DonorReportSeeder::class,
        TranslationSeeder::class,
        NotificationAndSettingsAuditSeeder::class,
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

        $this->db->transComplete();
    }
}
