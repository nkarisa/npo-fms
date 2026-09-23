/**
 * The other branch: choosing the demonstration organisation, which brings its own
 * entities and users so the organisation steps are never asked.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8097';
const SHOTS = '/tmp/wizard-demo-shots';
const DB = process.env.DB || '/tmp/wizard-demo.sqlite';
mkdirSync(SHOTS, { recursive: true });

let failures = 0;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1000, height: 1600 } });
page.on('pageerror', (e) => { console.log(`  ! page error: ${e.message}`); failures++; });

const expectStep = async (heading, timeout = 20000) => {
  try {
    await page.locator('#install .auth-title', { hasText: heading }).first().waitFor({ timeout });
    console.log(`  ✓ on "${heading}"`);
  } catch (e) {
    const at = await page.locator('#install .auth-title').first().textContent().catch(() => '(nothing)');
    const why = await page.locator('#install .auth-msg').first().textContent().catch(() => '');
    console.log(`  ✕ expected "${heading}", got "${String(at).trim()}"${why ? ` — ${why.trim()}` : ''}`);
    failures++;
  }
};
const expectText = async (what, timeout = 20000) => {
  try {
    await page.locator(`#install:has-text("${what}")`).first().waitFor({ timeout });
    console.log(`  ✓ ${what}`);
  } catch (e) { console.log(`  ✕ expected to see: ${what}`); failures++; }
};

await page.goto(`${BASE}/install`, { waitUntil: 'domcontentloaded' });
await expectStep('The database server');
await page.selectOption('[name=driver]', 'SQLite3');
await page.click('#f-server button[type=submit]');

// A file outside writable/, given by its path rather than picked from the list.
await expectStep('The database');
await page.click('input[name=pick][value=__typed]');
await page.fill('[name=typed]', DB);
await page.click('#f-db button[type=submit]');

await expectStep('What to put in it');
await page.click('.mfa-choice[data-choice=demo]');

// The organisation, entities and user steps are skipped entirely.
await expectStep('Check and install');
await expectText('The demonstration organisation');
await expectText('brings its own entities, users and ledger');
const steps = await page.locator('#install .ins-step').allTextContents();
console.log(`  steps shown: ${steps.map((s) => s.replace(/^[0-9✓]\s*/, '').trim()).join(' / ')}`);
if (steps.length !== 4) { console.log(`  ✕ expected 4 steps for the demonstration path, saw ${steps.length}`); failures++; }
await page.screenshot({ path: `${SHOTS}/01-review.png`, fullPage: true });

await page.click('#f-run button[type=submit]');
// The demonstration seeder loads 21 seeders' worth of data.
await expectStep('Installed', 300000);
await expectText('published in docs/setup.md');
await expectText('second sign-in step is switched off');
await page.screenshot({ path: `${SHOTS}/02-done.png`, fullPage: true });

const entities = await page.locator('#install .ins-read').first().textContent().catch(() => '');
console.log(`  entities: ${entities.replace(/\s+/g, ' ').trim().slice(0, 90)}`);

const closed = await page.goto(`${BASE}/install`, { waitUntil: 'domcontentloaded' });
console.log(`  GET /install -> ${closed.status()}`);
if (closed.status() !== 404) { console.log('  ✕ expected 404'); failures++; }

await browser.close();
console.log(`\n${failures === 0 ? 'All good.' : failures + ' problem(s).'}`);
process.exit(failures === 0 ? 0 : 1);
