# NPO Finance Suite — User Manual

This is the user manual for the NPO (Non-Profit Organization) Finance Suite. It documents the whole
application page by page, with step-by-step instructions and screenshots. Each
functional area is written up as its own section; sections are added over time,
so some are marked *coming soon* below.

- **Application address:** `http://localhost:8090/`
- **Reporting framework:** IFRS · KES functional

---

## About the application

The NPO Finance Suite is a fund-accounting system for a non-profit organization
that reports under **IFRS** with the Kenya Shilling (**KES**) as its functional
currency. It
covers the full accounting cycle — chart of accounts, general ledger, journals,
payables, receivables, procurement, bank reconciliation, fixed assets, payroll
and staff advances — together with fund and grant management, budgets, donor
reporting, a cashflow forecast, financial statements and system settings.

Two principles run through the whole application:

- **Segregation of duties (the two-person rule).** The person who prepares a
  transaction cannot approve it; approval and posting come from a second person.
  The interface says so plainly whenever an action is blocked for this reason.
- **The ledger is the source of truth.** Subsidiary records (the asset register,
  payroll, advances, donor claims) are reconciled back to their control accounts,
  and the screens show whether they agree.

### How the screens are built

Every page shares a common shell (a left sidebar and a top bar). The body of each
page is rendered in your browser and shows the same building blocks throughout:

- **Stat cards** — a row of headline figures.
- **Tabs** — status filters such as *All*, *Draft*, *Approved*.
- **Tables** — the working list for each area, usually ten rows a page.
- **Drawers** — dialogs that open in the middle of the screen to show or edit one record.
- **Modals** — centred dialogs for building a new record.
- **Toasts** — brief confirmations after an action.

---

## Getting started

### Signing in and the workspace

1. Open the application's address in your browser (for example
   `http://localhost:8090/`). You are taken to **Sign in**.
2. Enter your email and password and choose **Sign in**. **Show** at the end of the
   password box reveals what you have typed, in case a long password went in wrong;
   **Hide** masks it again, and the next screen always starts masked.
3. Give your **second step**: the six-digit code your authenticator app shows, or
   the code just emailed to you.
4. The application opens on the **Dashboard** (Finance overview), or on the page
   you were trying to open.

**Your first time.** Your invitation email has a link. Open it, choose a password
of at least 12 characters (a few unrelated words are easy to remember), then set
up a second step. The checklist under the box ticks the rules off as you type, and
**Show** reveals what you have typed so you can check it before saving:

- **Authenticator app** (recommended). Install Google Authenticator, Microsoft
  Authenticator, Okta Verify or similar on your phone, add an account, scan the
  QR code on screen (or type the key under it), and enter the code the app shows.
- **Code by email.** A code is sent to you; enter it. You'll get a new one each
  time you sign in.

You are then shown ten **recovery codes**. Save them somewhere safe, away from
your phone: copy them into a password manager, download them or print them. Each
one gets you in once if you lose your phone or can't reach your email. They are
not shown again.

**Trouble signing in**

| What happened | What to do |
|---|---|
| Forgot your password | **Forgot your password?** on the sign-in page emails a link that works for an hour |
| Lost your phone | **Use a recovery code** on the second-step screen, then set up the app again under **My account** |
| Lost your phone and your recovery codes | Ask whoever manages users to **Reset second step** for you |
| *Too many wrong passwords* | The account is locked for a while — the message says how long. Wait it out, or reset your password, which unlocks it straight away |
| Signed out while working | You're signed out after 30 minutes without using the application. Sign in again and you return to the same page |

### My account

Open the user menu (your initials, top right) and choose **My account**. Here you
can see which roles you hold and what they let you do, change your password, set
up or change your second step, make new recovery codes (the old ones stop
working), and check your recent sign-ins. If one isn't you, change your password
and tell whoever manages users. **Sign out** is in the same menu.

### The navigation sidebar

The left sidebar lists every page, grouped into four sections:

| Group | Pages |
|-------|-------|
| **Overview** | Dashboard, Period close |
| **Accounting** | Chart of accounts, Programmes, General ledger, Journals, Payables, Receivables, Procurement, Bank reconciliation, Asset register, Asset verification, Payroll, Staff advances |
| **Funds and grants** | Funds, Grants and awards, Budgets, Donor reports |
| **Insight** | Cashflow forecast, Reports, Settings |

Use the collapse toggle (‹ / ›) at the top of the sidebar to shrink it to icons
only; hover an icon to see its label. The sidebar footer shows the reporting
framework, **IFRS · KES functional**.

### The top bar

Running left to right across the top of every page:

- **Breadcrumb** — the current group and page.
- **Interface language** — switches the language of the interface text only;
  posted amounts, dates and account codes are unchanged.
- **Entity picker** — the entity whose books you are working in. Every page
  shows, searches and records only that entity's records, and you act with the
  role you hold there. The picker lists the entities you hold a role at; if you
  hold one at a single entity it is shown as a label instead. Switching reloads
  the page, and the application remembers your choice for your next sign-in.
  Someone holding a role at every entity also sees **Consolidated**, which reads
  all the entities together. It is read only: to record or approve anything,
  choose an entity first. The chart of accounts, funds, programmes, suppliers,
  funders and most settings are shared by the whole organisation. A few settings
  are each entity's own, and Settings shows those of the entity you are in: its
  registered name and KRA PIN, its approval thresholds and procurement threshold,
  the bank, M-Pesa and cash accounts it pays from (**Settings → Ledger →
  Posting accounts**), and its M-Pesa short code. An entity that has set none of
  its own follows the head office's, and can go back to following them.

  Each entity opens its own bank, M-Pesa and petty cash accounts in **Settings →
  Bank statements**. Because every journal belongs to an entity, a branch's bank
  can sit on the same ledger account as the head office's (1110, say): each is
  reconciled against its own entity's postings to it. An entity holds one cash
  account per ledger account, and the cash accounts sharing one are of the same
  kind and currency. A ledger account another entity uses for something else —
  its grants receivable, say — cannot become a cash account.
- **Search everything (⌘K)** — a command palette that searches journals,
  accounts, suppliers, donors and awards.
- **Financial-year pill** — the current FY and whether it is open.
- **Notifications (◎)** — a bell with an unread badge; the panel links each item
  to its record.
- **User menu** — who you are signed in as and your roles, **My account** and
  **Sign out**.

---

## Table of contents

General:

- [About the application](#about-the-application)
- [Getting started](#getting-started)
- [Supporting documents](#supporting-documents)

Pages, in sidebar order. ✅ means the section is written; *coming soon* means it
is planned.

| Group | Page | Status |
|-------|------|--------|
| Overview | [Dashboard (Finance overview)](#dashboard-finance-overview) | ✅ |
| Overview | [Period close](#period-close) | ✅ |
| Accounting | [Chart of accounts](#chart-of-accounts) | ✅ |
| Accounting | [Programmes](#programmes) | ✅ |
| Accounting | [General ledger](#general-ledger) | ✅ |
| Accounting | [Journals](#journals) | ✅ |
| Accounting | [Payables](#payables) | ✅ |
| Accounting | [Receivables](#receivables) | ✅ |
| Accounting | [Procurement](#procurement) | ✅ |
| Accounting | [Bank reconciliation](#bank-reconciliation) | ✅ |
| Accounting | [Asset register](#asset-register) | ✅ |
| Accounting | [Asset verification](#asset-verification) | ✅ |
| Accounting | [Payroll](#payroll) | ✅ |
| Accounting | [Staff advances](#staff-advances) | ✅ |
| Funds and grants | [Funds](#funds) | ✅ |
| Funds and grants | [Grants and awards](#grants-and-awards) | ✅ |
| Funds and grants | [Budgets](#budgets) | ✅ |
| Funds and grants | [Donor reports](#donor-reports) | ✅ |
| Insight | [Cashflow forecast](#cashflow-forecast) | ✅ |
| Insight | [Reports](#reports) | ✅ |
| Insight | Settings | Coming soon ([Users and roles](#users-and-roles) ✅) |

> For a page-by-page overview of the entire interface, see
> [`documentation.md`](documentation.md).

---

## Dashboard (Finance overview)

The Dashboard is the landing page. It is a finance "cockpit" that answers four
questions at a glance: what needs a decision from you, how the grants are
burning, where the money sits, and whether the books hold together. Everything on
it is drawn live from the ledger.

- **Page:** *Dashboard* (`http://localhost:8090/`)

### 1. Getting to the page

The Dashboard opens automatically when you sign in. From anywhere else, click
**Dashboard** at the top of the sidebar (under **Overview**).

![The Dashboard: header with Board pack and New journal, a row of stat cards, and the decision queue, grant burn, fund balances, integrity checks and audit feed](images/dashboard-full.png)

### 2. Reading the header and headline figures

![Dashboard header — the date, the Finance overview title, and the Board pack and New journal buttons above the stat cards](images/dashboard-header.png)

At the top:

- **Kicker** — today's date and whether the month is open (e.g.
  *Monday, 31 August 2026 · August open*).
- **Title** — *Finance overview* — with a one-line summary beneath it.
- **Board pack** button — opens a governance summary drawer (see §7).
- **New journal** button — opens the journal editor to post an entry (see §8).

Below the header is a row of **stat cards** with the headline position, such as
Cash and bank, Restricted funds held, Unspent commitment, Owed to suppliers and
Surplus year to date. Each card carries a short note (for example, how much of
cash is restricted, or how many bills are past due).

### 3. Needs a decision from you

The top-left card is your work queue — the items waiting on your action. Its
subtitle shows how many are open and the role you are acting as.

Each row shows a coloured status dot, a title, a short detail line, a value, and
a call-to-action link (for example *Review →*, *Approve →*, *Schedule →*,
*Reconcile →*, *Fix →*, *Run →*). Typical items include journals awaiting
approval, supplier bills above the delegated limit, suppliers past due, donor
reports that do not tie to the ledger, draft journals out of balance,
depreciation not yet run, and bank accounts not yet reconciled.

**To act on an item:** click its row (or the call-to-action link). You are taken
to the exact page where the work is done. When nothing is waiting, the card reads
*"Nothing is waiting on you."*

### 4. Grant burn against elapsed time

The lower-left card compares how much of each award has been spent against how
much of its period has elapsed. Each award shows the funder, the programme, the
money spent of the total, a **burn bar**, and an **elapsed-time marker**. When the
spend rate sits below the marker, the award is running behind, and the row says
so (for example, *"Behind schedule by 47 points"*). The footer summarises the
portfolio, e.g. *"5 live awards · portfolio burn 31% against 60% elapsed"*.

**To see an award in detail:** click its row to open **Grants and awards** filtered
to that award.

### 5. Where the money sits

The top-right card lists fund balances with a proportion bar for each, and a
heading total (e.g. *"96,982,600 across 9 funds"*).

**To open a fund:** click its row.

### 6. Does the book hold together

The middle-right card is a set of integrity checks, each marked **✓** (in order)
or **!** (needs attention) with a short note — for example the trial balance, the
statement of financial position, donor reports tying to the ledger, donor
traceability and closed periods. Click any check to jump to where it can be
investigated.

Below it, **Recent changes to the rules** shows a short audit-log feed of
configuration changes, with **Open the full audit log →** linking to
*Settings → Audit log*.

### 7. Producing the board pack

The board pack is a governance-level summary for the Board of Trustees.

1. Click **Board pack** (top right). A drawer opens in the middle of the screen.

![The Board pack drawer — headline cells, a numbered contents list, and matters for the board tagged In order or Attention](images/dashboard-board-pack.png)

2. Review the drawer:
   - **Headline cells** — the position at a glance (e.g. Cash held, Runway,
     Surplus to date, Restricted funds).
   - **Contents** — a numbered list of the pack's sections, each with its headline
     figure, and a note of how many matters need the board's attention.
   - **Matters for the board** — a risk list, each tagged **In order** or
     **Attention** (for example liquidity, donor reporting, budget control,
     restricted funds, period close and segregation of duties).
3. Click **Print** to open the print dialog (choose *Save as PDF* there to export).
4. Press **Esc** or click **×** to close the drawer.

### 8. Posting a new journal

**New journal** opens the shared journal editor as a drawer. Enter the narration,
period, date, type and lines, attach any supporting evidence, and save the draft
or submit it for approval. When you save from the Dashboard, you are taken to the
**Journals** page where the entry now lives. (The journal editor is documented in
full in the Journals section — *coming soon*.)

### 9. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the Dashboard | Sidebar → **Overview** → **Dashboard** (or sign in) |
| See what needs my action | Read the **Needs a decision from you** queue |
| Act on a queue item | Click the row / its call-to-action link |
| Check whether an award is on track | **Grant burn** card → compare the bar to the marker |
| See fund balances | **Where the money sits** card |
| Confirm the books are consistent | **Does the book hold together** card (✓ / !) |
| Brief the board | **Board pack** → **Print** (or Save as PDF) |
| Post an entry | **New journal** |

---

## Period close

This section walks you step by step through closing an accounting period,
reopening one, and producing the close pack. It is written for the people who run
the month end: accountants who prepare, and the Finance Manager and Executive
Director who confirm and authorise.

- **Page:** *Period close* (`http://localhost:8090/period-close`)

### 1. What this page is for

Closing a period **locks it against further posting**. Once a month is closed, no
journal can be posted into it and the balances carried forward are fixed. Because
that is hard to undo, the page makes you settle a checklist of controls first:

- **Ledger checks** are answered for you automatically from the accounts (for
  example, whether the trial balance is in balance). You cannot tick these — you
  fix the underlying issue and they turn green on their own.
- **Confirmations** are ticked by the named person who did the work (for example,
  the Finance Manager confirming they reviewed the management accounts).

A period can only be closed when **every** item is settled.

---

### 2. Getting to the page

1. Sign in and open `http://localhost:8090/`.
2. In the left sidebar, under **Overview**, click **Period close**.

The page opens on the earliest month that is still open for posting.

![Period close — top of the page: breadcrumb, title, action buttons, and the year and month selectors](images/period-close-header.png)

At the top you will see:

- **A kicker** reading either `LEDGER · OPEN FOR POSTING` or `LEDGER · CLOSED`,
  telling you the state of the selected month at a glance.
- **Close pack** button — assembles the evidence bundle for the period (see §7).
- **The close button** — labelled **Close \<month>** when the period is ready, or
  **Close blocked · N open** (greyed) when items are still outstanding. On a
  closed month this is replaced by **Reopen \<month>**.

---

### 3. Choosing the year and month

Two rows of selectors sit under the page title.

- **Year row:** the recent financial years (e.g. `FY2024`, `FY2025`, `FY2026`),
  with a count of open months on the current year, plus an **Earlier · N ▼**
  dropdown for older years. The note to the right summarises progress, e.g.
  *"7 of 9 months locked · earliest open is Aug 2026"*.
- **Month row:** every month with its state, e.g. `Jul · closed`, `Aug · open`.
  The selected month is highlighted.

**To change the period:**

1. Click a **year** to switch financial years (the earlier-years list opens with
   **Earlier · N ▼**).
2. Click a **month** to load its checklist.

Your selection is kept in the address bar, so reloading the page returns you to
the same month.

---

### 4. Reading the close checklist

The main panel is the **Close checklist** for the selected month. Its heading
shows how many items are still outstanding, e.g. *"10 of 14 outstanding"*.

![The close checklist showing ledger checks (✓ / !) and confirmation checkboxes, each with a status pill](images/period-close-full.png)

Each row shows:

| Element | Meaning |
|---------|---------|
| **✓ (green)** | A ledger check that is **settled**. |
| **! (red)** | A ledger check that is **blocking** — the ledger does not yet satisfy it. |
| **Checkbox** | A **confirmation** to be ticked by the named owner. Disabled (greyed) if it is not your task or if the period is already locked. |
| **Label + note** | What the control is, and its current ledger reading. |
| **Owner** | The person responsible (e.g. *M. Otieno · Accountant*). |
| **Status pill** | **Settled**, **Blocking**, or **Outstanding**. |
| **Link (→)** | Jumps to the page where you fix the issue (e.g. *Open journals →*, *Open payables →*, *Open reconciliation →*). |

The footer summarises what remains, e.g. *"7 ledger checks failing · 3
confirmations outstanding"*.

Typical checklist items include: every journal approved and posted; supplier
bills captured and approved; bank and M-Pesa accounts reconciled; payroll posted
and statutory deductions accrued; accruals and prepayments raised; depreciation
charged; every posting carries its fund, grant and restriction; donor reports
reconcile to the ledger; budget variances explained; trial balance in balance;
reviewed by the Finance Manager; authorised by the Executive Director.

---

### 5. Working through the checklist step by step

**Step 1 — Clear the ledger checks first.**
For each item showing a red **!** and a **Blocking** pill, click its link (e.g.
*Open journals →*) to go to the relevant module, fix the underlying issue (approve
the draft journal, reconcile the account, post the depreciation run, and so on),
then return to Period close. The check re-reads the ledger and turns green when
it is satisfied.

> Tip: the **Readiness** note on the right tells you the current blocker in plain
> language, e.g. *"The ledger is not ready: every journal in the period is
> approved and posted is still failing. Clear the ledger checks first, then take
> the confirmations."*

**Step 2 — Take the confirmations.**
Once the ledger checks are green, the responsible people tick their confirmation
boxes (for example, *Reviewed by the Finance Manager* and *Authorised by the
Executive Director*).

- To record a confirmation, click its **checkbox**. The tick is saved
  immediately and a brief toast confirms it.
- You can only tick a box that belongs to your role. Boxes for other people are
  disabled, with a tooltip naming the owner.

**Step 3 — Watch readiness reach 100%.**
As items settle, the **Readiness** percentage and progress bar climb. The close
button stays blocked until every item is settled.

---

### 6. Monitoring readiness and period totals

The right-hand column gives you three at-a-glance cards.

![Right column: Readiness with progress bar, What is in the period, and Close history](images/period-close-sidebar.png)

- **Readiness** — a percentage and progress bar (green when done, amber when
  blocked), a plain-language note on what is still failing, and the date posting
  will be **locked from**.
- **What is in the period** — journals posted, journals still open, total debits,
  total credits, the difference, and the value sitting in open journals. A line
  at the foot confirms *"✓ Debits and credits agree for the period"* (or warns if
  they do not).
- **Close history** — a log of previous closes and reopens, each with the date,
  who did it and who approved it.

---

### 7. Producing the close pack

The close pack is the evidence bundle for the period — the statements and
schedules an auditor or the board would expect.

1. Click **Close pack** (top right). A drawer opens in the middle of the screen.

![The Close pack drawer listing the eight documents with Ready / Outstanding status and the Print and Export PDF buttons](images/period-close-pack-drawer.png)

2. Review the **Contents**. The header shows how many are ready, e.g.
   *"5 of 8 ready"*. Each document carries a **Ready** or **Outstanding** pill and,
   where relevant, a headline figure and an **Open →** link. The pack includes:
   1. Trial balance
   2. Statement of financial position
   3. Statement of income and expenditure
   4. Fund movement schedule
   5. Bank and M-Pesa reconciliations
   6. Journal listing
   7. Budget against actual
   8. Signed close checklist
3. To output the pack:
   - **Print** — opens your browser's print dialog with the full pack laid out.
   - **Export PDF** — the same, but the document is titled as a close pack for
     the selected month; choose **Save as PDF** as the destination in the print
     dialog.

> You can assemble and print the pack even when some documents are still
> **Outstanding** — the footer warns that in that case the pack should not be
> circulated until the outstanding items are settled.

4. Press **Esc** or click the **×** to close the drawer.

---

### 8. Closing the period

Once **Readiness is 100%** and every checklist item is settled:

1. Confirm the close button reads **Close \<month>** (green). If it still reads
   **Close blocked · N open**, clicking it only shows a toast naming how many
   items remain and the first blocker — return to §5 and settle them.
2. Click **Close \<month>**.
3. The month is locked. Its state changes to **closed**, Readiness shows **100%**,
   posting is **Locked**, and a new entry appears in **Close history**.

---

### 9. Reopening a closed period

Reopening is occasionally necessary — for example, to correct a misposted batch.
It is recorded on the audit trail.

![A closed period (July 2026): the LEDGER · CLOSED kicker and the Reopen button](images/period-close-closed-state.png)

1. Select the closed month you need to reopen.
2. Click **Reopen \<month>** (top right).
3. When prompted *"Why is \<month> being reopened?"*, type the reason. It is kept
   in the audit log.
4. Confirm. The period reopens for posting, and the reason and who reopened it
   are recorded in **Close history**.

> Only reopen when necessary, and close the period again as soon as the
> correction is posted. Reopening a period that has already been reported on can
> change figures others rely on.

---

### 10. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Overview** → **Period close** |
| Switch year / month | Click a year, then a month, in the selector rows |
| See why I cannot close | Read the **Readiness** note and the **Blocking** / **Outstanding** pills |
| Fix a blocking item | Click its link (e.g. *Open journals →*), fix it, return |
| Record a confirmation | Tick the confirmation **checkbox** (your role only) |
| Check the period balances | **What is in the period** card → *Debits and credits agree* |
| Build the evidence bundle | **Close pack** → **Print** or **Export PDF** |
| Lock the period | **Close \<month>** (enabled at 100% readiness) |
| Undo a close | **Reopen \<month>** → give a reason |

---

### 11. Notes and good practice

- **The two-person rule applies.** Preparation and authorisation are separate
  confirmations owned by different roles, so no one person can both do the work
  and lock the period.
- **Ledger checks cannot be forced.** They read from the accounts. If one is red,
  the fix is in the ledger, not on this page.
- **Closing is deliberate.** A closed period blocks posting; corrections are made
  by reopening (with a reason) or by posting in the next open period, depending on
  your accounting policy.
- **The figures shown are live** and come from the ledger; the screenshots in this
  guide use sample data (Aug 2026 open, Jul 2026 closed) to illustrate the states.

---

## Chart of accounts

The chart of accounts is the master list of accounts every posting is coded to.
It is shared across all entities, browsable as a tree or a flat list, and edited
through a drawer. You can also bring accounts in from a CSV file or export the
chart. Balances open at zero and only ever move through journals — you never type
a balance here.

- **Page:** *Chart of accounts* (`http://localhost:8090/coa`)
- **Who can change it:** only the **Finance Manager**. Everyone else sees the
  chart read-only.

### 1. Getting to the page

In the sidebar, under **Accounting**, click **Chart of accounts**. (The General
ledger's *Open in chart of accounts* link also brings you here with a search
already applied.)

![Chart of accounts: stat cards, the search/type/view toolbar, and the account tree with its columns](images/coa-full.png)

### 2. Reading the page

- **Header** — the title and blurb, with **Import CSV**, **Export** and
  **+ New account** buttons.
- **Stat cards** — Accounts (and how many are postable), Entities, Restricted
  funds, YTD income and YTD expenditure.
- **Toolbar** — a **search** box (code or name), a **type filter** (All, Assets,
  Liabilities, Funds, Income, Expenditure), and on the right an **Expand all /
  Collapse all** link and a **Tree / Flat** toggle.
- **The grid** — one row per account with these columns:

| Column | Meaning |
|--------|---------|
| **Code** | The account number. |
| **Account** | The name, indented under its heading in tree view. |
| **Type** | Asset, Liability, Fund, Income or Expense. |
| **Statement** | Where it reports — Balance sheet or Income & expenditure. |
| **Normally** | Its normal balance — **Dr** (debit) or **Cr** (credit). |
| **Restriction** | Unrestricted, Restricted, etc. |
| **Fund / Programme / Funder** | The default coding dimensions. |
| **YTD balance (KES)** | The year-to-date balance. |
| **Status** | ● Active or ○ Archived. |

- **Footer** — a count of rows, postable accounts and archived accounts, plus who
  last edited the chart and when.

### 3. Finding an account

- **Search** — type a code or name; the list filters as you type.
- **Filter by type** — click a type in the segmented control.
- **Tree vs Flat** — **Tree** shows the account hierarchy with expandable
  headings (click a chevron ▶/▼ to open or close a branch, or use **Expand all /
  Collapse all**). **Flat** shows only the postable accounts as a plain list.
- **Pagination** — the grid shows ten rows a page; use the pager at the foot.

### 4. Viewing or editing an account

Click any account row to open the **account drawer**.

![The account drawer: Identification, Classification (IFRS), Dimensions, Posting rules, Description and Balances, with Archive / Cancel / Save changes](images/coa-account-drawer.png)

The drawer is organised into sections:

- **Identification** — Account code (read-only when editing an existing account),
  Account name and Parent account.
- **Classification (IFRS)** — Account type, Normal balance (fixed — it follows the
  type), Restriction class and Currency.
- **Dimensions** (required at posting) — Fund, Programme, Grant/award and Funder.
- **Posting rules** — three checkboxes: *Allow direct posting to this account*,
  *Require monthly reconciliation*, and *Include in donor expenditure reports*.
- **Description** — free text.
- **Balances** (when editing) — Opening, Movement and YTD (or a rolled-up total
  for a heading account).

To save, click **Save changes**. If you are not the Finance Manager, the fields
are disabled and a note explains that only the Finance Manager can make changes.

### 5. Adding a new account

1. Click **+ New account** (top right). The drawer opens empty.
2. Enter the **Account code** and **Account name**, and choose the **Parent
   account** it sits under.
3. Set the **Classification** (type, restriction, currency) and the default
   **Dimensions**.
4. Tick the **Posting rules** that apply.
5. Click **Create account**. A toast confirms the account was added.

> Every code must sit under a parent that already exists in the chart.

### 6. Archiving an account

Open an active account and click **Archive** (bottom-left of the drawer). The
account is hidden from new postings, but its historical postings are kept. A toast
confirms *"…archived. Historical postings are retained."*

### 7. Exporting the chart

Click **Export**. The chart downloads as a CSV file. If a search or type filter is
active, only the filtered accounts are exported, and the toast says so.

### 8. Importing accounts from a CSV

Importing runs a **dry run first** — nothing changes until you have reviewed it
and confirmed.

**Step 1 — choose a file.**

![Import wizard step 1: choose a CSV file or load a sample, with the rules the import enforces](images/coa-import-step1.png)

1. Click **Import CSV**. The wizard opens.
2. Click **Choose a CSV file** and pick your file (code, name and type are
   required; restriction, fund, programme and funder are optional). If you just
   want to see how it works, click **Load a sample file**.

The panel also lists the rules the import enforces: balances are never imported;
an account that already carries postings cannot change its type or code; and every
code must sit under an existing parent.

**Step 2 — check the dry run.**

![Import wizard step 2: column mapping, an update-or-skip toggle, summary tiles and a row-by-row preview with New / Update / Rejected tags](images/coa-import-step2.png)

3. Review and, if needed, adjust the **Column mapping** — the wizard matches your
   file's headers to each field automatically.
4. Decide how to treat **codes that already exist**: **Update them** or
   **Skip them**.
5. Read the summary tiles — **Rows read**, **New accounts**, **Updates** and
   **Rejected** — and the per-row preview. Each row is tagged **New**, **Update**,
   **Rejected** (with the reason, e.g. *"Account code must be digits only"*,
   *"Type is missing"*, *"Duplicate of line N in this file"*) or **Skipped**. If
   there are rejects, use **Download rejects →** to get them as a file to fix.
6. When you are satisfied, click the commit button (**Import N accounts**) to
   apply the New and Update rows. Rejected rows are never imported.

Importing is limited to the Finance Manager.

### 9. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Chart of accounts** |
| Find an account | Type in **Search**, or filter by **type** |
| See the hierarchy | Use **Tree** view; expand/collapse branches |
| See only postable accounts | Switch to **Flat** view |
| View or edit an account | Click its row → edit in the drawer → **Save changes** |
| Add an account | **+ New account** → fill in → **Create account** |
| Retire an account | Open it → **Archive** |
| Download the chart | **Export** (respects the current filter/search) |
| Bring accounts in from CSV | **Import CSV** → choose file → check dry run → commit |

### 10. Notes and good practice

- **Balances are never typed or imported.** Accounts open at zero and move only
  through journals.
- **Codes are stable.** An existing account's code cannot be changed; nor can the
  type of an account that already carries postings.
- **Normal balance follows the type.** You choose the type; the debit/credit side
  is set for you (contra accounts such as accumulated depreciation take the
  opposite side).
- **Archiving preserves history.** Retire an account by archiving it rather than
  deleting, so past postings remain intact.

---

## Programmes

Programmes is the third coding dimension on every posting line, alongside
account and fund — it is where activity is tracked (a project, an election
observation, civic education, and so on). The page doubles as the screen that
manages the **shared-cost allocation base**: the percentages by which one
programme's overhead ("the shared-cost pool") is spread across the others,
which always have to add up to exactly 100%.

- **Page:** *Programmes* (`http://localhost:8090/programmes`)
- **Who can change it:** anyone signed in. Unlike the chart of accounts, this
  page carries no permission gate — there is no role restriction on adding,
  renaming, deactivating, reactivating a programme, or reshaping the
  shared-cost allocation. Every change is still written to the audit log
  against the person who made it.

### 1. Getting to the page

In the sidebar, under **Accounting**, click **Programmes** (directly below
Chart of accounts).

### 2. Reading the page

- **Header** — the title and a one-line blurb explaining the shared-cost
  allocation, with **+ New programme** at the top right.
- **Stat cards** — Active programmes (with a note of how many are on the
  register including closed ones), Budget for the year (with actual spend to
  date as its note), Committed (open purchase-order commitments), Shared-cost
  base (the allocation total as a percentage, with a note of whether it
  balances), and Staff allocated (headcount coded here, across every
  programme).
- **Shared-cost warning banner** — appears only when the allocation base is
  off 100% by more than a rounding tolerance: *"Shared-cost allocation totals
  X%, not 100%. Until this balances, support costs are either under-recovered
  or charged twice — check the monthly allocation journal."*
- **Toolbar** — a **search** box (programme, code or manager) and **status
  tabs**: All, Active, Pipeline, Inactive, each showing a count.
- **The table** — one row per programme:

| Column | Meaning |
|--------|---------|
| **Programme** | Name, then code and the date it started (e.g. *PRG-10 · since 1 Jan 2024*). |
| **Manager** | The programme manager, or — if none is set. |
| **Budget** | This year's approved working budget for the programme. |
| **Actual** | Posted expenditure to date. |
| **Committed** | Value on open purchase orders not yet received. |
| **Remaining** | Budget less actual and committed; shown in red when overspent. |
| **Burn** | A progress bar and percentage of budget consumed; the bar turns heavier once it passes about 92%. Shows — when there is no budget. |
| **Shared cost** | The programme's share of the shared-cost allocation, or — for a closed programme or the pool itself. |
| **Status** | **Active**, **Pipeline** or **Inactive**. |

The footer reminds you why there is no delete option: *"…programme is a
mandatory coding dimension on every posting line, so a programme with history
is deactivated, never deleted."*

### 3. Finding a programme

- **Search** — type a name, code or manager; the list filters as you type.
- **Status tabs** — click **All**, **Active**, **Pipeline** or **Inactive** to
  narrow the list.

### 4. Viewing a programme

Click any row to open the **programme drawer**.

- The header repeats the code and status, the programme name, and its
  **share of shared costs** — or, for the pool programme, a note that it is
  not allocated because it is the one charged out to the others; or, for a
  closed programme, that it carries no share.
- **Purpose** — the free text describing what the programme covers.
- **Facts** — code, programme manager, active since, budget for the year,
  actual to date, committed, remaining, how many awards are coded to it (and
  how many of those are live), and how many staff allocations are coded to it.
- **Funds charged to this programme** — a chip list of the funds linked to
  it, when any are.
- **Rename** — a text box pre-filled with the current name; **Save** appears
  once you have typed a different name. Renaming does not rewrite history:
  journals already posted keep the name as it was recorded, and the change
  only applies going forward. Every past name is kept in **Previously known
  as**, with the date, the old and new names and who made the change.
- **Deactivation** — tells you where things stand: already closed; blocked,
  with the list of what is in the way (see §6); or clear to deactivate.

Footer buttons, shown only when they apply: **Zero shared-cost share** (only
if the programme currently holds a share above 0), **Reopen programme** (only
if it is Inactive), **Deactivate** (only if nothing is blocking it), and
**Close** to dismiss the drawer.

### 5. Adding a new programme

1. Click **+ New programme** (top right). The modal opens.
2. Fill in the **Programme name** and, if you want to set your own, a
   **Code** — leave it blank and one is assigned for you (e.g. `PRG-60`).
3. Choose the **Programme manager**, the date it is **Active from**, and
   describe **what the programme covers** — this is required, since it is
   what keeps coding decisions consistent between people.
4. Enter its **Share of shared costs %**, then choose how the other
   programmes give up the room for it:
   - **Pro-rate existing** (the usual choice) — every existing programme's
     share is reduced proportionally so shared costs stay fully recovered
     from day one.
   - **Leave unchanged** — the new programme opens at 0% and nothing else
     moves. Correct only when the award funds its own support costs
     directly, or the programme has no activity yet.
   - **Set by hand** — type a new share for every existing programme
     yourself.
5. The **allocation preview** table updates live as you type — Programme,
   Was, New share, and a Total row that turns green once it balances to 100%
   and red when it does not.
6. Click **Open programme**. The button is disabled, with the reason shown
   beneath it, until the name, purpose and allocation are all valid.

A new programme always opens as **Pipeline** with **no budget** — nothing can
be spent against it until an award is recorded or a budget revision adds
lines to it.

**Validation.** The name must be unique (checked against every programme, not
just the ones on screen) and the purpose cannot be blank. The share must sit
between 0% and 99% once other programmes already exist — 100% is only
possible for the very first programme on the register. Whatever the
allocation mode, the resulting shares (existing programmes' new shares plus
the new one) must total exactly 100%, or the modal explains by how much it is
out. In **Set by hand** mode, every programme needs an explicit share. A code
you typed yourself must not already belong to another programme.

### 6. Managing the shared-cost share

**Zero shared-cost share**, in the drawer, moves a programme's share to 0 and
spreads it proportionally across the remaining programmes so the base stays
at 100%. It is refused if the programme has no share to give up, or if it is
the only one left to receive it — there is nowhere for the share to go.

### 7. Deactivating and reactivating a programme

A programme can be deactivated only once none of the following stand in the
way — the drawer lists whichever apply:

| Blocker | Cleared by |
|---|---|
| It collects the shared costs (it is the allocation pool) | Hand that role to another programme first |
| Open purchase-order commitments | Receive or cancel the purchase orders |
| Live awards still coded to it | Close or recode the awards |
| Staff allocations still coded to it | Re-base the payroll allocation lines |
| It still receives a share of shared costs | **Zero shared-cost share** first, so the rest re-base to 100% |
| Unspent budget remains | Close the budget lines, or move the balance with a budget revision |

There is no delete option anywhere on this page: a programme that has ever
carried a posting can only be deactivated, never removed, so the postings
coded to it are never orphaned. Deactivating sets its status to **Inactive**
and clears its shared-cost share entirely — it drops out of the allocation
base rather than sitting in it at nothing.

**Reopen programme** brings an Inactive programme back as **Pipeline**, with
no budget and no shared-cost share — both have to be set again before
anything can be coded to it.

### 8. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Programmes** |
| Find a programme | **Search**, or a **status tab** |
| See whether the allocation balances | **Shared-cost base** stat card, or the warning banner |
| View a programme's detail | Click its row |
| Rename a programme | Open it → **Rename** → type the new name → **Save** |
| Add a programme | **+ New programme** → fill in → choose how the base is re-based → **Open programme** |
| Give up a programme's shared-cost share | Open it → **Zero shared-cost share** |
| Retire a programme | Open it → clear any blockers → **Deactivate** |
| Bring a closed programme back | Open it → **Reopen programme** |

### 9. Notes and good practice

- **Programme is mandatory on every posting.** Journals, bills, requisitions,
  budgets, assets, payroll allocations and advances all carry it, alongside
  account and fund.
- **Nothing is ever deleted here.** A programme with history is deactivated,
  which preserves its postings; only a programme with no history at all could
  in principle disappear, and there is still no button for it.
- **The allocation base must always total 100%.** Opening, closing or zeroing
  a programme's share is really a re-basing of every other programme's share
  — read the live preview before you save.
- **A new programme starts empty.** Pipeline status and a zero budget are the
  default; it only becomes chargeable once an award or a budget revision
  gives it money to spend.
- **Renaming keeps history intact.** Journals already posted keep the name as
  recorded at the time; only the display name changes going forward.

---

## General ledger

The general ledger shows one account's posted movements for a period, in
code order from the chart of accounts, with a running balance. Every line
carries its fund, programme and grant segments so the ledger reconciles to
donor reports without extra reconciliation work.

- **Page:** *General ledger* (`http://localhost:8090/gl`)
- **Who can change it:** viewing, filtering, exporting and opening entries
  carry no permission gate — anyone signed in can see the ledger. Raising a
  journal from this page, and reversing an entry, both need the
  **journal.prepare** permission (the same one used on the Journals and
  Dashboard pages); there is no separate "reversal" permission. Reversals
  still need a *different* person with approval rights to approve them
  before they take effect — the same prepare/approve separation used
  everywhere else in the ledger.

### 1. Getting to the page

Sidebar → **Accounting** → **General ledger**. You can also arrive here with
an account already selected — for example a future link from another page
that passes `?account=<code>` on the URL.

### 2. Reading the page

**Header** — the title and a short blurb, plus three buttons:

- **Open in chart of accounts** — jumps to Chart of accounts with the
  current account's code pre-filled in the search box.
- **Export** — downloads the filtered ledger as CSV.
- **+ Post journal** — opens the shared journal editor (the same drawer used
  on the Dashboard and Journals pages) with a line pre-filled for the
  current account.

**Filter row:**

| Filter | Options |
|--------|---------|
| Account | Every active, postable account, shown as `code · name`. Defaults to account `5110` if it exists, otherwise the first account in the list. Archived accounts, non-postable header accounts and the derived surplus/deficit account are never offered. |
| Period | A year-to-date range (e.g. `FY2025 · Jan – Sep`), any completed quarter of the current fiscal year, the previous period, and the current period. Defaults to the year-to-date range. |
| Fund | **All funds**, plus only the funds actually posted to the selected account. |
| Programme | **All programmes**, plus only the programmes actually posted to the selected account. |
| Grant / award | **All awards**, plus only the grants actually posted to the selected account. |
| Search | Free text, matched against reference, narration, source and grant text. |

**Changing the account resets Fund, Programme and Grant back to "All..."**
— a different account can have a completely different set of segments, so
the page clears those three filters for you. Changing Period, Fund,
Programme or Grant on their own leaves the others as they are.

**Summary band** — five metric cards above the grid:

| Card | Shows |
|------|-------|
| Opening balance | Balance as at the start of the selected period |
| Debits | Sum of the Debit column, with a count of debit postings |
| Credits | Sum of the Credit column, with a count of credit postings |
| Net movement | Closing balance minus opening balance, for the selected period |
| Closing balance | Running closing balance, noted as **debit normal** (Asset and Expense accounts) or **credit normal** (Liability, Fund and Income accounts) |

**Ledger grid** — one row per posting, in **Date, Reference, Narration,
Source, Fund, Programme, Grant, Debit, Credit, Balance** columns:

- It opens with an **Opening balance brought forward** row. This always
  reflects the fiscal year's opening balance plus everything posted before
  the selected period — regardless of the Fund, Programme or Grant filters,
  which only affect the in-period rows and the closing balance.
- It closes with a **Closing balance — *period*** row, which also totals the
  Debit and Credit columns for the filtered rows.
- A blank Debit or Credit cell is shown as **—**.
- If nothing matches the filters, the grid reads *"No postings match the
  current filters."*

**Restricted posting without an award** — on a Grant Fund or Capital Fund
posting with no grant/award attached, the Grant cell reads **Unassigned**
with a small flag and the tooltip *"Restricted posting with no award
attributed."* — it can't be traced to a donor report as it stands. The same
empty cell on a General Fund or Endowment Fund posting reads **Unrestricted**
instead, with no flag — that's expected, not a problem.

**By award** — when the filtered rows touch more than one grant, a row of
chips appears below the grid, one per award, each showing the total posted
to it (debits plus credits) across the filtered period, most-active award
first. It's a read-only summary — the chips aren't clickable.

The grid's footer states the account type, restriction and fund
(e.g. *"14 postings · expense account · unrestricted · General Fund"*) and
the ledger's currency and normal balance side.

### 3. Exporting the ledger

**Export** downloads exactly what's on screen — account, period, fund,
programme, grant and search are all applied — as a CSV named
*"{Brand} general ledger {account code}.csv"*, with the account, period,
opening balance, every posting (full date) and the closing balance. A toast
confirms how many postings were exported, or reports that the export failed.

### 4. Posting a journal from here

**+ Post journal** opens the same journal editor used on the Dashboard and
Journals pages, with the first line pre-filled to the account, fund and
programme currently selected (the second line starts blank). If your role
can't prepare journals, the drawer doesn't open — a toast tells you to
switch to a preparer in the account menu instead. Saving refreshes the
ledger, so a matching new posting appears immediately.

### 5. The entry drawer

Click any posting row (or press Enter on a focused one) to open it:

- **Posting date, Source** and a **● Posted** status.
- **Entry lines** — every line of the journal, Code/Account/Debit/Credit,
  with a totals row and a confirmation that the entry is in balance.
- **Segments** — Fund, Programme, Grant/award and Funder for that posting.
- **Audit trail** — who prepared it and when; who approved it, if recorded;
  and the supporting document reference, if one exists.
- **Reverse entry** and **View source document** — both open the Journals
  page with that entry loaded in the journal editor, where the actual
  reversal happens.

If the entry is held in a closed period's archive, **Reverse entry** and
**View source document** are both left off, replaced by a note: *"Held in
the closed-period archive. A correction is made with a new journal in an
open period."* This is decided per entry, by whether that posting's period
is closed — not by which period the page's filter happens to show.

### 6. Reversing an entry

**Reverse entry** is only offered on a Posted entry that isn't the fiscal
year's opening balance. Raising it needs the **journal.prepare** permission,
and creates a new reversing journal — debits and credits swapped, narrated
*"Reversal of {ref} — {original narration}"* — which is sent straight to
**Pending approval** rather than saved as a draft. A different person, with
approval rights, still has to approve it before the original entry actually
flips to **Reversed**.

A reversal is refused, with a toast explaining why, when:

| Situation | What happens |
|-----------|---------------|
| The entry isn't Posted | *"Only a posted entry can be reversed."* |
| The entry is archived, or is the opening-balance journal | Held in a closed period; correct it with a new journal instead |
| The entry's lines were capitalised onto the asset register | Dispose of the asset first, or correct with a new journal |
| A reversal already exists for this entry | You can't reverse it twice |
| No period is currently open for posting | The reversal can't be raised yet |

### 7. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **General ledger** |
| See an account's movements | Choose it from the **Account** filter |
| Narrow to a fund, programme or award | Use the matching filter — cleared automatically when you change account |
| Check a flagged restricted posting | Look for **Unassigned** with a flag in the Grant column |
| Download the ledger | **Export** |
| Raise a journal against this account | **+ Post journal** |
| See an entry's detail | Click its row |
| Reverse a posted entry | Open the entry → **Reverse entry** → have another user approve it |
| Jump to the account in the chart | **Open in chart of accounts** |

### 8. Notes and good practice

- **The opening balance ignores your Fund, Programme and Grant filters.**
  It always covers everything before the selected period; only the in-period
  rows and the closing balance respond to those three filters.
- **An unassigned award on a restricted fund is a data-quality flag**, not
  necessarily an error — but it means that posting can't yet be matched to a
  donor report.
- **Reversing is dual control, not a special permission.** Anyone who can
  prepare journals can raise a reversal; it still needs a different person
  to approve it before the original entry is marked Reversed.
- **Closed-period entries are corrected forward, never edited.** Their
  archive note says so directly, and their Reverse/View source actions are
  removed rather than disabled.

---

## Journals

Journals is the register of every double-entry batch — drafted, awaiting
approval, posted or reversed — and the editor they're raised and signed in.
Nothing reaches the general ledger until an entry balances (debits equal
credits) and a second person approves it; the account or bill or payroll run
that raises an entry automatically still goes through the same register and
the same signature.

- **Page:** *Journals* (`http://localhost:8090/journals`). A direct link to
  one entry — `/journals/<reference>` — opens the register with that entry's
  editor already open.
- **Who can change it:** viewing, searching and filtering carry no
  permission gate — anyone signed in can see the register. Raising or
  editing an entry needs the **journal.prepare** permission; approving or
  rejecting one needs **journal.approve**. The same person can never prepare
  and approve the same entry — the database itself refuses it, not just the
  screen.

### 1. Getting to the page

Sidebar → **Accounting** → **Journals**. The same editor also opens from the
**+ Post journal** button on [General ledger](#general-ledger), from the
Dashboard's quick action, and from a **Journal** reference shown on a
payables, receivables or procurement record — in each case with the first
line pre-coded to the account (and fund and programme) you came from.

### 2. Reading the page

**Header** — the title and a one-line blurb, with **Recurring templates**
and **+ New journal** at the top right.

**Stat cards:**

| Card | Shows |
|------|-------|
| Awaiting approval | Count of entries in Pending approval, with the oldest one's age in days |
| Drafts | Count of Draft entries, with how many are currently out of balance |
| Posted this period | Entries posted in the current accounting period, with the period's month |
| Value posted YTD | Total debits posted this fiscal year, across all funds (opening-balance entries excluded) |
| Reversals | Count of Reversed entries — a note that a reversal always needs a memo |

**Toolbar** — **status tabs** (All, Draft, Pending approval, Posted,
Reversed, each with a count), a **search** box (reference, narration or
preparer), a **Type** filter (All types, or one of Standard, Accrual,
Reversing, Recurring, Adjustment, Allocation), and a hint that reads *"All
drafts balance"* or *"N draft out of balance"*.

**The table** — ten entries a page, most recent first:

| Column | Meaning |
|--------|---------|
| **Reference** | The entry's auto-numbered reference, e.g. `JV-0231`. |
| **Date** | Day and month of the posting date. |
| **Type** | Standard, Accrual, Reversing, Recurring, Adjustment or Allocation. |
| **Narration** | What the entry records. |
| **Fund**, **Programme** | The shared value if every line agrees, otherwise *"N segments"*. |
| **Grant / award** | **—** if no line names one, the award if every line names the same one, otherwise *"N awards"*. |
| **Lines** | How many lines the entry has. |
| **Amount** | Total debits (equal to total credits once the entry balances). |
| **Status** | Draft, Pending approval, Posted or Reversed — with, for an entry on a ladder of more than one signature, a small note of how many have signed and who it's waiting on next. |
| **Prepared by** | Who raised the entry. |

A restricted line (a Grant Fund or Capital Fund line) with no award attached
carries a **!** flag next to the Grant / award cell.

The footer reads *"N of M journals · X awaiting approval"*, adding *"· Y
awaiting yours"* for anyone who can approve, and states the approval policy
in force — either *"Two-person rule enforced"*, or, where the journal
approval rule carries a threshold, *"Approval threshold KES X · above it the
[role] approves · two-person rule enforced"*. That rule, and any additional
signature steps, are set by an administrator in *Settings → Approvals*.

### 3. Finding a journal

- **Search** — matches reference, narration, preparer or the label of any
  award on the entry's lines.
- **Status tabs** — narrow to Draft, Pending approval, Posted or Reversed.
- **Type** — narrow to one journal type.
- Click a row (or press Enter on a focused one) to open it in the editor.

### 4. Raising or opening an entry

**+ New journal** opens the editor on a fresh entry: two lines to start with
— the first coded to a default account (or the account of the page you
opened it from), the second to a default cash/bank account — both without
amounts yet. Opening an entry from the register or from `/journals/<ref>`
loads it into the same editor instead.

**Header fields:**

| Field | Notes |
|-------|-------|
| **Period** | Open periods, plus the entry's own period if it's already posted there or closed-period posting is allowed. Changing period keeps the day of the month, clipped to fit. |
| **Posting date** | Bounded to the selected period's dates. Picking a date outside the current period is refused with a note to change the period first. |
| **Journal type** | Standard, Accrual, Reversing, Recurring, Adjustment or Allocation. **Reversing is raised only through Reverse entry on a posted entry (§8)** — setting a new entry's type to Reversing by hand leaves it with nothing to reverse, and saving it is refused. |
| **Source document** | *Manual — auto-numbered* (the next number in the type's own series — JV for most types, AC for Accrual or Recurring, AL for Allocation), or a link to the bill, payroll run, bank statement line or recurring template that raised it. An entry raised by another module carries that link automatically. |
| **Prepared by** | Fixed to whoever raised the entry — it cannot be reassigned. |

**Once an entry has been submitted (Pending approval), every field becomes
read-only** — for the preparer as much as anyone else. The only way back to
an editable draft is for an approver to **Reject** it (§7).

### 5. Entering lines

Each line has an **account**, a free-text **description**, an optional
**grant / award**, a **fund**, a **programme**, and either a **debit** or a
**credit** — never both; typing an amount on one side clears the other.
**+ Add line** appends another; the **✕** on a row removes it.

- **Choosing an award** narrows Fund and Programme to what that award
  actually covers, and picks the first of each automatically. Changing Fund
  or Programme afterwards to something the award doesn't cover clears the
  award again.
- **A line coded to a fund that awards are held in, with no award chosen,**
  is flagged — its Grant / award field is highlighted and a warning explains
  that a restricted fund reports by award, so the line can't be claimed on a
  donor report without one. An entry with any such gap cannot be submitted
  or approved, though it can still be saved as a draft.
- The **totals row** updates live: green *"Entry balances"* once debits equal
  credits and the total is above zero, amber for an imbalance (with the
  amount it's out by), or a neutral prompt while both sides are still empty.
  **Save draft** is disabled while the entry is out of balance; **Submit for
  approval** and **Approve and post** are disabled by that or by any
  restricted-fund gap.
- **Allocation-type entries** have an extra rule: their lines must move
  value between at least two funds, and each fund's own net movement must
  sum to zero across the entry — not just the entry as a whole.

### 6. Supporting evidence and the approval memo

**Supporting evidence** lists whatever is already attached, with a control
to attach more (or, while editable, to remove one). Some entries need a
document before they can be submitted — a manual entry above the
organisation's configured amount, or of a type flagged to always need one —
and the panel explains which applies; a draft can still be saved without it.
An entry raised from another record (a bill, a bank line, a reversal) is
already backed by that record and carries no such requirement of its own.

**Approval memo** is free text for the approver — the grant condition being
applied, an audit reference, or the reason for a correction. It's read-only
once the entry can no longer be edited.

### 7. Submitting, approving and rejecting

- **Submit for approval** needs a narration, a balanced entry, no
  restricted-fund gaps, and its supporting document if one is required. It
  moves the entry to Pending approval and records who it's waiting on.
- **Approve and post** needs the **journal.approve** permission and a
  different person from the preparer — the drawer explains this ("Prepared
  by ... waiting on an approver") whenever the signed-in actor can't sign.
  Some entry types collect more than one signature before they post; a
  signature that doesn't complete the ladder leaves the entry in Pending
  approval and records who signs next, rather than posting it early. If
  anything in the editor changed since it was opened, approving is blocked
  until you close without saving or save the change and have the entry
  resubmitted — approval always posts exactly what was submitted.
- **Reject** swaps the footer for a reason box; confirming returns the entry
  to Draft with the preparer named and the reason kept on the audit trail.
  Any signatures already collected stay on record, but a resubmitted entry
  is signed again from the first step.

### 8. Discarding and reversing

**Discard** removes a Draft entirely — its lines and any attached documents
go with it, though the audit log keeps a record that it existed. It's
offered only on a draft, to anyone who can prepare journals.

**Reverse entry** is offered only on a Posted entry that isn't the fiscal
year's opening balance. It raises a new entry with debits and credits
swapped, narrated *"Reversal of {ref} — {original narration}"*, and sends it
straight to Pending approval — a different approver still has to sign it
before the original is actually marked Reversed. It's refused when:

| Situation | Reason |
|-----------|--------|
| The entry isn't Posted | Only a posted entry can be reversed |
| The entry is archived, or is the opening-balance entry | Correct it with a new journal instead |
| The entry's lines were capitalised onto the asset register | Dispose of the asset first, or correct with a new journal |
| It's already been reversed | An entry can only be reversed once |
| No period is currently open for posting | The reversal can't be raised yet |

### 9. Recurring templates

**Recurring templates** opens a drawer for entries the organisation raises
on a calendar rather than on an event — monthly rent, a standing allocation,
a recurring accrual. A template posts nothing by itself: running it raises
an ordinary journal, held to every rule a manual entry is (an open period, a
balanced entry, awards on restricted lines), and — unless the template says
otherwise — still needs a second person to approve it.

- The list shows each template's name, id, frequency and schedule rule
  (*Last day of the month*, *First day of the month*, or the *Nth*), its
  per-run total, how many lines it carries, and when it's next due (or was
  due, if paused).
- **Create from a recent entry** turns any journal not already generated by
  a template into one — the lines, coding and narration carry forward
  monthly, on the day of the month the source entry was dated (month end
  stays month end).
- Opening a template shows its schedule, its narration and memo, the lines
  it generates each run, whether those runs go straight to Pending approval
  or are saved as drafts for the owner to review first, and its run
  history — click a past run to open the journal it produced.
- **Pause template** / **Resume template** stops or restarts its schedule
  without discarding it. **Generate entry now** raises its next run
  immediately and advances the schedule, active templates only.

### 10. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Journals** |
| Find an entry | **Search**, a **status tab**, or the **Type** filter |
| Raise a new entry | **+ New journal** |
| Post a journal against a specific account | **+ Post journal** on [General ledger](#general-ledger) |
| See or edit an entry | Click its row |
| Submit a draft | Open it → fill it in and balance it → **Submit for approval** |
| Approve or reject a pending entry | Open it → **Approve and post**, or **Reject** with a reason |
| Discard a draft | Open it → **Discard** |
| Correct a posted entry | Open it → **Reverse entry** → have another user approve the reversal |
| Raise or run a recurring entry | **Recurring templates** |

### 11. Notes and good practice

- **An entry is never editable once submitted.** Pending approval locks
  every field for everyone, preparer included; an approver has to **Reject**
  it back to Draft before it can change.
- **A restricted-fund line always needs its award.** Without one, the entry
  can be saved as a draft but not submitted — and the cost can't be matched
  to a donor report until it's added.
- **Reversal is dual control, not a special permission.** Anyone who can
  prepare journals can raise one; a different person with approval rights
  still has to sign it before the original is marked Reversed.
- **A journal is corrected forward, never edited in place.** Once posted, an
  entry is fixed; the only way to change its effect is to reverse it and, if
  needed, post the correct entry alongside.

---

## Payables

Payables is the register of supplier bills, from capture through approval,
scheduling and payment, and the place withholding tax held on those bills is
remitted to KRA. Capturing a bill posts nothing on its own: the cost, VAT and
withholding tax reach the ledger only once a second person approves it, and
cash moves only when a payment run is released. A bill's preparer can never
approve it or release its payment — the database refuses it, not just the
screen — and Settings → Approvals decides which role's signature a bill or a
payment run of a given value needs (see [Journals](#journals) and
[docs/approvals.md](approvals.md) for how that ladder works).

- **Page:** *Payables* (`http://localhost:8090/payables`). A direct link to
  one bill — `/payables/<reference>` — opens the register with that bill
  already open.
- **Who can change it:** viewing, searching and filtering carry no permission
  gate — anyone signed in can see the register. Capturing a bill needs the
  same **journal.prepare** permission that lets someone raise a journal;
  approving a bill, releasing a payment run or remitting withholding tax
  needs **journal.approve**. Changing a bill's payment method, or scheduling
  it into a payment run, is open to either.

### 1. Getting to the page

Sidebar → **Accounting** → **Payables**. A bill can also arrive already
captured from Procurement's three-way match — **Raise supplier bill** against
a purchase order and its goods received note — and lands in the same
register, coded from the order rather than typed by hand.

### 2. Reading the page

**Header** — the title and a one-line blurb explaining that withholding tax
is computed per invoice and held for remittance to KRA by the 20th, with
**Remit WHT to KRA** and **+ New bill** at the top right.

**Stat cards:**

| Card | Shows |
|------|-------|
| Total outstanding | Net value of every open bill, with a count of open bills |
| Overdue | Net value of open bills past their due date, with how many suppliers are waiting |
| Due within 7 days | Net value falling due in the coming week, with a count |
| Awaiting approval | Net value awaiting a signature, with *"N need sign-off"* and, for anyone who can approve, how many are theirs to sign |
| WHT to remit | Withholding tax held and not yet paid to KRA, noting either when it was last remitted this month or the date it's due by, plus anything still pending on bills awaiting approval |

**Aging strip** — five buckets (Current, 1–30 days, 31–60 days, 61–90 days,
Over 90 days), each showing the net value and bill count of open bills in
that band. Click a bucket to filter the table to it; click it again to clear.

**Toolbar** — **status tabs** (All, Awaiting approval, Approved, Scheduled,
Paid, Overdue, each with a count), a **search** box (supplier, bill number or
KRA PIN), a **Fund** filter, and a hint reading either *"N bills past due"*
or, once an aging bucket is picked, *"Aging filter: <bucket>"*.

**The table** — ten bills a page:

| Column | Meaning |
|--------|---------|
| ☐ | Selects the bill for a bulk action (§4). |
| **Bill** | The auto-numbered reference, e.g. `BILL-0453`. |
| **Supplier** | Name, with the spend category and KRA PIN underneath. |
| **Invoice**, **Due** | The supplier's invoice date and the date it falls due. |
| **Fund**, **Programme** | Where the bill's first line is coded. |
| **Gross** | The invoice total, VAT included. |
| **WHT** | Withholding tax held back from the supplier. |
| **Net due** | What is actually paid to the supplier — gross less WHT. |
| **Status** | Awaiting approval, Approved, Scheduled, Paid or Rejected — with, on a bill part-way up a multi-signature ladder, a small note of how many have signed and who it's waiting on next. |
| **Age** | *"in Nd"*, *"today"* or *"Nd late"* (in red once it's overdue); **—** once paid. |

The footer reads *"N of M bills · net payable X"*, and beneath it a note of
the withholding policy — *"WHT remitted by the 20th · VAT at X% · payments
cleared through KCB and M-Pesa"*.

### 3. Finding a bill

- **Search** — matches supplier, bill number, KRA PIN, spend category or the
  supplier's own invoice number.
- **Status tabs** — narrow to a status, or to **Overdue** (any open bill past
  its due date, whatever its status).
- **Aging strip** — narrow to a bucket; combines with the status tab and fund
  filter.
- **Fund** — narrow to one fund.
- Click a row (or press Enter on a focused one) to open it in the drawer.

### 4. Selecting bills for a bulk action

Ticking a bill's checkbox opens a selection bar showing how many are
selected, their combined net payable and withholding tax, and four actions:
**Approve**, **Schedule**, **Pay now** and **Clear**. Each bulk action skips
whatever it doesn't apply to — a bill not awaiting approval, one you
prepared yourself — and reports what it skipped and why alongside what it
did. **Pay now** on a selection above what the signed-in approver's step of
the payment-run ladder covers opens a bar asking for the reference of the
authority it escalates to (a board minute, for instance) before it will
release.

### 5. Capturing a new bill

**+ New bill** opens a form to capture a supplier invoice and code it to a
budget line. Nothing is posted to the ledger by this step — only approval
does that.

| Field | Notes |
|-------|-------|
| **Supplier** | Typed as it appears on the invoice, or picked from **Or pull from the ledger**, which fills in the PIN and category. A name not on the supplier register is added to it as *Not pre-qualified*. |
| **KRA PIN** | Validated against the format KRA issues (`P0` then eight digits and a letter). Required — without it VAT and WHT can't be filed. |
| **Spend category** | Sets the default withholding tax rate for the bill. |
| **Supplier invoice number** | The duplicate-payment check: the same supplier's invoice number, or the same supplier at the same value, cannot be captured twice. |
| **Invoice date** | Must fall within a VAT rate in force (set in *Settings → Taxes*). |
| **Payment terms** | One of the terms offered in *Settings → Suppliers*; sets the due date shown alongside. |
| **Budget line** | Fund, programme and grant the bill is coded to, with what's left uncommitted on it. Coding above what's left needs a reason. |
| **Expected payment method** | How the supplier is expected to be paid; changeable later from the drawer. |
| **Lines** | One or more, each a description of what was supplied and an amount before VAT. **+ Add line** appends another. |
| **Withholding tax** | The spend category's policy rate by default, or an override — which needs a reason, for the tax file. |

As you type, the form shows the coded budget line's remaining balance, the
computed due date, a running VAT/WHT/net summary, and warns of a likely
duplicate invoice. A supplier without a current pre-qualification can still
be billed up to the three-quote threshold with a reason; above it, the bill
is refused — pre-qualify the supplier in Procurement first, or buy through a
requisition and purchase order instead. Unless *Settings → Documents* has
turned it off, the supplier's invoice must be attached before **Capture
bill** — the first thing an audit asks for.

### 6. The bill drawer

Click a bill to open it. Alongside its coding, tax and settlement figures
(taxable amount, VAT, gross, withholding tax, net payable to the supplier),
the drawer shows:

- **Approval** — how many signatures a part-signed bill has collected and
  who it's waiting on next, when it's awaiting approval.
- **An overdue banner**, once it's past due, naming the supplier's terms.
- **Why it's rejected**, when it is — the reason the approver gave.
- **A pre-qualification note**, when the bill was captured against a
  supplier without one.
- **Coding** — every line, its account and amount.
- **Supplier's invoice** — the attached document, with a control to attach
  another while the bill is still open to it.
- **Payment method** — editable, for anyone who can prepare or approve
  bills, while the bill isn't yet paid or rejected.
- **In the ledger** — links to the journal the approval posted, the payment
  run it was paid in, and the withholding tax remittance that cleared it.
- **Approval trail** — every step taken on the bill, with when.

### 7. Approving, scheduling and paying

- **Approve for payment** needs **journal.approve**, a different person from
  the preparer, and the signed-in approver's role to be the ladder's open
  step for the bill's value. It posts the cost and irrecoverable VAT to the
  coded lines and credits trade payables and withholding tax payable — once
  the last engaged step signs. A signature that doesn't finish the ladder
  leaves the bill in **Awaiting approval**, noting who signs next; nothing
  posts until then.
- **Reject** swaps the footer for a reason box; confirming moves the bill to
  **Rejected** and notifies the preparer, with the reason kept on the audit
  trail. A rejected bill is a dead end — there is no way to resubmit it; the
  preparer captures a fresh, corrected bill instead.
- **Schedule** moves an **Approved** bill into the earliest draft payment run
  dated today or later, or opens a new one for the coming Friday if none
  exists. It's open to anyone who can prepare or approve bills, and doesn't
  touch the ledger.
- **Pay** releases payment of one or more **Approved** or **Scheduled**
  bills, net of withholding tax, grouped into one payment run per bank or
  mobile-money account they're paid from. Each run posts one journal clearing
  trade payables against that account. It needs **journal.approve**, a
  different person from the preparer, and is checked against the payment-run
  ladder in one act — releasing money has nowhere to hold a part-signed run,
  so a payment-run rule asking for more than one signature at that value is
  refused rather than letting the first signer pay it out; release it in a
  smaller run instead, or set the rule to ask for one signature at that
  value. A run above what the signed-in approver's step covers asks for the
  reference of the authority it escalates to before releasing.

### 8. Remitting withholding tax

**Remit WHT to KRA** pays over everything held on the withholding tax
account across every approved, scheduled or paid bill not yet remitted, once
for the accounting period currently open. It's refused when the period has
already been remitted, when nothing is held, or when no period covers today.
Like releasing a payment run, it needs **journal.approve**, is checked
against the payment-run ladder in one act, and asks for an authority
reference when the amount escalates beyond what the approver's own step
covers. It posts one entry — grouped by fund, programme and grant — debiting
withholding tax payable and crediting the bank it's paid from, referenced
`WHT-YYYY-MM`.

### 9. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Payables** |
| Find a bill | **Search**, a **status tab**, the **aging strip**, or the **Fund** filter |
| Capture a bill | **+ New bill** → fill it in and code it → attach the invoice → **Capture bill** |
| See or edit a bill | Click its row |
| Approve a bill, or a selection | Open it → **Approve for payment**; or select several → **Approve** |
| Reject a bill | Open it → **Reject** with a reason |
| Schedule approved bills for payment | Select them → **Schedule** |
| Pay approved or scheduled bills | Select them → **Pay now**; or open one → **Pay** |
| Change how a bill will be paid | Open it → change **Payment method** |
| Remit withholding tax to KRA | **Remit WHT to KRA** |

### 10. Notes and good practice

- **Capture posts nothing.** A bill sits as figures only until a second
  person approves it — that's the moment the cost, VAT and withholding tax
  reach the ledger.
- **A bill's preparer can neither approve it nor release its payment.** The
  database enforces this, whatever permissions they hold.
- **Rejection has no way back.** Unlike a journal, a rejected bill cannot be
  reopened and resubmitted — capture the correction as a new bill.
- **Releasing money is one act, not a queue.** A payment run and a
  withholding tax remittance post and pay in the same step they're released
  in, so a rule asking either for more than one signature at a value has to
  be satisfied by one signer at that step — split larger payments into
  smaller runs if it isn't.
- **Withholding tax is irrecoverable VAT's opposite number.** VAT on a bill
  is absorbed into its coded cost; withholding tax is held back from the
  supplier and owed to KRA until it's remitted.

---

## Receivables

Receivables is the register of donor claims and other invoices, from a draft
built out of expenditure already in the ledger through to issue, receipt, and
— where a donor will not pay — write-off. Building a claim posts nothing on
its own: it raises grants receivable and recognises the income only once a
second person issues it to the donor, the same maker-checker split as a
Payables bill. What may not come in is provided for as an allowance for
doubtful debts, either set on a claim by hand or by ageing rates applied
across every open claim no one has judged individually.

- **Page:** *Receivables* (`http://localhost:8090/receivables`). A direct
  link to one claim — `/receivables/<reference>` — opens the register with
  that invoice already open, the same as Payables.
- **Who can change it:** viewing, searching and filtering carry no permission
  gate. Building a claim, sending a reminder, recording a receipt or
  attaching a document needs the **journal.prepare** permission; issuing a
  claim to a donor, writing it off, reversing a write-off, or changing its
  allowance needs **journal.approve**. The person who built a claim cannot
  issue it themselves — the drawer says so.

### 1. Getting to the page

Sidebar → **Accounting** → **Receivables**.

### 2. Reading the page

**Header** — the title and a one-line blurb explaining that issuing a claim
raises the receivable and recognises the income, and a receipt clears it
against the account the money lands in, with **Aged statement** and **+ New
donor invoice** at the top right.

**Stat cards:**

| Card | Shows |
|------|-------|
| Total receivable | Outstanding on every issued claim, with a count of issued invoices and, when any allowance is held, the figure net of it |
| Overdue | Outstanding on open claims past their due date, with how many are past due |
| Due within 30 days | Outstanding falling due in the coming month, noting the date it's calculated to |
| Received this month | What has been banked this month |
| Unbilled entitlement | Expenditure already incurred on active awards but not yet claimed from the donor |

**Ageing strip** — six buckets (All outstanding, Current, 1–30 days, 31–60
days, 61–90 days, Over 90 days), each showing the outstanding value and
invoice count in that band. A part-received claim ages on what's left, not
its original amount. Click a bucket to filter the table to it; click it
again to clear.

**Allowance for doubtful debts** — a card for account `1215`, showing what is
held, receivables net of it, and how many claims were set by hand (which the
ageing rates then leave alone). Its table lists, for *Not yet due* and each
ageing bucket, the rate, the outstanding balance, what the rate calls for,
and what is actually held. Anyone who can approve sees **Edit rates**, which
becomes **Apply rates** once changed — disabled with a note when the
allowance already matches the rates it's checked against.

**Toolbar** — **status tabs** (All, Draft, Issued, Part received, Overdue,
Received, Written off, each with a count — Overdue is a computed view of any
open, issued claim past due, not a stored status), a **search** box (donor,
invoice number or grant reference), a **Fund** filter, and a hint reading
either *"N invoices past due"* or, once an aging bucket is picked, *"Aging
filter: <bucket>"*.

**The table** — one page of claims:

| Column | Meaning |
|--------|---------|
| ☐ | Selects the invoice for a bulk action (§4). |
| **Invoice** | The auto-numbered reference, e.g. `INV-26-001`. |
| **Donor** | Name, with the grant reference and claim type underneath. |
| **Issued**, **Due** | The date the claim was issued and the date it falls due. |
| **Fund**, **Programme** | Where the claim is coded. |
| **Invoiced**, **Received** | The claim total, and what has been banked against it. |
| **Outstanding** | What is still owed — never shown negative, even on an overpayment. |
| **Status** | Draft, Issued, Part received, Received or Written off — or **Overdue** in place of the stored status, once a claim is open and past due. |
| **Age** | *"in Nd"*, or *"Nd"* in red once it's overdue. |

The footer reads *"N of M invoices · outstanding X"*, and beneath it: *"Issuing
raises 1210 grants receivable and recognises income · receipts clear 1210 ·
doubtful debts provided for in 1215 against 5370 · write-offs use the
allowance first"*.

### 3. Finding an invoice

- **Search** — matches donor, invoice number, grant reference or programme.
- **Status tabs** — narrow to a status, or to **Overdue** (any open claim
  past due, whatever its stored status).
- **Ageing strip** — narrow to a bucket; combines with the status tab and
  fund filter.
- **Fund** — narrow to one fund.
- Click a row to open it in the drawer.

### 4. Selecting invoices for a bulk action

Ticking an invoice's checkbox opens a selection bar showing how many are
selected and their combined outstanding value, with four actions: **Issue**,
**Send reminder**, **Record receipt in full** and **Clear**. Each skips
whatever it doesn't apply to and reports what it skipped and why alongside
what it did.

### 5. Building a new donor invoice

**+ New donor invoice** opens the claim builder — a claim built from
expenditure already in the ledger, not typed in from scratch. Nothing posts
until it is issued.

| Field | Notes |
|-------|-------|
| **Award being claimed against** | The grant or agreement the claim draws on, or **— No award —** for other income or a county MoU. |
| **Claim type** | Grant claim or Cost reimbursement, or *Other income* when there's no award. |
| **Period claimed** | Free text, e.g. *Jul – Sep 2026*. |
| **Claim lines** | With an award: a grid of budget lines showing budget, spent and unclaimed, each with a **Claim now** amount and an **All** shortcut; **Claim all expenditure** fills every line from what's unclaimed. A line cannot claim more than has been spent — that's what triggers a donor disallowance — nor push total claims past the award's value. |
| **Indirect cost recovery** | Calculated on direct costs actually claimed, not on budget, and capped at the agreement's ceiling; anything above the cap "stays a cost to unrestricted funds" and isn't claimed here. |
| **Invoice to / Income account / What is being invoiced** | Shown instead, for *Other income*: who it's addressed to, the income account it's coded to (outside any fund restriction), and a description of the basis for the invoice. |
| **Invoice currency**, **Rate used** | The currency shown to the donor; the ledger always carries the claim in KES at the rate used, and later movement is an exchange difference, not a shortfall in the claim. |

**Build draft claim** saves it as a draft. Attach the donor's call for the
claim, or the signed contract for other income, if you have it — recommended,
not required.

### 6. The invoice drawer

Click an invoice to open it. Alongside Invoiced, Received and Outstanding,
the drawer shows:

- **Issued**, **Due** (with how many days late, in red, once overdue),
  **Fund**, **Currency** (the foreign amount and rate, when the claim isn't
  in KES), **Basis**, and a link to the posting journal once one exists.
- **Allowance** — what is held against this claim in 1215, and whether it
  was set by hand or follows the ageing rates.
- **Written off**, with the reason, once the claim is in that state.
- **Claim lines** — the budget line, description and amount of each.
- **Receipts** — every receipt against the claim (date, note, reference,
  account and a link to its journal), or a note that nothing has come in
  yet. Anyone who can record receipts sees an inline form: amount, the bank
  account it was banked in, and an optional bank reference.
- **Recovery** — shown instead of a plain receipt form on a written-off
  claim with something still outstanding: money in reverses the write-off
  for what came in, reinstating 1210 against the allowance, clearing it with
  the receipt, and releasing the allowance back to bad and doubtful debts
  (5370).
- **Supporting documents** and **Audit trail**, as elsewhere.

### 7. Issuing, reminders and receipts

- **Issue to donor** needs **journal.approve** and a different person from
  whoever built the claim — the drawer explains why before it lets you. It
  raises grants receivable (1210) and recognises the income on every claim
  line.
- **Send reminder** is open to anyone who can prepare or approve claims; it
  emails the donor the invoice and a ledger extract.
- **Record receipt** (in the drawer, or **Record receipt in full** on a
  selection) needs journal.prepare or journal.approve. It clears what came in
  against 1210, in full or in part.

### 8. Writing off a claim, and reversing it

- **Write off** needs journal.approve and is only offered once a claim is
  late. Give the reason the donor won't pay — the claim cannot be written
  off without one. It clears what's outstanding from 1210, drawing first on
  whatever allowance is already held against the claim and charging only the
  remainder to bad and doubtful debts (5370).
- **Record recovery** on a written-off claim reverses it for whatever comes
  in afterwards: 1210 is reinstated against the allowance, the receipt clears
  it, and the allowance is released back to 5370.

### 9. Managing the allowance for doubtful debts

- **Set allowance** / **Change allowance** (needs journal.approve) provides,
  by hand, for however much of one claim's outstanding balance may not come
  in. The change posts between 1215 and bad and doubtful debts (5370), and
  from then on the ageing rates leave that claim alone.
- **Edit rates** / **Apply rates**, on the allowance card, sets what
  percentage of each ageing bucket to provide for and posts the difference
  across every claim not set by hand. It's disabled once the allowance
  already matches what the rates call for.

### 10. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Receivables** |
| Find an invoice | **Search**, a **status tab**, the **ageing strip**, or the **Fund** filter |
| Build a claim | **+ New donor invoice** → choose the award (or *Other income*) → claim lines → **Build draft claim** |
| Issue a claim, or a selection | Open it → **Issue to donor**; or select several → **Issue** |
| Remind a donor | Open it → **Send reminder**; or select several → **Send reminder** |
| Record a receipt | Open it → fill in the receipt form → **Record receipt**; or select several → **Record receipt in full** |
| Write off a claim | Open it → **Write off** with a reason |
| Reverse a write-off | Open the written-off claim → **Record recovery** |
| Provide for one claim by hand | Open it → **Set allowance** / **Change allowance** |
| Provide across every claim by age | Allowance card → **Edit rates** → **Apply rates** |
| Send the ageing position | **Aged statement** (CSV) |

### 11. Notes and good practice

- **Building a claim posts nothing.** It sits as a draft until a second
  person issues it — that's the moment 1210 and income are raised.
- **A claim's preparer can't issue it themselves.** Get someone else to
  check and issue it.
- **There is no separate customer register.** The donor or funder is the
  counterparty; a claim is built directly against an award (or, for other
  income, addressed to whoever is being invoiced).
- **The allowance is two mechanisms, not one.** A claim set by hand keeps its
  own allowance; every other open claim follows the ageing rates — applying
  the rates never touches a claim someone has already judged individually.
- **A write-off draws on the allowance first.** Only what wasn't already
  provided for hits bad and doubtful debts (5370); money that comes in later
  reverses that in the same order.

---

## Procurement

Procurement is the requisition-to-pay pipeline: a requisition is raised,
approved against budget, quoted, turned into a purchase order, matched
against what was actually received, and finally billed — landing in
[Payables](#payables) for its own approval and payment. Nothing is spent
against the budget until a purchase order is raised, and nothing is billed
until the goods received note and the supplier's invoice line up with it —
the three-way match.

- **Page:** *Procurement* (`http://localhost:8090/procurement`). A direct
  link to one requisition — `/procurement/<reference>` — opens the register
  with that requisition already open, the same as Payables and Receivables.
- **Who can change it:** viewing, searching and filtering carry no permission
  gate. Raising a requisition needs **requisition.raise**; issuing an RFQ,
  raising the purchase order, recording goods received and raising the
  supplier bill need **journal.prepare**; approving or rejecting a
  requisition needs **journal.approve**. The person who raised a requisition
  cannot approve or reject it themselves — the database refuses it, not just
  the screen. Registering a supplier or editing its details needs either
  permission; pre-qualifying, renewing, blocking or restoring a supplier
  needs **journal.approve**.

### 1. Getting to the page

Sidebar → **Accounting** → **Procurement**.

### 2. Reading the page

**Header** — the title and a one-line blurb: *"Requisition to purchase order
to goods received. Budget availability is checked before approval, three
quotations are required above KES 500,000, and a goods received note raises
the supplier bill."* **+ New requisition** sits at the top right; **+ Add
supplier** joins it on the Suppliers view.

**View switcher** — four views: **Requisitions**, **Purchase orders**,
**Goods received**, **Suppliers**.

**Stat cards**, computed across the whole register whichever view is open:

| Card | Shows |
|------|-------|
| Open requisitions | Count of requisitions not yet Rejected, Goods received or Closed, with *"Across N programmes"* |
| Awaiting my approval | Count of Awaiting-approval requisitions that are the viewer's to sign (excluding ones they raised); reads *"Awaiting approval"* for anyone who can't approve |
| Value in progress | Sum of the open requisitions' amounts — *"Not yet invoiced"* |
| Committed on POs | Sum of amounts on purchase orders still awaiting delivery — *"Reserved against budget lines"* |
| Over available budget | Count of open requisitions currently over their budget line — *"Need a budget revision first"* |

**Requisitions table** (the default view):

- **Status tabs** — Open, Awaiting approval, Approved, RFQ issued, PO raised,
  Goods received, Closed, All, each with a count. Open is every requisition
  that hasn't been rejected, received or closed, not a stored status.
- **Search** — requisition number, item, requester, programme or any line
  description.
- **Columns:** Requisition, Item, Requester (name · role), Programme, Grant
  / award, Fund, Raised, Need by, Value, **Budget left** (red and bold once
  over), Status (with, under a part-signed pill, *"N/M · awaiting …"*).
- Footer: *"N of M requisitions · three quotations required above KES
  500,000 · goods received notes raise the supplier bill."*

**Purchase orders view:** Order, Supplier, Item, Requisition, Issued,
Expected, Value, and **Three-way match** — a pill (Awaiting delivery /
Received / Closed) with a hint that reads *PO only*, then *PO · GRN matched,
invoice awaited*, then *PO · GRN · invoice matched* as each leg lands.
Footer: *"N purchase orders · KES X committed and not yet received."*

**Goods received view:** Note (GRN reference), Order, Supplier, Received
(item and GRN note), Date, Signed by, Value, Invoice (the bill reference, or
*"Not yet invoiced"*). Footer: *"N goods received notes · N awaiting the
supplier invoice for three-way matching."*

**Suppliers view:** Supplier, KRA PIN, Category, Pre-qualified to, Rating,
Withholding, Spend YTD, and a status pill — **Pre-qualified**, **Expiring**
(inside the pre-qualification warning window), **Lapsed**, **Not
pre-qualified** or **Blocked** — plus how many open purchase orders sit with
them. Footer: *"N suppliers · pre-qualification runs to calendar year end ·
lapsed suppliers cannot be selected on a purchase order."*

### 3. Finding a requisition

- **Search** — requisition number, item, requester or programme.
- **Status tabs** — narrow to a stage, or **Open** for everything still live.
- Click a row, in any view, to open its requisition in the drawer.

### 4. Raising a new requisition

**+ New requisition** opens the requisition builder. Nothing is committed
against the budget yet — that happens only once a purchase order is raised.

| Field | Notes |
|-------|-------|
| **What is being bought** | Free text. Required. |
| **Grant / award** | The award or fund the purchase draws on, grouped by award, or *Unrestricted — own income* for general funds. |
| **Programme** | Filtered to programmes the chosen award actually funds. |
| **Budget line** | The account/fund/programme/grant combination actually charged — approved budget lines in the working year only. Required. |
| **Requester** | Defaults to the signed-in user; can be set to anyone who holds **requisition.raise**, for staff raising on someone else's behalf. |
| **Needed by** | Optional date. |
| **Preferred supplier** | Free text, used only if no quotation is later marked chosen. |
| **Requisition lines** | Repeatable: description, quantity (default 1), unit price → computed amount. At least one valid line is required. |
| **Quotations** | Repeatable: supplier, an evaluation note, an attached quotation document, an amount, and a radio marking one "chosen." A supplier not yet on the register is added automatically, as **Not pre-qualified**. |
| **Justification** | Free text — why the purchase is needed, or the single-source reasoning. |

As you fill it in, the page shows a running total and a **budget check**
against the chosen line — its uncommitted balance (budget less actual less
committed), and, if the requisition would exceed it, a note that the draft
can still be saved but approval will be blocked until a budget revision is
posted. Above KES 500,000, a note reminds you that three quotations (or a
single-source justification) will be needed before a purchase order can be
raised — a quotation only counts once its document is attached.

**Build draft requisition** saves it as a **Draft**. Nothing posts and
nothing is checked against budget until it is submitted and approved.

### 5. The requisition drawer

Click a requisition, in any view, to open it.

- **Header** — reference, status pill, title, requester · programme · need
  by date.
- An **approval banner** while awaiting a signature, an amber **needs
  quotes** alert once above threshold with fewer than three documented
  quotations, a red **over budget** alert naming the line and what's
  available, and the rejection reason once rejected.
- **Figures** — Value, Budget, Spent, Available (bold and red once over).
- **Detail** — budget line, grant/award and fund, supplier (or *"Not yet
  selected"*), the order once raised (PO number, issued and expected dates),
  goods received once recorded (GRN reference, date, who signed for it, a
  link to the accrual journal), the supplier's bill once raised (a link
  straight into its Payables entry), any single-source justification, and
  the requisition's own justification.
- **Requisition lines** and **Quotations** (with the chosen one marked and a
  link to its attached document, when there is one).
- **Audit trail** — every step, chronologically, including each ladder
  signature and who it's still awaiting.

**Footer actions**, shown only when they apply:

| Button | Available when |
|---|---|
| **Submit for approval** | Draft, and you raised it or can prepare |
| **Approve** | Awaiting approval, you can approve, and you didn't raise it |
| **Reject** | Same as Approve; opens a reason box |
| **Issue RFQ** | Approved, and you can prepare |
| **Raise purchase order** | Approved or RFQ issued, and you can prepare |
| **Record goods received** | PO raised, and you can prepare |
| **Raise supplier bill** | Goods received, and you can prepare |

When the signed-in person both raised the requisition and can approve, a
note stands in place of Approve/Reject: *"You raised this requisition, so a
second person must approve it."*

### 6. Submitting, approving and rejecting

- **Submit for approval** moves a Draft to **Awaiting approval** and
  notifies whichever role the approval ladder engages first. With no
  requisition-specific ladder configured in *Settings → Approvals*, the
  default is a single signature from the Finance Manager, unlimited amount.
- **Approve** needs journal.approve and a different person from whoever
  raised it. It is refused outright — not just warned — if the requisition
  exceeds the available balance on its budget line; a budget revision or
  reallocation has to land first. A signature that doesn't finish the ladder
  leaves the requisition in **Awaiting approval**, noting who signs next.
- **Reject** opens a reason box; confirming moves the requisition to
  **Rejected**, with the reason kept on the audit trail. Like a Payables
  bill, this is a dead end — there is no way to resubmit a rejected
  requisition; raise a fresh one instead.

### 7. Quoting and raising the purchase order

- **Issue RFQ** broadcasts a request for quotation to every pre-qualified
  or expiring supplier on the register. It's optional — a requisition can go
  straight from Approved to a purchase order if quotations are already on
  file.
- **Raise purchase order** commits the requisition's value against its
  budget line — this is the point spending is actually reserved, not
  approval. It asks who the order is placed with (defaulting to the chosen
  quotation), and, if the three-quote rule isn't met, a required
  single-source justification. It's refused if the supplier isn't
  pre-qualified or is Expiring past its own lapse date, Lapsed, Not
  pre-qualified or Blocked.

### 8. Receiving goods and raising the supplier bill

- **Record goods received** captures who signed for the delivery and an
  optional note, and posts an accrual journal — debiting the requisition's
  coded expense account and crediting goods received not invoiced (2120).
  This is what moves the value from committed to actual; the purchase order
  itself stays committed in parallel until its bill is approved.
- **Raise supplier bill** asks for the supplier's invoice number and an
  attached invoice document, then creates the bill in
  [Payables](#payables) — coded line-for-line from the purchase order, with
  VAT and withholding tax computed the same way a bill typed by hand would
  be — and closes the requisition. The bill lands **Awaiting approval** in
  its own register; approving it there debits the 2120 accrual instead of
  the expense account again, so the accrual and the bill never double-count
  the cost. It's refused without an invoice number, on a duplicate invoice
  number for the same supplier, or if the supplier has no KRA PIN on file.

### 9. Managing suppliers

On the **Suppliers** view, **+ Add supplier** or opening an existing row
lets you register or update a supplier's details (name, KRA PIN, category,
withholding rate) — open to anyone who can prepare or approve. Only someone
who can approve can pre-qualify, renew, block or restore a supplier. A KRA
PIN already used on a bill cannot be changed, to protect filed withholding
certificates. A supplier past its pre-qualification date shows **Lapsed**
and cannot be placed on a purchase order until it is renewed.

### 10. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Procurement** |
| Find a requisition | **Search**, or a **status tab** |
| Raise a requisition | **+ New requisition** → fill it in and code it → attach quotations → **Build draft requisition** |
| Submit it for approval | Open it → **Submit for approval** |
| Approve or reject a requisition | Open it → **Approve** or **Reject** with a reason |
| Ask suppliers for quotes | Open an approved requisition → **Issue RFQ** |
| Commit the spend | Open it → **Raise purchase order** |
| Record what arrived | Open it → **Record goods received** |
| Bill the supplier | Open it → **Raise supplier bill** |
| Register or pre-qualify a supplier | Suppliers view → **+ Add supplier**, or open a row |

### 11. Notes and good practice

- **A requisition doesn't touch the budget until a purchase order is
  raised.** Drafting and even approving it is free — the commitment appears
  only once **Raise purchase order** runs.
- **The budget commitment outlives goods receipt.** Receiving goods posts
  the cost as actual, but the purchase order line stays committed until its
  bill is approved in Payables — a requisition can show **Closed** while its
  budget line is still carrying the commitment for a few more days.
- **A rejected requisition cannot be resubmitted.** Raise a fresh one with
  the correction, the same as a rejected Payables bill.
- **A requisition's preparer can neither approve it nor raise the purchase
  order and bill unless they also hold journal.prepare.** Approval always
  needs a second person; the database enforces it.
- **Only a document makes a quotation count.** Naming a supplier and an
  amount isn't enough toward the three-quote rule — the quotation's file has
  to be attached.
- **Procurement never creates a bill by itself.** **Raise supplier bill**
  hands the three-way match to Payables, where it is approved, scheduled and
  paid like any other bill.

---

## Bank reconciliation

Bank reconciliation sets one cash account's bank (or M-Pesa) statement for a
month against its cash book — the posted ledger lines on that account in the
period. The cash book opens where the last reconciliation agreed, so a month
is reconciled on its own movements. Every statement line has to end up either
**matched** to a posting whose amount agrees exactly, or **journalised**
first when the bank put it through on its own account (charges, interest,
exchange gain, an unidentified credit). Signing off satisfies the
period-close check for that account; nothing on a signed-off, or a
period-closed, reconciliation can change until it is reopened.

- **Page:** *Bank reconciliation* (`http://localhost:8090/bank-rec`). A
  direct link — `/bank-rec?account=<code>&period=<Month Year>` — opens that
  account's statement for that month straight away.
- **Who can change it:** viewing carries no permission gate, but a role that
  cannot prepare sees why the upload button is disabled. Loading a statement
  needs **journal.prepare**. Matching, unmatching and auto-matching need
  either **journal.prepare** or **journal.approve**. Raising the journal for
  a bank-only entry needs **journal.prepare**. Completing or reopening a
  reconciliation needs **journal.approve** — and whoever loaded the
  statement (its preparer) cannot also sign it off; the database refuses it,
  not just the screen.

### 1. Getting to the page

Sidebar → **Accounting** → **Bank reconciliation**. From the Dashboard, an
unreconciled account under period-close checks links straight into its
latest open statement.

### 2. Reading the page

**Header** — a kicker (*"Reconciled and signed off"*, *"Agreed · awaiting
sign-off"*, or *"N statement lines to explain"*), the title, and a blurb:
*"The bank's statement on the left, the cash book on the right. Every line
on the statement has to be matched to a posting or journalised before the
reconciliation can be completed. Cheques and transfers not yet presented
stay in the book as uncleared."* Actions on the right: **Upload statement**;
**Reopen reconciliation** (signed-off reconciliations only); **Auto-match**
(hidden once signed off); and a primary button that reads **Complete
reconciliation**, **Cannot complete · <the gap>**, or **Reconciled ✓**.

**Account and period picker** — an **Account** dropdown and a **Statement
period** dropdown, plus the statement's reference and, once signed off,
*"Signed off by <name> on <date>"* — or, for a closed accounting period,
*"The period is closed · read only."* Below it, a note names the last upload
(file, who, when, how many uploads) or, in amber, warns that the statement's
lines don't add up to the bank's own printed closing balance — part of the
statement is missing.

**Stat cards:** Balance per statement (as at period end); Balance per cash
book (with the count of postings in the period); Payments not yet presented
(cheques and transfers out, with receipts still in transit noted alongside);
Unmatched on statement (a count, with the unexplained amount); Difference
(the gap between the two sides — must be nil to complete).

**Two side-by-side panels:**

| | Bank statement (left) | Cash book (right) |
|---|---|---|
| Row | Checkbox, date, description, reference, amount | Checkbox, date, description, reference (linking into the [General ledger](#general-ledger)), amount |
| Tag when matched | ✓ matched to *<posting reference(s)>*, with an **unmatch** link | ✓ cleared |
| Tag when open | The statement reference; a **journalise** button (*"Post bank charges →"*, *"Post interest received →"*, *"Post exchange gain →"*, *"Post to suspense →"*) when the line is one of the bank's own entries, or a link to *<reference> awaiting approval* once it's been journalised but not yet approved | ◐ not yet presented |
| Extra tag | — | *raised from the statement*, on a journal Bank reconciliation itself posted |

Each panel's header repeats its closing balance and a hint (*"N of M
unmatched"* / *"all N matched"*; *"N uncleared"* / *"all cleared"*), and its
footer explains what the side represents.

**Reconciliation statement**, underneath both panels: balance per cash
book; add payments issued but not yet presented; less receipts banked but
not yet credited; the adjusted balance; balance per bank statement; and the
unexplained difference. The footer reads, in green, that the two sides agree
and the reconciliation can be signed off, or, in red, what's left to explain
— and whether that means journalising the bank's own entries, matching what
remains, or (when the statement nets to nil but lines are still open) that
matching itself is still needed before sign-off.

### 3. Choosing an account and period

Pick the **Account**, then the **Statement period** — only periods that
already have a statement loaded are listed. Switching either clears any
lines currently ticked.

### 4. Uploading a statement

**Upload statement** opens a drawer for loading a CSV downloaded from the
bank's or M-Pesa's portal.

1. Pick the **Account** and **Statement period**. The status line reports
   whether the account has a statement format set (Settings → Bank
   statements), whether this month is already signed off (blocking further
   uploads until reopened), and — for a month with a statement already
   loaded — how many lines and what it currently closes at. New lines are
   added; lines already on the statement are recognised and skipped, so the
   same download can be loaded more than once safely.
2. For a brand-new statement, enter the **Opening balance** printed on it
   (or accept it carried over from where the last one closed) and the
   **Closing balance** the bank printed. An optional **Statement reference**
   and the **Statement file (CSV)** complete the form.
3. **Check file** previews the import without saving: how many rows were
   read, and how many are new, already loaded, outside the chosen month, or
   unreadable. Every check has to pass before it can be loaded:
   - every row in the file could be read;
   - there is at least one new line dated in the chosen period;
   - where the bank prints a running balance, each line follows from the one
     before, and the first line follows from the opening balance; and
   - opening balance plus every line on the statement equals the closing
     balance entered.

   A statement line the bank raised on its own account (a charge, interest,
   an exchange gain, an unidentified credit) can be given its kind from a
   dropdown on each new row — left as *"Matched to the cash book"* for an
   ordinary line that already has a posting behind it.
4. **Load statement** is enabled once every check passes, and reports how
   many lines were loaded (and how many duplicates were skipped).

### 5. Matching lines

Tick a line on the statement and a line (or several) in the cash book whose
amounts sum to the same figure — the selection bar along the top of the
panels shows how many are ticked on each side, the two sums, and whether
they agree. **Match** is enabled only once they agree exactly; it is
refused otherwise, even by a penny. **Clear** drops the current selection.
An already-matched line can be **unmatched** from its tag, reopening both
sides.

**Auto-match** pairs every statement line against an uncleared cash-book
line for the exact same amount, in one pass, and reports how many it found
— *"Nothing left to match on amount alone — what remains needs a judgement
call"* when there's nothing left to pair automatically. It still leaves
dates to be checked by eye before signing off.

### 6. Journalising the bank's own entries

A statement line the bank put through itself — charges, interest, an
exchange gain, or an unidentified credit — carries a **journalise** button
instead of a match. Raising it posts a journal against the account
configured for that kind of entry in *Settings → Ledger* and sends it into
[Journals](#journals) **Awaiting approval**; the statement line stays
unmatched, shown as *"<reference> awaiting approval"*, until that journal is
approved there, at which point it posts to the cash book and clears
automatically against the statement line that raised it.

### 7. Completing and reopening a reconciliation

**Complete reconciliation** is enabled only once every statement line is
matched or journalised, the difference is nil, and the statement's lines add
up to the bank's own printed closing balance. It also refuses to let the
person who loaded the statement sign it off themselves — a second person,
holding journal.approve, has to complete it. Completing satisfies the
period-close check for that account.

**Reopen reconciliation** is available on a signed-off reconciliation whose
period is still open; it withdraws the period-close check and returns the
reconciliation to matching. A period that has itself been closed has to be
reopened in [Period close](#period-close) first.

### 8. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Accounting** → **Bank reconciliation** |
| Switch account or month | **Account** / **Statement period** dropdowns |
| Load a new statement | **Upload statement** → pick account and period → **Check file** → fix any failing check → **Load statement** |
| Pair a statement line with its posting | Tick both, matching lines → **Match** in the selection bar |
| Undo a pairing | Open the matched statement line's tag → **unmatch** |
| Match everything obvious at once | **Auto-match** |
| Post a bank charge, interest, FX gain or suspense entry | Open the statement line's **journalise** button, then approve it in Journals |
| Sign off the month | Clear every line, get the difference to nil → **Complete reconciliation** |
| Rework a signed-off month | **Reopen reconciliation** (period must still be open) |

### 9. Notes and good practice

- **A match must agree to the last cent.** There's no partial or
  tolerance-based matching — select the postings that sum to exactly the
  statement line, splitting or combining as needed.
- **A bank-raised entry stays unmatched until its journal is approved.**
  Journalising doesn't post immediately; it queues the entry in Journals,
  the same as any other prepared journal, and only approval clears it
  against the statement.
- **The preparer can't also be the approver.** Whoever loaded the statement
  is recorded as its preparer and is blocked from completing it themselves,
  the same second-person rule as every other approval in the ledger.
- **A reconciliation locks the moment it's signed off or its period
  closes.** Reopen it (or reopen the period first, in Period close) before
  trying to change a match.
- **Several downloads in the same month are safe to load.** Lines already on
  the statement are recognised and skipped, so reloading a fuller export
  partway through the month only adds what's new.

---

## Asset register

The asset register is the subsidiary record behind the fixed-asset control
accounts — cost of property and equipment (1310 and 1320) and accumulated
depreciation (1390). Every capitalised item shows what it cost, what it has
been written down by, and who holds title. An asset reaches the register one
of two ways: **capitalising** a purchase already posted to a cost account, or
**adding** something with no purchase behind it — donated in kind, or found in
a physical count and never recorded. Depreciation is calculated for the whole
register and posted to the ledger in a single monthly run; taking an asset off
the register happens through a **disposal**, which — like every write to the
register — needs a second person's approval before it posts.

- **Page:** *Asset register* (`http://localhost:8090/asset-register`). A
  direct link — `/asset-register?asset=<tag>` — opens that asset's drawer
  straight away.
- **Who can change it:** viewing the register and opening an asset's drawer
  carry no permission gate — anyone signed in can see it. Capitalising a
  purchase, proposing an addition or a disposal, running depreciation, and
  attaching a document all need the **journal.prepare** permission (the same
  one used on Journals and General ledger); approving an addition or a
  disposal needs **journal.approve**. Withdrawing a pending addition or
  disposal needs either. As everywhere else in the ledger, the person who
  proposed an addition or disposal cannot also approve it — the database
  refuses it, not just the screen.

### 1. Getting to the page

Sidebar → **Accounting** → **Asset register**, between Bank reconciliation
and Asset verification. **Asset count sheet** (top of the page) crosses over
to **Asset verification** — the physical count that feeds this page's "found
in a count" items.

### 2. Reading the page

**Header** — a kicker reporting whether the month's depreciation is posted
(*"Depreciation posted for \<period>"* or *"Depreciation for \<period> not
yet posted"*), the title **Asset register**, and a blurb: *"Every capitalised
item, what it cost, what it has been written down by, and who holds title.
Depreciation is calculated here and posted to the ledger in one monthly run —
the register is the subsidiary record behind accounts 1310, 1320 and 1390."*
Three buttons on the right:

- **Asset count sheet** — opens Asset verification.
- **+ Add donated or found asset** — opens the addition modal (§6); disabled,
  with the tooltip *"Only a preparer can propose an asset"*, unless you hold
  journal.prepare.
- **Run depreciation for \<period>** — the primary button; reads **Depreciation
  posted ✓** (disabled) once the month is done, with a tooltip naming the
  journal it posted as. See §8.

**Stat cards**, five:

| Card | Shows |
|---|---|
| Cost of assets held | Total cost of every asset still held, with a count |
| Depreciation to date | Accumulated depreciation of assets still held, and what % of cost that is |
| Net book value | Cost less depreciation to date |
| Charge for \<period> | This period's depreciation charge — posted to 5350 once the run is done |
| Donor-funded book value | Net book value of assets carrying a funder, with a count — *"title may revert"* |

**Filter row** — a segmented tab control (**All**, **In use**, **Donor-funded**,
**Fully depreciated**, **Disposed**), each labelled with a count; a search box
(*"Search tag, description, funder or custodian"*); and a right-aligned hint
naming whether the period's charge is in the ledger yet.

**The register table** — ten rows a page, columns **Tag, Asset, Funder, Cost,
Depn to date, Net book value, Monthly, Status**:

| Column | Meaning |
|---|---|
| Tag | The asset's register tag, e.g. `VEH-001`. |
| Asset | Name, with a sub-line: class · useful life in years · date acquired. |
| Funder | The grant's funder, or **Core funded** if none. |
| Cost | Original cost. |
| Depn to date | Accumulated depreciation. |
| Net book value | Cost less depreciation to date. |
| Monthly | This period's depreciation charge for that one asset. |
| Status | **● In use**, **◐ Fully depreciated** or **○ Disposed**. |

An empty result reads *"No asset matches that search."* The footer states
*"N of M assets · N donor-funded · N fully written down"*, and, on the right,
the fixed depreciation policy: *"Straight line over useful life · nil
residual · charged from the month after acquisition."*

### 3. Finding an asset

- **Tabs** — **All**, **In use**, **Donor-funded**, **Fully depreciated**,
  **Disposed**.
- **Search** — matches tag, name, funder, custodian or class as you type.

### 4. Viewing an asset

Click any row to open the asset drawer.

- **Facts** — Class; Acquired (with the document reference, if any); Cost;
  Depreciation to date; Net book value (relabelled **Net book value at
  disposal** once disposed); Policy (*"Straight line over N years, nil
  residual · KES X a month"*); Fund and grant; Programme; Title (the title
  condition recorded for it, or —); Custodian and location; and a Status
  sentence — for an asset in use, just its status; for one with a disposal
  proposed, *"In use · disposal proposed \<date>, waiting for approval — the
  asset stays on the register and keeps depreciating until it is posted"*;
  for one disposed, *"Disposed \<date> by \<method> · proceeds \<amount>,
  \<gain/loss> of \<amount> to \<account> · \<journal ref>"*.
- **Cost account \<code>** — a link that opens [General ledger](#general-ledger)
  on the asset's cost account. Alongside it: **Propose disposal** (§7), shown
  only while the asset is held and has no disposal already proposed; or a
  note that a disposal is proposed and awaiting approval, or already posted.
- **Depreciation schedule** — a year-by-year table (Year, Opening, Charge,
  Closing) over the asset's useful life, the current year marked, and a
  disposed asset's final row labelled with its disposal date.
- **Depreciation in the ledger** — this asset's own share of every posted
  monthly run: two figures (its accumulated depreciation and depreciation
  expense to date) and a table of each run's date, the journal it posted as,
  its charge and the running 1390 balance; older rows fold into a single
  expandable *"N earlier monthly charges"* line. A footnote explains that
  1390 and 5350 post by fund/programme/grant, not by asset, so this is the
  asset's own reconstructed share.
- **Documents** — attach the purchase invoice or deed of gift, and title
  documents for a vehicle or land; empty until something is attached.
- **History** — a chronological audit trail for the asset.

Close with **✕**, the backdrop, or **Esc**; the address bar returns to
`/asset-register`.

### 5. Bringing a purchase onto the register

A **"Coming onto the register"** section sits below the table, with two
kinds of item waiting to join the register.

**Purchases waiting to be capitalised** lists every posted journal or bill
line charged to a class cost account (1310/1320) that hasn't yet been fully
capitalised — reference and description, the account it was charged to, the
amount purchased, how much of it is already capitalised, and what's still
waiting. When nothing is waiting, the section reads: *"Every purchase posted
to the asset cost accounts is on the register. A bill or journal charged to
them appears here to be capitalised."*

**To capitalise a purchase:**

1. Click **Capitalise** on its row (disabled, with the tooltip *"Only a
   preparer can capitalise"*, unless you hold journal.prepare). The modal
   opens.
2. Choose the **Asset class** — only classes carried on the same cost account
   as the purchase are offered; changing it resets **Useful life** to the
   class default.
3. Adjust the **Description**, the **Cost to capitalise** (defaults to the
   full amount still waiting), and, to split one purchase line into several
   identical assets, the number of **Assets to tag** (1–500) — tags are
   issued in sequence, e.g. `IT-031`, `IT-032`, `IT-033`.
4. Record where it's kept (**Location**), who holds it (**Custodian**), a
   **Serial number** if it has one, and a **Title** note if title is
   conditional (for example reverting to a donor).
5. The right-hand summary shows what's left waiting after this capitalisation
   and, per asset, its cost, monthly depreciation and the month depreciation
   starts (the month after the purchase's date). A note explains that nothing
   new posts — the purchase already carried the cost; the asset inherits its
   date, fund, programme and grant from it.
6. Click **Put on the register**. It's disabled while any rule is unmet — for
   example an amount greater than what's left to capitalise, a useful life
   outside 1–50 years, or no location recorded.

### 6. Adding a donated or found asset

Use this only for something with **no purchase behind it**. A purchase is
always capitalised (§5) instead — the modal refuses a value that exactly
matches a purchase still waiting on the same cost account, and tells you so.

1. Click **+ Add donated or found asset** (top of the page), or **Add to
   register** on an item already listed under *"Found in a count but never
   recorded"* (populated from Asset verification).
2. Choose the **Basis** — **Donated in kind** or **Found in a count, never
   recorded** — which changes the rest of the form:
   - **Found** — pick the counted asset, its deemed cost (pre-filled from the
     count), which count found it, and an optional reference, plus why it was
     never recorded and how the value was set.
   - **Donated** — the asset class and useful life, a description, its fair
     value, the donor, a reference to the deed of gift or donor letter,
     location and custodian, any title condition, and what was donated and
     how the fair value was set.
3. Attach the count sheet or photo (found) or the deed of gift, donor letter
   or valuation (donation) — recommended, not required.
4. The summary previews the journal that posts on approval: a **found** item
   debits the class cost account and credits **3100 Fund balance** — it
   corrects an omission from an earlier year, not this year's income; a
   **donation** credits **4260 Donated assets** instead — income in kind at
   fair value when it's received. Both depreciate from the month after.
5. Click **Submit for approval**.

The item then sits under **"Pending additions"**, tagged **Awaiting
approval**, until a second person clicks **Approve and post** (needing
journal.approve; refused if they proposed it themselves) — which posts the
journal above and puts the asset on the register — or either person clicks
**Withdraw** to drop it.

### 7. Disposing of an asset

1. Open the asset (§4) and click **Propose disposal**.
2. Choose the **Method** — Public auction, Direct sale, Trade-in, Donation to
   a partner, or Write-off (beyond repair) — the **Disposal date**, and the
   **Proceeds**, if any. The buyer field relabels itself to match the method
   (*Disposal contractor*, *Receiving partner*, or *Buyer*).
3. Record the **Board minute** that approved the disposal, and, if the asset
   is donor-funded and its title condition mentions reverting to the donor, a
   **Donor consent reference** — the modal won't let the disposal through
   without one. Add the **reason and condition**.
4. The right-hand summary works out the **net book value at disposal**
   (cost less depreciation to date) and the **gain or loss on disposal**
   (proceeds less that net book value), and previews the journal that posts
   on approval — releasing the accumulated depreciation, banking any
   proceeds, recognising the gain or loss, and clearing the asset's cost —
   all coded to the asset's own fund, programme and grant, so proceeds from a
   donor-funded asset stay in the donor's fund.
5. A note beneath the form explains what donor funding means for this
   disposal: if title reverts, that proceeds stay in the fund and must be
   reapplied to the award or returned; otherwise, that ELOG holds title but
   the disposal still has to be reported in the next donor report.
6. Click **Submit for approval**. It's disabled while a rule is unmet — no
   board minute, missing donor consent where it's needed, a write-off
   proposing proceeds, a sale proposing none, a date before the asset was
   acquired or outside an open period, or no reason recorded.

While pending, the drawer shows *"disposal proposed … waiting for approval —
the asset stays on the register and keeps depreciating until it is posted."*
It also appears under the page's **Disposals** section, tagged **Awaiting
approval**, where either person can **Withdraw** it or a second person
(holding journal.approve, and not the one who proposed it) can **Approve and
post**. Approving posts the journal and moves the asset to **Disposed**; the
section then shows it as **✓ Posted \<journal ref>**.

> An asset whose purchase was capitalised cannot have that journal reversed
> while it's still on the register — dispose of the asset first, or correct
> the original purchase with a new journal instead.

### 8. Running the monthly depreciation

**Run depreciation for \<period>** calculates and posts, in one journal, the
charge for every asset still in use: straight line over its useful life, nil
residual, nothing in the month of acquisition, stopping once an asset is
fully written down or disposed. It posts once per period — the button reads
**Depreciation posted ✓** once it has, naming the journal — and is refused if
there's no open period to post to, or nothing left in use to depreciate.

### 9. Checking the register against the ledger

**"Register against the ledger"**, at the foot of the page, ties the
register's totals to the control accounts for the period: cost of property
and equipment (1310/1320), accumulated depreciation (1390), and the net book
value they imply — register total, ledger total, and the difference, each
row. When they agree the footer reads *"✓ The register agrees to the control
accounts."* When they don't, it names the cause where it can — usually a
purchase posted to the ledger but not yet capitalised — and tells you to
capitalise it to bring the register back into agreement.

### 10. Quick reference

| I want to… | Do this |
|---|---|
| Open the page | Sidebar → **Accounting** → **Asset register** |
| Find an asset | A **tab**, or **Search** |
| See an asset's detail | Click its row |
| Jump to its postings | Open it → **Cost account \<code>** |
| Put a purchase on the register | **Capitalise** on its row under *Coming onto the register* |
| Register something with no purchase behind it | **+ Add donated or found asset** |
| Approve or drop a pending addition | Under **Pending additions** → **Approve and post** / **Withdraw** |
| Take an asset off the register | Open it → **Propose disposal** |
| Approve or drop a pending disposal | Under **Disposals** → **Approve and post** / **Withdraw** |
| Post the month's depreciation | **Run depreciation for \<period>** |
| Check the register ties to the ledger | **Register against the ledger**, at the foot of the page |
| Go to the physical count | **Asset count sheet** |

### 11. Notes and good practice

- **A purchase is always capitalised, never added.** The addition modal
  refuses an amount that matches a purchase still waiting to be capitalised
  on the same cost account — that purchase is capitalised instead.
- **Depreciation starts the month after acquisition**, straight line, nil
  residual, and never runs past what's left to depreciate.
- **A disposal keeps depreciating until it's posted.** Proposing one doesn't
  take the asset off the register — only approval does.
- **The two-person rule applies throughout.** Whoever proposes a
  capitalisation, addition or disposal cannot also approve it; both use the
  same journal.prepare / journal.approve permissions as the rest of the
  ledger.
- **Donor-funded assets carry extra care on disposal.** Where title reverts,
  proceeds stay in the donor's fund; where it doesn't, the disposal still
  has to be reported to the donor.
- **The register is the subsidiary record.** It should always tie to 1310,
  1320 and 1390 — an untied difference is almost always a purchase not yet
  capitalised.

---

## Asset verification

Asset verification is the physical count against the [asset register](#asset-register)
— walking the floor and confirming every capitalised item is where the
register says it is. A count is evidence, so a result is never just a tick:
an asset that isn't produced, or shows damage or impairment, carries a book
value that has to be written off or impaired before the period closes. This
page is where that count is recorded and where the resulting exceptions are
listed; resolving them — the actual write-off or impairment journal — happens
back on Asset register.

- **Page:** *Asset verification* (`http://localhost:8090/asset-verification`).
- **Who can change it:** carries no permission gate. Unlike every other
  screen in Accounting, recording a count result has no prepare/approve
  split — any signed-in user can open an asset's row and save a count
  against it, for any asset. Resolving an exception (writing off or
  impairing the asset) is done from Asset register, and follows that
  page's own journal.prepare / journal.approve rule.
- **Counts are opened administratively.** There's no "start a new count"
  button on this page — a count **round** is opened behind the scenes, and
  this screen always counts against whichever round is currently open (or,
  once it's closed, the most recent one). If no round is open, the page
  reads *"no count open"* under the Round stat card and there is nothing to
  record against.

### 1. Getting to the page

Sidebar → **Accounting** → **Asset verification**, directly below Asset
register. The reverse route is the **Asset count sheet** button at the top
of [Asset register](#asset-register), which lands here.

### 2. Reading the page

**Header** — the title **Asset verification** and a blurb: *"Physical count
against the register. Exceptions carry a book value that must be resolved
before the period closes."*

**Stat cards**, five:

| Card | Shows |
|---|---|
| Round | The open round's reference, with a note of when it was opened — or *"no count open"* if none is |
| Progress | "N of M" counted, with the percentage of the register checked as its note |
| Sighted | Net book value of everything confirmed present, with a count of how many assets |
| Not found | Net book value of everything not produced, with a note: *"N to write off if unresolved"* |
| Condition issues | Net book value of everything flagged with damage or impairment, with a note: *"N impairment indicators"* |

**Warning banner** — appears only once there is at least one exception,
naming both kinds together: *"N asset(s) not produced and N with impairment
indicators, carrying \<value> in the register. Both need a disposal or
impairment journal before the period can close."*

**The count card** — headed **Round \<reference>**, with "N of M counted" on
the right and a progress bar beneath it. Below that:

- **Toolbar** — a search box (*"Search tag, description or custodian…"*) and
  a **location** dropdown, defaulting to **All locations**.
- **Result tabs** — **All**, **Sighted**, **Not found**, **Condition issue**,
  **Not yet checked**, each showing a count.
- **The table** — one row per asset on the count sheet:

| Column | Meaning |
|--------|---------|
| **Tag** | The asset's register tag. |
| **Asset** | Description. |
| **Class** | Asset class. |
| **Location** | Where the register expects it to be. |
| **Custodian** | Who holds it. |
| **Result** | A badge — **Sighted** (calm), **Not found** (urgent), **Condition issue** (warning) or **Not yet checked** (plain) — with the recorded note shown beneath it, if any. |
| **NBV** | Net book value. |

An empty result reads *"No assets match your filters."* The footer reads
*"Count sheets are signed by custodian and verifier; variances clear through
the asset register."*

**Exceptions card**, below the count card — every asset whose result is
neither Sighted nor still pending, headed **Exceptions** with "N carrying
\<value>" alongside it:

| Column | Meaning |
|--------|---------|
| **Tag / Asset / Location / Custodian** | As above. |
| **Finding** | **Not found** (urgent) or **Condition issue** (warning). |
| **Accounting action** | What resolving it takes — *"Write off to 5340 on Finance Manager approval, or recover from the custodian"* for a missing asset, or *"Impair to recoverable amount and post the charge against the funding grant"* for a condition issue. |
| **NBV** | Net book value. |

This card is read-only — there's nothing to click. When nothing is
outstanding it reads *"No exceptions. Every asset counted agreed to the
register."*; otherwise its footer reads *"Unresolved exceptions carry into
the audit trail and block the period close."*

### 3. Finding an asset on the sheet

- **Search** — matches tag, description, custodian or class as you type.
- **Location** — narrow to one location, or **All locations**.
- **Result tabs** — **All**, **Sighted**, **Not found**, **Condition issue**,
  **Not yet checked**.

### 4. Recording a count

1. Click any row to open the count drawer. It shows the asset's Class,
   Location, Custodian, Net book value and Current result, plus any note and
   documents already recorded against it.
2. Under **Record the count**, choose the **Result** — **Sighted**, **Not
   found** or **Condition issue**.
3. Enter a **note** saying what was found. It's required for anything other
   than Sighted: *"Anything other than \"Sighted\" needs a note — a count
   without a reason is not evidence."*
4. Attach a **Photo** if you have one — recommended, not required: *"The
   asset with its tag showing, or the damage found. It stays with the count
   as evidence."*
5. Click **Save result**. It records the count, toasts **"Count recorded"**,
   and refreshes the sheet — re-recording an asset already counted this
   round overwrites its previous result.

An asset not on the open round's sheet can't be recorded against — recording
is refused with *"\<tag> is not on the verification list for \<round>."*

### 5. Resolving an exception

This page doesn't post anything itself. A **Not found** or **Condition
issue** result is resolved back on [Asset register](#asset-register):

- A missing asset that's genuinely gone is taken off the register through
  **Propose disposal** (write-off), same as any other disposal.
- A damaged or impaired asset is written down through the same disposal path
  if it's a total loss, or otherwise carried at its impaired value once a
  correcting journal is posted.

Every count — Sighted or not — is written to the asset's own **History** on
its Asset register drawer (e.g. *"Counted in \<round>: Not found (\<name>)"*),
alongside its capitalisation, depreciation and disposal events, so the count
that raised an exception stays visible on that asset for good.

### 6. Bringing an unregistered find onto the register

An asset found during the count that was never on the register at all — not
missing, just never recorded — doesn't get logged here. It's added from
[Asset register](#asset-register)'s **"Found in a count but never recorded"**
panel: click **Add to register** on its row there, which opens the addition
modal pre-filled with basis **Found in a count, never recorded**, the round
as its source, and a reason field for why it was never recorded and how the
value was set — see [Asset register](#asset-register), "Adding a donated or
found asset".
It follows the same propose-then-approve path as any other addition.

### 7. Quick reference

| I want to… | Do this |
|---|---|
| Open the page | Sidebar → **Accounting** → **Asset verification** |
| Find an asset on the sheet | **Search**, the **location** filter, or a result **tab** |
| Record what was found | Click the row → set **Result** and **note** → **Save result** |
| See what still needs resolving | The **Exceptions** card |
| Resolve a missing or damaged asset | Open it on [Asset register](#asset-register) → **Propose disposal** |
| Add something found but never registered | [Asset register](#asset-register) → **"Found in a count but never recorded"** → **Add to register** |
| See a count in an asset's own history | Open the asset on Asset register → **History** |

### 8. Notes and good practice

- **No prepare/approve split here.** Recording a count is open to anyone
  signed in — the control sits downstream, in the journal.prepare /
  journal.approve rule on the disposal or addition that resolves an
  exception.
- **A note is mandatory for anything but Sighted.** The screen won't save a
  Not found or Condition issue result without one.
- **Exceptions block the close, not the count.** An unresolved Not found or
  Condition issue keeps carrying its book value on the register — and in the
  warning banner here — until a disposal or impairment journal clears it.
- **There's no "new count" button.** The page always works against whichever
  round is currently open; opening or closing a round isn't a self-service
  action from this screen.

---

## Payroll

One run a month for the whole secretariat, moved a month at a time. The run
shown is calculated fresh from the roster as it stood that month, and carries
through **prepared → approved → posted → remitted**: pay, the statutory
deductions withheld from it, and the employer's own contributions on top, are
worked out here and charged to the funds, programmes and grants each person
is coded against. It is the subsidiary record behind the salary accounts
(5210, 5220) and the statutory liabilities that sit in 22xx until remitted.
No rate is typed in on the run itself — the calculation reads the statutory
rates and the benefit schedule Settings holds.

- **Page:** *Payroll* (`http://localhost:8090/payroll`)
- **Who can change it:** anyone signed in can prepare a run and change the
  roster — there is no permission gate on the page itself. What stops one
  person doing the whole thing is the approval ladder: approving needs a
  different person from whoever prepared the run, and needs to hold the
  ladder's open role for the run's value (gross pay plus the employer's own
  contributions). Out of the box that ladder is one step, the Executive
  Director, whatever the amount; an administrator can extend it in Settings →
  Approvals. Every view of the register, and of an individual payslip, is
  logged against the person who opened it — payroll is personal data.

### 1. Getting to the page

In the sidebar, under **Accounting**, click **Payroll** (the last item,
below Asset verification).

### 2. Reading the page

- **Header** — the title, a blurb explaining what the run is, and a kicker
  giving the month and its status (e.g. *"September 2026 payroll awaiting
  approval"*).
- **Toolbar** — a **←** / **→** stepper to move a month at a time (disabled
  at either end of the periods on file), the month's own name and status
  note, an **Edit roster** button, and whichever action the run's current
  status offers next: **Send for approval**, **Approve run**, **Post
  {month} payroll**, or a plain **Posted ✓** once it's done.
- **Blocked banner** — appears when the month is closed in Period close but
  the run hasn't been posted: *"…is closed to posting. Reopen the month in
  Period close before posting this run."* A run can still be prepared and
  approved for a closed month; only posting waits for the month to reopen.
- **Stat cards** — Gross pay (with staff count on the run), Statutory
  deductions (PAYE, NSSF, SHIF and the housing levy withheld), Employer
  contributions (the NSSF match, housing levy and NITA training levy),
  Net pay to staff (which account it releases from), and Total cost to ELOG
  (with a note comparing it to the previous run, or the share charged to
  grants on a first run).
- **The register** — one row per person on that month's roster:

| Column | Meaning |
|--------|---------|
| **Staff no** | The payroll number. |
| **Name and post** | Name, then role and grade, with a note when something changed this month — *joined this month*, *pay award this month*, *re-allocated this month*, or, for a leaver, *leaver, N of M days*. |
| **Charged to** | The funding split — a grant's code (or **Core**) and its percentage, one segment per line when the cost is shared. |
| **Gross** | Basic plus benefits and any acting allowance, before deductions. |
| **PAYE** | Income tax withheld. |
| **NSSF, SHIF, levy** | The other statutory deductions withheld, combined. |
| **Other** | Sacco deduction and salary-advance recovery, combined. |
| **Net pay** | What is actually paid out. |

The footer summarises the run: how many staff match the current filter out
of the total, how many are charged in part to a grant, and how many joiners,
leavers or acting-role changes there are this month (or that the roster is
unchanged from the previous run).

- **Statutory remittances** — PAYE, NSSF Tier I and II, SHIF, the housing
  levy and the NITA training levy, each with its basis and the date it's
  due, plus a **Remit to KRA, NSSF and SHA** button.
- **Where the cost is charged** — the run grouped by grant (or Core funded),
  largest first, each with its amount, share of the total and a bar.
- **Journal** — the posting lines the run will raise (or has already
  raised), and whether they balance. If a pay component has nowhere to post
  yet, this card lists exactly what's missing in **Settings → Payroll**
  instead of a journal.

### 3. Moving between runs

Use **←** and **→** to step a month at a time through every period on file.
The status note under the month name reads one of: *Open — nothing in the
ledger yet*, *Awaiting the approver*, *Approved, awaiting posting*, or
*Posted · locked to further change*.

### 4. Finding someone on the run

- **Search** — staff number, name, role or grade; the register filters as
  you type.
- **Tabs** — **All**, **Grant funded**, **Core funded** or **Changes this
  month**, each showing a count.

### 5. Viewing a payslip

Click any row to open that person's payslip for the month on screen:
earnings (basic, benefits, acting allowance) building to gross; deductions
(PAYE, NSSF, SHIF, housing levy, and sacco or advance recovery where they
apply) each with a short note on how it was worked out, building to net pay
and the bank it's paid to; the employer's own cost (the NSSF match, housing
levy and NITA, none of it deducted from pay) building to the total cost of
the post; and, last, exactly what that cost is charged to.

### 6. Changing the roster

**Edit roster** opens a staff editor with four tabs — switching tabs keeps
whoever you had selected:

| Tab | What it does |
|---|---|
| **New starter** | Appoints someone: name, grade, post, joined date, bank details, KRA PIN, NSSF number, a sacco deduction, pay, and how the cost is charged. They first appear in the run for the month they join. |
| **Salary change** | A pay award for someone already on the roster, effective from a chosen run onward — earlier runs are untouched. Opens pre-filled with what they're paid now. |
| **Re-allocation** | Re-splits someone's cost across grants and programmes, effective from a chosen run onward, including the employer's own contributions. Opens pre-filled with their current split. |
| **Leaver** | Records a last day. They're paid pro rata for the days worked in that final month, then drop off the roster from the next run. |

For a new starter or a pay change, picking a grade shows what it awards for
each benefit; leave a benefit blank to take the grade's own figure, or enter
one to override it for that person alone — grades and benefits are set in
Settings → Payroll. A re-allocation (or a starter's own split) is one or
more lines, each a grant and programme with a percentage; **Add split**
adds another line, and the total must come to exactly 100% before it can be
saved.

### 7. Submitting, approving, posting and remitting

- **Send for approval** is offered once at least one person is on the
  roster. It's refused, naming what's missing, if a pay component has
  nowhere in the chart of accounts to post yet — set it in Settings →
  Payroll first.
- **Approve run** needs a different person from whoever prepared it, and
  needs the signed-in approver to hold the ladder's open role for the run's
  value. A signature that doesn't finish the ladder leaves the run
  **Awaiting the approver** and notifies whoever signs next, rather than
  posting it early.
- **Post {month} payroll** is offered once the run is approved and its month
  isn't closed. Posting releases net pay from the payroll bank account and
  raises PAYE, NSSF, SHIF, the housing levy and NITA as liabilities in 22xx
  — nothing reaches the ledger before this.
- **Remit to KRA, NSSF and SHA** is offered once the run is posted and not
  already remitted. It pays over everything the run withheld and matched,
  due the 9th of the following month, and clears the 22xx liabilities it
  raised.

### 8. Quick reference

| I want to… | Do this |
|---|---|
| Open the page | Sidebar → **Accounting** → **Payroll** |
| Move to another month | **←** / **→** next to the month name |
| See a payslip | Click the person's row |
| Appoint someone | **Edit roster** → **New starter** → fill in → **Add to roster** |
| Give a pay award | **Edit roster** → **Salary change** → pick the person and effective run → **Apply pay award** |
| Re-split someone's cost | **Edit roster** → **Re-allocation** → adjust the splits to 100% → **Apply re-allocation** |
| Record someone leaving | **Edit roster** → **Leaver** → pick the person and last day → **Record leaver** |
| Send the run for approval | **Send for approval** |
| Approve a run | **Approve run** (as a different person from the preparer, holding the open ladder role) |
| Post a run to the ledger | **Post {month} payroll** |
| Remit statutory deductions | **Remit to KRA, NSSF and SHA** |

### 9. Notes and good practice

- **Nothing posts until it's approved.** Preparing and re-preparing a run
  (adding a starter, a pay award, a re-allocation, a leaver) simply
  recalculates it; the ledger sees nothing until **Post** is used.
- **A run can't be posted into a closed month.** Reopen the month in Period
  close first — the blocked banner says so.
- **Earlier runs don't move.** A pay award or re-allocation takes effect
  from the run you choose onward; the runs before it still show what was
  actually paid at the time.
- **A newly installed instance may have a run with nowhere to post.**
  Payroll is worked out before the chart of accounts is imported, so the
  journal card names each pay component still needing an account, and
  **Send for approval** refuses until they're set in Settings → Payroll.
- **Viewing is logged.** Because the register and payslips carry personal
  pay data, opening either is written to the audit log against the reader,
  not just changes to them.

---

## Staff advances

Money handed to a person before it becomes expenditure. An advance is
raised, approved, and issued — issuing is what puts it on **1220 staff and
observer advances** as a receivable from the holder, not yet expenditure —
then either cleared by receipts on surrender or, if it goes quiet too long,
taken off the holder's pay. It is the register the ageing here, not the bank
balance, is really reporting: an observer batch can be a hundred M-Pesa
transfers against one reference, and it ages as a single item.

- **Page:** *Staff and observer advances* (`http://localhost:8090/advances`).
  A link with `?advance=<reference>` opens the register with that advance
  already open in the drawer.
- **Who can change it:** viewing, searching and filtering carry no permission
  gate. Raising, approving, rejecting, issuing, chasing, surrendering and
  recovering an advance are likewise open to anyone signed in — what stops
  one person doing the whole thing is the approval ladder and a couple of
  narrower rules: the person who requested an advance cannot approve it, and
  approving needs the signed-in approver to hold the ladder's open role for
  the amount. Out of the box that ladder is two steps — the Finance Manager
  up to KES 200,000, escalating to the Executive Director above it — set in
  *Settings → Approvals* like every other document type (see
  [Journals](#journals) and [docs/approvals.md](approvals.md)). Attaching a
  receipt document after the advance has already moved on needs the same
  **journal.prepare** permission as attaching anywhere else in the system.

### 1. Getting to the page

In the sidebar, under **Accounting**, click **Staff advances** (the item
below Payroll).

### 2. Reading the page

- **Header** — the title, a blurb explaining that every issued advance is a
  receivable on 1220 until it is surrendered or recovered, and **+ Request
  advance** at the top right.
- **Stat cards** — Outstanding (against 1220), Overdue (past the surrender
  date), Awaiting approval (with how many are approved but not yet issued),
  Due within a week, and Observer float (open deployment batches).
- **Ageing strip** — four buckets, Not due, 1–30 days, 31–60 days and 60+
  days, each showing the outstanding value and how many advances are in it;
  the 60+ days bucket is picked out once anything sits in it.
- **Register agrees to 1220 / Register leads the ledger** — a banner tying
  the register's outstanding total to the posted balance on 1220. A
  difference means an issue or surrender has been raised but not yet
  posted, or — the thing the tie exists to catch — that expenditure has been
  coded straight out of the control account without going through a
  surrender.
- **Toolbar** — a **search** box (holder, reference or purpose), the ageing
  buckets as filter buttons, and **status tabs**: Outstanding (the default),
  Overdue, Awaiting approval, Cleared, All — each showing a count.
- **The register** — one row per advance:

| Column | Meaning |
|--------|---------|
| **Advance** | The holder's name, then the reference and purpose underneath. |
| **Holder** | **Staff** or **Observer**. |
| **Programme**, **Grant / award** | Where the advance is coded. |
| **Advanced** | The amount requested. |
| **Accounted** | Receipts coded against it so far, on any surrender to date. |
| **Outstanding** | What is still owed by the holder — in red once overdue, — once cleared. |
| **Due** | The surrender due date. |
| **Ageing** | *"in Nd"*, or *"Nd overdue"* in red — or — once cleared. |
| **Status** | **Requested**, **Approved**, **Issued**, **Surrendered**, **Recovered** or **Rejected**, with, on a request part-way up the approval ladder, a small note of how many have signed and who it's waiting on next. |

The footer reads *"N of M advances · N surrendered · N recovered through
payroll · X outstanding"*.

### 3. Finding an advance

- **Search** — matches holder, reference, purpose, programme or grant.
- **Ageing buckets** — narrow to one band of days outstanding.
- **Status tabs** — narrow to **Outstanding**, **Overdue**, **Awaiting
  approval** (requested or approved but not yet issued), **Cleared**
  (surrendered or recovered), or **All**.
- Click a row to open it in the drawer.

### 4. The advance drawer

Click an advance to open it. Alongside the amount advanced and what it comes
to outstanding, the drawer shows:

- **Approval** — a note of the ladder's progress, on a request awaiting a
  signature.
- **Flags**, where they apply — an overdue advance carries a note of how
  long it has run past its surrender date and when payroll recovery opens up
  (§7); a holder who has left carries a note that recovery from pay is no
  longer available and the balance needs a write-off decision from the Board
  finance committee, or recovery from the former employee directly; a
  request above the approver's threshold carries a note of whose approval it
  escalates to; a rejected request carries the reason.
- **Facts** — holder and kind, role, programme, grant/award and fund, amount
  advanced, accounted for, recovered, outstanding, requested date, issued
  date, method paid by, the issuing journal, and the surrender due date.
- **Receipts coded on surrender** — the lines from every surrender to date,
  each against its account and description, and the total coded to
  programme lines — shown once there is at least one.
- **Receipt documents** — the receipts on file, with a control to attach
  another once the advance is past **Requested**, for anyone with
  **journal.prepare**.
- **Reminders sent** — each chase, with its level and who it went to — shown
  once at least one has been sent.
- **Audit trail** — every step taken on the advance, with when.
- **Pay out by** — a method to choose, shown only while the advance is
  **Approved** and waiting to be issued.

### 5. Requesting an advance

**+ Request advance** opens the request form:

| Field | Notes |
|-------|-------|
| **Who holds the money** | A person's name, or a batch label such as *STO batch — Coast* for an observer deployment. |
| **Holder type** | **Staff** or **Observer**. |
| **Role or batch size** | Free text, e.g. *Long-term observer, Kilifi* or *24 short-term observers*. |
| **What the advance is for** | The activity the receipts will later be checked against. |
| **Grant / award** | The award to charge, or **Unrestricted — own income**; picking a restricted award narrows **Programme** to what it may fund. |
| **Programme** | The programme the advance is coded against. |
| **Amount requested** | Must be more than zero. |
| **Surrender due by** | Defaults to three weeks out. Policy allows 14 days from the end of the activity; anything longer needs the programme director to say why, though the form itself only refuses a date earlier than today. |

**Raise request** is a claim on the programme budget, not a posting —
nothing reaches 1220 until the advance is later issued.

### 6. Approving, rejecting and issuing

- **Approve** needs a different person from the requester and needs the
  signed-in approver to hold the ladder's open role for the amount. A
  signature that doesn't finish the ladder leaves the request **Approved**
  — awaiting the next role — rather than clearing it to be issued early; the
  drawer's approval note says who signs next.
- **Reject** asks for a reason — the requester has to know what to do
  instead — and returns the request to them. A rejected request is a dead
  end; there is no way to resubmit it.
- **Issue funds** is offered once an advance is **Approved**. Choosing a
  method (M-Pesa, Bank or Cash) and confirming posts the advance to 1220
  against the paying account — a receivable from the holder, not
  expenditure. The surrender due date is the one set when the advance was
  requested; issuing doesn't move it.

### 7. Chasing, recovering and surrendering

- **Send reminder** is offered once an advance is overdue. The first chase
  goes to the holder; unanswered chases escalate — the second to the
  programme director, later ones to the Executive Director for payroll
  recovery — and each is logged on the audit trail.
- **Recover from payroll** converts an unsurrendered balance to a payroll
  deduction. It's only offered once the advance is at least 14 days (the
  default in *Settings*, as `advanceRecoveryDays`) past its surrender date,
  and only for a holder still on the payroll register who hasn't left —
  a leaver's final pay is already settled, and a holder never on payroll
  can't be recovered this way either; both need a write-off decision or
  direct recovery instead. Nothing posts at this point: the deduction is
  scheduled over one to six coming payroll runs depending on the balance
  (fewer if there aren't that many open runs left), and 1220 clears as each
  run actually takes it.
- **Surrender with receipts** codes what receipts came back against
  expenditure and clears the advance, in whole or in part:
  - Each receipt line picks an expenditure account, a description of what
    was bought, and an amount — at least one line is required, and every
    line needs its description, since the audit file has to say what was
    bought.
  - Receipt documents are required before posting, unless the installation
    has turned that off (`requireAdvanceReceipts` in
    [Supporting documents](documents.md)).
  - If the receipts fall short of the advance, choose what happens to the
    unspent balance: **Cash returned and banked** clears the advance and
    banks the difference; **Balance stays outstanding** leaves the
    difference owed by the holder, still ageing, so a later surrender can
    pick up where this one left off.
  - Receipts that exceed the advance leave the overspend owed back to the
    holder, added to payables.
  - Posting moves the receipted amount off 1220 and onto the programme
    expenditure lines.

### 8. Quick reference

| I want to… | Do this |
|---|---|
| Open the page | Sidebar → **Accounting** → **Staff advances** |
| Find an advance | **Search**, an **ageing bucket**, or a **status tab** |
| Request an advance | **+ Request advance** → fill in the form → **Raise request** |
| Approve a request | Open it → **Approve** (as a different person from the requester, holding the open ladder role) |
| Reject a request | Open it → **Reject** with a reason |
| Issue the funds | Open an **Approved** advance → choose the method → **Issue funds** |
| Chase an overdue advance | Open it → **Send reminder** |
| Recover through payroll | Open an advance overdue past the recovery grace period → **Recover from payroll** |
| Clear an advance against receipts | Open an **Issued** advance → **Surrender with receipts** → code the lines → **Post surrender** |
| Attach a receipt after the fact | Open the advance → **Attach a receipt** (needs `journal.prepare`) |

### 9. Notes and good practice

- **Requesting posts nothing.** It's a claim on the programme budget; only
  **Issue funds** puts the amount on 1220 as a receivable.
- **The requester can't approve their own request.** It needs a second
  person, holding the ladder's open role for the amount.
- **The register has to agree to 1220.** A difference between the two is
  either a posting not yet caught up with the register, or expenditure
  coded straight out of the control account without a surrender — either
  way, the tie banner is where it shows up first.
- **A partial surrender leaves the advance Issued.** Coding some receipts
  and choosing to leave the rest outstanding doesn't close the advance; it
  keeps ageing for the balance still owed, so a later surrender can finish
  it.
- **Payroll recovery is a last resort.** The holder gets the grace period
  the advance policy allows (14 days past the surrender date, by default)
  before their pay is touched, and it's only available at all to someone
  still on the payroll register.
- **A leaver's balance doesn't recover through payroll.** Their final pay
  is already settled; it needs a write-off decision from the Board finance
  committee, or recovery from the former employee directly.
- **Observer batches age as one line.** A single reference can stand for a
  whole deployment's worth of transfers, which is why the register tracks
  the batch rather than each individual payment.

---

## Users and roles

**Settings → Users** and **Settings → Roles**. Seeing or changing either needs a
role with the `users.manage` permission, which the Finance Manager has out of the
box. Nobody else sees them.

The rest of Settings works the same way, one duty at a time: `settings.view` shows
the everyday sections read only, and `settings.organisation`, `settings.ledger`,
`settings.approvals`, `settings.banking`, `settings.integrations`,
`settings.payroll` and `settings.maintenance` each change their own part. Payroll settings are also shown to
anyone with `payroll.view`, and the audit log to anyone with `audit.view`; the
Maintenance section, like Users and Roles, is shown only to the permission that
changes it. Someone
who can see no part of Settings does not have it in their menu at all.

### 1. How access works

- A **role** is a named set of permissions, for example *Accountant*: view the
  ledger, prepare journals, raise requisitions.
- People never hold permissions directly. They hold **roles**, as many as their
  job needs, and each role at **all entities** or only the ones named.
- What someone can do is everything their roles allow, added together. Change a
  role's permissions and it changes for everyone who holds it.

### 2. Inviting someone

1. **Settings → Users → Invite user.**
2. Enter their name and work email, tick one or more **roles**, and choose the
   entities they work in.
3. **Send invitation.** They are emailed a link to choose a password. It works
   for seven days. They show as *Invited* until they use it.

### 3. Changing someone's roles

1. **Settings → Users**, then **Manage** on their row.
2. Under **Roles**, change a role in its drop-down, tick **All entities** or the
   entities it applies to, **+ Add a role** for another, or ✕ to take one away.
3. **Save roles.** It takes effect straight away.

From the same drawer you can **Suspend** someone, which signs them out and stops
them signing in, and **Reinstate** them later. You can also **Reset second step**
for someone who has lost their phone and their recovery codes, or email a new
link to someone who hasn't set a password yet.

### 4. Defining roles

1. **Settings → Roles → New role.**
2. Give it a name and a short description, and tick its permissions. They are
   grouped by area: Ledger, Journals and documents, Procurement, Payroll, Period
   close, Chart of accounts and Administration.
3. **Add role.** Add as many roles as the organisation needs.

To change a role, choose **Edit** on its card, then **Save role**. The roles the application comes
with keep their names, because approvals and the close checklist refer to them,
but you can change their permissions. A role you added can be removed with
**Delete role** once nobody holds it.

You cannot change or delete a role you hold yourself, at any entity — otherwise
whoever manages roles could widen their own permissions. Its card is marked
**Yours** and opens read only; ask someone else who manages users to make the change.

### 5. The password policy

**Settings → Users**, below the list of people. It sets what a new password has to
be — its length, the kinds of character it must mix, whether it may carry the
person's own name or be one anyone would try first, how many earlier passwords it
may not repeat, and after how many days it has to be changed. The sentence at the
foot of the section reads the whole policy back to you before you save it.

The rules apply to the next password each person chooses, from an invitation, a
reset link or **My account** — tightening them never locks anyone out of an account
they can already get into.

**Locking after wrong passwords** is the one rule about guessing rather than about
the password itself. Wrong passwords in a row are counted per account; enough of
them lock it for the minutes you set, and even the right password then waits the
lock out. Resetting the password unlocks the account straight away, and a sign-in
that works clears the count. Set the attempts to **0** to stop locking altogether:
wrong passwords are still counted and still recorded in the audit log, and saving
that releases anyone locked out at the time.

Save it with **Save changes**, like the rest of Settings. The change goes into the
audit log in words.

### 6. Safeguards

- There must always be someone active who can manage users. A change that would
  leave nobody is refused, and so is suspending yourself.
- Everyone must hold at least one role.
- Every change is in **Settings → Audit log**: who changed what, when.

### 7. Quick reference

| Task | How |
|---|---|
| Add a person | Users → **Invite user** → name, email, roles, entities → **Send invitation** |
| Give someone another role | Users → **Manage** → **+ Add a role** → **Save roles** |
| Limit a role to some entities | Users → **Manage** → untick **All entities** → tick the entities |
| Stop someone's access | Users → **Manage** → **Suspend** |
| Someone lost their phone and codes | Users → **Manage** → **Reset second step** |
| Create a role | Roles → **New role** → name, permissions → **Add role** |
| Change what a role can do | Roles → **Edit** on its card → tick or untick permissions → **Save role** |
| Lock accounts after wrong passwords | Users → **Locking after wrong passwords** → set the attempts and minutes → **Save changes** |
| Stop locking accounts | Users → **Locking after wrong passwords** → set the attempts to **0** → **Save changes** |

---

## Closing the application for maintenance

**Settings → Maintenance.** The page belongs to the `settings.maintenance`
permission: a role that holds it reads and uses the page, and nobody else is shown
the section at all — being able to see the rest of Settings is not enough. That
permission is also the key to the door: while the application is closed, the people
who hold it are the only ones who can sign in. Give it out as carefully as
`users.manage`, and to more than one person, so a closure can always be undone.

Everybody else does not need the page. They are told about a closure where it
matters to them: a notification when one is booked, a bar across the top of every
page as it approaches, and the maintenance screen itself while it runs.

Nothing on this page touches the ledger. Maintenance mode stops people reaching
the application; it does not change a single figure in the books, and nobody loses
work they had saved.

### 1. Closing it now

1. **Settings → Maintenance → Close the application now.**
2. Write what everybody else will be told — *"We are upgrading the database and
   will be back by 19:00."* It is the only thing they are going to read, so give
   them the reason and, if you know it, the time.
3. **Close it now.**

From that moment everybody else — signed in already or signing in now — gets a
plain **Closed for maintenance** page carrying your message, instead of the
application. Their sessions are left alone: when you open it again they carry on
from where they were. You and the other holders of the permission work as usual,
with a red bar at the top of every page reminding you the application is shut to
everyone else.

**Open the application** puts everybody back in. A closure switched by hand stays
until somebody opens it — it does not time out.

### 2. Planning one in advance

A **maintenance window** is a period booked ahead of time. Booking one tells
everybody straight away, carries the notice on every page for the seven days
before it starts, and closes the application by itself while it runs — nobody has
to be at a keyboard at midnight to close it, or first thing in the morning to open
it again.

1. **Settings → Maintenance**, under *Plan a maintenance period*.
2. Choose when it **starts** and **ends**, and say what the maintenance is for.
3. **Book it and notify everyone.**

A window starts in the future, runs at least five minutes and at most 72 hours,
and cannot overlap one already booked — book a longer stretch as two windows.

### 3. Calling one off, or ending one early

**Cancel** on a booked window calls it off; **End now** on one that is running
opens the application at once. Everybody who was told about it is told it is off.
Windows are never deleted: cancelled ones stay on the list, so what was announced
and what actually happened are both on the record.

### 4. What everybody else sees

- A notification in the bell the moment a window is booked, and another if it is
  cancelled.
- An amber bar on every page for the seven days before it starts, saying when and
  for how long.
- While it is closed: the **Closed for maintenance** page with your message and,
  for a booked window, the time it is expected back.

### 5. Quick reference

| Task | How |
|---|---|
| Close the application now | Maintenance → **Close the application now** → message → **Close it now** |
| Open it again | Maintenance → **Open the application** |
| Book a period in advance | Maintenance → starts, ends, what for → **Book it and notify everyone** |
| Call a booked period off | Maintenance → **Cancel** on its row |
| End one that is running | Maintenance → **End the maintenance now** |
| See what was closed and when | Settings → **Audit log**, area *Maintenance* |

---

## Funds

Every shilling in the ledger sits in a fund, the first coding dimension on any
posting line. The page is the statement of changes in funds for the working
year — opening, income, expenditure, transfers and closing, one row a fund —
plus the fund drawer (movement, utilisation, restriction terms, the
programmes and ledger accounts it carries) and inter-fund transfers.

- **Page:** *Funds* (`http://localhost:8090/funds`)
- **Who can change it:** raising a transfer needs the *journal.prepare*
  permission — someone who prepares journals, because a transfer is one.
  Opening a fund is not done here: it is done in **Settings → Ledger**, and
  is restricted to the **Finance Manager**, since a fund is the first coding
  every posting carries. Anyone signed in can browse the register and open a
  fund's drawer.

### 1. Getting to the page

In the sidebar, under **Funds and grants**, click **Funds**.

### 2. Reading the page

- **Header** — the title and a one-line reminder of why restriction matters
  (restricted balances can only be spent on the purpose the donor agreed, and
  unspent amounts are returnable at grant close), with **Fund transfer** and
  **Statement of funds** at the top right.
- **Stat cards** — Total fund balance, Unrestricted, Restricted (with its
  utilisation as the note), Endowment, and Closing within 90 days (the
  unspent balance of restricted funds whose spending window closes soon, so
  it is at risk of being returned to the funder).
- **Toolbar** — a **search** box (fund, funder or grant reference) and
  **class tabs**: All funds, Unrestricted, Restricted, Endowment, each
  showing a count. A hint alongside says how many restricted funds close
  within 90 days, or that none do.
- **The table** — one row per fund:

| Column | Meaning |
|--------|---------|
| **Fund** | Name, then its purpose underneath. |
| **Class** | **Unrestricted**, **Restricted** or **Endowment**. A board designation reports as Unrestricted — it is money the board has earmarked, not a donor restriction. |
| **Funder** | Who the money is held for, or *Own income*. |
| **Opening** | The balance brought into the working year. |
| **Income** | Received so far this year. |
| **Expenditure** | Spent so far this year. |
| **Transfers** | Net of inter-fund transfers in and out; — when there are none. |
| **Closing** | Opening + income − expenditure + transfers; shown in red when overdrawn. |
| **Utilisation** | A bar and percentage of what the fund has had available (opening + income + any transfer in) that has been spent; the bar turns amber past 70% and heavier past 90%. |
| **Spend by** | The end of the funding agreement, highlighted when it falls within 90 days. |

- **Footer** — how many of the register's funds are shown against the total,
  restricted utilisation across all restricted funds, and, when any exist,
  how many transfers are raised but not yet posted. A total row at the foot
  of the table carries the opening, income, expenditure, transfers and
  closing columns forward as **Total funds carried forward**.

### 3. Finding a fund

- **Search** — type a fund name, funder or grant reference; the list filters
  as you type.
- **Class tabs** — click **All funds**, **Unrestricted**, **Restricted** or
  **Endowment** to narrow the list.

### 4. Viewing a fund

Click any row to open the **fund drawer**.

- An **alert** appears at the top when it applies, in this order: the
  spending window closes within 90 days (naming the unspent amount and the
  funder it would be returnable to), the fund is 90% or more utilised
  (further commitments need a budget revision), or the fund is overdrawn.
- **Movement for the year** — opening balance, income received, expenditure
  (shown in brackets), transfers in/(out), and the closing balance.
- **Utilisation** — the same bar as the table, with the remaining balance
  against what was available stated beneath it.
- **Restriction terms** — Funder, Grant reference, Agreement period, Spend
  by, and Conditions (the donor's conditions in their own words, or — when
  none are recorded).
- **Programmes charged to this fund** — a chip list, when any are linked.
- **Linked ledger accounts** — up to six accounts this fund posted to this
  year, largest balance first, each linking through to the general ledger;
  or a note that nothing has been posted to the fund this year.
- **Transfers not yet posted** — any inter-fund transfer into or out of this
  fund still awaiting approval, linking through to its journal.

Footer buttons: **Fund transfer** (opens the transfer form pre-filled with
this fund), **Close**, and **Generate donor report**, which takes you to
Donor reports.

### 5. Raising an inter-fund transfer

**Fund transfer**, on the page header or in a fund's drawer, opens the
transfer form.

1. Choose the fund to **Transfer from** and the fund to **Transfer to**.
2. Enter the **Amount (KES)**, a **Board minute reference** (required — every
   inter-fund transfer needs one, e.g. `BM/2026/08/04`), and, optionally, a
   **Reason for transfer**.
3. As you fill it in, the line beneath the form says either why the transfer
   is not permitted, or what it will do and who it goes to for approval.
4. **Post transfer** raises it.

**What is and is not permitted.** A transfer cannot be raised when:

- The source and destination fund are the same.
- The source fund is **donor-restricted** — restricted money cannot move out
  without the funder's written consent, recorded first as a grant amendment.
- The source fund is an **endowment** — its capital is permanently
  maintained; only realised investment income may be released, and only
  through the General Fund.
- The amount is more than the source fund's closing balance less any
  transfers already raised from it and still awaiting approval.

A transfer does not move money by itself: it is a journal, raised here and
sent to whoever the inter-fund transfer rule names as approver. **The
balances on this page only ever show what has actually posted** — a raised
transfer sits in **Transfers not yet posted** until the approver posts it in
Journals, at which point the fund dimension moves. The bank balance itself
never moves; only which fund it belongs to does.

### 6. The statement of funds

**Statement of funds** downloads the statement of changes in funds for the
working year as a CSV, for the board pack. It always covers every fund on
the register — the search box and class tabs on screen do not filter what is
exported.

### 7. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Funds and grants** → **Funds** |
| Find a fund | **Search**, or a **class tab** |
| See a fund's restriction terms and history | Click its row to open the drawer |
| Move money between funds | **Fund transfer** → choose funds, amount, board minute → **Post transfer** |
| See transfers still awaiting approval | The footer count, or **Transfers not yet posted** in a fund's drawer |
| Send the board the year's movement | **Statement of funds** |
| Open a new fund | **Settings → Ledger** (Finance Manager only) |

---

## Grants and awards

The award portfolio from proposal to close-out. Burn is read against elapsed
time, so an award falling behind schedule shows before the funder asks. The
page is one row per grant or contract with a donor; the drawer holds that
award's budget against actual, disbursement schedule, reporting calendar and
conditions. **Record award** walks through the signed agreement in six steps
and writes nothing until the last — every rule the wizard applies, the API
applies again.

- **Page:** *Grants and awards* (`http://localhost:8090/grants`)
- **Who can change it:** recording an award, or converting a pipeline award
  to active, needs the *settings.ledger* permission — the **Finance
  Manager**, since recording an award opens a fund, the first coding every
  posting carries. Anyone signed in can browse the portfolio, open an
  award's drawer, and open the reporting calendar.

### 1. Getting to the page

In the sidebar, under **Funds and grants**, click **Grants and awards**.

### 2. Reading the page

- **Header** — the title and a one-line reminder that burn is read against
  elapsed time, with **Reporting calendar** and **+ Record award** at the
  top right.
- **Stat cards** — Live portfolio (the value of active and closing awards),
  Received to date (with what is still receivable as its note), Spent to
  date, Unspent commitment (still to deliver before close), and Reports due
  (within the reporting warning window set in Settings).
- **Toolbar** — a **search** box (award, funder or programme) and **status
  tabs**: All, Active, Closing, Pipeline, Suspended, Closed, each showing a
  count. A legend alongside explains the burn bar's two marks: **Spent**
  and **Time elapsed**.
- **The table** — one row per award:

| Column | Meaning |
|--------|---------|
| **Award** | Title, then reference and lead programme underneath. |
| **Funder** | Who the agreement is with. |
| **Period** | The agreement's start and end months. |
| **Award value** | The agreement's total value, in KES. |
| **Received** | Disbursements actually received to date. |
| **Spent** | Expenditure posted against the award. |
| **Burn vs elapsed** | A bar comparing spend (the fill) against the share of the period gone (the marker), and the burn percentage; rust when spending runs ahead of the period, amber when it lags well behind, green when broadly in line. |
| **Next report** | The next donor report's due date, highlighted when it falls within the reporting warning window. |
| **Status** | **Pipeline**, **Active**, **Closing**, **Suspended** or **Closed**. |

- **Footer** — how many of the portfolio's awards are shown against the
  total, how many are live, and the portfolio's overall burn percentage. A
  note reminds you values are stated in KES at the agreement rate, and that
  pipeline awards are excluded from committed income.

### 3. Finding an award

- **Search** — type an award title, reference, funder or programme; the
  list filters as you type.
- **Status tabs** — click **All**, **Active**, **Closing**, **Pipeline**,
  **Suspended** or **Closed** to narrow the list.

### 4. Viewing an award

Click any row to open the **award drawer**.

- An **alert** appears at the top when it applies, in this order:
  disbursements are suspended (naming an overdue report, if one exists), the
  award is closing (naming the unspent, uncommitted amount that would be
  returnable without a no-cost extension), or the next donor report falls
  due soon.
- **Award value, Received, Unspent commitment** — three summary cards.
- **Burn against elapsed time** — the same bar as the table, larger, with a
  note on whether spending is ahead of, behind, or in line with the period.
- **Budget against actual** — one row per budget line (account, name,
  budget, actual, variance), shown in red when a line is overspent, with a
  total row.
- **Disbursement schedule** — each tranche's date and amount, and its
  status: **Scheduled**, **Due** (inside the tranche warning window),
  **Received** or **Cancelled**.
- **Reporting calendar** — each report this award owes, its period, due
  date and state.
- **Agreement terms** — Agreement currency (when the agreement is not in
  KES, with the agreement rate), Agreement period, Held in (the fund and its
  code), Indirect cost cap (when one is agreed, as a percentage and amount),
  and Records retained.
- **Signed agreement** — the agreement and any variations on file; attach
  one from here if the permission allows it.
- **Compliance conditions** — the conditions carried over from the
  agreement, or a note that none are recorded.

Footer buttons: **View in ledger** (the award's first budget line's
account), **Close**, **Record disbursement** (for an Active or Closing
award, taking you to Receivables), and either **Convert to award** (for a
Pipeline award) or **Prepare donor report** / **Open close-out file**
(taking you to Donor reports).

### 5. Converting a pipeline award

**Convert to award**, in a Pipeline award's drawer, moves it to Active on
signature — from that moment its budget may be committed against.

- The agreement period must already be recorded, and the budget lines and
  disbursement schedule must still agree with the award value — the same
  checks the wizard applied when the award was first recorded.
- The signed agreement must be attached first, unless the agreement
  requirement is turned off in Settings; if it is missing, the button tells
  you so and the drawer scrolls to the attachment panel.
- Nothing else about the award changes — value and period become locked
  fields once an award is live; a later change needs a recorded variation.

### 6. The reporting calendar

**Reporting calendar**, on the page header, lists every report still owed to
a donor across the whole portfolio, soonest first — pipeline and closed
awards are left out, since neither owes a report right now. Each entry shows
how many days until it is due (or how many it is overdue), the report name,
the award, funder and period it covers, and its state (**Overdue**, **Due**
or **Scheduled**). Click any entry to go to Donor reports for that award.
How many days out a report is flagged is set in Settings.

### 7. Recording an award

**+ Record award** opens a six-step wizard. Nothing is written until the
final step; value and period become locked fields once it is.

1. **Agreement** — Funder (typed, or picked from existing funders), Award
   reference (unique, up to 40 characters — the key the donor quotes in
   every query), Award title, Lead programme, Grant manager, and **Also
   funds** for a workplan that genuinely splits across more than one
   programme. Currency and Award value in KES (with the agreement rate,
   when the currency is not KES — the award is held in KES at that rate,
   and the difference on each receipt posts as an exchange gain or loss).
   Start and end dates. **Record as** Pipeline (while the agreement is
   unsigned — visible budget, nothing committable, no receivable raised) or
   Active. A signed agreement can be attached here: required for an Active
   award when Settings requires one, recommended otherwise.
2. **Fund** — **Open a new fund** (the normal case) or **Attach to an
   existing fund**. Opening a new fund asks for its name (a suggested name
   is offered), its class — Restricted, Designated or Endowment
   (Unrestricted is not offered; donor money with an agreement and a
   reporting calendar is restricted by definition), whether it is for
   capital items, when Restricted, and its purpose. The screen shows which
   ledger fund the new fund rolls up into (Grant Fund, Capital Fund, General
   Fund or Endowment Fund) and, unless it is already the General Fund, lets
   you override that and present the money there instead — only for an
   agreement that genuinely carries no spending restriction, and only with
   a reason recorded on the audit trail, since doing so overstates free
   reserves. Attaching to an existing fund warns if the fund chosen is
   unrestricted, since coding donor money there loses the spending
   restriction.
3. **Budget lines** — one row per account the award may be spent on
   (expense or asset accounts only), with an amount and its share of the
   award value; **Balance to last line** and **+ Add line** help. The lines
   must total the award value exactly. An **indirect cost cap** (a
   percentage of the award) checks the lines coded to the administration
   and governance account group and warns when they exceed it.
4. **Disbursements** — one row per expected tranche (a label, expected
   date, amount); **Split evenly**, **Balance to last** and **+ Add** help.
   The schedule must total the award value exactly. Each tranche is later
   claimed from the donor under Receivables when it falls due.
5. **Reporting** — one row per report the donor expects back (name, the
   period it covers, and its due date). Presets fill in a standard
   calendar dated from the agreement start: **+ Quarterly financial** (one
   report per quarter of the period), **+ Narrative** (a semi-annual and an
   annual report), and **+ Close-out** (due 90 days after the award ends;
   the other presets are due 30 days after the period they cover). Every
   date set here is later flagged on the Grants and awards page and the
   reporting calendar however many days out Settings specifies.
6. **Conditions and review** — the conditions carried over from the signed
   agreement, at least one required, plus a summary of everything entered
   and a preview of what recording the award will do: the fund it opens or
   attaches to, the budget it records, the disbursements it schedules, the
   reporting deadlines it sets, and that the set-up is recorded against you
   on the audit trail. **Record award** commits it.

### 8. Quick reference

| I want to… | Do this |
|------------|---------|
| Open the page | Sidebar → **Funds and grants** → **Grants and awards** |
| Find an award | **Search**, or a **status tab** |
| See an award's budget, disbursements and reporting calendar | Click its row to open the drawer |
| See everything still owed to donors | **Reporting calendar** |
| Record a new award from a signed agreement | **+ Record award** → work through the six steps → **Record award** (Finance Manager only) |
| Convert a signed pipeline award to active | Open its drawer → **Convert to award** |
| Record a donor receipt against an award | Award drawer → **Record disbursement** (opens Receivables) |
| Prepare a donor report | Award drawer → **Prepare donor report** (opens Donor reports) |

---

## Budgets

**Funds and grants → Budgets.** The approved budget against actual, month by
month. Variance is measured against the **phasing to date** — how the line was
expected to be spent by now, by its profile — so an election programme that
spends most of its money mid-year is not flagged in January.

### 1. Reading the page

- The **stat cards** give the year at a glance: annual budget, phased to date,
  actual to date, variance to phasing (favourable if positive), and how many lines
  need attention.
- **Budget version** chooses which version to read. The approved one is marked
  *(approved)*; a revision being worked on is *(working)*; next year's is *(draft)*.
- **Group by** regroups the table by account group, programme or fund.
  **Compare to** switches the comparison between the phasing to date and the
  full-year budget.
- The **Consumed** bar shows how much of the annual budget is spent; the dark mark
  shows how much was phased to date. A bar past its mark is spending ahead of plan.
- **Status:** *Over* — actual has passed the annual budget. *Watch* — more than
  10% ahead of the phasing. *Underspent* — below 70% of the phasing. Otherwise
  *On track*.

Click a line to see its monthly budget against actual, what each version held for
it, and the rules for revising it. **View postings** opens the general ledger on
its account.

### 2. Moving budget between lines

1. **Budget revision** (or **Request revision** on a line).
2. Choose the line to **reduce** and the line to **increase**, the **amount**, the
   **authority reference** and a **justification**.
3. The dialog says straight away whether the move is allowed. It is not when the
   lines are in different funds or under different grant agreements, when more is
   asked for than is uncommitted, when it would increase indirect-cost recovery on
   a grant, or when it takes more than 10% of a grant-funded line without the
   funder's written consent.
4. **Apply to working version.** The first movement opens the working revision
   (for example *FY2026 Revision 2*), a copy of the approved budget. The approved
   budget does not change yet.
5. When all the movements are in, choose the working version and **Submit for
   approval**.

The approver opens the same version and either **Approves** it — it then replaces
the approved budget, and the procurement budget check reads it from then on — or
**Sends it back** with a note. The person who moved the money cannot approve it.
A draft you no longer want can be **Discarded**.

### 3. Next year's budget

1. **New financial year.** Choose the year, the **uplift on core lines**, and
   whether to keep lines that have nothing left (at zero, for comparison).
2. **Derive the budget.** Grant-funded lines come from each award's remaining
   ceiling, shared by the months of the award that fall in the year; core lines are
   this year's approved figure plus the uplift. Three checks follow: every grant
   line within its award ceiling, restricted spend within what the awards still
   owe, and core spend against unrestricted income and reserves.
3. **Raise … (draft).** Submit it for approval when ready. It cannot be used for
   variance reporting until the Executive Director approves it.

### 4. Quick reference

| Task | How |
|---|---|
| See spend against plan for a programme | **Group by** → Programme |
| Compare with the whole year | **Compare to** → Full-year budget |
| Move budget | **Budget revision** → lines, amount, reference, justification → **Apply to working version** |
| Send a revision for approval | Choose the *(working)* version → **Submit for approval** |
| Approve a revision | Choose the *(pending approval)* version → **Approve** |
| Start next year's budget | **New financial year** → assumptions → **Derive the budget** → **Raise** |
| Send the variance report | **Export variance report** (CSV) |

---

## Donor reports

**Funds and grants → Donor reports.** Expenditure reports to funders, built from
the ledger rather than typed in. Every report shows whether what the funder is
told **ties** to what is posted for the award, and a report that does not tie
cannot be sent for review or submitted.

### 1. Reading the page

- The **stat cards** count the reports still with you (draft, in review or
  overdue), those due soon (with the earliest date), those overdue, what has
  been reported to funders this year, and the reports that do not tie.
- The **tabs** filter by status; the search finds a funder, award, reference or
  period. Reports still waiting on you come first, soonest due at the top.
- **Reconciled** shows *✓ Ties*, the difference in red, or *No figures yet* for a
  scheduled report whose figures have not been taken. A due date in red is inside
  the warning window.
- **Reporting calendar** lists everything still to go, month by month; click a
  report to open it. **Report language** sets the language each funder receives
  its pack in and previews the cover — headings translate, figures stay in KES.

### 2. Preparing a report

1. **+ New report.** Choose the award, give the title, type, period and the date
   the funder expects it, then **Generate report**. Its figures are taken from
   what is posted to the award: this period and cumulative, by account, against
   the award's budget. (Reports scheduled when an award was recorded are already
   on the list; open one and **Refresh from ledger** to fill it.)
2. Check the three tabs. **Expenditure** is the schedule and the fund position;
   **Reconciliation** compares what is reported with the ledger and holds the
   supporting schedules — attach the ledger extract and anything else the funder
   asks for; **Compliance** has the funder's conditions, the audit trail and a
   note for the reviewer.
3. If the report does not tie, post the missing journal and **Refresh from
   ledger**, or **Remove the adjustment**.
4. **Send for review.**

### 3. Review, submission and the funder's reply

- Someone who approves documents — never the preparer — opens the report and
  either **Submits to funder** or **Returns to preparer** with what to change.
  Submitting confirms the compliance statements.
- When the funder asks a question, **Record funder query** with their reference
  and wording. Answer it on the Compliance tab and **Respond to query**; the
  report goes back to *Submitted*.
- When the acceptance letter arrives, attach it and **File acceptance letter**.
- **Export pack** downloads the schedule, the reconciliation, the compliance
  record and the queries as a spreadsheet.

### 4. Quick reference

| Task | How |
|---|---|
| Start a report | **+ New report** → award, title, type, period, due date → **Generate report** |
| Fill a scheduled report | Open it → **Refresh from ledger** |
| Clear a difference | Post the correction, then **Refresh from ledger** — or **Remove the adjustment** |
| Send it on | **Send for review**; the reviewer **Submits to funder** |
| Answer a funder | **Record funder query** → write the response → **Respond to query** |
| Close it off | **File acceptance letter** |
| Change a funder's language | **Report language** → choose the language on the funder's row |

---

## Cashflow forecast

**Insight → Cashflow forecast.** Will there be cash to pay staff in three months?
Thirteen weeks ahead, one row a week, built from donor claims in receivables,
approved bills, payroll and statutory dates. Restricted balances are shown
separately, because they cannot be used for core costs.

### 1. Reading the page

- The **headline figures** give the cash on hand (and the accounts it is held in),
  how much of it is restricted, how many weeks the unrestricted cash covers at the
  current weekly spend, the lowest point unrestricted cash reaches, and the closing
  position at the end of the thirteen weeks.
- Each **week** shows receipts (green), payments (amber), the net movement, and the
  closing cash. The two bars show the week's size at a glance, with what drives it
  beneath (a donor claim expected, payroll, field advances).
- **Of which unrestricted** is closing cash less restricted balances. This is the
  column to watch: it can go negative while closing cash still looks healthy. It
  turns red when it does, and a warning above the table names the week.

### 2. Trying a scenario

- **Donor delay** holds back most of each grant receipt due in the first six weeks
  and brings it in six weeks later, the way a tranche usually slips.
- **By-election surge** raises payments by a third across the campaign weeks.
- **Hold discretionary spend from week 5** cuts payments by 15% from the fifth
  week. You can combine it with any scenario to see whether it closes the gap.
- **Reset** returns to the base case without the hold.

### 3. Quick reference

| Task | How |
|---|---|
| See whether core costs can be met | Read **Of which unrestricted**; red means they cannot |
| Test a late donor payment | Choose **Donor delay** |
| See what holding spend would do | Tick **Hold discretionary spend from week 5** |
| Send the forecast to the Board | **Export for Board** (CSV of what is on screen) |

---

## Reports

**Insight → Reports.** The financial statements — financial position, activities,
cash flows and the trial balance — for any period, against the same months a year
earlier or the approved budget. Every figure is read from the posted ledger, so
the statements always agree with each other and with the general ledger.

### 1. Choosing a statement

- The **tabs** switch between *Financial position*, *Activities*, *Cash flows* and
  *Trial balance*.
- **Period** offers the year to date, each completed quarter, each month of the
  year, and earlier years the books hold (for example *FY2025 (final)*). The
  statement of financial position and the trial balance read the books as at the
  period's last day; activities and cash flows read what moved within it.
- **Comparative** adds a column: the **Prior year** (the same months a year
  earlier), the **Approved budget** (activities only — phased to the same months),
  or **None**.
- On *Activities*, **Split by restriction class** shows unrestricted and
  restricted funds side by side, so donor money is never mixed with core.

### 2. Reading a statement

- The line beneath the statement's name says the date it is drawn at, the
  comparative, and the currency.
- The note at the right of the filters is the statement's own check: *Ledger in
  balance* for the trial balance, *Statement balances* for the statement of
  financial position. Anything else is shown in red and explained in the notes.
- **Click any line** to open that account in the general ledger for the same
  months.
- The **notes** explain the figures that matter — the surplus, restricted funds,
  support costs against funders' indirect-cost ceilings, what the cash includes.
- The strip at the foot says the basis: prepared under IFRS, the functional
  currency, and whether the months are closed or still open (figures in an open
  month can still change).

### 3. The year before the ledger started

The first year on the ledger still needs comparatives. The year before it was
kept in the previous system; its figures are carried across when the ledger
starts, and appear as *Prior year* and as their own period (*FY2025 (final)*).
They close exactly on the balances the ledger brought forward. A statement drawn
from them says so, and its lines do not open postings — there are none here.
When nothing is held for a comparative period, the column is blank and a note
says so.

### 4. Quick reference

| Task | How |
|---|---|
| Month-end statements | Choose the month under **Period** |
| Compare with last year | **Comparative** → Prior year |
| Compare with the budget | *Activities* → **Comparative** → Approved budget |
| Show restricted and unrestricted apart | *Activities* → **Split by restriction class** |
| See what makes up a figure | Click the line — the general ledger opens on it |
| Print or save as PDF | **Print** (choose *Save as PDF* as the destination) |
| Take the figures to a spreadsheet | **Export** (CSV, every figure unformatted, notes beneath) |

---

## Supporting documents

An auditor, or a donor checking how their money was spent, picks transactions and
asks for the paper behind each one. The application keeps that paper with the
record. For some records it is required: you cannot go on without it.

### 1. Attaching a document on a form

Forms that take a document show a box headed with what it is for, such as
**Supplier's invoice** or **Receipts**. The box is marked **Required** or
**Recommended**.

1. Click **+ Attach a document** and choose the file. A scan, a photo from your
   phone, or the PDF you were sent is fine. You can choose several at once.
2. The file uploads straight away and appears with its size. While it says
   *Uploading…*, wait before saving.
3. To take a file off, click **✕** next to it.
4. Save the form as usual. The documents are filed with the record.

Accepted: PDF, images (PNG, JPG, GIF, WebP, HEIC), Word, Excel, CSV, text and saved
emails. Each file can be up to 10 MB.

### 2. What is required

| Where | What you must attach | When |
|---|---|---|
| Payables → **+ New bill** | The supplier's invoice | Before **Capture bill** |
| Procurement → **Raise supplier bill** | The supplier's invoice | Before the bill is raised |
| Staff advances → **Surrender with receipts** | The receipts | Before **Post surrender** |
| Grants and awards → **+ Record award** | The signed grant agreement | When **Record as** is **Active**. A **Pipeline** award can be recorded without it. |
| Grants and awards → **Convert to award** | The signed agreement | Attach it on the award first |
| Journals | The supporting document | Before **Submit for approval**, if the entry is over 500,000 or is an **Adjustment**. The editor tells you when this applies. You can save a draft without it. |
| Procurement → quotations | Each supplier's quotation | Above 500,000, only quotations with their document count towards the three required |

If something is missing, the form says what to attach, and nothing is saved.

### 3. What is recommended

These records do not need a document, but have a place for one:

- **Receivables:** the donor's request for the claim, or the claim as sent.
- **Asset register:** the deed of gift, valuation, purchase invoice or title.
- **Asset verification:** a photo of the asset with its tag, or of the damage.
- **Donor reports:** the report as submitted and the donor's acknowledgement.

### 4. Opening a document and adding one later

Open the record: a bill, advance, award, invoice, asset or donor report. Its
documents are listed with who attached them and when. Click one to download it.

To add a document to a record that already exists, for example a signed agreement
that arrived after the award was recorded:

1. Under the documents, click **+ Attach a document** and choose the file.
2. Click **Add to the file**.

Records from before documents were required may say *No supporting document on
file*. Attach the paper when you find it.

A document cannot be removed once it is on a record: it is part of the audit
trail. If the wrong file went on, attach the right one; the history shows both.

### 5. Quick reference

| Task | How |
|---|---|
| Capture a bill | Payables → **+ New bill** → fill in → **+ Attach a document** (the invoice) → **Capture bill** |
| Surrender an advance | Open the advance → **Surrender with receipts** → code the receipts → attach them → **Post surrender** |
| Record a signed award | Grants → **+ Record award** → **Record as: Active** → attach the agreement → continue through the steps |
| Convert a pipeline award | Open the award → **+ Attach a document** → **Add to the file** → **Convert to award** |
| Add paperwork later | Open the record → **+ Attach a document** → **Add to the file** |

