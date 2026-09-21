---
name: run-fms
description: Run, start, launch, screenshot, click through or smoke-test the FMS (ELOG finance suite) CodeIgniter app — spins up a throwaway SQLite instance (demo data or a blank new install) on its own port and drives it with a headless Playwright step-runner. Use to see a UI/API change working, reproduce a bug in the browser, act as a given user (preparer vs approver), or run the PHPUnit suite.
---

# Run FMS

CodeIgniter 4 / PHP 8.4 app, server-rendered shell + JS pages that call `/api/*`.
Agents drive it with two files in this directory. Paths are relative to the
project root.

- `serve.sh`: builds a **throwaway SQLite DB** under `writable/run-skill/` and
  serves it on its own port. It never touches the MySQL DB in `.env`, and never
  touches the dev server you may already have on `app.baseURL` (`:8090`).
- `driver.mjs`: headless Chromium step runner. It acts as a user, opens pages,
  clicks, reads text and takes screenshots.

## Prerequisites (macOS, as verified)

- PHP 8.4 at `/opt/homebrew/bin/php`. It is **not on the agent shell's PATH**;
  `serve.sh` finds it, but run other commands with `export PATH=/opt/homebrew/bin:$PATH`.
  Extensions needed: `intl mbstring sqlite3 pdo_sqlite fileinfo openssl`.
- `composer install` already done (`vendor/` present).
- Node 22 and the Playwright Chromium already cached (`~/Library/Caches/ms-playwright/chromium-1181`).
  Install the driver's one dependency once:

```bash
npm install --prefix .claude/skills/run-fms
```

If Chromium is missing: `npx --prefix .claude/skills/run-fms playwright install chromium`.

## Run (agent path)

```bash
.claude/skills/run-fms/serve.sh up                # demo data (ELOG), http://localhost:8095
DATA=blank .claude/skills/run-fms/serve.sh up     # brand-new instance (CCT), http://localhost:8096
.claude/skills/run-fms/serve.sh status
```

The first `up` builds the DB in about 1s (migrate + seed). Later `up`s reuse it,
so **state persists between runs**. Use `serve.sh up --fresh` (or `reset`) to get
the pristine data back. Stop with `serve.sh down` (and `DATA=blank … down`).

Drive it. Steps run in order in one page, and the act-as cookie persists between steps:

```bash
node .claude/skills/run-fms/driver.mjs \
  as:m.otieno@elog.or.ke goto:/journals/JV-26-0310 'wait:text=Approval has to come from someone else' ss:01-preparer-cannot-approve \
  as:w.kamau@elog.or.ke  goto:/journals/JV-26-0310 'click:text=Approve and post' 'wait:text=need the Executive Director' ss:02-fm-over-limit \
  as:d.kiptoo@elog.or.ke goto:/journals/JV-26-0310 'click:text=Approve and post' sleep:500 ss:03-ed-approved \
  goto:/journals 'text:.jr-row:has-text("JV-26-0310")'
```

Steps: `as:<email>`, `goto:<path>`, `click:<sel>`, `fill:<sel>=<v>`, `select:<sel>=<label>`,
`wait:<sel>`, `text:<sel>`, `eval:<js>`, `ss:<name>`, `ssfull:<name>`, `sleep:<ms>`.
Selectors are Playwright's (`text=…`, CSS, `role=button[name="…"]`). `--port 8096`
targets the blank instance.

- Screenshots go to `writable/run-skill/shots/<name>.png`. **Read them.**
- On failure it prints `FAILED: …`, saves `shots/_failure.png` and exits 1.
  Uncaught page errors exit 2.
- API errors (`[api 422] POST /api/…`) and console errors are printed inline.

To discover what to click, list the buttons in an open panel:
`'eval:[...document.querySelectorAll("[role=dialog] button, .drawer button")].map(b=>b.innerText.trim())'`

Blank instance flow (chart-of-accounts template adoption), verified:

```bash
node .claude/skills/run-fms/driver.mjs --port 8096 goto:/coa 'click:text=Start from a template' \
  'click:text=Not-for-profit — compact' 'click:text=Review 44 accounts' 'click:text=Adopt 44 accounts' sleep:500 ss:14-adopted
```

### Who to act as

Demo (`:8095`): `w.kamau@elog.or.ke` Finance Manager (default, limit KES 5M, but
journals over **KES 500,000** need the ED), `m.otieno@elog.or.ke` Senior Accountant,
`d.kiptoo@elog.or.ke` Executive Director, `j.achieng@elog.or.ke` Accountant,
`audit@pkfea.com` Auditor. Full list in `docs/setup.md`. Blank (`:8096`): only
`a.salim@cct.or.ke` (Finance Manager), from `install.json`.

### API and DB directly

```bash
curl -s localhost:8095/api/me
curl -s localhost:8095/api/journals/JV-26-0310 | grep -m1 '"status"'
sqlite3 writable/run-skill/demo.sqlite "select code from db_entities"   # tables are prefixed db_
.claude/skills/run-fms/serve.sh spark migrate:status                    # any spark command, against the SQLite DB
```

`curl` has no act-as cookie, so it is always the default user. For POSTs as someone
else, use the driver (`as:` then `eval:fetch(...)`).

## Test

```bash
export PATH=/opt/homebrew/bin:$PATH
vendor/bin/phpunit --filter JournalLifecycleTest   # ~12s
vendor/bin/phpunit                                 # 227 tests, ~2m20s
```

Tests use their own in-memory SQLite and need no server.

## Run (human path)

`./server.sh` (port 8075) or `php spark serve --port 8090` against the MySQL DB in
`.env`. See `docs/setup.md`. Agents should not use this: it is the user's real
dev data.

## Gotchas

- **Environment variables cannot override `.env`'s `database.default.*`.** PHP
  drops dotted names from `$_ENV`, so DotEnv fills `$_ENV['database.default.X']`
  from `.env`, and that is checked before any env var. `serve.sh` works around it
  by setting `database_defaultGroup=tests` + `database_tests_database=<file>`,
  which `.env` doesn't define. Hence the `db_` table prefix.
- **`spark migrate` ignores `defaultGroup`.** Without `-g tests` it migrates the
  MySQL `default` group even with the env above set. `serve.sh spark migrate…`
  appends `-g tests` for you. Don't call bare `php spark migrate` with the
  SQLite env and expect it to go there.
- `app.baseURL` in `.env` is `:8090`, so the debug toolbar on `:8095/8096` makes a
  cross-origin call there and logs a CORS error. The driver filters it out.
  Page links and `/api` calls are root-relative, so nothing else leaks to 8090.
- `.env`'s `app.asOf = 2026-08-31` applies to both instances ("today" is Aug 2026).
- The journal "detail" route (`/journals/<ref>`) opens as a drawer over the
  register. The register rows are `div.jr-row`, not `<tr>`.
- As the preparer, the Approve button is **absent** (a footer note replaces it),
  not disabled. Over-limit approvals return 422 and show the reason in the footer.
- `vendor/bin/phpunit` exits **1 even when all tests pass**, because of the
  "No code coverage driver available" warning. Read the `Tests: … OK` line, not the exit code.

## Troubleshooting

- `Port 8095 is taken by something else`: an earlier server is still running.
  Use `serve.sh down`, or check with `lsof -nP -iTCP:8095 -sTCP:LISTEN`.
- `db:seed` fails with `no such table: db_entities`: migrations went to MySQL
  (missing `-g tests`). Use `serve.sh up --fresh`.
- `Server did not come up; see writable/run-skill/server-<data>.log`: PHP not found
  or crashed. Set `PHP_BIN=/path/to/php`.
- Driver `locator… Timeout 15000ms exceeded`: the text isn't on the page. Open
  `shots/_failure.png`. It's often a business rule message shown instead of the
  button you expected.
