# ELOG Finance Suite — User Interface Documentation

This document describes the ELOG Finance Suite user interface page by page: what
you see on each screen, how it is laid out, and what you can do there.

## About the application

ELOG Finance Suite is a fund-accounting system for a not-for-profit that reports
under **IFRS** with **KES** as its functional currency. It covers the full
accounting cycle — chart of accounts, the general ledger, journals, payables,
receivables, procurement, bank reconciliation, fixed assets, payroll and staff
advances — alongside fund and grant management, budgets, donor reporting, a
cashflow forecast and financial statements.

Two principles run through the whole interface:

- **Segregation of duties (the two-person rule).** The person who prepares a
  transaction cannot approve it. Approval and posting come from a second person,
  and the interface says so plainly whenever an action is blocked for this reason.
- **The ledger is the source of truth.** Subsidiary records (the asset register,
  the payroll run, the advances register, donor claims) are reconciled back to
  their control accounts, and the interface shows whether they agree.

### Setting up an installation

This document describes an installation already in use. How to install one —
with the demonstration data, or as a new instance for a real organisation — and
the order to finish setting it up in, is in [setup.md](setup.md).

### How the interface is built

Every page shares a common shell (sidebar + top bar). The page body is rendered
in the browser by a small JavaScript module that fetches its data from a matching
`/api/…` endpoint and draws the tables, cards and drawers you see. The server
applies every business rule again when you save, so the checks shown on screen
are enforced, not merely advisory.

Common building blocks you will meet on most pages:

- **Stat cards** — a row of headline figures (label, value, short note).
- **Tabs** — status filters such as *All*, *Draft*, *Approved*.
- **Tables** — the working list for each area, usually ten rows a page.
- **Drawers** — dialogs that open in the middle of the screen to show or edit one record.
- **Modals** — centred dialogs for building a new record.
- **Toasts** — brief confirmations after an action.

---

## The shell: navigation and top bar

The shell wraps every page and is always available.

### Sidebar

The left sidebar carries the brand — the application name, the line under it and
the logo, all set in *Settings → Appearance*, and **ELOG · Finance Suite** until
they are — and the full page
menu, grouped into four sections:

| Group | Pages |
|-------|-------|
| **Overview** | Dashboard, Period close |
| **Accounting** | Chart of accounts, Programmes, General ledger, Journals, Payables, Receivables, Procurement, Bank reconciliation, Asset register, Asset verification, Payroll, Staff advances |
| **Funds and grants** | Funds, Grants and awards, Budgets, Donor reports |
| **Insight** | Cashflow forecast, Reports, Settings |

- A **collapse toggle** (‹ / ›) shrinks the sidebar to icons only; the choice is
  remembered per browser. When collapsed, hovering an icon shows its label as a
  tooltip.
- The footer of the sidebar states the reporting framework: **IFRS · KES functional**.

### Top bar

Running left to right across the top of every page:

- **Breadcrumb** — the current group and page (e.g. *Accounting / Journals*).
- **Interface language** — a menu of available languages with their translation
  coverage. Changing it re-renders the interface text only; posted amounts, dates
  and account codes are unchanged. Links through to *Settings → Language and translation*.
- **Entity picker** — switches between entities (National Secretariat, regional
  offices, the endowment trust, or a consolidated view).
- **Search everything (⌘K)** — a command-palette search across journals,
  accounts, suppliers, donors and awards. Results are grouped and link straight
  to the record.
- **Financial-year pill** — shows the current FY and whether it is open.
- **Notifications (◎)** — a bell with an unread badge. The panel lists items by
  day, can be filtered to unread only, supports *Mark all read*, and each item
  links to the relevant record.
- **User menu** — the signed-in user and every role they hold, **My account**, and
  **Sign out**. On a training instance with `auth.actAs` on, it also has an
  **Act as** switcher for demonstrating the two-person rule without signing out.

---

## Signing in

**Purpose:** let only the people the organisation has given access in, and prove
each one is who they say. The design is in [authentication.md](authentication.md).

**On screen:** a standalone page at `/login`, outside the shell. Opening any page
while signed out lands here and returns to that page afterwards.

1. **Email and password.** A wrong password gets the same answer whether or not
   the email has an account. Five in a row lock an account for 15 minutes.
2. **Second step.** A six-digit code from an authenticator app (Google
   Authenticator, Microsoft Authenticator, Okta Verify…) or one emailed for this
   sign-in. A one-time recovery code works in place of either.
3. **Setting one up**, the first time, if the instance requires a second step
   (the default is everyone). Choose the app, which means scanning a QR code or
   typing the key, or email, then confirm with a code. Ten recovery codes follow,
   shown once, with copy, download and print. **Continue** stays off until you
   tick *I have saved these codes*.

**Also here:** **Forgot your password?** emails a link that works for an hour.
**Accept invite** (`/accept-invite`) and **Reset password** (`/reset-password`)
are the pages those emailed links open, where you choose a password (at least 12
characters). Being idle for 30 minutes signs you out.

### My account

**Purpose:** your own sign-in settings, and what your roles let you do.

**On screen:** *You* (name, email, last sign-in and where from, what you can do,
approval limit); *Roles* (each role you hold and at which entities, then every
permission they give you together); *Second sign-in step* (on or off, the method,
recovery codes left); *Password*; and *Recent sign-in activity*.

**What you can do:** change your password (needs the current one); set up, switch
or remove your second step; make a new set of recovery codes, which cancels the
old ones. Removing the second step and new codes both ask for your password.
Where the instance requires a second step, it can be switched but not removed.

---

## Overview

### Dashboard (Finance overview)

**Purpose:** the finance landing page — what needs a decision, how grants are
burning, where funds sit, and whether the books hold together.

**On screen:**
- **Header** — today's date, title *Finance overview*, and header buttons
  **Board pack** and **New journal**.
- **Stat cards** — headline metrics for the period.
- **Left column:**
  - **Needs a decision from you** — a queue of items awaiting your action, each a
    clickable row with a status dot, title, detail, value and a call to action.
  - **Grant burn against elapsed time** — each award with a burn bar and an
    elapsed-time marker; spend below the marker means the award is running behind.
- **Right column:**
  - **Where the money sits** — fund balances with proportion bars.
  - **Does the book hold together** — integrity checks, each marked ✓ or ! with a note.
  - **Recent changes to the rules** — a short audit-log feed with a link to the full log.

**What you can do:** open the **Board pack** (a printable governance summary with
headline figures, contents and matters for the board; prints or saves as PDF);
start a **New journal** in the shared journal editor; click any queue, grant, fund
or check to jump to the underlying page.

### Period close

**Purpose:** lock an accounting period once its control checklist is settled;
reopen periods; and produce a printable close pack.

**On screen:**
- **Header** — title *Period close*, with **Close pack** and either **Close
  \<period>** (shown blocked if there are outstanding items) or **Reopen \<period>**.
- **Year switcher** and **period (month) switcher**.
- **Close checklist** — one row per control task. Some are confirmed by hand (a
  checkbox, owned by a named person); others are answered automatically from the
  ledger (✓ or !). Each shows a status pill: *Settled*, *Blocking* or *Outstanding*,
  with a deep link to fix it.
- **Sidebar cards:** *Readiness* (percentage, progress bar, lock-from date);
  *What is in the period* (totals and whether debits and credits agree); *Close history*.

**What you can do:** pick a year and month; tick/untick confirmation tasks;
**Close** the period (blocked with an explanation if anything is outstanding);
**Reopen** a closed period (a reason is required and kept on the audit log); open
the **Close pack** and **Print** or **Export PDF** (trial balance, financial
position, income and expenditure, fund movements, bank reconciliations, journal
listing, budget vs actual and the signed checklist).

---

## Accounting

### Chart of accounts

**Purpose:** the master chart, browsable as a tree or a flat list, with an editor,
CSV import and CSV export.

**On screen:**
- **Header** — title, with **Import CSV**, **Export** and **+ New account**.
- **Stat cards**, then a **toolbar** with a code/name search, an account-type
  filter, a *Tree / Flat* toggle and *Expand all / Collapse all*.
- **Accounts grid** — columns: Code, Account, Type, Statement, Normally (Dr/Cr),
  Restriction, Fund, Programme, Funder, YTD balance (KES), Status (● Active /
  ○ Archived). Paginated ten a page, with a footer summary.

**What you can do:** search, filter and switch views; open an account (or **+ New
account**) in the **account drawer** — Identification, Classification (IFRS),
required Dimensions (fund, programme, grant, funder), Posting rules and, when
editing, Balances. **Archive** an account (postings are kept), **Save**/**Create**.
**Export** the current view to CSV. **Import CSV** through a two-step wizard that
does a dry run first (column mapping, update-or-skip existing codes, a per-row
preview with rejects) before committing. Editing is limited to the Finance Manager.

### Programmes

**Purpose:** manage programmes — the third coding dimension on every posting line,
alongside account and fund — with budget and burn tracking.

**On screen:**
- **Header** — kicker *Accounting*, title *Programmes*.
- **Stat cards**, a **shared-cost allocation banner** (green when the allocation
  base totals 100%, amber when not), then a **search** and **status tabs**.
- **Programmes table** — Code; Programme (name, manager, running since); Status;
  Share (allocates out, or a percentage); Burn (bar coloured by burn rate);
  Budget; Actual; Committed; Available (red when overspent).

**What you can do:** search and filter; open a **programme detail drawer** with
its purpose, a facts list (manager, status, share of shared costs, budget, actual,
committed, available, awards, staff), the funds charged there, and whether it can
be deactivated (blockers are listed; a programme with posted expenditure can be
deactivated but never deleted).

### General ledger

**Purpose:** view one account's posted movements for a period, with a running
balance and segment filters.

**On screen:**
- **Header** — title, with **Open in chart of accounts**, **Export** and
  **+ Post journal**.
- **Filter row** — Account, Period, Fund, Programme, Grant/award, plus a
  reference/narration search — and a **summary band** of metric cards.
- **Ledger grid** — Date, Reference, Narration, Source, Fund, Programme, Grant,
  Debit, Credit, Balance. It opens with *Opening balance brought forward* and ends
  with *Closing balance — \<period>*. Restricted postings with no award are flagged.
- **By award** chips and a footer stating the currency and normal balance.

**What you can do:** change any filter or search (changing account resets the
segment filters); **Export** the account to CSV; **+ Post journal** (pre-seeded
with the current account/fund/programme); open an entry in the **entry drawer**
(posting date, source, status; entry lines with a balance check; segments; audit
trail) and **Reverse entry** or **View source document**. In closed periods the
action buttons are hidden with a note that corrections are made by a new journal.

### Journals

**Purpose:** the register of double-entry journal batches through prepare →
approve → post, plus recurring templates. Nothing reaches the ledger until it
balances and a second person approves it.

**On screen:**
- **Header** — title, with **Recurring templates** and **+ New journal**.
- **Stat cards**, a **filters bar** (status tabs with counts, a reference/
  narration/preparer search, a Type dropdown, a hint).
- **Journals grid** — Reference, Date, Type, Narration, Fund, Programme, Grant/
  award, Lines, Amount, Status (pill), Prepared by. Restricted lines missing an
  award are flagged.

**The journal editor** (opened by clicking a row or **+ New journal**) is a shared
drawer used across Journals, the General ledger and the Dashboard:
- Narration, period, posting date, journal type and source document; a preparer
  who cannot be reassigned.
- A **line grid**: Account, Line description, Grant/award, Fund, Programme, Debit,
  Credit. The chosen award constrains which funds and programmes each line may carry.
- A **running balance check** ("Entry balances" / "Out of balance by …"), warnings
  for restricted-fund lines with no award, an approval memo, supporting-evidence
  attachments and an audit trail.
- **Footer actions by status:** Save draft, Submit for approval, Approve and post,
  Reject (with reason), Discard, Reverse entry — each shown only when your role
  and the record's status allow it. A posted entry is immutable and is corrected
  by reversal.

**Recurring templates** open in their own drawer: a list of templates (frequency,
rule, amount, next due), the option to *save a recent entry as a template*, and a
detail view with the lines generated at each run and a run history. You can
**Pause / Resume** a template and **Generate entry now**; generated entries still
require approval.

### Payables

**Purpose:** supplier bills from capture through approval, scheduling and payment,
including withholding tax (WHT) held for remittance to KRA.

**On screen:**
- **Header** — title, with **Remit WHT to KRA** and **+ New bill**.
- **Stat cards**, a clickable **ageing strip** (buckets filter the list), a
  **filter bar** (status tabs with counts, a supplier/bill/PIN search, a Fund
  dropdown), a **selection bar** for bulk actions and an **authority bar** for
  high-value releases.
- **Bills table** — checkbox, Bill, Supplier, Invoice, Due, Fund, Programme,
  Gross, WHT, Net due, Status, Age (flagged late). Footer: WHT by the 20th, VAT
  at 16%, payments through KCB and M-Pesa.

**What you can do:** filter by status/age/fund/search; select bills and bulk
**Approve / Schedule / Pay now / Clear**; open a bill's **drawer** (coding, tax
and settlement, payment method, ledger links, approval trail) to Reject, Approve,
Schedule, Pay or change the payment method; release payments above the in-system
limit by recording an external authority reference; **Remit WHT to KRA**; capture
a **new bill** (supplier, validated KRA PIN, spend category, invoice number with a
duplicate check, terms, budget line, coded lines, WHT rate with an override reason,
and a live totals summary).

### Receivables

**Purpose:** donor claims and other invoices from draft through issue to receipt,
including the doubtful-debt allowance, write-offs and recoveries.

**On screen:**
- **Header** — title, with **Aged statement** and **+ New donor invoice**.
- **Stat cards**, an **ageing strip** on the outstanding balance, an **allowance
  card** (*Allowance for doubtful debts 1215* — held vs what the ageing rates
  call for), a **filter bar** (status tabs, a donor/invoice/grant search, a Fund
  dropdown), and a **selection bar**.
- **Invoice table** — checkbox, Invoice, Donor, Issued, Due, Fund, Programme,
  Invoiced, Received, Outstanding, Status, Age.

**What you can do:** filter/search; bulk **Issue / Send reminder / Record receipt
in full**; open an invoice **drawer** to issue, remind, record part or full
receipts, set or change the allowance, write off (with a reason) or record a
recovery after write-off; edit and **Apply** the ageing allowance rates; export
the **Aged statement**; build a **new donor invoice** in the claim builder
(choose an award or "other income", claim expenditure line by line up to what has
actually been spent, add capped indirect-cost recovery, set the currency and rate,
see a live total).

### Procurement

**Purpose:** the procurement cycle — requisition → approval → RFQ → purchase order
→ goods received → supplier bill — with budget and three-quotation controls.

**On screen:**
- **Header** — title, with **+ New requisition**.
- **Stat cards**, then a **view switcher**: *Requisitions*, *Purchase orders*,
  *Goods received*, *Suppliers*.
- **Table** (columns change per view). For *Requisitions*: Requisition, Item,
  Requester, Programme, Grant/award, Fund, Raised, Need by, Value, Budget left
  (red when over), Status. Other views cover POs, GRNs and the supplier register.

**What you can do:** switch views; filter/search requisitions; open a
**requisition drawer** and move it through each step — **Submit for approval**,
**Approve**, **Reject**, **Issue RFQ**, **Raise purchase order** (pick the
supplier; single-source justification if fewer than three quotes), **Record goods
received** (signed-for-by and note), **Raise supplier bill** (creates a bill in
Payables). Create a **new requisition** with lines, quotations (attachable, one
selected) and a live budget check.

### Bank reconciliation

**Purpose:** reconcile one cash account's bank statement for a period against the
cash book.

**On screen:**
- **Header** — title, with **Upload statement**, **Reopen reconciliation** (when
  signed off), **Auto-match** and a **Complete / sign off** button.
- **Picker row** — Account and Statement period dropdowns, a statement reference
  and a read-only note when the period is closed.
- **Stat cards** and a **selection bar** that shows the two selected sides, their
  sums and whether they agree.
- **Two side-by-side cards** — **Bank statement** (left) and **Cash book**
  (right). Each line has a checkbox, date, description, tags and amount. Statement
  lines show *matched* (with **unmatch**), *awaiting approval*, or a **journalise**
  button; cash-book lines show *cleared*, *not yet presented*, or *raised from the
  statement*.
- **Reconciliation statement card** — ending in a nil or gap line, with a footer
  saying whether the statement and cash book agree.

**What you can do:** pick the account and period; **Auto-match**; manually select
balancing lines on both sides and **Match** / **unmatch**; **journalise** bank-only
entries for approval; **Upload** a CSV statement (checked against its format, then
loaded with a preview of new/duplicate/unreadable rows); **Complete** the
reconciliation when there is no difference; **Reopen** a signed-off period.

### Asset register

**Purpose:** the fixed-asset register — cost, accumulated depreciation and custody
— with a monthly depreciation run and the capitalise / add / dispose workflows.

**On screen:**
- **Header** — title, with **Asset count sheet** (link to Asset verification),
  **+ Add donated or found asset** and the monthly **depreciation-run** button.
- **Stat cards**, a **filter bar** (status tabs, a search), and the **register
  table** — Tag, Asset, Funder, Cost, Depn to date, Net book value, Monthly,
  Status (● In use / ◐ Fully depreciated / ○ Disposed).
- **Coming onto the register** — purchases waiting to be **Capitalised**, additions
  awaiting approval (**Withdraw** / **Approve and post**) and a "found in the count"
  list.
- **Disposals** — pending disposals (Withdraw / Approve and post) or posted ones.
- **Register against the ledger** — a tie-out of each control account
  (Per register / Per ledger / Difference) with an agree / does-not-agree footer.

**What you can do:** filter/search; **run monthly depreciation**; **capitalise**
purchases; **add** donated or found assets; approve or withdraw pending additions
and disposals; open an asset **drawer** (facts, a drill-down to its cost account, a depreciation
schedule, the asset's own share of 1390 and 5350 — see below — and history) and **Propose disposal** (method, date, proceeds, board
minute, donor consent where title reverts, with a live gain/loss and the journal
that will post on approval).

**Disposal posting.** On approval by a second person one journal posts: Dr bank
(proceeds), Dr 1390 (the asset's accumulated depreciation — brought forward plus
its posted monthly charges, as the register holds it), Cr the class cost account
(cost), and the difference to 4250 (gain) or 5360 (loss). Every line carries the
asset's own fund, programme and grant — where its cost was debited and its
depreciation credited — so the release clears them there and proceeds from a
donor-funded asset stay in the donor's fund.

**Depreciation in the ledger (drawer).** 1390 and 5350 are posted by programme,
so the ledger pools every asset. The drawer reads one asset's share from the
depreciation runs it was charged in (`depreciation_entries`):
- **1390 · Accumulated depreciation** — its balance: the amount brought forward
  when the asset came onto the register (`opening_accumulated_depreciation`), plus
  every posted monthly charge, less what a posted disposal released (to nil).
- **5350 · Depreciation** — the charge in the financial year the books are in,
  with the months charged and the life-to-date total from monthly runs.
- A small ledger: brought forward, one row per run (period, journal, 5350 Dr,
  1390 Cr, running 1390 balance) and the release on disposal. Only the latest six
  monthly charges show; earlier ones fold into one row that expands on click.

### Asset verification

**Purpose:** record a physical count against the register and surface exceptions
before the period closes.

**On screen:**
- **Header** — kicker *Accounting*, title *Asset verification*.
- **Stat cards**, an optional warning banner, then a **Round** card with a "counted
  X of Y" caption, a progress bar, a search, a location dropdown and status tabs.
- **Count table** — Tag, Asset, Class, Location, Custodian, Result (Sighted / Not
  found / Condition issue, with a note), NBV.
- **Exceptions card** — the unresolved findings with their carrying value and the
  accounting action each requires.

**What you can do:** filter by location, status or search; open an asset to
**record the count** (result plus a note — anything other than *Sighted* requires
a note) and **Save result**; review exceptions and their required accounting action.

### Payroll

**Purpose:** run the monthly secretariat payroll and carry it through prepared →
approved → posted → remitted, showing the ledger impact before posting.

**On screen:**
- **Period toolbar** — previous/next month steppers around the current period,
  and actions: **Edit roster** plus (by state) **Send for approval**, **Approve
  run** or the post button, and a *Posted ✓* indicator.
- **Stat cards**, an optional blocked-action banner, and the **register** (search,
  status tabs, columns: Staff no, Name and post, Charged to, Gross, PAYE, "NSSF,
  SHIF, levy", Other, Net pay).
- Two cards side by side: **Statutory remittances** (amounts and due dates, with a
  remit button) and **Where the cost is charged** (allocation bars).
- A **journal preview** showing the Dr/Cr lines the run will post.

**What you can do:** step through months; **Edit roster** in the staff editor
(four modes — New starter, Salary change, Re-allocation, Leaver — each carrying
the run it takes effect from); **Send for approval**, **Approve run**, **post**
the run; **remit** the statutory liabilities; open any staff member's **payslip**
(earnings, deductions, net pay, employer's own cost, and how the cost is charged).

### Staff advances

**Purpose:** track money advanced to staff and observers as a receivable on
account 1220, from request → approve → issue → chase → clear, with ageing to the fore.

**On screen:**
- **Header** — kicker *Accounting*, title *Staff and observer advances*, with
  **+ Request advance**.
- **Stat cards**, an **ageing buckets** strip, and a **control-tie card** (green
  when the register agrees to 1220, amber when it leads the ledger).
- **Register** — search, an age-bucket filter, status tabs, and columns: Advance,
  Holder (Staff/Observer), Programme, Grant/award, Advanced, Accounted,
  Outstanding, Due, Ageing, Status.

**What you can do:** **request** an advance (holder, type, purpose, grant,
programme, amount, surrender-due date); open an advance **drawer** to **Approve**,
**Issue funds** (choosing the pay-out method), **Send reminder**, **Recover from
payroll** or **Reject**; **Surrender with receipts** (code the receipts against
programme lines, then choose whether unspent cash is returned or stays outstanding).

---

## Funds and grants

### Funds

**Purpose:** the statement of changes in funds for the working year — what each
fund opened with, received, spent and had transferred, and what it closes on.
Restricted balances can only be spent on what the donor agreed, and unspent amounts
are returnable when the grant closes.

**On screen:** header (*Funds*) with **Fund transfer** and **Statement of funds**;
five stat cards (total fund balance, unrestricted, restricted with its utilisation,
endowment, and the unspent balance of restricted funds closing within 90 days);
class tabs, a search and a note of funds closing soon; then a table — Fund (with
its purpose), Class, Funder, Opening, Income, Expenditure, Transfers, Closing,
Utilisation (bar and %) and Spend by, which turns rust inside 90 days. A totals
row carries the funds forward, and the footer counts transfers not yet posted.

Every figure comes from the posted ledger, and the columns articulate: opening +
income − expenditure + transfers = closing. Opening includes the year's balances
brought forward. An overdrawn fund's closing balance shows in brackets, in rust.

**The fund drawer:** click a fund for its movement for the year, how much of what
it had available is spent, its restriction terms (funder, grant reference,
agreement period, spend-by date, conditions), the programmes charged to it, and
the ledger accounts carrying its movement — each opens in the general ledger. A
fund closing within 90 days, nearly spent or overdrawn says so at the top.

**Fund transfer:** moves a balance from one fund to another on a board minute.
Choose the funds, the amount, the board minute reference and the reason; the form
says at once whether the transfer is permitted:

- A donor-restricted fund cannot be transferred out of — that needs the donor's
  written consent, recorded as a grant amendment.
- Endowment capital is permanently maintained and cannot be transferred.
- No transfer may exceed what the fund has, less transfers out of it already
  awaiting approval.

**Post transfer** raises a journal and sends it for approval. It is approved in
**Journals** by the approver the *Inter-fund transfers* rule names in Settings →
Approvals — the Executive Director, whatever the amount — and the balances move
only once it posts. The journal debits the giving fund's balance account and
credits the receiving fund's, and moves the cash between the two funds on the
operating bank account, so the bank balance itself does not change. Its lines
cannot be edited in Journals; a returned transfer is discarded and raised again.

**Statement of funds** downloads the statement as a CSV for the board pack.

**Who can do what:** anyone can view. Anyone who prepares journals can raise a
transfer; the Executive Director cannot raise one, only approve it.

### Grants and awards

**Purpose:** the award portfolio from proposal to close-out. Burn is read against
elapsed time, so an award that is behind schedule shows before the funder asks.

**On screen:** header (*Grants and awards*) with **Reporting calendar** and
**+ Record award**; five stat cards (live portfolio, received with what is still
receivable, spent, unspent commitment, reports due within 45 days); status tabs and
a search; then a table — Award (title, reference and lead programme), Funder,
Period, Award value, Received, Spent, Burn vs elapsed, Next report and Status. The
burn bar fills with what is spent and carries a black marker for the share of the
agreement period gone: rust when spending runs more than 8 points ahead of time,
amber when it lags more than 15 behind. A next report due within 45 days is rust.

**The award drawer:** click an award for its value, received and unspent
commitment; burn against elapsed time with what it means; budget against actual by
line, with overspent lines in rust; the disbursement schedule (Received, Due within
30 days, Scheduled); its reporting calendar (Submitted, Queried, Overdue, Due within
45 days, Scheduled); the agreement terms — currency and agreement rate, period, the
fund it is held in, the indirect cost cap and how long records are kept; and its
compliance conditions. A suspended award, one closing with money unspent, or one
with a report due soon says so at the top. From the drawer: **View in ledger**,
**Record disbursement** (on Receivables, filtered to the award), **Prepare donor
report** (on Donor reports, filtered to the award), and on a pipeline award
**Convert to award**.

**Reporting calendar:** every report still owed to a donor across the portfolio,
soonest first, with how many days are left or how late it is.

**Record award:** six steps from the signed agreement. Nothing is written until the
last.

1. **Agreement** — funder (a new one is added to the register), award reference,
   title, lead programme and any others the award also funds, grant manager,
   currency and value in KES (with the agreement rate for a foreign currency), start
   and end dates, and whether it is **Pipeline** (unsigned) or **Active**.
2. **Fund** — open a new fund for it (the normal case) or attach it to an existing
   one. A new fund is restricted, designated or an endowment, and rolls up into the
   Grant, Capital, General or Endowment Fund accordingly; presenting restricted
   money in the General Fund needs a reason, recorded on the audit trail. Attaching
   donor money to an unrestricted fund is warned against.
3. **Budget lines** — expense and asset accounts, each once, totalling the award
   value exactly. Lines on 53xx accounts are indirect and support costs and may not
   exceed the indirect cost cap.
4. **Disbursements** — when the donor expects to pay, totalling the award value.
5. **Reporting** — the reports the donor expects, each with the period it covers
   and its due date. The presets (quarterly financial, narrative, close-out) date
   themselves from the agreement start.
6. **Conditions and review** — the agreement's conditions, a summary, and what
   recording it does.

Recording opens the fund (or attaches the existing one) and writes the award, its
budget lines, disbursements, reports and conditions in one step. The award's budget
is what the drawer measures actuals against; the organisation budget that the
procurement check reads changes only through a budget revision under **Budgets**.
Disbursements are claimed from the donor under **Receivables** when they fall due.

**Who can do what:** anyone can view. The Finance Manager records awards and
converts pipeline awards — recording one opens a fund and sets the budget it is
held to.

### Budgets

**Purpose:** the approved budget against actual, phased month by month, with the
versions a year's budget goes through. Variance is read against each line's
phasing to date (its profile: even, election peak, front- or back-loaded), not
the annual figure, so a seasonal programme is not flagged for spending in season.

**On screen:**
- **Header** — kicker *Funds and grants*, title *Budgets*, and **New financial
  year**, **Budget revision** and **Export variance report**.
- **Stat cards** — annual budget, phased to date, actual to date, variance to
  phasing, lines needing attention.
- **Version banner** (not shown for the approved budget) — where a working
  revision or a draft stands, the movements applied to it, and **Submit for
  approval**, **Approve**, **Send back** or **Discard**.
- **Filters** — Budget version, Group by (Account group / Programme / Fund),
  Compare to (phasing to date / full-year budget), and a search.
- **Variance table** — Budget line, Fund, Programme, Annual budget, Phased (or
  full-year), Actual to date, Variance (adverse in red), Consumed (bar with a mark
  at the phasing), Status (Over / Watch / On track / Underspent). Group rows carry
  subtotals; a total row closes the table.

**What you can do:**
- Open a **line** to see its monthly phasing against actual, what each version
  held for it, the revision rules of its fund, **View postings** in the general
  ledger, and **Request revision**.
- **Move budget between lines** into the working revision: the lines must be in
  the same fund and the same grant agreement; no more than is uncommitted can be
  moved; indirect-cost lines on a grant cannot be increased; and more than 10% of
  a grant-funded line (counting earlier movements in the revision) needs the
  funder's written consent first. An authority reference and a justification are
  required.
- **New financial year** — derive next year's budget: grant lines from each award's
  remaining ceiling, apportioned by the months of the award that fall in the year;
  core lines rolled forward with an uplift. It is raised as a draft.
- **Export** the variance report for the version, grouping and basis on screen, as CSV.

**Who can do what:** anyone can view. Moving budget, raising a year's budget,
submitting and discarding need `journal.prepare`. A revision is approved under the
*Budget revisions* approval rule (the Finance Manager; above its threshold with the
funder's consent recorded); a year's budget by whoever holds `period.authorise`
(the Executive Director). Nobody approves a version they prepared, submitted or
moved money in. Approving one supersedes the year's approved version — only one
is approved at a time, and procurement, payables and the programme register read
the approved version of the working year.

### Donor reports

**Purpose:** expenditure reports to funders, built from the ledger rather than
re-keyed, and their path from draft to the funder's acceptance.

**On screen:** header (*Donor reports*) with **Report language**, **Reporting
calendar** and **+ New report**; five stat cards (Open reports, Due within the
report warning window, Overdue, Reported this year, Not reconciled); status tabs
with counts (All, Draft, In review, Submitted, Queried, Overdue, Accepted), a
search and a tie hint; then the register — Report (title, reference and award),
Funder, Period, Type, Reported, Ledger actual, Reconciled (✓ Ties, the
difference, or *No figures yet*), Due (highlighted within the warning window) and
Status — ten a page. Open and queried reports come first, soonest due at the top.

**A report** opens on three tabs:

- *Expenditure* — the schedule by account: budget, this period, cumulative and
  the share of budget used, with the fund position (received, spent, unspent).
  A draft can **Refresh from ledger**.
- *Reconciliation* — reported expenditure against posted actuals, the
  difference, funds received and the unspent balance; the supporting schedules
  attached; and, where a manual adjustment stops the report tying, **Remove the
  adjustment**.
- *Compliance* — the funder's compliance statements, its queries (with a
  response box while one is open), the audit trail and a reviewer note.

**The path:** Draft → **Send for review** → **Submit to funder** (or **Return to
preparer** with a reason) → Submitted → **Record funder query** / **Respond to
query** as often as needed → **File acceptance letter** → Accepted. A draft past
its due date shows as Overdue.

**Rules:**
- A report's figures are a snapshot of the ledger for its award and period:
  expense accounts in donor reports (and any account the award budgets for) with
  postings coded to the award, cumulative to the period's end. The snapshot is
  retaken only while the report is a draft, and retaking it removes any manual
  adjustment.
- What the funder is told is the snapshot plus any manual adjustment. A report
  that does not tie cannot be sent for review or submitted.
- A financial or close-out report needs figures before it goes for review.
- Preparing (new report, refresh, review, queries, acceptance, the note, the
  delivery language) needs `journal.prepare`. Submitting and returning need
  `journal.approve`, and the preparer can never submit their own report.
  Submitting confirms the compliance statements. Nothing can be changed in the
  consolidated view.
- A new report's period must fall inside the award, and it cannot fall due
  before the period ends. References run DR-yy-nnn by the year it falls due, the
  same series the award wizard uses for its reporting calendar.

**Report language** sets the language each funder's pack is delivered in and
previews its cover. Headings translate; figures, dates and currency stay in the
reporting locale. **Export pack** downloads the schedule, reconciliation,
compliance record, queries and documents list as CSV.

---

## Insight

### Cashflow forecast

**Purpose:** a thirteen-week cash forecast that highlights whether unrestricted
cash can cover core costs.

**On screen:** header (*Cashflow forecast*) with a blurb naming the week the
forecast starts, and **Reset** and **Export for Board**; five stat cards (cash on
hand and the accounts it is held in, of which restricted, unrestricted cover in
weeks, lowest unrestricted point, closing position at the end of the horizon); a
segmented control for the scenario (*Base case*, *Donor delay*, *By-election
surge*) and a **Hold discretionary spend from week 5** checkbox; a red warning when
unrestricted cash goes negative; then one row a week — Week, Receipts (green),
Payments (amber), Net, Shape and driver (a receipts bar and a payments bar scaled
to the largest week, with what drives the week beneath), Closing cash, Of which
unrestricted (red and bold when negative). The footer states the horizon, the
opening balance and the scenario, and the bar legend.

**What you can do:** switch scenario and toggle the discretionary hold; both re-run
the projection on the API (`GET /api/cashflow`). **Reset** returns to the base case
without the hold. **Export for Board** downloads the projection on screen as CSV
(`GET /api/cashflow/export`, same parameters). The table is read-only.

**Data:** the latest `cashflow_forecasts` row of each entity in scope and its
`cashflow_forecast_weeks`; in the consolidated view the entities' forecasts are
added together week by week. The accounts named under *Cash on hand* are the
active bank and mobile-money accounts in scope.

### Reports

**Purpose:** the financial statements for any period, against a comparative:
statement of financial position, statement of activities, statement of cash flows
(indirect method) and the trial balance.

**On screen:** the statement's title and blurb with **Print** and **Export**; a
segmented control for the four statements; **Period** (year to date, completed
quarters, months of the working year, earlier years held) and **Comparative**
(*Prior year*, *Approved budget* on activities only, *None*); on activities,
**Split by restriction class** (unrestricted, restricted, total). At the right, the
statement's check (*Ledger in balance*, *Statement balances*). The statement card
has the organisation and statement name, the date and comparative, a sticky column
header, sections with account lines, subtotals and totals, and numbered notes; the
foot strip names the statement and period, and the basis (IFRS, functional
currency, and whether the period is closed).

**What you can do:** switch statement, period and comparative (all kept in the
address, so a view can be linked); click an account line to open its postings in
the general ledger for the same months; print the statement; export it as CSV.

**Where the figures come from:** posted journals only. A statement of position
reads balances as at the period's last day, with the year's surplus derived from
income less expenditure (3900 is never posted); activities read income and
expenditure within the period, split by the restriction class of each line's
fund; cash flows read every movement in the period except the balances brought
forward, and close on the cash and bank accounts. Statement membership is read
from the chart. A financial year with nothing posted on the ledger is read from
`legacy_balances` — the year before the ledger started, as the previous system
kept it — so the first year carries comparatives; that year closes on the
balances brought forward into the ledger's first year (OB-26-0001 in the demo).

### Settings

**Purpose:** configure the organisation and its entities, the ledger, controls,
posting accounts, currencies, taxes, terms and reminders, approvals, bank-statement formats, opening balances, integrations, payroll scales,
appearance, language/translation, users and the audit log. Changes are edited into one draft and saved together.

**On screen:** a left navigation of sections and a content pane on the right. The
top-right shows **Discard** (when there are unsaved changes) and a save button that
reads **Save changes** when dirty or **Saved** when clean. Each section is shown and changed by its own permission
(see [authentication.md](authentication.md#permissions)); the Finance Manager holds them all out of the box. Someone who can see
a section but not change it gets a read-only view and a banner naming the permission it needs. A
warning appears if you try to leave with unsaved changes.

**Sections:**
- **Organisation** — registered name, short name, KRA PIN, NGO Board registration;
  and the entities the accounts consolidate. An entity's name, type, functional
  currency and status are edited in place, and a new one is added below the table
  and reaches the ledger with the rest of the draft. An entity is never deleted:
  every posting carries the entity it was made against, so one that closes is made
  dormant, which keeps its history and drops it out of the lists that offer a
  choice. The code is fixed once saved, since user access and imported files refer
  to it; the head office cannot change type or go dormant; there is only ever one
  of it; and an entity that holds postings can no longer change its functional
  currency.
- **Organisation names on documents** — the registered name printed on the close
  pack, the board pack and a donor report's cover (the cover shows the short name
  with it) is the one held here, never a fixed name.
- **Ledger** — reporting framework, functional currency, year end, code length;
  posting-control toggles; the **posting accounts**; open-period chips. The posting
  accounts say where the postings the system makes itself go — trade payables for
  a bill, grants receivable for a claim, the allowance for a doubtful debt, the
  depreciation charge, the bank a payroll is paid from, the account a bank charge
  is taken to — grouped by module. Each role starts at its standard code and
  offers only active postable accounts of the right type (an expense for a charge,
  a liability for a payable). A change applies to postings from then on. An
  account that holds a balance to be cleared later (payables, goods received not
  invoiced, withholding tax, receivables, the allowance, staff advances, suspense)
  can move only once that balance is nil, or after it has been journalled across;
  otherwise the entries that clear it would land in the new account.
  Below them, the **asset classes** (`AssetClassRepository`): each class's name,
  tag prefix, the useful life a new asset starts with, and the cost account it is
  carried in. The cost account is where a purchase is capitalised from, where a
  donated or found asset of the class is debited on approval (the credit is the
  donated-assets or assets-found posting account), and what the register's cost
  is tied to. It must be an active asset account in the accumulated depreciation
  account's group (1300 in the standard chart), and it is fixed while the class
  carries assets at cost — a different account needs a new class. A class is
  added here but never removed, since every asset names its class; a new install
  has none until one is added. Changing a class's life or prefix affects only
  assets registered afterwards. Depreciation posts to the posting accounts, not
  to the class.
- **Segments** — which coding segments are required and shown on reports, with a
  warning if grant/fund segments are left optional; and, below them, the **funds**
  every posting is coded to. A fund is opened as it is entered rather than saved
  with the rest of the screen: the ledger refers to it, so it cannot wait in a
  draft while something is coded to it. Each fund has a class (unrestricted,
  restricted, designated or endowment) and the ledger column it rolls up to, and
  the two have to agree — an endowment is reported as one and rolls up as one, and
  the grant and capital columns report money held for a donor, so a fund in them
  cannot be unrestricted. A restricted fund can name the funder it is held for.
  Funds are never deleted; the table shows how many postings each carries.
- **Currencies** — indicative rates and enable/disable; add a currency. The base
  and in-use currencies cannot be disabled.
- **Taxes** — the VAT rate and the withholding rates a bill may carry (each with
  what it applies to; nil is always allowed), as the Finance Act sets them. Rates
  are dated: a change takes effect from a date chosen here, today or later, and a
  bill takes the rates in force on its invoice date. Bills already captured keep
  the tax they were entered with. A rate that a spend category defaults to cannot
  be removed. The rate history is listed below. Out of the box: VAT 16%;
  withholding 3%, 5% and 10%, from 1 January 2021.
- **Terms and reminders** — the supplier payment terms a bill can carry (14, 30,
  45 and 60 days to start; a bill raised from goods received takes 30 when it is
  offered), the days after issue a donor claim falls due (30), the days past its
  surrender date before an advance can be recovered from pay (14), and how far
  ahead a supplier shows as expiring (30), a donor report is flagged (45) and a
  tranche shows as due (30). A bill or claim already raised keeps its due date.
- **Approvals** — thresholds and approvers per transaction type, with a
  segregation-of-duties note, and the **procurement threshold** (KES 500,000 to
  start): above it a purchase needs three quotations before its purchase order,
  and a bill captured straight into Payables is paid only to a pre-qualified
  supplier.
- **Bank statements** — the cash accounts and the CSV statement format each uses.
  **Open a cash account** puts one on a ledger account: only postable asset
  accounts that do not already carry a cash account are offered, because a
  reconciliation agrees the statement of the account behind it and would not know
  which balance it had agreed if two sat on one. An account is a bank account,
  mobile money or petty cash; petty cash takes no statement, so it takes no
  format. Formats are defined below (column mapping, separators, date and decimal
  formats) and assigned to an account from the list. This section saves as you go.
- **Opening balances** — carrying an entity's permanent balances from a legacy
  system onto this ledger, when the system is first stood up. Balances are not
  stored as figures against accounts: everything this application reports is
  derived from posted journal lines, so an opening balance held anywhere else
  would be a second answer the trial balance could not see. What is carried
  becomes one journal, dated the first day of the first period kept here and
  marked as brought-forward figures the way the chart, the general ledger and the
  journal register already recognise.
  - *The file*: the trial balance exported from the old system as CSV. Columns are
    recognised by their headers and the usual synonyms — an account column is
    required, and either debit and credit columns or one signed balance column
    where a credit is written negative. Fund, programme, award and county are
    taken from the file where it carries them and from the account's own defaults
    where it does not. A report title above the header, a totals footer, nil
    balances and blank rows are all passed over.
  - *The template*: **Download the trial balance to fill in** issues a CSV with
    exactly the columns this reader expects, so a file built from it loads back
    with no mapping at all. Under the header sits the organisation's own chart —
    every postable account, already carrying the fund and programme code it
    defaults to — leaving the figures as the only thing to enter. Where the chosen
    period opens the fiscal year, income and expenditure accounts are left out of
    it, since a year that has ended carries its result in the accumulated fund.
    Enter the figures against the accounts that hold a balance and delete the rest;
    a nil balance brings nothing forward either way. Before the chart is imported
    the template is just its column headings.
  - *Checked before anything is written*: **Check the file** answers every rule
    and changes nothing. Every row the chart cannot place is named with its line
    number and the reason — an account that is not in the chart, a heading rather
    than a postable account, a fund or programme the ledger does not hold, an
    award that does not cover the line. The trial balance must balance, no
    restricted or endowment fund may open below zero, and every line in a fund
    that awards are held in must name its award, exactly as a journal must.
  - *Onto an empty ledger*: opening balances are refused where anything is already
    posted on or before the cut-off date, because the figures would count twice
    with no way to tell which entry was which. Where the period opens the fiscal
    year, only balance-sheet accounts carry — a year that has ended carries its
    result in the accumulated fund, not line by line. Converting mid-year, income
    and expenditure carry too, as the year to date. Figures are read in the
    entity's own currency; there is no rate table to translate against.
  - *Approved like any other entry*: **Carry the balances** writes a draft
    journal, not a posting. It is then submitted and approved on the Journals
    screen, so the person who loaded the conversion cannot also approve it and the
    approval limits apply — a conversion is usually worth more than a Finance
    Manager may approve, so it escalates. Until it is approved the whole load can
    be discarded and the draft disappears with it; once it has posted it is
    immutable like every other entry and a mistake is corrected by a further
    journal. The load is in the audit log either way.
- **Integrations** — the M-Pesa (Safaricom Daraja) connection: environment
  (sandbox or production), the paybill or till and the account number payers
  quote, the mobile-money account it settles to, the callback address and the
  addresses under it that Safaricom's results belong at, the four credentials, and
  the two services — collections and payments — with the per-payment ceiling and
  automatic receipt matching. A service switches on only once everything it needs
  is set, and the screen says what is missing; payments cannot be switched off
  while bills or advances are already committed to M-Pesa. **Check connection**
  asks Safaricom for an access token without moving money. Credentials are held
  encrypted and never shown again — only whether each is set and its last four
  characters. This section saves as you go.
- **Payroll** — benefits and the grade scale (each benefit becomes a payslip line
  and a grade column); add benefits and grades. In-use items cannot be disabled.
- **Appearance** — what the application calls itself and the colours it is drawn
  in.
  - *Name and logo*: the name in the sidebar and the browser tab, and the line
    under it. This is the name on the screen staff work in — the registered name
    that prints on statements is on the Organisation section, and the two need not
    match. A logo is uploaded (PNG, JPEG or WebP under 500 KB; an SVG is refused,
    since it can carry script as well as a picture) and saved as it is chosen
    rather than with the draft. With no logo the sidebar draws the initials of the
    application name.
  - *Interface theme*: one theme is held for the whole organisation, so everyone
    reads the shell in the same colours; picking one repaints the screen
    immediately as a preview, and it takes effect for everyone once saved. Five
    are supplied, and **Custom** takes two colours of your own — an accent and a
    menu colour — from which every other shade is derived, so a picked pair cannot
    come out as a palette whose parts do not belong together. Both carry light
    text, so each is held to a WCAG contrast ratio against white (4.5:1 for the
    accent, 7:1 for the menu); the measured ratio is shown as you pick, and
    anything below the mark is refused on save.
  - A theme reaches the whole screen, not just the sidebar: the accent, the
    buttons and links, the selected row, the tinted panels and the colours the
    page scripts draw badges and bars in all follow it. Two families deliberately
    do not. Urgent and warning keep their own colours, and so does **settled** —
    a posted journal, a signed reconciliation, a balanced period and an account
    that is live read the same green whatever theme is on, because "this one is
    done" has to mean the same thing on every screen. A theme changes nothing
    about who can post, approve or read a record.
- **Language and translation** — interface languages and coverage, fallback
  behaviour, raising wording for review, approving/declining translation requests,
  the locked reporting locale and terminology.
  - The language itself is each reader's, chosen in the top bar and kept in their
    browser. **Fallback behaviour** — whether a string with no approved translation
    shows the English source silently, shows it marked `EN`, or shows the string key
    — is the organisation's, not the browser's: one answer for everybody, saved with
    the rest of the draft, needing `settings.organisation`, and recorded in the audit
    log. It is set once so that two people reading the same screen agree on which
    wording their reviewer has actually approved.
- **Users** — everyone with access: their roles (as chips), entity access, last
  sign-in, status and whether they have a second step. **Invite user** takes a
  name, an email, one or more roles and the entities; the person is emailed a
  link to choose a password. **Manage** opens a drawer to give a person any number
  of roles, each at all entities or only some, and to suspend or reinstate them,
  reset a second step they have lost, or email a new link. Changing any of this
  needs `users.manage`. Saves as you go.
- **Roles** — the roles, as cards: each one's permissions and who holds it.
  People get permissions only by holding roles, never directly. **New role** adds
  one (a name, a description, and permissions ticked from the catalogue by area);
  there is no limit to how many. Built-in roles keep their names, because the
  approval policy and close checklist refer to them, but their permissions can
  change. A role is deleted only when nobody holds it and no approval rule or
  translation lock uses it. Any change that would leave nobody active who can
  manage users is refused. Changes apply at once to everyone holding the role and
  go in the audit log.
- **Maintenance** — closing the application while it is worked on, and the
  periods that closure is planned for. The section is shown only to a role holding
  `settings.maintenance` — `settings.view` does not reach it — and that permission
  is also the key to the door: while the application is closed, its holders are the
  only people who can sign in or stay signed in, so it is given out as carefully as
  `users.manage`. Everybody else hears about a closure where it matters to them, in
  the notification sent when one is booked and the bar across the top of every page.
  - *Closing it now*: a message is asked for, because it is the only thing
    everybody else is going to read. From then on every other person is shown a
    plain "Closed for maintenance" page instead of the application, whether they
    were signed in already or are signing in now, and their work is where they
    left it when it opens again. It stays closed until somebody opens it.
  - *Planning one*: a window is a period booked in advance — when it starts, when
    it ends, and what the maintenance is for. Booking one notifies everybody at
    once, puts the window on every page for the seven days before it starts, and
    closes the application by itself while it runs, opening it again at the end,
    so nobody has to be at a keyboard at midnight. A window runs at most 72 hours
    and cannot overlap another. Windows are never deleted: one is cancelled — or
    ended early while it is running — and what was announced and what happened
    both stay on the record, with everybody told of the cancellation too.
  - Nothing here is drafted, and nothing here touches the ledger. Maintenance mode
    stops people reaching the application; it does not change what the books say.
    Every switch, booking and cancellation is in the audit log.
- **Audit log** — a read-only, searchable log of configuration changes, retained
  seven years and not editable from within the application.

**What you can do:** edit any section into the draft and **Save changes** or
**Discard**; manage currencies, approvals, payroll scales, segments and posting
controls; assign and define bank-statement formats; set up the M-Pesa integration
and check its connection; open funds and cash accounts; carry opening balances from
a legacy system and discard a conversion that has not yet been approved; add and
amend entities; name the
application, upload a logo and choose the interface theme; manage users and
languages; close the application for maintenance and book the periods it is
planned to be closed for. Saving is restricted to the Finance Manager.

---

## Cross-cutting behaviour

- **Permissions and the two-person rule** are enforced everywhere. Buttons for
  actions your role cannot take are hidden or disabled, usually with a short note
  explaining what is needed (for example, that approval must come from someone
  other than the preparer).
- **Ageing** is a recurring theme on Payables, Receivables and Staff advances —
  the ageing strips are clickable filters, and overdue items are visually flagged.
- **Ledger tie-outs** appear on the subsidiary ledgers (Asset register, Staff
  advances) so you can see at a glance whether the register agrees with its
  control account.
- **Search, filters and pagination** behave consistently: search boxes filter as
  you type, tabs filter by status, and lists show ten rows a page.
- **Every action confirms** with a toast, and most writes refresh the affected
  list or record so the screen always reflects the posted position.
- **Supporting documents** are uploaded from a picker on the form, which marks
  them **Required** or **Recommended**. A bill needs the supplier's invoice, a
  surrender its receipts, and an active award its signed agreement. A manual
  journal above 500,000, or an Adjustment journal, needs a document before
  approval. Above the three-quote threshold, only quotations with their document
  count. Claims, assets, counts and donor reports are asked for one. Documents open
  from the record, and can be added later with **Attach a document**. See
  [documents.md](documents.md).
