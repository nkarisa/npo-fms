<?php

namespace App\Repositories;

use App\Libraries\Prototype;

/**
 * Who may approve a document of a given type and value, from the approval rules
 * and role ceilings in Settings. Approval limits are data, not code.
 *
 * - Up to a rule's threshold the rule's approver role approves; the escalation
 *   role may too. A threshold of nil sends every document to the approver.
 * - Above the threshold only the escalation role approves. Where the escalation
 *   is an authority outside the system (the Board Treasurer), the approver role
 *   proceeds only once the reference for that authority is recorded.
 * - A role's ceiling (approval_limits) caps what it may approve in one transaction.
 *
 * Segregation of duties is checked by each record's own repository; this class
 * only answers whether the approver's role and limits cover the amount.
 */
final class ApprovalPolicy extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** @return array{label: string, threshold: float, approver: string, escalation: string|null, authority: string|null}|null */
    public function rule(string $documentType): ?array
    {
        return $this->cached("rule:{$documentType}", function () use ($documentType) {
            $r = $this->row(
                'SELECT ar.label, ar.threshold, ar.escalation_note, r.name AS approver, e.name AS escalation
                 FROM {approval_rules} ar JOIN {roles} r ON r.id = ar.approver_role_id LEFT JOIN {roles} e ON e.id = ar.escalation_role_id
                 WHERE ar.document_type = ? AND ar.entity_id = ?',
                [$documentType, $this->lookups->headOfficeId()]
            );

            return $r === null ? null : [
                'label' => $r['label'], 'threshold' => (float) $r['threshold'], 'approver' => $r['approver'],
                'escalation' => $r['escalation'], 'authority' => $r['escalation'] === null ? $r['escalation_note'] : null,
            ];
        });
    }

    /**
     * Why the actor may not approve, or null when they may. An escalation to an
     * authority outside the system is satisfied by `$authorityRef`.
     *
     * @return array{message: string, needsAuthority: bool}|null
     */
    public function refusal(string $documentType, float $amount, int $actorId, string $ref, ?string $authorityRef = null): ?array
    {
        $rule = $this->rule($documentType);
        if ($rule === null) {
            return null;
        }

        $role   = $this->lookups->roleOf($actorId);
        $above  = $rule['threshold'] > 0 && $amount > $rule['threshold'];
        $amountText = 'KES ' . Prototype::fmt($amount);
        $limitText  = 'KES ' . Prototype::fmt($rule['threshold']);

        if ($above && $rule['escalation'] !== null && $role !== $rule['escalation']) {
            return ['message' => $rule['label'] . ' above ' . $limitText . ' need the ' . $rule['escalation'] . "'s approval. {$ref} is {$amountText}.", 'needsAuthority' => false];
        }
        if (!in_array($role, array_filter([$rule['approver'], $rule['escalation']]), true)) {
            return ['message' => $rule['label'] . ' are approved by the ' . $rule['approver'] . ($rule['escalation'] !== null ? ' or the ' . $rule['escalation'] : '') . ". The {$role} cannot sign off {$ref}.", 'needsAuthority' => false];
        }
        if ($above && $rule['authority'] !== null && trim((string) $authorityRef) === '') {
            return [
                'message' => $rule['label'] . ' above ' . $limitText . ' go to the ' . $rule['authority'] . ". {$ref} is {$amountText}: record the reference for that authority, such as the board minute, to proceed.",
                'needsAuthority' => true,
            ];
        }

        $ceiling = $this->ceiling($role, $documentType);
        if ($ceiling !== null && $amount > $ceiling) {
            return ['message' => "The {$role} may approve up to KES " . Prototype::fmt($ceiling) . " in one transaction. {$ref} is {$amountText}.", 'needsAuthority' => false];
        }

        return null;
    }

    /** Refuses the approval when the actor's role or limits do not cover it. */
    public function check(string $documentType, float $amount, int $actorId, string $ref, ?string $authorityRef = null): void
    {
        $refusal = $this->refusal($documentType, $amount, $actorId, $ref, $authorityRef);
        if ($refusal !== null) {
            throw $refusal['needsAuthority'] ? new AuthorityRequired($refusal['message']) : new RuleViolation($refusal['message']);
        }
    }

    /** Whether an approval of this value goes to an authority outside the system. */
    public function needsAuthority(string $documentType, float $amount): bool
    {
        $rule = $this->rule($documentType);

        return $rule !== null && $rule['authority'] !== null && $rule['threshold'] > 0 && $amount > $rule['threshold'];
    }

    /** The most a role may approve in one transaction of a type; null for no ceiling. */
    private function ceiling(string $role, string $documentType): ?float
    {
        $row = $this->row(
            'SELECT al.ceiling FROM {approval_limits} al JOIN {roles} r ON r.id = al.role_id
             WHERE r.name = ? AND (al.document_type = ? OR al.document_type IS NULL) ORDER BY al.document_type IS NULL LIMIT 1',
            [$role, $documentType]
        );

        return $row === null || $row['ceiling'] === null ? null : (float) $row['ceiling'];
    }
}
