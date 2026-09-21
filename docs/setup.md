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

> **There is no sign-in yet.** Anyone who can reach the application can use it,
> and the **Act as** menu lets them act as any user. Keep an installation on a
> private network until authentication is added.

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
| `encryption.key` | Generate one with `php spark key:generate`. Without it, M-Pesa credentials cannot be stored; everything else works. |
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

### Who to act as

With no sign-in, you choose who you are from the **Act as** menu. The default is
the Finance Manager.

| Person | Email | Role | Useful for |
|---|---|---|---|
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
through, prepare it as one person and switch to another to approve.

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
with two commands and then finished inside the application.

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
| Your email address | — | How you are identified |

Nothing is written until every answer has been checked, and everything is
written in one transaction — a refused answer leaves the database exactly as it
was.

**What it creates**

- The organisation and its head office entity
- The reporting settings, posting controls and appearance, all at their defaults
- The approval policy (thresholds and who approves above them)
- The first financial year and its twelve months, all open
- You, as **Finance Manager** — the only role that can change settings
- A system user that owns records nobody signed for; it can never sign in
- An entry in the audit log recording the installation

It then prints what to do next.

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
    "userEmail": "a.salim@cct.or.ke"
}
```

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

### Step 3 — Finish setting up in the application

Open the application. You are acting as the user you just created. Do these in
order — each one is what the next depends on.

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
rm -rf writable/uploads/branding writable/uploads/journals \
       writable/uploads/statements writable/uploads/quotations
rm -rf writable/cache/* writable/session/*
```

Keep `writable/uploads/index.html`. The folders are recreated on the next upload.

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
