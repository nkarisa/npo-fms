# Running and driving the application with `run-fms`

`run-fms` stands up a throwaway copy of the application and drives it in a
headless browser. It exists so that a change can be seen working in the real
application — pages loading, buttons doing what they should, the right person
being refused — without touching your development database.

It is a [Claude Code](https://claude.com/claude-code) skill, kept in the
repository at `.claude/skills/run-fms/`, but its scripts are ordinary shell and
Node and can be run by hand just as well.

| File | What it does |
|---|---|
| `serve.sh` | Builds a SQLite database under `writable/run-skill/` and serves the application from it on its own port |
| `driver.mjs` | Opens pages in headless Chromium, acts as a user, clicks, reads text and takes screenshots |
| `install.json` | The answers for the blank instance's `spark install` |
| `SKILL.md` | The instructions Claude Code reads |

---

## What it will not touch

- **Your database.** Every instance is a SQLite file under `writable/run-skill/`.
  The MySQL database named in `.env` is never opened.
- **Your development server.** Instances run on ports 8095 and 8096, not on
  `app.baseURL`.
- **Git.** `writable/run-skill/` ignores itself, and the driver's `node_modules/`
  is ignored too.

It serves your working copy, so code you have just changed is what you see.

---

## Using it through Claude Code

Ask for what you want to see. The skill loads itself when a request matches:

- *Screenshot the payroll page as m.otieno*
- *Check that the person who prepared a journal can't approve it*
- *Adopt the compact chart template on a new instance and show me the result*
- *Reproduce this bug in the browser, acting as the Executive Director*

Or name it: `/run-fms screenshot the payroll page as m.otieno`.

Claude starts an instance, drives the pages, looks at the screenshots and
reports what it found. Screenshots are saved under `writable/run-skill/shots/`
for you to open.

---

## Using it by hand

All commands run from the project root.

### Once

```bash
npm install --prefix .claude/skills/run-fms
```

This installs Playwright for the driver. If Chromium is not already on the
machine, add it with:

```bash
npx --prefix .claude/skills/run-fms playwright install chromium
```

PHP 8.2 or later with `sqlite3` and `pdo_sqlite` is needed, as for the test
suite. `serve.sh` looks for PHP at `/opt/homebrew/bin/php` and then on the
`PATH`; set `PHP_BIN` to use another.

### Start an instance

```bash
.claude/skills/run-fms/serve.sh up                  # the demonstration organisation (ELOG)
DATA=blank .claude/skills/run-fms/serve.sh up       # a brand-new instance (Coast Community Trust)
```

| | Demonstration | Blank |
|---|---|---|
| Address | http://localhost:8095 | http://localhost:8096 |
| Built from | `migrate` → `db:seed DatabaseSeeder` | `migrate` → `db:seed BaselineSeeder` → `install` |
| Database | `writable/run-skill/demo.sqlite` | `writable/run-skill/blank.sqlite` |
| Acting as | Wanjiru Kamau, Finance Manager, by default | Amina Salim, Finance Manager — the only user |

The first `up` builds the database, which takes about a second. After that the
database is kept, so **whatever you do in it is still there next time**. Open
the addresses in your own browser if you like.

### Stop, rebuild, check

```bash
.claude/skills/run-fms/serve.sh status
.claude/skills/run-fms/serve.sh down
.claude/skills/run-fms/serve.sh up --fresh          # rebuild the database from scratch
.claude/skills/run-fms/serve.sh reset               # the same: down, delete, up
```

Put `DATA=blank` in front of any of them for the blank instance. Rebuild after
adding a migration, or whenever you want the pristine data back.

### Run a spark command against an instance

```bash
.claude/skills/run-fms/serve.sh spark migrate:status
DATA=blank .claude/skills/run-fms/serve.sh spark install --show-config --no-header
```

---

## Driving it

`driver.mjs` takes a list of steps and runs them in order in one browser page:

```bash
node .claude/skills/run-fms/driver.mjs as:m.otieno@elog.or.ke goto:/payroll ss:payroll
```

That acts as Michael Otieno, opens the payroll page and saves
`writable/run-skill/shots/payroll.png`.

### Steps

| Step | What it does |
|---|---|
| `as:<email>` | Act as this user — the same as choosing them from the **Act as** menu. Lasts until the next `as:` |
| `goto:<path>` | Open a page, e.g. `goto:/journals`, and wait for its data to load |
| `click:<selector>` | Click something, e.g. `'click:text=Approve and post'` |
| `fill:<selector>=<value>` | Type into a field |
| `select:<selector>=<label>` | Choose from a drop-down by its visible label |
| `wait:<selector>` | Wait until something is showing, e.g. a message — fails after 15 seconds |
| `text:<selector>` | Print the text of something |
| `eval:<javascript>` | Run JavaScript in the page and print the result |
| `ss:<name>` | Screenshot what is on screen to `writable/run-skill/shots/<name>.png` |
| `ssfull:<name>` | Screenshot the whole page, however long |
| `sleep:<ms>` | Pause |

Selectors are [Playwright's](https://playwright.dev/docs/locators): `text=…` for
visible text, CSS such as `.jr-row`, or `role=button[name="Reject"]`. Quote any
step with spaces in it.

Add `--port 8096` to drive the blank instance, and `--width <px>` to change the
browser width (1440 by default).

### What it prints

Each step is echoed as it runs. Alongside them it prints:

- `[api 422] POST /api/…` — any API call that failed, which is usually a
  business rule saying no
- `[console] …` — errors from the page's JavaScript
- `FAILED: …` — the step that could not be done. It saves
  `shots/_failure.png` showing the page at that moment and exits with status 1

An uncaught JavaScript error exits with status 2 even when every step succeeded.

---

## Examples

### The two-person rule and the approval limit

Journal JV-26-0310 was prepared by Michael Otieno and is for KES 510,000 — over
the Finance Manager's KES 500,000 threshold.

```bash
node .claude/skills/run-fms/driver.mjs \
  as:m.otieno@elog.or.ke goto:/journals/JV-26-0310 'wait:text=Approval has to come from someone else' ss:01-preparer \
  as:w.kamau@elog.or.ke  goto:/journals/JV-26-0310 'click:text=Approve and post' 'wait:text=need the Executive Director' ss:02-finance-manager \
  as:d.kiptoo@elog.or.ke goto:/journals/JV-26-0310 'click:text=Approve and post' sleep:500 ss:03-executive-director \
  goto:/journals 'text:.jr-row:has-text("JV-26-0310")'
```

The preparer is not offered **Approve and post** at all; the Finance Manager is
refused for the amount; the Executive Director posts it. Run
`serve.sh up --fresh` to put the journal back to *Pending approval*.

### Adopting a chart template on a new instance

```bash
DATA=blank .claude/skills/run-fms/serve.sh up
node .claude/skills/run-fms/driver.mjs --port 8096 goto:/coa \
  'click:text=Start from a template' 'click:text=Not-for-profit — compact' \
  'click:text=Review 44 accounts' 'click:text=Adopt 44 accounts' sleep:500 ss:adopted
```

### A whole page, as a particular person

```bash
node .claude/skills/run-fms/driver.mjs as:m.otieno@elog.or.ke goto:/payroll ssfull:payroll-full
```

### Finding what to click

When you do not know a button's wording, list the buttons in an open panel:

```bash
node .claude/skills/run-fms/driver.mjs goto:/coa 'click:text=Start from a template' \
  'eval:[...document.querySelectorAll("[role=dialog] button, .drawer button")].map(b=>b.innerText.trim())'
```

### Without a browser

The API and the database can be read directly:

```bash
curl -s localhost:8095/api/journals/JV-26-0310
sqlite3 writable/run-skill/demo.sqlite "select code, name from db_entities"
```

`curl` always acts as the default user, since it carries no **Act as** choice.
Tables in the SQLite database are prefixed `db_`.

---

## Who to act as

On the demonstration instance, the people in [setup.md](setup.md#who-to-act-as).
The ones most useful for checking permissions:

| Email | Role | Useful for |
|---|---|---|
| w.kamau@elog.or.ke | Finance Manager | The default. Settings, approving up to KES 500,000 |
| m.otieno@elog.or.ke | Senior Accountant | Preparing journals and payroll |
| d.kiptoo@elog.or.ke | Executive Director | Approving above the Finance Manager's limit |
| j.achieng@elog.or.ke | Accountant | Bank reconciliation |
| audit@pkfea.com | Auditor | Seeing what read-only access sees |

On the blank instance there is only a.salim@cct.or.ke.

---

## Things that behave unexpectedly

- **Both instances think it is 31 August 2026.** They read `app.asOf` from
  `.env`, like everything else there except the database.
- **The debug toolbar logs a CORS error.** It calls `app.baseURL` (port 8090)
  from port 8095. The driver hides it; it is harmless.
- **A button that is not there is often a rule, not a bug.** The preparer of a
  journal sees a note in place of **Approve and post**, not a greyed-out button.
  Look at `shots/_failure.png` when a `click:` or `wait:` times out.
- **Journal rows are not table rows.** The register is built from `div.jr-row`
  elements, and `/journals/<reference>` opens the journal as a panel over it.

### Why the database is configured the way it is

Settings in `.env` cannot be overridden from the environment for
`database.default.*`: PHP drops variable names containing dots, so the values in
`.env` always win. `serve.sh` points the application at the `tests` database
group instead — which `.env` does not set — and gives it the SQLite file. That
is why the tables carry the `tests` group's `db_` prefix.

`php spark migrate` also ignores the default group unless it is told `-g tests`,
and would otherwise migrate MySQL. `serve.sh spark migrate…` adds it for you, so
use that rather than `php spark migrate` directly.

---

## Troubleshooting

**`Port 8095 is taken by something else`**
An instance from an earlier session is still running, or something else holds the
port. Run `serve.sh down`, or pass another port: `PORT=8097 serve.sh up` — and
then `--port 8097` to the driver.

**`Server did not come up`**
PHP was not found or could not start. The log named in the message says why.
Set `PHP_BIN=/path/to/php` if PHP is not where `serve.sh` looks.

**`no such table: db_entities`**
The migrations went to the wrong database. Rebuild: `serve.sh up --fresh`.

**`Timeout 15000ms exceeded`**
What the step was waiting for never appeared. Open `shots/_failure.png`.

**`Cannot find package 'playwright'`**
Run `npm install --prefix .claude/skills/run-fms`.
