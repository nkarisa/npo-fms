<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Donor reports, and the interface-language tables behind them.
 *
 * A submitted report is a document the funder holds, so its figures are a
 * snapshot (donor_report_lines), not a live query of the ledger.
 *
 * Translation changes words only: amounts and dates are formatted from the locale
 * at render time and are never stored translated. Locked terms (the vocabulary of
 * the audited statements and donor agreements) can only be changed by the role
 * named in unlock_role_id.
 */
class CreateDonorReportsAndTranslations extends SchemaMigration
{
    public function up(): void
    {
        // "Overdue" is derived from due_on.
        $this->table('donor_reports', [
            'id'                  => $this->id(),
            'entity_id'           => $this->fk(),
            'reference'           => $this->string(20),
            'grant_id'            => $this->fk(),
            'title'               => $this->string(255),
            'type'                => $this->string(10),
            'period_starts_on'    => $this->date(),
            'period_ends_on'      => $this->date(),
            'due_on'              => $this->date(),
            'locale_id'           => $this->fk(true),
            'status'              => $this->string(10, false, 'draft'),
            'funds_received'      => $this->money(),
            'reported_adjustment' => $this->money(),
            'note'                => $this->text(),
            'prepared_by'         => $this->fk(),
            'reviewed_by'         => $this->fk(true),
            'submitted_at'        => $this->datetime(),
            'accepted_at'         => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'keys'   => ['grant_id', ['status', 'due_on']],
            'fks'    => ['entity_id' => 'entities', 'grant_id' => 'grants', 'locale_id' => 'locales',
                'prepared_by' => 'users', 'reviewed_by' => 'users'],
            'checks' => [
                'type'      => $this->in('type', ['financial', 'narrative', 'close_out']),
                'status'    => $this->in('status', ['draft', 'in_review', 'submitted', 'queried', 'accepted']),
                'dates'     => 'period_ends_on >= period_starts_on',
                'sod'       => 'reviewed_by IS NULL OR reviewed_by <> prepared_by',
                'submitted' => "status IN ('draft', 'in_review') OR submitted_at IS NOT NULL",
            ],
        ]);

        $this->table('donor_report_lines', [
            'id'                => $this->id(),
            'donor_report_id'   => $this->fk(),
            'account_id'        => $this->fk(),
            'budget'            => $this->money(),
            'period_amount'     => $this->money(),
            'cumulative_amount' => $this->money(),
        ], [
            'unique' => [['donor_report_id', 'account_id']],
            'fks'    => ['donor_report_id' => ['donor_reports', 'CASCADE'], 'account_id' => 'accounts'],
        ]);

        // Compliance statements the preparer confirms before submission.
        $this->table('donor_report_checks', [
            'id'              => $this->id(),
            'donor_report_id' => $this->fk(),
            'sort_order'      => $this->int(false, 0, 'SMALLINT'),
            'text'            => $this->text(false),
            'is_confirmed'    => $this->bool(),
            'confirmed_by'    => $this->fk(true),
            'confirmed_at'    => $this->datetime(),
        ], [
            'keys' => ['donor_report_id'],
            'fks'  => ['donor_report_id' => ['donor_reports', 'CASCADE'], 'confirmed_by' => 'users'],
        ]);

        $this->table('donor_report_queries', [
            'id'              => $this->id(),
            'donor_report_id' => $this->fk(),
            'reference'       => $this->string(20),
            'raised_on'       => $this->date(),
            'text'            => $this->text(false),
            'response'        => $this->text(),
            'responded_by'    => $this->fk(true),
            'responded_on'    => $this->date(true),
        ] + $this->timestamps(), [
            'unique' => [['donor_report_id', 'reference']],
            'fks'    => ['donor_report_id' => ['donor_reports', 'CASCADE'], 'responded_by' => 'users'],
        ]);

        $this->table('translation_strings', [
            'id'             => $this->id(),
            'source_text'    => $this->string(500),
            'area'           => $this->string(60),
            'context'        => $this->string(255, true),
            'is_locked'      => $this->bool(),
            'lock_reason'    => $this->text(),
            'unlock_role_id' => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => ['source_text'],
            'keys'   => ['area'],
            'fks'    => ['unlock_role_id' => 'roles'],
            'checks' => ['locked' => 'is_locked = 0 OR (lock_reason IS NOT NULL AND unlock_role_id IS NOT NULL)'],
        ]);

        $this->table('translations', [
            'id'                    => $this->id(),
            'translation_string_id' => $this->fk(),
            'locale_id'             => $this->fk(),
            'text'                  => $this->text(false),
            'status'                => $this->string(10, false, 'draft'),
            'reviewed_by'           => $this->fk(true),
            'reviewed_at'           => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['translation_string_id', 'locale_id']],
            'keys'   => [['locale_id', 'status']],
            'fks'    => ['translation_string_id' => ['translation_strings', 'CASCADE'], 'locale_id' => 'locales',
                'reviewed_by' => 'users'],
            'checks' => ['status' => $this->in('status', ['draft', 'in_review', 'published'])],
        ]);

        // Review notes per area ("Right-to-left layout checked"); coverage is computed.
        $this->table('locale_area_reviews', [
            'id'          => $this->id(),
            'locale_id'   => $this->fk(),
            'area'        => $this->string(60),
            'note'        => $this->string(255, true),
            'reviewed_by' => $this->fk(true),
            'reviewed_at' => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['locale_id', 'area']],
            'fks'    => ['locale_id' => ['locales', 'CASCADE'], 'reviewed_by' => 'users'],
        ]);

        $this->table('translation_requests', [
            'id'                    => $this->id(),
            'reference'             => $this->string(20),
            'translation_string_id' => $this->fk(),
            'locale_id'             => $this->fk(),
            'kind'                  => $this->string(16),
            'proposed_text'         => $this->text(),
            'status'                => $this->string(14, false, 'raised'),
            'requested_by'          => $this->fk(),
            'reviewed_by'           => $this->fk(true),
            'reviewed_at'           => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => ['reference'],
            'keys'   => [['locale_id', 'status'], 'translation_string_id'],
            'fks'    => ['translation_string_id' => 'translation_strings', 'locale_id' => 'locales',
                'requested_by' => 'users', 'reviewed_by' => 'users'],
            'checks' => [
                'kind'   => $this->in('kind', ['suggestion', 'unlock_request', 'report']),
                'status' => $this->in('status', ['raised', 'with_reviewer', 'approved', 'rejected']),
                'sod'    => 'reviewed_by IS NULL OR reviewed_by <> requested_by',
            ],
        ]);

        // Translated record content a donor sees, e.g. a project title in French.
        $this->table('content_translations', [
            'id'          => $this->id(),
            'object_type' => $this->string(30),
            'object_id'   => $this->fk(),
            'field'       => $this->string(40),
            'locale_id'   => $this->fk(),
            'text'        => $this->text(false),
        ] + $this->timestamps(), [
            'unique' => [['object_type', 'object_id', 'field', 'locale_id']],
            'fks'    => ['locale_id' => 'locales'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['content_translations', 'translation_requests', 'locale_area_reviews', 'translations',
            'translation_strings', 'donor_report_queries', 'donor_report_checks', 'donor_report_lines', 'donor_reports']);
    }
}
