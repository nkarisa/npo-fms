/**
 * Walks the install wizard end to end in a headless browser and says what it saw.
 *
 * Point it at an instance that has NOT been installed — one with no
 * writable/installed.lock and no database credentials in .env. serve.sh cannot make
 * one, because it builds and seeds the database itself, which is the very thing the
 * wizard is for; so this expects a server started against a bare copy of the
 * project (see docs/setup.md, "Installing in a browser").
 *
 *   BASE=http://localhost:8097 SHOTS=/tmp/wizard-shots node .claude/skills/run-fms/wizard.mjs
 *
 * Exits non-zero on the first thing that is not as it should be, so it can be used
 * as a check rather than only read.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE || 'http://localhost:8097';
const SHOTS = process.env.SHOTS || '/tmp/wizard-shots';
/** The SQLite database the wizard creates, as writable/<DB>.sqlite in the served copy. */
const DB = process.env.DB || 'wizard_books';
mkdirSync(SHOTS, { recursive: true });

let shot = 0;
let failures = 0;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1000, height: 1400 } });
page.on('pageerror', (e) => {
  console.log(`  ! page error: ${e.message}`);
  failures++;
});
page.on('console', (m) => {
  if (m.type() === 'error') console.log(`  ! console: ${m.text()}`);
});

const save = async (name) => {
  shot++;
  const file = `${SHOTS}/${String(shot).padStart(2, '0')}-${name}.png`;
  await page.screenshot({ path: file, fullPage: true });
  return file;
};

const card = () => page.locator('#install');

/** Asserts some text is on the card, and says so either way. */
const expectText = async (what, timeout = 20000) => {
  try {
    await page.locator(`#install:has-text("${what}")`).first().waitFor({ timeout });
    console.log(`  ✓ ${what}`);
  } catch (e) {
    console.log(`  ✕ expected to see: ${what}`);
    failures++;
  }
};

/**
 * Asserts the wizard is on a step, by its heading rather than by any text on the
 * card — the step list carries every step's name, so looking for the words alone
 * would match wherever it actually was.
 */
const expectStep = async (heading, timeout = 20000) => {
  try {
    await page.locator('#install .auth-title', { hasText: heading }).first().waitFor({ timeout });
    console.log(`  ✓ on "${heading}"`);
  } catch (e) {
    const at = await page.locator('#install .auth-title').first().textContent().catch(() => '(nothing)');
    const why = await page.locator('#install .auth-msg').first().textContent().catch(() => '');
    console.log(`  ✕ expected the step "${heading}", but it is on "${String(at).trim()}"${why ? ` — ${why.trim()}` : ''}`);
    failures++;
  }
};

const step = (n) => console.log(`\n--- ${n}`);

// ---- 1. An uninstalled instance sends everything to the installer ----

step('a fresh instance sends you to the installer');
await page.goto(`${BASE}/journals`, { waitUntil: 'networkidle' });
console.log(`  /journals ended at ${page.url()}`);
if (!page.url().endsWith('/install')) { console.log('  ✕ expected to land on /install'); failures++; }
await expectStep('The database server');
await expectText('lists the databases this user can reach');
// A fresh instance has no encryption key yet: the installer says so, and says it
// will generate one, without blocking.
await expectText('Worth knowing about this server');
await expectText('Encryption key');
console.log(`  shot ${await save('server-step')}`);

// ---- 2. A server that cannot be reached is refused in the driver's words ----

step('a server that cannot be reached');
await page.selectOption('[name=driver]', 'MySQLi');
await page.fill('[name=hostname]', '127.0.0.1');
await page.fill('[name=port]', '1');
await page.fill('[name=username]', 'nobody');
await page.fill('[name=password]', 'wrong');
await page.click('#f-server button[type=submit]');
await expectText('could not be reached');
console.log(`  shot ${await save('server-refused')}`);

// ---- 3. The real thing: SQLite, and a new database file created from the list ----

step('the databases are listed, and a new one is created');
await page.selectOption('[name=driver]', 'SQLite3');
await page.click('#f-server button[type=submit]');
await expectStep('The database');
await expectText('Create a new database file');
console.log(`  shot ${await save('database-list')}`);
// A name that is not a file name is refused on this step.
await page.click('input[name=pick][value=__new]');
await page.fill('[name=newName]', 'no spaces');
await page.click('#f-db button[type=submit]');
await expectText('letters, digits');
await page.fill('[name=newName]', DB);
await page.click('#f-db button[type=submit]');
await expectStep('What to put in it');
await expectText('created');
await expectText('A new organisation');
await expectText('A copy of the demonstration organisation');
console.log(`  shot ${await save('data-step')}`);

// ---- 4. A new instance ----

step('a new instance');
await page.click('.mfa-choice[data-choice=new]');
await expectStep('The organisation');
await page.fill('[name=registeredName]', 'Mara Conservation Trust');
await page.fill('[name=shortName]', 'Mara Trust');
await page.fill('[name=taxPin]', 'P051234567X');
await page.selectOption('[name=yearEnd]', '30 June');
await page.fill('[name=firstYear]', '2027');
// The derived year is worked out as you type; check it says what the installer will do.
await expectText('FY2027 · 1 July 2026 to 30 June 2027');
console.log(`  shot ${await save('organisation-step')}`);
await page.click('#f-org button[type=submit]');

// ---- 5. Entities: the head office is prefilled, and more can be added ----

step('its entities');
await expectStep('Its entities');
const code = await page.inputValue('[name=entityCode]');
const name = await page.inputValue('[name=entityName]');
console.log(`  head office prefilled as ${code} / ${name}`);
if (code !== 'MARATRUS-HQ') { console.log(`  ! code was suggested as ${code}`); }
await page.fill('[name=entityCode]', 'MARA-HQ');
await page.fill('[name=entityName]', 'Mara Conservation Trust — Head Office');

await page.click('#ins-add');
await page.fill('.ins-entity[data-i="0"] [data-f=code]', 'MARA-NRB');
await page.fill('.ins-entity[data-i="0"] [data-f=name]', 'Mara Trust Nairobi Office');
await page.click('#ins-add');
await page.fill('.ins-entity[data-i="1"] [data-f=code]', 'MARA-END');
await page.fill('.ins-entity[data-i="1"] [data-f=name]', 'Mara Endowment Fund');
await page.selectOption('.ins-entity[data-i="1"] [data-f=type]', 'Related trust');
await page.selectOption('.ins-entity[data-i="1"] [data-f=currency]', 'USD');
console.log(`  shot ${await save('entities-step')}`);

// A bad code is refused on this step, not at the end.
await page.fill('.ins-entity[data-i="1"] [data-f=code]', 'no lowercase');
await page.click('#f-ent button[type=submit]');
await expectText('is not an entity code');
await page.fill('.ins-entity[data-i="1"] [data-f=code]', 'MARA-END');
await page.click('#f-ent button[type=submit]');

// ---- 6. The first user ----

step('the first user');
await expectStep('The first user');
await expectText('Every permission, at all 3 entities');
await expectText('You will not be able to approve your own entries');
await expectText('At least 12 characters');
// A name with no surname is refused: the ledger shows who prepared and who approved.
await page.fill('[name=userName]', 'Grace');
await page.fill('[name=userEmail]', 'g.wanjiru@mara.or.ke');
await page.click('#f-user button[type=submit]');
await expectText('full name');
await page.fill('[name=userName]', 'Grace Wanjiru');
console.log(`  shot ${await save('user-step')}`);
await page.click('#f-user button[type=submit]');

// ---- 7. Read it back, then write it ----

step('check and install');
await expectStep('Check and install');
await expectText('Mara Conservation Trust');
await expectText('MARA-NRB');
await expectText('Administrator and Finance Manager — every permission, at every entity');
await expectText('Nothing has been written yet');
console.log(`  shot ${await save('review-step')}`);

await page.click('#f-run button[type=submit]');
// Migrating 43 migrations and seeding takes a while.
await expectStep('Installed', 180000);
await expectText('Mara Conservation Trust is ready');
await expectText('Choose your password');
await expectText('shown once');
await expectText('invite a second person');
console.log(`  shot ${await save('done')}`);

const link = await card().locator('.ins-link').first().textContent().catch(() => null);
console.log(`  one-time link: ${link ? link.trim().slice(0, 64) + '…' : '(none)'}`);

// ---- 8. The installer is gone ----

step('the installer has closed');
const closed = await page.goto(`${BASE}/install`, { waitUntil: 'domcontentloaded' });
console.log(`  GET /install -> ${closed.status()}`);
if (closed.status() !== 404) { console.log('  ✕ expected 404 now that the lock is written'); failures++; }
const api = await page.request.get(`${BASE}/api/install`);
console.log(`  GET /api/install -> ${api.status()}`);
if (api.status() !== 404) { console.log('  ✕ expected 404'); failures++; }

// And the application is reachable again.
const login = await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
console.log(`  GET /login -> ${login.status()} at ${page.url()}`);
if (!page.url().endsWith('/login')) { console.log('  ✕ expected to stay on /login'); failures++; }
await page.locator('#auth:has-text("Sign in")').first().waitFor({ timeout: 15000 }).then(
  () => console.log('  ✓ the sign-in screen is served'),
  () => { console.log('  ✕ no sign-in screen'); failures++; },
);
console.log(`  shot ${await save('sign-in')}`);

await browser.close();
console.log(`\n${failures === 0 ? 'All good.' : failures + ' problem(s).'}`);
process.exit(failures === 0 ? 0 : 1);
