# Setting up the application

This guide takes you from a fresh checkout to a working installation. There are
two ways to set one up, and they share the same first steps:

| | **Demonstration data** | **A new instance** |
|---|---|---|
| **What you get** | A fictional organisation (ELOG) with six years of books, staff, grants, budgets and donor reports | An empty organisation of your own, ready for its chart, funds and opening balances |
| **Use it for** | Development, testing, training, demos | Running a real organisation's accounts |
| **Commands** | `migrate` → `db:seed DatabaseSeeder` | `migrate` → `db:seed BaselineSeeder` → `install` |
| **Section** | [Path A](#path-a--demonstration-data) | [Path B](#path-b--a-new-instance) |

A database holds one or the other, never both. To switch, reset it first — see
[Resetting](#resetting-a-database).

> **Everyone signs in** with their email, a password and, by default, a second
> step: a code from an authenticator app (Google Authenticator, Microsoft
> Authenticator, Okta Verify…) or a code sent by email. See [Signing in](#signing-in).

---

## 1. Requirements

- **PHP 8.2 or later** (developed on 8.4), with these extensions: `intl`,
  `mbstring`, `mysqli`, `json`, `fileinfo`, `openssl`. The test suite also needs
  `sqlite3`.
- **MySQL 8** for the application database. The schema is also written for SQLite,
  which the test suite uses; other databases are refused rather than installed
  without the ledger's integrity rules.
- **Composer**.

Check the extensions with:

```bash
php -m | grep -iE '^(intl|mbstring|mysqli|json|fileinfo|openssl|sqlite3)$'
```

## 2. Get the code and configure it

```bash
composer install
cp env .env
```

Then edit `.env`. These are the settings that matter:

| Setting | What to put there |
|---|---|
| `CI_ENVIRONMENT` | `development` while setting up; `production` for a live instance. |
| `app.baseURL` | The address the application is served from, with a trailing slash, e.g. `'http://localhost:8090/'`. |
| `database.default.hostname` | The MySQL host, e.g. `127.0.0.1`. |
| `database.default.database` | The database name. Create an empty database first. |
| `database.default.username` / `password` | A MySQL user with rights to create tables, triggers and views. |
| `database.default.DBDriver` | `MySQLi`. |
| `database.default.charset` / `DBCollat` | `utf8mb4` / `utf8mb4_unicode_ci`. |
| `encryption.key` | Generate one with `php spark key:generate`. **Needed for authenticator apps**: their keys are stored encrypted. Without it, the second step can only be a code by email, and M-Pesa credentials can't be stored. |
| `email.*` | How the application sends invitations, password resets and sign-in codes. See [Mail](#mail). |
| `auth.*` | Optional sign-in settings. See [Signing in](#signing-in). |
| `app.asOf` | **Demonstration data only** — see below. Leave it out for a real instance. |

### About `app.asOf`

The application measures everything that depends on "today" — days overdue, the
current period, how far a grant has run — from one date. Normally that is the
real date. Setting `app.asOf` pins it:

```ini
app.asOf = 2026-08-31
```

The demonstration data is written as at **31 August 2026**, so set `app.asOf` to
that date when you load it; otherwise its bills all look years overdue and its
open periods are in the past. **Remove the line for a real instance**, or the
books will think it is August 2026 forever.

### Serving it

For development, CodeIgniter's own server is enough. Match the port to `app.baseURL`:

```bash
php spark serve --port 8090
```

In production, point a web server's document root at `public/` and make
`writable/` writable by the web server user.

## 3. Build the schema

Both paths start here. This creates every table, the integrity triggers that
enforce the accounting rules, and the reporting views:

```bash
php spark migrate
```

Check that everything ran:

```bash
php spark migrate:status
```

The triggers are the reason the database is MySQL or SQLite only: they are what
stop an unbalanced journal posting, a posted journal being changed, a closed
period being posted to, or a restricted fund going below zero — at the database,
whatever the screen allows.

---

## Path A — Demonstration data

```bash
php spark db:seed DatabaseSeeder
```

This loads a complete fictional organisation, **Elections Observation Group
(ELOG)**, a Kenyan not-for-profit reporting under IFRS in KES. It runs in one
transaction: if anything fails, nothing is left behind.

Set `app.asOf = 2026-08-31` in `.env` before you open it (see above).

### What it loads

It runs `BaselineSeeder` first — the same reference data a real instance gets —
then builds the organisation on top of it from the prototype data in
`app/Data/OLD/*.json`:

- **Five entities** — the National Secretariat (head office), the Coast and
  Western regional offices, ELOG Trust (Endowment), and a dormant Rift Valley
  office.
- **Nine people** in every role, plus a system user that owns records nobody
  signed for.
- **Six financial years**, FY2021 to FY2026. January to July 2026 are closed;
  August to December 2026 are open. August is the month being closed.
- **The chart of accounts**, nine funds, six programmes, eight grants and their
  funders, and the bank, M-Pesa and petty cash accounts.
- **The ledger** — an opening-balance journal (`OB-26-0001`) and the year's
  postings, with summaries for the months locked before the ledger was kept here.
- **Everything around it** — supplier bills and payment runs, donor claims and
  receipts, purchase requisitions, budgets, the asset register, payroll, staff
  advances, bank statements and reconciliations, the cashflow forecast, donor
  reports, translations and an audit trail.

### Who to sign in as

Every active demonstration user has the password **`elog-demo-password`**
(`OrganisationSeeder::DEMO_PASSWORD`). It is public, so never load the
demonstration data where the internet can reach it. At first sign-in each person
is asked to set up a second step. To skip that on a laptop, set
`auth.mfaRequired = optional` in `.env`.

| Person | Email | Role | Useful for |
|---|---|---|---|
| Administrator | admin@elog.or.ke | Administrator | Every permission at every entity — what the install hands over |
| Wanjiru Kamau | w.kamau@elog.or.ke | Finance Manager | Settings, approving, closing periods — the default |
| Michael Otieno | m.otieno@elog.or.ke | Senior Accountant | Preparing journals and payroll |
| Sarah Njeri | s.njeri@elog.or.ke | Accountant | Preparing |
| Peter Mwangi | p.mwangi@elog.or.ke | Accountant | Preparing |
| Joyce Achieng | j.achieng@elog.or.ke | Accountant | Bank reconciliation |
| Daniel Kiptoo | d.kiptoo@elog.or.ke | Executive Director | Approvals above the Finance Manager's limit; authorising a period close |
| PKF Kenya (audit) | audit@pkfea.com | Auditor (read only) | Seeing what an auditor sees |
| Grace Wambui | g.wambui@elog.or.ke | Programme Officer | Invited — has not accepted |
| Brian Omondi | b.omondi@elog.or.ke | Accountant | Suspended |

The person who prepares something can never approve it. To see an approval
through, prepare it as one person, then sign out and sign in as another to approve.
For training sessions, `auth.actAs = true` brings back an **Act as** menu that
switches user without signing out. Everything is recorded against the person
acted as. **Never turn it on for an instance holding real books.**

### Seeding again

The seeder refuses to run on a database that already holds data:

```
The database already holds data. Run `php spark migrate:refresh` first, then seed again.
```

This is deliberate. Posted journals and the audit log cannot be deleted — by
design — so the only way back to a clean demonstration is to rebuild the schema.
See [Resetting](#resetting-a-database).

---

## Path B — A new instance

A new instance belongs to one organisation, in its own database. It is stood up
in a browser, or with two commands, and then finished inside the application.

### Installing in a browser

Serve the application with no database in `.env` and open any page: an instance
with no organisation answers everything with the installer at `/install`. From
any machine but the server itself it first asks for the **setup key**, which it
writes to `writable/install.token` on the server.

| Step | What it asks |
|---|---|
| The server | MySQL or SQLite; for MySQL the host, port, user and password. The installer signs in and lists what that user can reach |
| The database | Pick one from the list. Each is labelled *Empty*, *Part installed*, *Other tables* or *In use*; one already holding an organisation cannot be picked. **Create a new database** is offered when the user holds `CREATE` on `*.*` (MySQL, created as `utf8mb4`) or `writable/` is writable (SQLite). The installer never drops or empties a database |
| What to put in it | **A new organisation** (the reference data below, then your answers), or **a copy of the demonstration organisation** (Path A's data, whose passwords are published; on a production server this needs a confirming tick) |
| The organisation, its entities, the first user | The same answers as `php spark install` below. The head office is always created; more entities can be opened with it |
| Check and install | Writes `.env`, builds the schema, seeds, then writes the organisation in one transaction |

The chosen database is proved before anything is written: its character set,
whether it already holds an organisation, and that the user can create tables,
views and triggers. Once installed, the installer writes `writable/installed.lock`
and `/install` no longer exists. Delete the lock to offer it again, for an
instance whose database has been dropped.

The steps below do the same from the command line.

### Step 1 — Seed the reference data

```bash
php spark db:seed BaselineSeeder
```

`BaselineSeeder` writes what is the same for every organisation. It names no
organisation at all.

**What it seeds**

- The roles, the permissions each holds, and the approval ceilings
- The coding segments every posting carries (fund, programme, restriction, grant,
  funder, county)
- The document series journals are numbered in (`PV`, `RC`, `JV`, `PR`, `BK`,
  `MP`, `AC`, `AL`, `OB`)
- Currencies with indicative rates (KES, USD, EUR, DKK, GBP)
- Kenya's 47 counties
- Budget phasing profiles and budget-revision rules
- Payroll: pay components, the grade scale, and the statutory rates (PAYE bands,
  personal relief, NSSF, SHIF, the housing levy, NITA)
- The month-end close checklist
- The source language (English)

**What it deliberately does not seed** — because these belong to the organisation:
no entity, no users, no chart of accounts, no funds or programmes, no financial
year, and no balances.

Every row is written only if it is not already there, so running it again
changes nothing.

### Step 2 — Install the organisation

```bash
php spark install
```

The installer asks each question in turn. Press Enter to accept the answer in
brackets.

| Question | Default | Notes |
|---|---|---|
| Registered name | — | As on the certificate of registration |
| Short name | — | What staff call the organisation. Also becomes the application's name in the sidebar |
| Tax PIN | blank | Can be added later |
| Registration number | blank | Can be added later |
| Head office code | — | 2–20 letters, digits and hyphens, e.g. `ACME-HQ`. **Fixed once saved** — user access and imported files refer to it |
| Head office name | — | The reporting entity the accounts consolidate into |
| Functional currency | `KES` | Must be one of the seeded currencies |
| Reporting framework | `IFRS` | `IFRS`, `IPSAS` or `Kenyan GAAP` |
| Financial year end | `31 December` | `31 December`, `30 June` or `30 September` |
| Account code length | `4 digits` | **Choose 4 digits.** The chart of accounts and its templates currently work with four-digit codes |
| First financial year to keep | this year | Named by the year it ends in. A year ending 30 June 2027 is FY2027 and opens on 1 July 2026 |
| Your full name | — | The first user. First and last name, so entries show who prepared them |
| Your email address | — | What you sign in with |
| Your password | — | **Answers file only** (`userPassword`), never asked on screen. Leave it out and the installer prints a one-time link for you to choose one |

Nothing is written until every answer has been checked, and everything is
written in one transaction — a refused answer leaves the database exactly as it
was.

**What it creates**

- The organisation and its head office entity
- The reporting settings, posting controls and appearance, all at their defaults
- The approval policy (thresholds and who approves above them)
- The first financial year and its twelve months, all open
- You, holding **Finance Manager** and **Administrator** at every entity it
  opens: every permission, the consolidated view, and every entity opened later.
  Approval rules name roles, and you approve as the Finance Manager
- A system user that owns records nobody signed for; it can never sign in
- An entry in the audit log recording the installation

It then prints what to do next, and, unless the answers file gave a password, a
**one-time link** to choose your password. The link works once, for seven days.
If it is lost, `php spark user:link <your email>` prints a new one.

#### Installing without prompts

For scripted or repeatable installs, write the answers to a file:

```bash
php spark install --show-config --no-header > install.json
```

Fill it in:

```json
{
    "registeredName": "Coast Community Trust",
    "shortName": "CCT",
    "taxPin": "",
    "registrationNo": "",
    "entityCode": "CCT-HQ",
    "entityName": "Coast Community Trust — Head Office",
    "currency": "KES",
    "framework": "IFRS",
    "yearEnd": "31 December",
    "codeLength": "4 digits",
    "firstYear": "2026",
    "userName": "Amina Salim",
    "userEmail": "a.salim@cct.or.ke",
    "userPassword": ""
}
```

`userPassword` is optional. Give one (at least 12 characters) for an unattended
install, or leave it empty and use the link the installer prints.

And run it:

```bash
php spark install --config=install.json
```

`--no-header` matters: without it, the command's banner ends up in the file.

#### When the installer refuses

| Message | Why | What to do |
|---|---|---|
| *This instance already belongs to an organisation…* | The database already has an entity. A second head office would break the consolidation every report is built on | Use a new database, or [reset](#resetting-a-database) this one |
| *The reference data is missing…* | `BaselineSeeder` has not run | Run `php spark db:seed BaselineSeeder` |
| *… is not a currency this instance holds* | The currency is not seeded | Use a seeded currency; add others in Settings → Currencies afterwards |
| *Use at least 12 characters…* (or a similar reason) | `userPassword` is too short, too obvious, or contains your email address | Choose a longer one, or leave it out and use the link |

### Step 3 — Finish setting up in the application

Open the link the installer printed, choose your password and set up your second
step. Then do these in order — each one is what the next depends on.

#### 1. Check the defaults — Settings

Go through **Ledger**, **Segments**, **Currencies** and **Approvals** and adjust
anything that does not match how the organisation works. Upload a logo and pick
a theme under **Appearance** if you like.

#### 2. Build the chart of accounts — Chart of accounts

Nothing can be posted until there is at least one postable account. There are
three ways in, and they can be combined:

- **Start from a template.** Choose *Not-for-profit (IFRS)* (64 accounts) or
  *Not-for-profit — compact* (44 accounts). Before anything is written you can
  rename accounts, change a type or restriction, and leave out whatever you do
  not need — leaving out a heading leaves out everything under it. Accounts you
  already have are never overwritten; a template fills the gaps.
- **Import a CSV.** The top-level headings (for example `1000 Assets`, `1100
  Cash`) must exist before the accounts under them can be imported, so create the
  headings first — or start from a template, which creates them for you.
- **Add accounts one at a time** with **+ New account**.

Every account opens at zero. Only journals move a balance.

A template also sets the account each payroll component posts to — but only
where none has been set yet.

#### 3. Open funds and cash accounts — Settings

- **Settings → Segments → Open a fund.** Every posting carries a fund. Open at
  least a general, unrestricted fund. A fund in the grant or capital column cannot
  be unrestricted, and an endowment is both reported and rolled up as one.
- **Settings → Bank statements → Open a cash account.** One for each bank
  account, mobile money float and petty cash float, each on its own postable asset
  account. Then assign each bank or mobile money account a statement format so
  statements can be loaded.

#### 4. Open the first programme — Programmes

The first programme carries the whole shared-cost allocation (100%), since there
is nothing else to recover support costs against yet. Later programmes take a
share from it.

#### 5. Check payroll's accounts — Settings → Payroll

Under **Posting accounts**, every pay component a run posts must have an account.
If you started from a template these are already set. Until they are, the Payroll
screen still works out the run but says it has nowhere to post, and a run cannot
be approved.

#### 6. Carry the opening balances — Settings → Opening balances

1. Choose the period the balances are carried into. Only a period with nothing
   posted before it is offered.
2. **Download the trial balance to fill in.** It lists every postable account,
   already coded with its default fund and programme. Enter the closing balances
   from the old system and delete the rows with none. Where the period opens the
   financial year, income and expenditure accounts are left out — last year's
   result belongs in the accumulated fund.
3. Upload it and **Check the file**. Every problem is named with its line number.
   Nothing is written.
4. **Carry the balances.** This writes a **draft** journal, not a posting.

The draft is then submitted and approved on the **Journals** screen like any
other entry. The person who loaded it cannot approve it, so step 7 comes first.

#### 7. Invite the rest of the team — Settings → Users

Invite at least one other person who can approve — typically the Executive
Director. Every entry needs a second person, and the opening balances will usually
be worth more than a Finance Manager may approve alone.

Each invitation emails a link to choose a password. Give a person as many roles
as their job needs, each at all entities or only some. Define new roles under
**Settings → Roles** if the built-in ones don't fit.

---

## Signing in

How it works in full — stages, protections, roles and the API — is in
[authentication.md](authentication.md).

People sign in at `/login` with their email and password, then a second step:

- **An authenticator app** (recommended): Google Authenticator, Microsoft
  Authenticator, Okta Verify, 1Password or any app that shows six-digit codes
  (TOTP, RFC 6238). Needs `encryption.key`.
- **A code by email**, valid for 10 minutes. Needs [mail](#mail).

Setting either up gives ten one-time **recovery codes** for when the phone or
mailbox is lost. People manage their password, second step and recovery codes
under **My account**, in the account menu.

**Permissions come only from roles.** A role is a named set of permissions
(Settings → Roles). A person holds one or more roles, each at all entities or at
the entities named (Settings → Users → Manage). What they may do is everything
their roles allow. Only someone with the `users.manage` permission (the Finance
Manager, out of the box) changes roles or who holds them. The application refuses
any change that would leave nobody active who can manage users.

### Settings

All optional. Set them in `.env` as `auth.<name>`:

| Setting | Default | What it does |
|---|---|---|
| `auth.mfaRequired` | `all` | Who must have a second step: `all`; `privileged` (anyone who can approve, post, authorise a close, or change settings or users); or `optional`. Anyone may set one up regardless |
| `auth.mfaMethods` | `totp, email` | Which second steps are offered |
| `auth.issuer` | the short name | The name shown against the account in authenticator apps |
| `auth.minPasswordLength` | `12` | Passwords containing the person's email name, the handful anyone tries first, and ones of fewer than five different characters are refused whatever their length |
| `auth.maxFailedSignIns` / `auth.lockMinutes` | `5` / `15` | Wrong passwords before an account is locked, and for how long, until a password policy is saved in Settings → Users |
| `auth.idleMinutes` | `30` | Idle time before someone must sign in again |
| `auth.inviteHours` / `auth.resetHours` | `168` / `1` | How long invitation and password-reset links work |
| `auth.actAs` | `false` | Training only: the **Act as** menu. See [Who to sign in as](#who-to-sign-in-as) |

Every sign-in, wrong password, lockout and change to a second step is in the
audit log with the address it came from. Sign-in attempts are also limited to
ten a minute from one address.

### Mail

Set CodeIgniter's email settings in `.env`, for example:

```ini
email.fromEmail = finance@example.org
email.fromName = 'ELOG Finance'
email.protocol = smtp
email.SMTPHost = smtp.example.org
email.SMTPUser = finance@example.org
email.SMTPPass = '…'
email.SMTPPort = 587
email.SMTPCrypto = tls
```

Until mail works, outside `production`, an invitation link, reset link or sign-in
code that could not be sent is written to `writable/logs/` instead, so a
developer can still use it. In production it is not logged.

### Locked out

Someone who has lost both their phone and their recovery codes: anyone with
`users.manage` opens **Settings → Users → Manage → Reset second step**, and the
person sets up a new one at their next sign-in. A forgotten password is reset
from **Forgot your password?** on the sign-in page.

If nobody who manages users can sign in, run this on the server:

```bash
php spark user:link w.kamau@elog.or.ke              # a one-time link to choose a new password
php spark user:link w.kamau@elog.or.ke --reset-mfa  # …and remove their second step
```

Both are recorded in the audit log.

### Upgrading an existing database

Sign-in comes with a migration. Run `php spark migrate` after updating. Existing
users have no password yet, so give each a link: `php spark user:link <email>`,
or, once one person is in, **Email a link to set a password** under Settings → Users → Manage. The migration
also gives the new `users.manage` permission to every role that could change settings.

Maintenance mode comes with one too. Its migration adds the
`settings.maintenance` permission — which closes the application to everyone but
its holders — and gives it to every role that already manages users, so an
instance is never left with an application it cannot close and nobody able to
grant the right to close it. Hand it out from Settings → Roles, and to more than
one person, so a closure can always be undone.

## Supporting documents

Some records need a supporting document before they can move on: the supplier's
invoice on a bill, the receipts on an advance surrender, the signed agreement on an
active award, and a document on large or adjusting journals. Others are asked for
one. [documents.md](documents.md) has the full list and how it works.

- **Settings** are in `.env` as `documents.<name>`. For example,
  `documents.journalThreshold = 250000` lowers the amount above which a journal
  needs a document, and `documents.requireBillInvoice = false` turns off the invoice
  rule, though an auditor will expect it on.
- **Files** are stored under `writable/uploads/` by default. The web server must
  be able to write there. **Back it up with the database**; the database only
  records where each file is.
- **In production, keep them in S3 with Object Lock** so that no one can delete a
  document before its retention ends. Set `documents.disk = s3` and the bucket
  settings, then run `php spark documents:check`. `php spark documents:migrate`
  moves the files already on disk. Downloads then go through short-lived
  presigned links. [documents.md](documents.md#storage) has the bucket setup.
- **To use S3 without an AWS account while developing**, point
  `documents.s3Endpoint` at LocalStack (`http://localhost:4566`). `./server.sh`
  then starts it in Docker and creates the bucket. See
  [documents.md](documents.md#s3-on-a-development-machine-localstack).
- **Upgrading** needs no migration. Bills, surrenders and awards recorded before
  the rules keep working, and their panels offer **Attach a document** so the
  paperwork can be added.

---

## Resetting a database

> **This cannot be undone.** Everything in the database is lost — posted
> journals, the audit log, all of it. Take a backup first.

```bash
mysqldump -h 127.0.0.1 -u <user> -p <database> > backup-$(date +%F).sql
```

Rebuild the schema. This drops every table and creates them again:

```bash
php spark migrate:refresh
```

Uploaded files — logos, journal attachments, bank statements, quotations — are
kept on disk under `writable/uploads/`, not in the database, so clear them too:

```bash
rm -rf writable/uploads/branding writable/uploads/journals writable/uploads/documents \
       writable/uploads/statements writable/uploads/quotations
rm -rf writable/cache/* writable/session/*
```

Keep `writable/uploads/index.html`. The folders are recreated on the next upload.

With `documents.disk = s3`, the documents are in the bucket, and locked ones
cannot be removed before their retention date. Give the reset database a new
bucket or a new `documents.s3Prefix`, so its documents are kept apart from the
old ones.

Then follow either path from the seeding step:

```bash
# the demonstration again
php spark db:seed DatabaseSeeder

# or a new instance
php spark db:seed BaselineSeeder
php spark install
```

Remember to set or remove `app.asOf` to match.

---

## Running the tests

```bash
vendor/bin/phpunit
```

The tests never touch your database. Each one builds its own in-memory SQLite
database, seeds it, and throws it away. Most run against the demonstration data;
`InstallTest` runs against a baseline-only database, so it checks what a real
installation actually has — including that every screen either works or says
what is missing on a brand-new instance.

A single test class:

```bash
vendor/bin/phpunit --filter InstallTest
```

To see a change working in the running application rather than in the tests —
on a throwaway copy, acting as any user, with screenshots — see
[run-fms.md](run-fms.md).

---

## Troubleshooting

**A page shows its header and sidebar but nothing else.**
The page loaded but the data behind it did not. Open the browser's developer
tools and look at the failed `/api/…` request — its response says what is wrong.
On a new instance this is usually something not set up yet (see Step 3).

**The General ledger says there are no postable accounts yet.**
The chart of accounts is empty. Start from a template or import one.

**Payroll says it has nowhere to post yet.**
Some pay components have no account, or there is no general fund or no
programme. The screen lists exactly which. Set them in Settings → Payroll,
Settings → Segments and Programmes.

**An error page says the instance has no organisation yet.**
The schema and reference data are there but `php spark install` has not run.

**Dates look wrong — everything is overdue, or the current period is in 2026.**
Check `app.asOf` in `.env`. It should be `2026-08-31` for the demonstration data
and absent for a real instance.

**M-Pesa credentials cannot be saved.**
There is no `encryption.key` in `.env`. Run `php spark key:generate`.

**`mysqldump` or `mysql` is not found.**
The MySQL client is often not on the PATH. With Homebrew it is under
`/opt/homebrew/opt/mysql-client/bin/`.

---

## Reference

### Commands

| Command | What it does |
|---|---|
| `php spark migrate` | Creates the schema, triggers and views |
| `php spark migrate:status` | Lists which migrations have run |
| `php spark migrate:refresh` | Drops every table and rebuilds the schema. Destroys all data |
| `php spark db:seed DatabaseSeeder` | Loads the demonstration organisation |
| `php spark db:seed BaselineSeeder` | Loads the reference data every instance needs |
| `php spark install` | Creates the organisation, its head office, first year and first user |
| `php spark install --show-config --no-header` | Prints an empty answers file |
| `php spark install --config=<file>` | Installs from an answers file |
| `php spark key:generate` | Writes an encryption key to `.env` |
| `php spark receivables:book-write-offs` | Maintenance for a database seeded before the doubtful-debt allowance existed. Not needed on a new install |

### Where things are

| Path | What is there |
|---|---|
| `app/Database/Migrations/` | The schema, in order |
| `app/Database/Seeds/BaselineSeeder.php` | The reference data every instance shares |
| `app/Database/Seeds/DatabaseSeeder.php` | The demonstration seeders, in the order they run |
| `app/Data/OLD/*.json` | The demonstration organisation's data |
| `app/Libraries/Installer.php` | What `spark install` asks and writes |
| `app/Libraries/ChartTemplate.php` | The chart of accounts templates |
| `writable/uploads/` | Uploaded files |
| `docs/documentation.md` | What each screen does |
| `docs/openapi.yaml` | The API |
| `docs/run-fms.md` | Running a throwaway copy of the application and driving it in a headless browser |
| `.claude/skills/run-fms/` | The scripts that do it, and the Claude Code skill that uses them |
