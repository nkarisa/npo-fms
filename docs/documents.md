# Supporting documents

Which records must carry a supporting document, which are asked for one, and how
documents are uploaded, stored and opened. This page is for developers and for
whoever runs an installation. For the screens, see the
[user manual](user-manual.md#supporting-documents).

A donor audit samples transactions and asks for the source document behind each
one. The records it samples first need their document before they can move on.

**Contents**

- [What is required, what is recommended](#what-is-required-what-is-recommended)
- [How it works](#how-it-works)
- [Who can do what](#who-can-do-what)
- [Configuration](#configuration)
- [Storage](#storage)
- [API](#api)
- [Records from before the rules](#records-from-before-the-rules)
- [Where the code is](#where-the-code-is)
- [Tests](#tests)
- [Extending](#extending)

## What is required, what is recommended

### Required

The action is refused until a document is attached.

| Section | Record | Document | When it is checked |
|---|---|---|---|
| Payables | Bill | The supplier's invoice | When the bill is captured, and when it is raised from a goods received note in Procurement |
| Staff advances | Surrender | The receipts for what was spent | When the advance is surrendered (each surrender, including a part surrender) |
| Grants and awards | Award | The signed grant agreement | When an award is recorded as **Active**, and when a **Pipeline** award is converted. A pipeline award can be recorded without one. |
| Journals | Manual journal | The invoice, board minute or other support | When it is submitted for approval, if its debits are above `documents.journalThreshold` (500,000) or its type is in `documents.journalTypes` (Adjustment). Drafts can be saved without one. |
| Procurement | Quotation | The supplier's quotation document | Above the three-quote threshold, a quotation counts towards the three only if its document is attached. With fewer than three, the purchase order needs a single-source justification. |

These journals are **exempt** from the journal rule, because they already point at
their evidence:

- reversals (the entry they reverse)
- entries raised from a bill, a payroll run, a bank statement line or a recurring template
- the opening-balance conversion (its fiscal year and imported file)

### Recommended

Offered on the form and on the record, labelled **Recommended**, but never required.

| Section | Where | What |
|---|---|---|
| Receivables | Building a claim or invoice, and the invoice panel | The donor's call for the claim, the claim as sent, the contract for other income |
| Asset register | Adding a donated or found asset, and the asset panel | The deed of gift or donor letter, a valuation, the purchase invoice, title documents |
| Asset verification | Recording a count | A photo of the asset with its tag, or of the damage found |
| Donor reports | The report panel | The report as submitted, the donor's acknowledgement or acceptance |

A document can also be added later to any of these records, or to a bill, advance
or award: open the record and use **Attach a document**, then **Add to the file**.

## How it works

1. **Upload.** Choosing a file uploads it straight away to `POST /api/attachments`.
   It is stored and recorded against the person who uploaded it, with type
   `upload`, and waits to be attached. The form shows its name and size.
2. **Attach.** The form sends the ids of its uploads as `documents` with the
   record. The record's repository checks that each id is an upload by this person
   that is still waiting (`AttachmentRepository::pending()`). In the same
   transaction that writes the record, it links them to it (`claim()`). If the
   record is refused, nothing is linked, and the uploads stay waiting so the person
   can fix the form and try again.
3. **Tidy up.** Uploads nobody attaches are deleted, with their files, after
   `documents.unclaimedHours` (24). Removing a file from a form before saving
   deletes it straight away.

Journals and quotations work slightly differently: the file travels in the same
multipart request as the form (`attachments[]` or `documents[]`), and the
repository stores it directly. Both paths use the same checks and storage
(`AttachmentRepository::accept()` and `store()`).

**Once attached, a document stays with its record as evidence.** There is no way to
remove it from a bill, advance, award, invoice, asset, count or report. A journal
still in draft is the exception: its editor can drop a document.

Every attachment is recorded in the record's audit trail. When a record is created
with documents, its history line says so ("… · 1 document attached"). A document
added later gets its own line ("Supporting document added: invoice.pdf").

## Who can do what

| Action | Who |
|---|---|
| Upload a file | Anyone signed in. It waits against them. |
| Attach to a bill, advance, invoice, asset, count, journal or donor report | A role with `journal.prepare` |
| Attach to an award | A role with `settings.manage`, the same as recording an award |
| Open (download) a document | Its uploader, or anyone who holds a role at the entity it belongs to. A waiting upload opens only for its uploader. |
| Discard a waiting upload | Only its uploader |

## Configuration

Every value is in [app/Config/Documents.php](../app/Config/Documents.php). Any of
them can be overridden in `.env` as `documents.<name>`.

| Setting | Default | Meaning |
|---|---|---|
| `requireBillInvoice` | `true` | A bill needs the supplier's invoice |
| `requireAdvanceReceipts` | `true` | A surrender needs the receipts |
| `requireGrantAgreement` | `true` | An active award needs its signed agreement |
| `journalThreshold` | `500000` | A manual journal above this needs a document before approval. `0` turns this off. |
| `journalTypes` | `['Adjustment']` | Journal types that always need a document |
| `requireQuotationDocuments` | `true` | Above the three-quote threshold, only documented quotations count |
| `maxBytes` | 10 MB | Largest file accepted |
| `types` | pdf, png, jpg, jpeg, gif, webp, heic, doc, docx, xls, xlsx, csv, txt, msg, eml | File extensions accepted |
| `unclaimedHours` | `24` | How long an upload waits to be attached |
| `disk` | `local` | `local` keeps files under `writable/uploads/`; `s3` keeps them in an S3 bucket with Object Lock. See [Storage](#storage). |
| `s3Bucket` | | The bucket's name |
| `s3Region` | `af-south-1` | The bucket's region. Cape Town is the AWS region nearest Kenya. |
| `s3Key`, `s3Secret` | | An access key for the bucket. Leave both empty on a server with an AWS role. |
| `s3Endpoint` | | The endpoint of another S3-compatible service (MinIO, R2, Wasabi). Empty for AWS. |
| `s3PathStyle` | `false` | Put the bucket in the URL path rather than the host name. MinIO usually needs it. |
| `s3Prefix` | | A folder inside the bucket for this installation, when the bucket is shared |
| `lockMode` | `COMPLIANCE` | `COMPLIANCE`: nobody can delete a document or shorten its lock, the bucket's owner included. `GOVERNANCE`: accounts with a special permission can. `OFF`: no lock. |
| `retentionYears` | `7` | Years each document is locked, counted from when it is stored |
| `linkSeconds` | `120` | How long a download link from S3 stays valid |

The screens read the settings that affect them (`requireInvoice`, `requireReceipts`,
`requireAgreement` and the journal `documentRule`), so turning a rule off also
removes its **Required** label.

## Storage

Documents are kept in one of two places, chosen by `documents.disk`:

- **`local`** (the default): under `writable/uploads/` on the application server.
  Good for development and trying the system out. Anyone who can reach the disk
  can delete a file, and a lost server loses the files.
- **`s3`**: in an Amazon S3 bucket with **Object Lock**. Each document is locked
  for `retentionYears` from when it is stored. In `COMPLIANCE` mode nobody can
  delete or overwrite it before then, not even the AWS account's owner. Use this
  in production.

All file handling goes through `service('documents')`, a `DocumentStore`:
`LocalStore` for the disk and `S3Store` for S3. The rest of the application only
deals in storage keys.

### What the database keeps

The `attachments` table records each file's original name, type, size, SHA-256,
who uploaded it and when, the record it belongs to (`object_type`, `object_id`),
and its **storage key**: its path in the store, such as
`documents/3f9c0e….pdf`. No link to the file is ever stored. A link is made only
when someone asks for the file and the application has checked they may have it.

### Downloads

`GET /api/attachments/{id}`, and the journal and quotation download links, check
who is asking first. Then:

- **From S3:** the response is a redirect to a **presigned link**: a URL signed
  for that one file that expires after `linkSeconds` (120 seconds). The browser
  fetches the file straight from S3, under its original name. The bucket itself
  stays private, so a link that is copied or forwarded stops working within
  minutes. The redirect is sent with `Cache-Control: no-store` and
  `Referrer-Policy: no-referrer`.
- **From disk:** the application sends the file itself.

### Folders

| Folder | Holds | Locked in S3 |
|---|---|---|
| `pending/` | Uploads waiting for a record (S3 only; on disk they are filed straight in `documents/`) | No |
| `documents/` | Uploads a record has claimed | Yes, when claimed |
| `journals/` | Journal supporting documents | Yes, when stored |
| `quotations/` | Quotation documents | Yes, when stored |
| `statements/` | Imported bank statement files | Yes, when stored |

**What happens to an upload in S3:**

1. It is written to `pending/`, unlocked.
2. When a record claims it, it is copied to `documents/` with the lock, and its
   row's storage key changes to the new path.
3. The copy left in `pending/` is removed by the bucket's lifecycle rule. It
   isn't deleted at claim time because the record's transaction could still roll
   back.

An upload nobody attaches is deleted by the application after `unclaimedHours`;
the lifecycle rule catches anything left over.

**Every write to S3 carries the file's SHA-256**, so S3 refuses a file that did
not arrive whole. The same checksum lets `documents:check` and `documents:migrate`
confirm that a stored file is the one that was uploaded.

**Deleting a locked file.** A draft journal can drop a document, and withdrawing
a donated asset removes its documents. In S3 this only adds a delete marker. The
document disappears from the application, but the locked version stays in the
bucket until its retention ends.

### Setting up the bucket

1. **Create the bucket** in `af-south-1`, with *Block all public access* on and
   **Object Lock enabled**. Object Lock can only be turned on when a bucket is
   created, and it turns on versioning. Leave the bucket's *default retention*
   off: the application sets retention on each document itself, and a default
   would also lock `pending/`, so unattached uploads could not be removed.
2. **Add a lifecycle rule** for the prefix `pending/` (after `s3Prefix`, if one is
   set) that expires current versions after **2 days** and permanently deletes
   noncurrent versions after 1 day.
3. **Give the application access** through an IAM role on the server, or through
   an access key in `s3Key` / `s3Secret`. It needs these permissions on the
   bucket:
   - `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:PutObjectRetention`
     on `arn:aws:s3:::BUCKET/*`
   - `s3:ListBucket`, `s3:GetBucketObjectLockConfiguration`,
     `s3:GetBucketVersioning`, `s3:GetLifecycleConfiguration` on
     `arn:aws:s3:::BUCKET` (the last three only for `documents:check`)

   Do not grant `s3:BypassGovernanceRetention`.
4. **Configure** `.env`:

   ```ini
   documents.disk = s3
   documents.s3Bucket = elog-fms-documents
   documents.s3Region = af-south-1
   documents.s3Key = AKIA…
   documents.s3Secret = …
   ```

5. **Check it:** run `php spark documents:check`. It reports whether Object Lock,
   versioning and the `pending/` rule are in place. It then writes a test file,
   reads it back, makes a link to it and removes it.

> **Try the setup with `documents.lockMode = GOVERNANCE`** and a short
> `retentionYears` on a test bucket. A document locked in COMPLIANCE mode cannot
> be deleted by anyone, AWS support included, until its date passes, and the
> bucket cannot be deleted while it holds one.

### S3 on a development machine (LocalStack)

[LocalStack](https://www.localstack.cloud/) runs S3 in Docker, so the S3 store
can be used without an AWS account. Point `.env` at it:

```ini
documents.disk = s3
documents.s3Bucket = npo-fms-documents
documents.s3Region = af-south-1
documents.s3Endpoint = http://localhost:4566
documents.s3PathStyle = true
documents.s3Key = test
documents.s3Secret = test
documents.lockMode = GOVERNANCE
```

**Starting it.** `./server.sh` sees a local `s3Endpoint` and runs
`./localstack.sh` before serving. `./server.sh --no-localstack` skips this, and
`./localstack.sh` can also be run on its own.

**What `localstack.sh` does:**

1. Starts a container named `localstack` on the endpoint's port. If one is
   already running under that name, for example another project's, it is reused.
2. Creates `s3Bucket` in `s3Region` with Object Lock, blocks public access, and
   adds the lifecycle rule for `pending/` (after `s3Prefix`).
3. Runs `php spark documents:check`.

It refuses to run when `s3Endpoint` is not on `localhost`, so it never touches a
real bucket.

**Things to know:**

- **Nothing survives a container restart.** LocalStack's free edition keeps no
  data then: the script recreates the bucket, but documents uploaded before the
  restart no longer open.
- **Downloads work, `fetch()` does not.** Downloads redirect to
  `http://localhost:4566/…` presigned links. The screens open them as ordinary
  links, so the bucket needs no CORS rule. A script calling `fetch()` on a
  download is blocked by the browser.
- **Stopping it.** The container is left running when the server stops.
  `docker stop localstack` stops it.

### Moving existing documents to S3

Configure the bucket as above, then:

```sh
php spark documents:migrate --dry-run   # lists what would be copied
php spark documents:migrate
```

- **Checks.** Each file under `writable/uploads/` is checked against the SHA-256
  recorded when it was uploaded, and copied with that checksum.
- **Locking.** Files on records are locked; uploads still waiting for a record go
  to `pending/`, unlocked.
- **Problem files.** Files that are missing, or no longer match their recorded
  SHA-256, are listed and not copied.
- **Re-running.** A file already in the bucket is skipped, so the command can be
  run again.
- **Local files.** They are left in place. Remove them once the report is clean
  and the application has been used against the bucket for a while.

### Backups

- **With `local`:** back up `writable/uploads/` with the database. A restored
  database without its files lists documents that will no longer open.
- **With `s3`:** the bucket keeps the documents across several data centres,
  and the lock protects them. For a copy in another region, turn on S3
  replication; the destination bucket also needs Object Lock.

**The company logo** (Settings → Appearance) is not a supporting document. It
stays under `writable/uploads/branding/` whichever store is chosen.

No migration of the database is needed: the `attachments` table already existed.

## API

See [openapi.yaml](openapi.yaml) (tag *Documents*) for full request and response
bodies.

| Endpoint | Purpose |
|---|---|
| `POST /api/attachments` | Multipart `file`. Returns `{document: {id, name, size}}`, a waiting upload |
| `POST /api/attachments/{id}/discard` | Remove a waiting upload (uploader only) |
| `GET /api/attachments/{id}` | Download a document: the file from disk, or a redirect to a presigned S3 link |
| `POST /api/documents/{kind}/{ref}` | `{documents: [ids]}`: attach uploads to an existing record. `kind` is `bill`, `advance`, `grant`, `invoice`, `asset`, `verification_result`, `donor_report` or `journal`. `ref` is the record's reference, award ref, asset tag or count id; slashes are fine. |

These existing endpoints now take `documents: [ids]`:

| Endpoint | Required? |
|---|---|
| `POST /api/payables` | Yes |
| `POST /api/procurement/{no}/bill` | Yes |
| `POST /api/advances/{ref}/surrender` | Yes |
| `POST /api/grants` | Yes, when `status` is Active |
| `POST /api/grants/{ref}/activate` | Yes, unless the award already has a document |
| `POST /api/receivables` | No |
| `POST /api/assets/additions` | No |
| `POST /api/asset-verification/{tag}` | No |

Records now include their documents as `documents: [{id, name, size, by, on}]` on
bills, advances, awards, invoices, the asset panel and count rows. On donor reports
the field is `attachments`. Procurement's requisition detail adds `quotesOnFile`,
the number of quotations that count towards the three.

## Records from before the rules

The rules apply to new actions. Bills, surrenders and awards already in the
database stay as they were. Their panels say there is no document on file and offer
**Attach a document**, so the gap can be filled when the paper turns up. Approving
an older bill does not require one.

Seeded quotations have no documents. Above the threshold they no longer count
towards the three, so raising a purchase order for such a requisition needs a
single-source justification, or new quotations with their documents.

## Where the code is

| File | Role |
|---|---|
| [Config/Documents.php](../app/Config/Documents.php) | The rules and limits |
| [Repositories/AttachmentRepository.php](../app/Repositories/AttachmentRepository.php) | Checking, storing, waiting uploads, attaching, listing, download permission, clean-up |
| [Libraries/Storage/](../app/Libraries/Storage/) | `DocumentStore`, and its two stores: `LocalStore` (disk) and `S3Store` (S3 with Object Lock, presigned links) |
| `Config\Services::documents()` | Picks the store from `documents.disk` |
| `BaseApiController::sendDocument()` | Serves a download: a redirect to a presigned link, or the file from disk |
| [Commands/DocumentsCheck.php](../app/Commands/DocumentsCheck.php), [DocumentsMigrate.php](../app/Commands/DocumentsMigrate.php) | `documents:check` and `documents:migrate` |
| [Controllers/Api/Attachments.php](../app/Controllers/Api/Attachments.php) | Upload, download, discard, attach to an existing record |
| `PayablesRepository::capture()` / `captureFromReceipt()` | Supplier invoice rule |
| `AdvancesRepository::surrender()` | Receipts rule |
| `GrantRepository::record()` / `activate()` | Agreement rule |
| `JournalRepository::documentRule()` / `assertSupported()` | Journal threshold and types |
| `ProcurementRepository::quotesOnFile()` / `needsQuotes()` | Documented quotations |
| [ui.js](../public/assets/js/ui.js) `UI.docPicker`, `UI.docList`, `UI.docPanel` | The picker on forms, the list, and the list with **Attach a document** |

## Tests

- [AttachmentsTest.php](../tests/unit/AttachmentsTest.php) covers file types, adding
  to existing records, count photos, who can open and discard, and the clean-up of
  uploads nobody attached.
- [DocumentStoreTest.php](../tests/unit/DocumentStoreTest.php) covers the S3 store
  against a stand-in for S3 that records each request:
  - locking and checksums on write
  - an upload waiting unlocked, then copied and locked when claimed
  - the presigned redirect, and no link for someone refused
  - a refused write recording nothing
  - removing a waiting upload
  - `documents:migrate`
  - turning the lock off
- The required rules are tested with their modules: PayablesTest, AdvancesTest,
  GrantsTest, JournalLifecycleTest, ProcurementTest and ProcurementControlsTest.
- The [StoresDocuments](../tests/_support/StoresDocuments.php) test helper provides
  `document($who, $name)`, which returns the id of a waiting upload, and
  `documentFile($name)`, which returns a file for journals and quotations. It
  deletes the stored files after each test.

## Extending

**Making another record take documents:**

1. Add its kind to `AttachmentRepository::KINDS`: table, reference column, label
   and the permission that may attach.
2. In the repository that creates the record, call `pending($f['documents'], $actorId)`
   before writing and `claim($ids, $kind, $id)` inside the transaction. To make the
   document required, refuse when `pending()` returns nothing, behind a setting in
   `Config\Documents`.
3. Include `byObject($kind)` in the record's read, as `documents`.
4. On the form, `UI.docPicker(host, { label, required | recommended })` and send
   `documents: picker.ids()`. On the record, `UI.docPanel(host, { kind, ref, docs, canAdd })`.
