<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;
use App\Repositories\ApprovalPolicy;

/**
 * Approval becomes a ladder of signatures rather than a single approver.
 *
 * `approval_rules` stays as the band header an entity holds for a document type.
 * Under it, `approval_steps` says which signatures that document must collect and
 * in what order, each named by a role so that anyone holding it can sign — people
 * go on leave and act in posts, and a ladder that names a person does not survive
 * that. `approval_signatures` records every signature given and every return,
 * append-only, so a document's progress is derived rather than stored and cannot
 * drift from what the approvers actually did.
 *
 * No document table changes. A part-signed document is still `pending_approval`,
 * and `approved_by` still names whoever signed last — so the segregation-of-duties
 * CHECK on every register, and every report that reads those columns, are untouched.
 *
 * Existing rules are backfilled into two banded steps that reproduce the old
 * threshold-and-escalation behaviour exactly (ApprovalPolicy::stepsFor), so nothing
 * is approved by anybody different the day this runs. Ladders grow a second
 * signature only when somebody adds one in Settings → Approvals.
 *
 * See docs/approvals.md.
 */
class CreateApprovalLadder extends SchemaMigration
{
    public function up(): void
    {
        // The ordered signatures a document of this type must collect. A step
        // engages for an amount above `applies_above` and up to `applies_upto`,
        // so two steps that meet at a threshold are the old escalation band, and
        // a step with no ceiling is one every document of its type must collect.
        $this->table('approval_steps', [
            'id'            => $this->id(),
            'rule_id'       => $this->fk(),
            'step_no'       => $this->int(),
            // One of the two: the role that signs, or an authority outside the
            // system (a board minute) that an in-system approver records.
            'role_id'       => $this->fk(true),
            'authority'     => $this->string(120, true),
            'label'         => $this->string(60, false, 'Approved'),
            'applies_above' => $this->money(),
            'applies_upto'  => $this->money(true),
            // How many holders of the role must sign: "any two of the trustees".
            'quorum'        => $this->int(false, 1),
        ] + $this->timestamps(), [
            'unique' => [['rule_id', 'step_no']],
            'fks'    => ['rule_id' => ['approval_rules', 'CASCADE'], 'role_id' => 'roles'],
            'checks' => [
                'who'    => 'role_id IS NOT NULL OR authority IS NOT NULL',
                'step'   => 'step_no >= 1',
                'above'  => 'applies_above >= 0',
                'band'   => 'applies_upto IS NULL OR applies_upto > applies_above',
                'quorum' => 'quorum >= 1',
            ],
        ]);

        // Every signature and every return, kept as given. A return closes the
        // round and sends the document back to its preparer; resubmitting opens
        // the next, and the ladder is climbed again from the first step, because
        // a signature attaches to the document that was signed and not to its
        // reference. `role` and `amount` are snapshots for the same reason.
        $this->table('approval_signatures', [
            'id'            => $this->id(),
            'entity_id'     => $this->fk(),
            'object_type'   => $this->string(40),
            'object_id'     => $this->fk(),
            'object_ref'    => $this->string(40, true),
            'document_type' => $this->string(20),
            'round'         => $this->int(false, 1),
            'step_no'       => $this->int(),
            'role'          => $this->string(60),
            'user_id'       => $this->fk(),
            'decision'      => $this->string(10, false, 'approved'),
            'amount'        => $this->money(),
            'authority_ref' => $this->string(120, true),
            'note'          => $this->text(),
            'signed_at'     => $this->datetime(false),
        ], [
            // Segregation of duties across a ladder: one person, one signature a
            // round. Refused by the database, not only by the application.
            'unique' => [['object_type', 'object_id', 'round', 'user_id']],
            'keys'   => [['object_type', 'object_id', 'round'], ['entity_id', 'signed_at'], 'user_id'],
            'fks'    => ['entity_id' => 'entities', 'user_id' => 'users'],
            'checks' => [
                'decision' => $this->in('decision', ['approved', 'returned']),
                'round'    => 'round >= 1',
                'step'     => 'step_no >= 1',
            ],
        ]);

        $this->backfill();
    }

    public function down(): void
    {
        $this->dropTables(['approval_signatures', 'approval_steps']);
    }

    /** Writes each existing rule's ladder: what that rule already meant, said in steps. */
    private function backfill(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = $this->db->table('approval_rules')->get()->getResultArray();

        foreach ($rows as $rule) {
            $steps = ApprovalPolicy::stepsFor(
                (float) $rule['threshold'],
                (int) $rule['approver_role_id'],
                $rule['escalation_role_id'] === null ? null : (int) $rule['escalation_role_id'],
                $rule['escalation_note']
            );

            foreach ($steps as $step) {
                $this->db->table('approval_steps')->insert(
                    ['rule_id' => (int) $rule['id'], 'created_at' => $now] + $step
                );
            }
        }
    }
}
