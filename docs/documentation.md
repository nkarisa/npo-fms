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
- **Drawers** — side panels that slide in to show or edit one record.
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
- **User menu** — the signed-in user with an **Act as** switcher for changing the
  acting role (used to demonstrate the two-person rule), plus account links and
  sign-out.

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
and disposals; open an asset **drawer** (facts, ledger drill-downs, a depreciation
schedule and history) and **Propose disposal** (method, date, proceeds, board
minute, donor consent where title reverts, with a live gain/loss and the journal
that will post on approval).

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

**Purpose:** list unrestricted, restricted and endowment fund balances with
utilisation.

**On screen:** header (*Funds*), stat cards, a search and class tabs, then a table
— Fund, Class, Funder, Opening, Income, Spend, Closing, Utilised (%).

**What you can do:** search, filter by class, and click a fund to open its detail.

### Grants and awards

**Purpose:** show every donor agreement with value, spend and a burn-rate-vs-time bar.

**On screen:** header (*Grants and awards*), stat cards, a search and status tabs,
then a list of award rows — funder, title, a status badge, a meta line (programme,
period, spent of value), and a bar with a burn fill and an elapsed-time marker.

**What you can do:** search, filter by status, and click an award to open its detail.

### Budgets

**Purpose:** compare the annual budget (phased against elapsed time) with posted
actuals, grouped.

**On screen:** header (*Budgets*), stat cards, a search and a grouping dropdown,
then grouped sections. Each group has a header (annual · actual · %) and a table —
Code, Name, Fund, Programme, Annual, Phased, Actual, Variance, Status (Over /
Watch / Underspent / on track).

**What you can do:** search budget lines and change the grouping dimension. The
table is read-only.

### Donor reports

**Purpose:** track financial reports to funders and whether each ties to the ledger.

**On screen:** header (*Donor reports*), stat cards, a search and status tabs, then
a table — Ref, Title, Funder, Period, Due, Status (Submitted/Accepted/Overdue/
Queried/Draft), Reported, Ledger actual, Ties? (Ties / Does not tie).

**What you can do:** search, filter by status, and click a report to open its detail.

---

## Insight

### Cashflow forecast

**Purpose:** a thirteen-week cash forecast that highlights whether unrestricted
cash can cover core costs.

**On screen:** header (*Cashflow forecast*), stat cards, an optional warning
banner, a **scenario** dropdown and a **hold** toggle, then a table — Week
commencing, Expected (with a *Grant receipt* badge on grant weeks), Opening, In,
Out, Net (green/red), Closing, Of which unrestricted (red and bold when tight).

**What you can do:** switch scenario and toggle the spending-hold lever; both
recompute the forecast. The table is read-only.

### Reports

**Purpose:** view the core financial statements drawn from the chart of accounts,
year to date.

**On screen:** a single card with tabs for the four statements — *Statement of
financial position*, *Statement of activities*, *Statement of cash flows* and
*Trial balance*. The trial balance shows Code, Account, Debit, Credit with a
totals row; the other three show grouped sections with headings, two-column tables
and section totals, followed by notes.

**What you can do:** switch between the four statement tabs. Read-only.

### Settings

**Purpose:** configure the organisation and its entities, the ledger, controls,
currencies, approvals, bank-statement formats, integrations, payroll scales,
appearance, language/translation, users and the audit log. Changes are edited into one draft and saved together.

**On screen:** a left navigation of sections and a content pane on the right. The
top-right shows **Discard** (when there are unsaved changes) and a save button that
reads **Save changes** when dirty or **Saved** when clean. Only the Finance Manager
can save; everyone else sees a read-only view and a banner explaining why. A
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
- **Ledger** — reporting framework, functional currency, year end, code length;
  posting-control toggles; open-period chips.
- **Segments** — which coding segments are required and shown on reports, with a
  warning if grant/fund segments are left optional.
- **Currencies** — indicative rates and enable/disable; add a currency. The base
  and in-use currencies cannot be disabled.
- **Approvals** — thresholds and approvers per transaction type, with a
  segregation-of-duties note.
- **Bank statements** — assign a CSV statement format to each cash account and
  define formats (column mapping, separators, date and decimal formats). This
  section saves as you go.
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
- **Users** — invite users, set roles and entity access, with warnings about
  missing or excessive privileged roles.
- **Audit log** — a read-only, searchable log of configuration changes, retained
  seven years and not editable from within the application.

**What you can do:** edit any section into the draft and **Save changes** or
**Discard**; manage currencies, approvals, payroll scales, segments and posting
controls; assign and define bank-statement formats; set up the M-Pesa integration
and check its connection; add and amend entities; name the application, upload a
logo and choose the interface theme; manage users and languages.
Saving is restricted to the Finance Manager.

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
