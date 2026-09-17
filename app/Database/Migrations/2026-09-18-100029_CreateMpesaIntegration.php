<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * The M-Pesa (Safaricom Daraja) integration the organisation collects and pays
 * through, held as configuration rather than in the environment so the Finance
 * Manager can see and change it, and so every change is in the audit log.
 *
 * One row per entity, because a short code belongs to one registered
 * organisation and settles into one cash account:
 *
 * - The short code, what kind it is (a paybill takes an account number from the
 *   payer, a till does not) and the account reference donors quote.
 * - The mobile-money cash account collections land in and disbursements leave
 *   from, so what the integration moves is already on the same account the M-Pesa
 *   statement reconciles against.
 * - The Daraja credentials. They are held encrypted (App\Libraries\Secret) and
 *   never leave the server: the API answers with whether each one is set and its
 *   last four characters, never the value.
 * - Which services are switched on — collections (customer to business) and
 *   disbursements (business to customer) are separate permissions at Safaricom
 *   and are separate here — and the per-payment ceiling, which cannot be set
 *   above the 250,000 Safaricom itself will pass.
 * - When the connection was last checked and what Safaricom answered.
 */
class CreateMpesaIntegration extends SchemaMigration
{
    public const ENVIRONMENTS = ['sandbox', 'production'];

    public const SHORTCODE_KINDS = ['paybill', 'till'];

    public function up(): void
    {
        $this->table('mpesa_integrations', [
            'id'                  => $this->id(),
            'entity_id'           => $this->fk(),
            'bank_account_id'     => $this->fk(true),
            'environment'         => $this->string(10, false, 'sandbox'),
            'shortcode'           => $this->string(12, true),
            'shortcode_kind'      => $this->string(8, false, 'paybill'),
            'account_reference'   => $this->string(20, true),
            'callback_base'       => $this->string(200, true),
            // Encrypted at rest; never served to the browser.
            'consumer_key'        => $this->text(),
            'consumer_secret'     => $this->text(),
            'passkey'             => $this->text(),
            'initiator_name'      => $this->string(60, true),
            'security_credential' => $this->text(),
            'collections_on'      => $this->bool(),
            'disbursements_on'    => $this->bool(),
            'auto_match'          => $this->bool(true),
            'payment_ceiling'     => $this->money(),
            'checked_at'          => $this->datetime(),
            'check_result'        => $this->string(160, true),
            'updated_by'          => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => ['entity_id'],
            'keys'   => ['bank_account_id'],
            'fks'    => ['entity_id' => ['entities', 'CASCADE'], 'bank_account_id' => 'bank_accounts', 'updated_by' => 'users'],
            'checks' => [
                'environment' => $this->in('environment', self::ENVIRONMENTS),
                'kind'        => $this->in('shortcode_kind', self::SHORTCODE_KINDS),
                'ceiling'     => 'payment_ceiling >= 0',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['mpesa_integrations']);
    }
}
