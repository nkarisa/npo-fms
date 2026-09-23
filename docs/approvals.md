# Approvals: the signature ladder

How a document collects the signatures it needs before it is treated as approved,
how an organisation defines those signatures, and how the code is arranged. This
page is for developers and for whoever runs an installation. For the screens
themselves, see the [user manual](user-manual.md). For roles and who holds them,
see [authentication.md](authentication.md#roles-and-permissions).

**Contents**

- [In brief](#in-brief)
- [Why a ladder](#why-a-ladder)
- [How a ladder is defined](#how-a-ladder-is-defined) · [Writing one](#writing-one)
- [How a document climbs it](#how-a-document-climbs-it)
- [Segregation of duties](#segregation-of-duties)
- [Returns](#returns)
- [Authorities outside the system](#authorities-outside-the-system)
- [Entities](#entities)
- [Data model](#data-model)
- [Upgrading an existing database](#upgrading-an-existing-database)
- [Where the code is](#where-the-code-is) · [Where a record has to wait, and where
  it cannot](#where-a-record-has-to-wait-and-where-it-cannot) · [Being told, and
  being counted](#being-told-and-being-counted)
- [Tests](#tests)
- [Rollout](#rollout)
- [Extending](#extending)

## In brief

- A document type's approval rule is no longer one approver. It is an ordered
  **ladder of steps**, each step naming a **role** rather than a person, so anyone
  holding that role at that entity can sign it.
- A document stays in `pending_approval` until every step that engages for its
  amount has been signed. Only then does it become approved — and only then does
  anything else happen: the ledger entry posts, the payment run releases, the
  bank statement line clears.
- Each signature is a row in `approval_signatures`, never overwritten. A
  document's signature history is a query of that table, so it cannot drift from
  what the approvers actually did.
- A step can require more than one signature from the same role (`quorum`): *any
  two of the three Board Signatories*.
- A step can be banded, so that it engages only for amounts within a range. That
  is how the old threshold-and-escalation rule is expressed, and it is why
  upgrading an existing database changes no behaviour.
- **A return sends the document back to the preparer**, not back a step. It is
  redrafted and resubmitted, and then it needs every signature again.
- Approval limits are still data, not code: an administrator edits the ladder in
  *Settings → Approvals*.

## Why a ladder

The first schema gave each document type one approver role, one threshold and one
escalation role. Above the threshold the escalation role signed *instead of* the
approver. That answers "who signs this?" but not "who else?", and real finance
controls are mostly about who else: a payment run reviewed by the Finance Manager
and countersigned by the Executive Director; a budget revision seen by the
programme lead before the accountant touches it; a disposal that two trustees
must both agree to.

Binding each step to a role rather than a named person is what makes this usable
day to day. People go on leave, act in posts and move between entities. A ladder
that names *the Finance Manager* keeps working when the Finance Manager changes;
a ladder that names a person does not.

## How a ladder is defined

One `approval_rules` row per entity and document type, as before — it carries the
label the screens show. Under it, `approval_steps` rows in order:

| Column | Meaning |
|---|---|
| `step_no` | The order signatures are collected in: 1, then 2, then 3 |
| `role_id` | The role that signs this step |
| `authority` | Set instead of `role_id` for an authority outside the system |
| `label` | What this signature is called: *Reviewed*, *Approved*, *Countersigned* |
| `applies_above` | The step engages only above this amount; `0` means always |
| `applies_upto` | …and only up to this amount; `NULL` means no ceiling |
| `quorum` | How many holders of the role must sign; `1` unless set |

A step **engages** for an amount when
`amount > applies_above AND (applies_upto IS NULL OR amount <= applies_upto)`.
The steps that engage, in `step_no` order, are the ladder that document must
climb. Steps that do not engage are skipped entirely — they are not shown as
outstanding and nobody is asked to sign them.

Two bands that meet at a threshold reproduce the old substitution rule exactly:

| step | role | above | up to | meaning |
|---|---|---|---|---|
| 1 | Finance Manager | 0 | 500,000 | signs payment runs up to half a million |
| 2 | Executive Director | 500,000 | — | signs them instead, above that |

Leaving `applies_upto` empty on step 1 turns the same pair into a true two-step
ladder: the Finance Manager signs every run, and the Executive Director
countersigns the large ones.

What a role may sign is entirely its steps' bands — there is no separate,
document-type-blind cap behind them (there was, in `approval_limits`, until it
was dropped: it only ever fought whatever a ladder's own bands already said, so
freeing a step of its ceiling did not free the role signing it). Cap what the
Finance Manager may sign by banding *their own* step, not by adding a second
control elsewhere.

### Writing one

*Settings → Approvals*, with the `settings.approvals` permission. Each band opens
out into its ladder: a row per signature, naming who signs it, the band of values
it engages for, and how many holders of the role must sign. **Add a signature**
appends a step; the × removes one.

Nothing is written until the whole ladder passes:

- every step names a role **or** an authority outside the system, not both and
  not neither;
- the role holds `journal.approve` — a role with no approval rights cannot be
  made to sign;
- the first signature is given inside the system, because an authority outside it
  is satisfied by whoever signs recording its reference;
- a band ends above where it begins;
- a quorum can actually be filled — asking three Finance Managers of an
  organisation that has two would leave documents waiting forever;
- at most `MAX_LADDER_STEPS` (6) signatures.

A refusal names the step and says what is wrong with it, and nothing at all is
written — the whole ladder is checked before any of it is saved.

The band header above the ladder is the short way of saying the same policy, so
the two are kept as one thing. Editing the **ladder** rewrites the header —
`threshold` becomes step 1's ceiling, `approver` its role. Editing the
**threshold or approver** rewrites the ladder from the band, and where that would
drop a longer ladder the plan says so before you save:

> Journal entries approver changed from Finance Manager to Executive Director —
> its 3-signature ladder goes back to the band above

An entity that edits any band gets **its own copy of the head office's**, ladders
included. A rule copied without its steps would ask for nobody's signature, so
the two always travel together.

A rule that names no step at all is treated as a broken policy rather than an
absent one: nothing can be approved against it until it names who signs. (A
document type with no rule is a different thing, and is not held to one.)

## How a document climbs it

1. The preparer submits. The document goes to `pending_approval`. Everyone
   holding the first engaged step's role at that entity is notified.
2. A holder of that role approves. A row goes into `approval_signatures`. If more
   steps remain, the document **stays** in `pending_approval` and the holders of
   the next step's role are notified. Its history gains a line — *Reviewed by
   W. Kamau · awaiting the Executive Director*.
3. When the last engaged step is signed, the document becomes approved. This is
   where everything that used to happen on approval happens, unchanged:
   `status`, `approved_by` and `approved_at` are written, and the side effects
   fire.

`approved_by` and `approved_at` still mean *the final signer and when they
signed*. Every existing read, report and database constraint that relies on them
is unaffected; the earlier signatures live in `approval_signatures`.

The amount a step is judged against is the document's own approval amount — for a
journal raised from a fund transfer, the amount moved rather than the doubled
debits; for a payment run, the run's total. Each repository already knows this and
keeps deciding it.

## Segregation of duties

The two-person rule becomes a many-person rule, and the database enforces it:

- The preparer may not sign **any** step.
- Nobody may sign **two steps of the same round**. A unique index on
  `(object_type, object_id, round, user_id)` refuses it even if the application
  is wrong.
- Where a step has a quorum above one, the signatures must come from distinct
  people — the same index again.

The existing `sod` CHECK constraint on each document table
(`approved_by <> prepared_by`) stays exactly as it is. It is now the last of
several checks rather than the only one.

## Returns

Any approver at any step may return a document. A return:

- records a `returned` row in `approval_signatures`, with the reason;
- sends the document back to the **preparer**, as a draft, discarding no history;
- closes the current **round**.

Resubmitting opens the next round, and the ladder starts from step 1 again. Every
signature must be given afresh, because the document that was signed is not the
document that is now being submitted.

This is the strict reading, and it is the one an auditor expects: a signature
attaches to a version of a document, not to a document. It also keeps the rule
simple to explain on screen — *returned to the preparer by the Executive
Director; it will need all three signatures again*.

The rounds make the stricter behaviour cheap to relax later if an organisation
asks: returning to the previous step instead would leave the round open and clear
only the signatures above it. Nothing in the schema would change.

## Authorities outside the system

Some escalations are not a role in the application — a board minute, the Board
Treasurer. A step with no `role_id` and an `authority` label is such a step. It is
satisfied when an in-system approver records the reference for that authority,
which is stored on the signature.

The API contract for this is unchanged: the request is refused with `422` and
`needsAuthority: true`, and the screen asks for the reference before trying
again. See `AuthorityRequired`.

## Entities

A role is held per entity ([authentication.md](authentication.md#roles-and-permissions)),
and two things follow:

- **A document of another entity cannot be reached at all.** Every read is
  limited to the entities the person holds a role at (`EntityScope`), so a
  document they have no business with is not found rather than merely refused.
- **A step's role is matched at the record's own entity.** The Finance Manager of
  the Nyanza office is not the Finance Manager of Coast and cannot sign Coast's
  documents. `sign()` asks about the record's entity, which it is given; the
  screens ask about the books being worked in, which is the same entity, because
  a record of another one is not found.

`holdsRole()` is also the fix for a person holding several roles: what they may
sign is asked of the set, rather than of whichever role `roleOf()` happens to
name first.

Ladders follow the same inheritance as the old bands: an entity uses its own when
it has set any, and the head office's otherwise. Setting the first step of its
own copies the head office's ladder down first, so nothing is lost.

## Data model

| Table or column | Purpose |
|---|---|
| `approval_rules` | Unchanged as the per-entity, per-document-type header: the label the screens show. `escalation_role_id` and `escalation_note` are kept for one release and then dropped |
| `approval_steps` | The ordered ladder under a rule. Columns above |
| `approval_signatures` | Every signature and every return, append-only. `round`, `step_no`, `role`, `user_id`, `decision`, `amount`, `authority_ref`, `note`, `signed_at` |
| `approval_limits` | Dropped (`DropApprovalCeilings`): a role's blanket ceiling, once checked alongside the ladder. Bands do that job alone now |
| `<document>.approved_by`, `.approved_at` | Unchanged: the final signer |
| `<document>.status` | Unchanged. No status enum gains a value; a part-signed document is `pending_approval` |

`approval_signatures.role` and `.amount` are snapshots — the role name as it was
when signed, and the figure that was signed off. A role renamed next year does not
rewrite last year's approvals.

Progress is derived from `approval_signatures` rather than cached on the document,
so the two cannot disagree. If the approval queues ever become slow, denormalise
then, not now.

## Upgrading an existing database

`php spark migrate` creates the two tables and writes a ladder for every existing
rule, mapping the old semantics exactly:

| Existing rule | Becomes |
|---|---|
| approver, threshold above nil, escalation role | step 1 approver `0 … threshold`; step 2 escalation `above threshold` |
| approver, threshold above nil, `escalation_note` | step 1 approver `0 … threshold`; step 2 authority step carrying the note |
| approver, threshold nil | step 1 approver, always, no ceiling |

**No behaviour changes when the migration runs.** Every document is approved by
exactly the same people as the day before. Ladders become multi-step only when
somebody edits one in *Settings → Approvals*.

## Where the code is

| File | Role |
|---|---|
| [Repositories/ApprovalPolicy.php](../app/Repositories/ApprovalPolicy.php) | The ladder: which steps engage, where a document stands, who may sign next, and recording a signature |
| [Repositories/AuthorityRequired.php](../app/Repositories/AuthorityRequired.php) | The `422` for an authority outside the system |
| [Repositories/Lookups.php](../app/Repositories/Lookups.php) | `holdsRole()` and `holdersOf()`: who may sign a step, and who to notify |
| [Repositories/JournalRepository.php](../app/Repositories/JournalRepository.php) | `approve()` — the pattern the other registers follow |
| [PayablesRepository.php](../app/Repositories/PayablesRepository.php) | Bills on the same shape; payment runs and the WHT remittance sign as they are released |
| [BudgetRepository.php](../app/Repositories/BudgetRepository.php), [AdvancesRepository.php](../app/Repositories/AdvancesRepository.php), [PayrollRepository.php](../app/Repositories/PayrollRepository.php), [ProcurementRepository.php](../app/Repositories/ProcurementRepository.php), [AssetRepository.php](../app/Repositories/AssetRepository.php) | The same `approve()` shape |
| [Repositories/SettingsRepository.php](../app/Repositories/SettingsRepository.php) | Reading and editing ladders, and copying them to an entity that sets its own |
| [Controllers/Api/Journals.php](../app/Controllers/Api/Journals.php) | The approve endpoint and the register's counters |
| [pages/journals.js](../public/assets/js/pages/journals.js), [payables.js](../public/assets/js/pages/payables.js), [advances.js](../public/assets/js/pages/advances.js), [procurement.js](../public/assets/js/pages/procurement.js), [ui.js](../public/assets/js/ui.js) | The `2/3 · Executive Director` line under a status, and the standing at the top of the drawer |
| [pages/settings.js](../public/assets/js/pages/settings.js) | The ladder editor under each approval band |
| [Libraries/EntityScope.php](../app/Libraries/EntityScope.php) | `viewerId()`: who a register is counting "waiting for you" for |

Each repository's `approve()` keeps its own guards and side effects. The only
shared part is the ladder:

```php
$policy->check($type, $amount, $actorId, $ref, null, 'journal', $id);   // may this actor sign the open step?

$this->transaction(function () use (...) {
    if (!$policy->sign('journal', $id, $ref, $type, $amount, $actorId, null, '', $entityId)) {
        // Signed, but the ladder is not finished. Nothing posts yet: the entry
        // waits in the register for the role that signs next.
        $this->audit('journal', $id, $ref, $step['label'] . ' by ' . $who
            . $policy->awaitingNote('journal', $id, $type, $amount), $actorId);

        return;
    }

    // …the terminal block: status, approved_by, approved_at, and the side effects.
});
```

A record awaiting approval carries an `approval` object to the screens —
`signed`, `of`, `awaiting`, a ready-made `note` and the signatures `given` so
far. It is null for anything not waiting. A register works the standings out for
every row at once — one call to `ApprovalPolicy::standings()`, which reads every
signature of that object type in a single query — so listing a page costs the
same whether one entry is waiting or fifty.

### Where a record has to wait, and where it cannot

The shape above needs somewhere for a part-signed record to sit: a bill stays
*Awaiting approval*, a requisition stays submitted, a payroll run stays
unpostable. Two acts in Payables have nowhere, because the record and the money
leave together — **releasing a payment run** and **remitting withholding tax**
create their record, post it and pay it in one breath. A rule that asks those for
more than one signature is refused, with the reason, rather than paying out on
the first:

> This payment run of KES 2,400,000 needs 2 signatures, and a payment run is
> released in one act — there is nowhere to hold it between them. Release it in
> smaller runs, or set a payment run rule that asks for one signature at this
> value.

The default rules never trip this — a payment run's two steps are banded, so only
one of them engages at any value. Holding a part-signed run is step 4 work: it
needs a waiting state on `payment_runs` and a screen to release it from.

**Asset additions** are the one register left out. The `document_type` a rule may
name is fixed by a CHECK on `approval_rules`, and `asset_addition` is not among
the twelve; widening it would rebuild the table on SQLite, which the schema's
triggers and foreign keys do not survive. Disposals are on the list and are
wired. An addition's approval stays a single signature until that list changes.

### Being told, and being counted

A signature that does not finish a ladder tells whoever can give the next one.
It is written from `sign()`, so every register does it without a line of its
own — an `approval` notification to the holders of the open step's role **at the
record's entity**, minus the person who has just signed:

> **JV-26-0417 is waiting for your approval**
> Journal entries — 620,000. W. Kamau has signed; yours is signature 2 of 3.

A step that goes to an authority outside the system has nobody in the system to
tell, so nobody is told: its reference is recorded by whoever signs next.

The same question — *is this one mine to sign?* — is what separates a queue from
a list. Each standing carries `mine`: the viewer holds a role that signs the open
step, and has not already signed this round. Registers add their own condition,
that the viewer did not prepare it, and count the two figures separately:

> 14 of 31 journals · 6 awaiting approval · 2 awaiting yours

Worked out from the signatures already read, so a queue costs no query per row.

## Tests

- [ApprovalLadderTest.php](../tests/unit/ApprovalLadderTest.php) covers a
  one-step ladder behaving as the old single approver did, a two-step ladder
  leaving the document pending after the first signature and approving on the
  last, the preparer being refused at every step, one person being refused a
  second step, a quorum needing distinct people, banded steps engaging by amount,
  a return closing the round, and the authority step. Its last section takes a
  real journal up a two-step ladder through `JournalRepository`, and checks that
  nothing posts until the last signature.
- Its *Every register on the same ladder* section does the same for each of the
  others: a bill that posts no cost until the last signature and goes back to its
  preparer when rejected, an advance that is not payable until its ladder is
  finished, a requisition taking a ladder an administrator writes for a type that
  had no rule, a payroll run that cannot be posted between signatures, and the
  two payment-run cases — refused on a two-signature rule, recorded like any
  other signature on a one-signature rule.
- The upgrade's own mapping is tested through `ApprovalPolicy::stepsFor()`,
  because a seeded database exercises only the seeder's copy of it.
- Its *Writing a ladder* section covers the editor end to end: a three-step
  ladder written from *Settings → Approvals* and binding at once, with the band
  header brought into step; each of the checks refusing without writing any of
  the ladder; an untouched ladder not counting as a change; an entity taking its
  own bands getting the ladder with them; a rule that names nobody refusing
  rather than letting anyone sign; the next role being told; and a register
  separating what is waiting from what is waiting for you.
- [SchemaIntegrityTest.php](../tests/database/SchemaIntegrityTest.php) covers the
  new tables' constraints.

```sh
vendor/bin/phpunit tests/unit/ApprovalLadderTest.php
```

## Rollout

**All four steps are built.** Every ladder in an existing database is the one or
two banded steps its rule already meant, so what is approved and by whom is what
it was; every register collects its signatures through the ladder; and a longer
one is written in *Settings → Approvals* rather than in the database.

1. ~~**Schema, engine and backfill.**~~ Every ladder is one or two banded steps
   and behaviour is identical. The regression tests prove it.
2. ~~**Journals** move to the new signing path~~, with the progress shown on the
   record and in its history.
3. ~~**The other registers** follow the same diff~~ — bills, budget revisions,
   advances, payroll runs, requisitions and asset disposals, with payment runs
   and the WHT remittance signing as they are released. See *Where a record has
   to wait, and where it cannot* for the two exceptions.
4. ~~**Settings, notifications and queues.**~~ The ladder editor in
   *Settings → Approvals*; the next step's role holders told when a signature is
   given; and "awaiting yours" counted apart from "awaiting approval", so a
   Finance Manager is not shown a queue full of the Director's work. A role is
   now matched at the record's own entity.

## Extending

**Adding a step to a ladder:** *Settings → Approvals*, with the
`settings.approvals` permission. Open the band out and add a signature. No code
or migration — see [Writing one](#writing-one).

**Bringing a new document type under approval:**

1. Add its key to `$documentTypes` in the organisation migration and seed an
   `approval_rules` row with at least one step.
2. In its repository's `approve()`, call `check()` then `sign()`, and guard the
   terminal block with the result as above.
3. Add it to `SettingsRepository::APPROVAL_KEYS` so it appears on the settings
   screen.

**Changing what a step is judged against:** the amount is the repository's to
decide — see `JournalRepository::approvalAmount()`, which uses the amount moved
for a fund transfer rather than the doubled debits.
