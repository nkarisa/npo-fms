<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * The signatures a document must collect before it counts as approved, and who
 * may give each of them. Approval limits are data, not code: the ladder and the
 * role ceilings are rows an administrator edits in Settings. An entity may hold
 * a ladder of its own; one that holds none follows the head office's.
 *
 * A document type's rule carries an ordered ladder of steps (`approval_steps`).
 * Each step names a **role** rather than a person, so anyone holding that role
 * can sign it, and engages only for amounts inside its band — which is how the
 * old single approver with an escalation above a threshold is said in steps, and
 * why nothing changed when ladders arrived (see stepsFor()).
 *
 * - A step is satisfied once `quorum` different people holding its role have
 *   signed it. The first step not yet satisfied is the one now open.
 * - A step naming an authority outside the system (the Board Treasurer, a board
 *   minute) is satisfied by the in-system approver recording its reference.
 * - A role's ceiling (`approval_limits`) caps what it may sign in one transaction.
 * - Nobody signs twice in a round, and a return closes the round: the document
 *   goes back to its preparer and, once resubmitted, is signed again from the top.
 *
 * Whether the preparer is also the actor is checked by each record's own
 * repository, which knows who prepared it; this class answers the rest.
 *
 * See docs/approvals.md.
 */
final class ApprovalPolicy extends Repository
{
    /** What a signature is called when no other name was given. */
    public const DEFAULT_LABEL = 'Approved';

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /**
     * The ladder a single-approver rule stands for: the approver, and the
     * escalation above the threshold where the rule has one to escalate to.
     *
     * The installer, the demonstration seed and the migration that backfilled
     * existing databases all write their steps from here, so one description of
     * the old policy serves all three. A threshold with nothing to escalate to
     * never bound anybody, so it becomes one step that always engages.
     *
     * @return list<array{step_no: int, role_id: int|null, authority: string|null, label: string, applies_above: float, applies_upto: float|null, quorum: int}>
     */
    public static function stepsFor(float $threshold, int $approverRoleId, ?int $escalationRoleId = null, ?string $escalationNote = null): array
    {
        $escalates = $threshold > 0 && ($escalationRoleId !== null || trim((string) $escalationNote) !== '');

        $steps = [[
            'step_no' => 1, 'role_id' => $approverRoleId, 'authority' => null, 'label' => self::DEFAULT_LABEL,
            'applies_above' => 0.0, 'applies_upto' => $escalates ? $threshold : null, 'quorum' => 1,
        ]];

        if ($escalates) {
            // Above the band the escalation signs instead, not as well: the two
            // steps share a threshold and only one of them ever engages.
            $steps[] = [
                'step_no' => 2, 'role_id' => $escalationRoleId, 'label' => self::DEFAULT_LABEL,
                'authority' => $escalationRoleId === null ? $escalationNote : null,
                'applies_above' => $threshold, 'applies_upto' => null, 'quorum' => 1,
            ];
        }

        return $steps;
    }

    // ------------------------------------------------------------------
    // The ladder
    // ------------------------------------------------------------------

    /** @return array{label: string, threshold: float, approver: string, escalation: string|null, authority: string|null}|null */
    public function rule(string $documentType): ?array
    {
        $r = $this->ruleRow($documentType);

        return $r === null ? null : [
            'label' => $r['label'], 'threshold' => (float) $r['threshold'], 'approver' => $r['approver'],
            'escalation' => $r['escalation'], 'authority' => $r['escalation'] === null ? $r['escalation_note'] : null,
        ];
    }

    /**
     * Every step of a document type's ladder, in order.
     *
     * @return list<array{step: int, role: string|null, authority: string|null, label: string, above: float, upto: float|null, quorum: int}>
     */
    public function steps(string $documentType): array
    {
        return $this->cached("steps:{$documentType}", function () use ($documentType) {
            $rule = $this->ruleRow($documentType);
            if ($rule === null) {
                return [];
            }

            return array_map(static fn ($s) => [
                'step'      => (int) $s['step_no'],
                'role'      => $s['role'],
                'authority' => $s['authority'],
                'label'     => $s['label'],
                'above'     => (float) $s['applies_above'],
                'upto'      => $s['applies_upto'] === null ? null : (float) $s['applies_upto'],
                'quorum'    => max(1, (int) $s['quorum']),
            ], $this->rows(
                'SELECT s.*, r.name AS role FROM {approval_steps} s LEFT JOIN {roles} r ON r.id = s.role_id
                 WHERE s.rule_id = ? ORDER BY s.step_no',
                [(int) $rule['id']]
            ));
        });
    }

    /**
     * The steps that engage for this amount — the signatures this document must
     * collect. A step with no band of its own engages whatever the amount,
     * nil included.
     */
    public function ladder(string $documentType, float $amount): array
    {
        return array_values(array_filter(
            $this->steps($documentType),
            static fn ($s) => ($s['above'] <= 0 || $amount > $s['above']) && ($s['upto'] === null || $amount <= $s['upto'])
        ));
    }

    /**
     * Where a document stands on its ladder: the signatures given this round, the
     * step now open, and whether the last one has been signed.
     *
     * @return array{round: int, steps: list<array>, open: array|null, signed: int, of: int, complete: bool}
     */
    public function progress(string $objectType, int $objectId, string $documentType, float $amount): array
    {
        return $this->standing($documentType, $amount, $this->signatures($objectType, $objectId));
    }

    /**
     * The same answer as progress(), from signatures already read — so a register
     * works out where every document stands without a query for each of them.
     *
     * @param list<array> $given one document's signatures, as signatures() returns them
     * @return array{round: int, steps: list<array>, open: array|null, signed: int, of: int, complete: bool}
     */
    public function standing(string $documentType, float $amount, array $given): array
    {
        $ladder = $this->ladder($documentType, $amount);
        // A return closes a round; the next submission opens the one after it.
        $round  = 1 + count(array_filter($given, static fn ($s) => $s['decision'] === 'returned'));
        $signed = array_values(array_filter($given, static fn ($s) => $s['round'] === $round && $s['decision'] === 'approved'));

        $steps = [];
        $open  = null;
        foreach ($ladder as $step) {
            $on   = array_values(array_filter($signed, static fn ($s) => $s['step'] === $step['step']));
            $done = count($on) >= $step['quorum'];
            if (!$done && $open === null) {
                $open = $step;
            }
            $steps[] = $step + ['signed' => $on, 'done' => $done];
        }

        return [
            'round' => $round, 'steps' => $steps, 'open' => $open,
            'signed' => count(array_filter($steps, static fn ($s) => $s['done'])),
            'of' => count($steps), 'complete' => $open === null,
        ];
    }

    // ------------------------------------------------------------------
    // Signing
    // ------------------------------------------------------------------

    /**
     * Why the actor may not sign this document's open step, or null when they may.
     * A step that escalates to an authority outside the system is satisfied by
     * `$authorityRef`.
     *
     * Naming the document (`$objectType`, `$objectId`) reads the signatures it has
     * already collected. Without it the answer is about the first step of the
     * ladder, which is what a screen asks before anything has been signed.
     *
     * @return array{message: string, needsAuthority: bool}|null
     */
    public function refusal(
        string $documentType,
        float $amount,
        int $actorId,
        string $ref,
        ?string $authorityRef = null,
        ?string $objectType = null,
        ?int $objectId = null
    ): ?array {
        $steps = $this->steps($documentType);
        if ($steps === []) {
            // A document type nobody has written a rule for is not held to one.
            return null;
        }

        $ladder   = $this->ladder($documentType, $amount);
        $named    = $objectType !== null && $objectId !== null;
        $progress = $named ? $this->progress($objectType, $objectId, $documentType, $amount) : null;
        // Without the document, the question is about the first step of the ladder:
        // what a screen asks before anything has been signed.
        $open = $progress === null ? ($ladder[0] ?? null) : $progress['open'];
        if ($open === null) {
            return null;
        }

        $rule        = $this->rule($documentType);
        $role        = $this->lookups->roleOf($actorId);
        $amountText  = 'KES ' . Prototype::fmt($amount);
        $signatories = self::signatories($steps, $ladder, $open);
        $signingAs   = $this->signingRole($signatories, $actorId);

        if ($signingAs === null) {
            // A step that engages only above an amount says so; the rest name who signs.
            if ($open['above'] > 0 && $open['role'] !== null) {
                return ['message' => $rule['label'] . ' above KES ' . Prototype::fmt($open['above']) . ' need the '
                    . $open['role'] . "'s approval. {$ref} is {$amountText}." . self::outstanding($ladder, $open), 'needsAuthority' => false];
            }

            return ['message' => $rule['label'] . ' are approved by the ' . implode(' or the ', $signatories)
                . ". The {$role} cannot sign off {$ref}." . self::outstanding($ladder, $open), 'needsAuthority' => false];
        }

        if ($open['authority'] !== null && trim((string) $authorityRef) === '') {
            return [
                'message' => $rule['label'] . ($open['above'] > 0 ? ' above KES ' . Prototype::fmt($open['above']) : '')
                    . ' go to the ' . $open['authority'] . ". {$ref} is {$amountText}: record the reference for that authority, such as the board minute, to proceed.",
                'needsAuthority' => true,
            ];
        }

        if ($progress !== null && $this->hasSigned($objectType, $objectId, $progress['round'], $actorId)) {
            return ['message' => $this->lookups->shortName($actorId) . ' has already signed ' . $ref
                . '. Each signature on the ladder is a different person.', 'needsAuthority' => false];
        }

        $ceiling = $this->ceiling($signingAs, $documentType);
        if ($ceiling !== null && $amount > $ceiling) {
            return ['message' => "The {$signingAs} may approve up to KES " . Prototype::fmt($ceiling) . " in one transaction. {$ref} is {$amountText}.", 'needsAuthority' => false];
        }

        return null;
    }

    /** Refuses the approval when the actor's role or limits do not cover the open step. */
    public function check(
        string $documentType,
        float $amount,
        int $actorId,
        string $ref,
        ?string $authorityRef = null,
        ?string $objectType = null,
        ?int $objectId = null
    ): void {
        $refusal = $this->refusal($documentType, $amount, $actorId, $ref, $authorityRef, $objectType, $objectId);
        if ($refusal !== null) {
            throw $refusal['needsAuthority'] ? new AuthorityRequired($refusal['message']) : new RuleViolation($refusal['message']);
        }
    }

    /**
     * Records the actor's signature against the open step, and says whether that
     * was the last one the document needed. Call it inside the caller's
     * transaction, after check(): a false answer means the document stays where
     * it is, waiting for the next role.
     */
    public function sign(
        string $objectType,
        int $objectId,
        string $ref,
        string $documentType,
        float $amount,
        int $actorId,
        ?string $authorityRef = null,
        string $note = '',
        ?int $entityId = null
    ): bool {
        $progress = $this->progress($objectType, $objectId, $documentType, $amount);
        $open     = $progress['open'];
        if ($open === null) {
            // Nothing to collect: a document type with no rule, or already signed off.
            return true;
        }
        if ($this->hasSigned($objectType, $objectId, $progress['round'], $actorId)) {
            throw new RuleViolation($this->lookups->shortName($actorId) . ' has already signed ' . $ref
                . '. Each signature on the ladder is a different person.');
        }

        $signatories = self::signatories($this->steps($documentType), $this->ladder($documentType, $amount), $open);

        $this->insert('approval_signatures', [
            'entity_id'     => $entityId ?? $this->lookups->entityId(),
            'object_type'   => $objectType,
            'object_id'     => $objectId,
            'object_ref'    => $ref,
            'document_type' => $documentType,
            'round'         => $progress['round'],
            'step_no'       => $open['step'],
            'role'          => $this->signingRole($signatories, $actorId) ?? $this->lookups->roleOf($actorId),
            'user_id'       => $actorId,
            'decision'      => 'approved',
            'amount'        => $amount,
            'authority_ref' => $open['authority'] === null || trim((string) $authorityRef) === '' ? null : $authorityRef,
            'note'          => $note === '' ? null : $note,
            'signed_at'     => Clock::timestamp(),
        ]);

        return $this->progress($objectType, $objectId, $documentType, $amount)['complete'];
    }

    /**
     * Records a return. The document goes back to its preparer — the caller puts
     * it there — and the round closes, so resubmitting it needs every signature
     * again: what was signed is not what is being submitted now.
     */
    public function returnToPreparer(
        string $objectType,
        int $objectId,
        string $ref,
        string $documentType,
        float $amount,
        int $actorId,
        string $reason = '',
        ?int $entityId = null
    ): void {
        $progress = $this->progress($objectType, $objectId, $documentType, $amount);
        $open     = $progress['open'] ?? ($progress['steps'][0] ?? null);

        $this->insert('approval_signatures', [
            'entity_id'     => $entityId ?? $this->lookups->entityId(),
            'object_type'   => $objectType,
            'object_id'     => $objectId,
            'object_ref'    => $ref,
            'document_type' => $documentType,
            'round'         => $progress['round'],
            'step_no'       => $open === null ? 1 : $open['step'],
            'role'          => $this->lookups->roleOf($actorId),
            'user_id'       => $actorId,
            'decision'      => 'returned',
            'amount'        => $amount,
            'authority_ref' => null,
            'note'          => $reason === '' ? null : $reason,
            'signed_at'     => Clock::timestamp(),
        ]);
    }

    /**
     * What a record's history says after a signature that did not finish the
     * ladder: " · awaiting the Executive Director", or '' when nothing is left.
     */
    public function awaitingNote(string $objectType, int $objectId, string $documentType, float $amount): string
    {
        $open = $this->progress($objectType, $objectId, $documentType, $amount)['open'];

        return $open === null ? '' : ' · awaiting the ' . ($open['role'] ?? $open['authority']);
    }

    /**
     * How many signatures a document of this value has to collect in all — the
     * quorums of every step its ladder engages. One is the ordinary case, and the
     * only one a record that is authorised and acted on in a single step can serve.
     */
    public function signaturesNeeded(string $documentType, float $amount): int
    {
        return (int) array_sum(array_column($this->ladder($documentType, $amount), 'quorum'));
    }

    /**
     * Where each document of a page of a register stands on its ladder, ready to
     * show: the signatures come back in one query, so a register costs the same
     * whether one record is waiting or fifty.
     *
     * @param array<int, array{type: string, amount: float}> $documents keyed by record id
     * @return array<int, array{signed: int, of: int, awaiting: string, note: string, given: list<array>}>
     */
    public function standings(string $objectType, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $signatures = $this->signaturesFor($objectType);
        $out = [];
        foreach ($documents as $id => $d) {
            $standing = $this->standing($d['type'], $d['amount'], $signatures[$id] ?? []);
            if ($standing['of'] > 0) {
                $out[$id] = $this->asLadder($standing);
            }
        }

        return $out;
    }

    /** What a waiting record shows about its ladder, in a register and in its drawer. */
    private function asLadder(array $standing): array
    {
        $awaiting = $standing['open'] === null ? '' : ($standing['open']['role'] ?? $standing['open']['authority']);
        $given    = [];
        foreach ($standing['steps'] as $step) {
            foreach ($step['signed'] as $signature) {
                $given[] = [
                    'step' => $step['label'],
                    'by'   => $this->lookups->shortName($signature['user']),
                    'at'   => self::dmy(substr((string) $signature['at'], 0, 10)),
                ];
            }
        }

        return [
            'signed'   => $standing['signed'],
            'of'       => $standing['of'],
            'awaiting' => $awaiting,
            // One signature reads as it always did; a ladder says how far along it is.
            'note'     => ($standing['of'] > 1 ? $standing['signed'] . ' of ' . $standing['of'] . ' signatures · ' : '')
                . ($awaiting === '' ? 'fully approved' : 'awaiting the ' . $awaiting),
            'given'    => $given,
        ];
    }

    /** Whether an approval of this value goes to an authority outside the system. */
    public function needsAuthority(string $documentType, float $amount): bool
    {
        foreach ($this->ladder($documentType, $amount) as $step) {
            if ($step['authority'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** The signatures a document has collected, newest last. */
    public function signatures(string $objectType, int $objectId): array
    {
        // Deliberately not cached: sign() reads this again inside its caller's
        // transaction, which is where the cache is cleared only at the end.
        return array_map(self::asSignature(...), $this->rows(
            'SELECT * FROM {approval_signatures} WHERE object_type = ? AND object_id = ? ORDER BY signed_at, id',
            [$objectType, $objectId]
        ));
    }

    /**
     * Every document of a type's signatures, keyed by record id — one query for a
     * whole register, to be passed to standing().
     *
     * @return array<int, list<array>>
     */
    public function signaturesFor(string $objectType): array
    {
        $out = [];
        foreach ($this->rows(
            'SELECT * FROM {approval_signatures} WHERE object_type = ? ORDER BY object_id, signed_at, id',
            [$objectType]
        ) as $row) {
            $out[(int) $row['object_id']][] = self::asSignature($row);
        }

        return $out;
    }

    private static function asSignature(array $s): array
    {
        return [
            'round' => (int) $s['round'], 'step' => (int) $s['step_no'], 'role' => $s['role'],
            'user' => (int) $s['user_id'], 'decision' => $s['decision'], 'amount' => (float) $s['amount'],
            'authorityRef' => $s['authority_ref'], 'note' => $s['note'], 'at' => $s['signed_at'],
        ];
    }

    // ------------------------------------------------------------------

    private function ruleRow(string $documentType): ?array
    {
        return $this->cached("rule:{$documentType}", fn () => $this->row(
            'SELECT ar.id, ar.label, ar.threshold, ar.escalation_note, r.name AS approver, e.name AS escalation
             FROM {approval_rules} ar JOIN {roles} r ON r.id = ar.approver_role_id LEFT JOIN {roles} e ON e.id = ar.escalation_role_id
             WHERE ar.document_type = ? AND ar.entity_id IN (?, ?) ORDER BY ar.entity_id = ? DESC LIMIT 1',
            // The entity's own band when it has set its own, else the organisation's.
            [$documentType, $this->lookups->entityId(), $this->lookups->headOfficeId(), $this->lookups->entityId()]
        ));
    }

    /**
     * The roles that may sign an open step.
     *
     * Its own role, and — because whoever signs a higher band could as well have
     * signed a lower one — the roles of later steps that do not engage at this
     * amount. A step naming an authority outside the system has no role of its
     * own: it is recorded by the in-system approver, which is the role at the
     * steps before it.
     *
     * @return list<string>
     */
    private static function signatories(array $steps, array $ladder, array $open): array
    {
        $engaged = array_column($ladder, 'step');
        $roles   = $open['role'] === null ? [] : [$open['role']];

        foreach ($steps as $step) {
            if ($step['role'] === null || in_array($step['step'], $engaged, true)) {
                continue;
            }
            $later = $step['step'] > $open['step'];
            if ($open['role'] === null ? !$later : $later) {
                $roles[] = $step['role'];
            }
        }

        return array_values(array_unique($roles));
    }

    /** The role the actor would be signing as, or null when they hold none of them. */
    private function signingRole(array $signatories, int $actorId): ?string
    {
        foreach ($signatories as $role) {
            if ($this->lookups->holdsRole($actorId, $role)) {
                return $role;
            }
        }

        return null;
    }

    private function hasSigned(string $objectType, int $objectId, int $round, int $actorId): bool
    {
        foreach ($this->signatures($objectType, $objectId) as $signature) {
            if ($signature['round'] === $round && $signature['user'] === $actorId) {
                return true;
            }
        }

        return false;
    }

    /** Said only of a ladder with more than one signature to collect. */
    private static function outstanding(array $ladder, array $open): string
    {
        if (count($ladder) < 2) {
            return '';
        }
        $at = (int) array_search($open['step'], array_column($ladder, 'step'), true) + 1;

        return ' This is signature ' . $at . ' of ' . count($ladder) . '.';
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
