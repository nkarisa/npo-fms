#!/usr/bin/env node
// Drive a running FMS instance (see serve.sh) in headless Chromium.
//
//   node driver.mjs [--port 8095] [--width 1440] step step ...
//
// Steps run in order in one browser page; the act-as cookie persists across them.
//   as:<email>            act as this user (POST /api/me/act-as)
//   goto:<path>           open a page, wait for its /api calls to settle
//   click:<selector>      Playwright selector: `text=Approve and post`, `#id`, `role=button[name="Reject"]`
//   fill:<selector>=<v>   type into an input
//   select:<selector>=<v> choose an <option> by label
//   wait:<selector>       wait until visible
//   text:<selector>       print innerText (first match)
//   eval:<js>             evaluate in the page, print the JSON result
//   ss:<name>             screenshot to writable/run-skill/shots/<name>.png (viewport)
//   ssfull:<name>         full-page screenshot
//   sleep:<ms>
//
// Exits non-zero on a failed step or an uncaught page error. Console errors are
// printed; the debug toolbar's cross-origin call to app.baseURL is ignored.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const shots = resolve(root, 'writable/run-skill/shots');
mkdirSync(shots, { recursive: true });

const args = process.argv.slice(2);
const opt = (name, dflt) => {
  const i = args.indexOf(`--${name}`);
  if (i === -1) return dflt;
  const v = args[i + 1];
  args.splice(i, 2);
  return v;
};
const port = opt('port', process.env.PORT || '8095');
const width = +opt('width', '1440');
const base = `http://localhost:${port}`;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width, height: 900 }, baseURL: base });
page.setDefaultTimeout(15000);
let pageErrors = 0;
page.on('pageerror', (e) => { pageErrors++; console.log(`[pageerror] ${e.message}`); });
page.on('console', (m) => {
  if (m.type() === 'error' && !/debugbar|ERR_FAILED/.test(m.text())) console.log(`[console] ${m.text()}`);
});
page.on('response', (r) => {
  if (r.url().includes('/api/') && r.status() >= 400) console.log(`[api ${r.status()}] ${r.request().method()} ${r.url().replace(base, '')}`);
});

const settle = async () => {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
};

try {
  for (const step of args) {
    const i = step.indexOf(':');
    const [cmd, arg] = i === -1 ? [step, ''] : [step.slice(0, i), step.slice(i + 1)];
    console.log(`> ${step}`);
    switch (cmd) {
      case 'as': {
        const r = await page.request.post('/api/me/act-as', { data: { email: arg } });
        const body = await r.json();
        if (!r.ok()) throw new Error(body.error || `act-as ${r.status()}`);
        console.log(`  acting as ${body.me.name} (${body.me.role})`);
        break;
      }
      case 'goto': await page.goto(arg); await settle(); break;
      case 'click': await page.locator(arg).first().click(); await settle(); break;
      case 'fill': { const j = arg.lastIndexOf('='); await page.locator(arg.slice(0, j)).first().fill(arg.slice(j + 1)); break; }
      case 'select': { const j = arg.lastIndexOf('='); await page.locator(arg.slice(0, j)).first().selectOption({ label: arg.slice(j + 1) }); await settle(); break; }
      case 'wait': await page.locator(arg).first().waitFor({ state: 'visible', timeout: 15000 }); break;
      case 'text': console.log((await page.locator(arg).first().innerText()).trim()); break;
      case 'eval': console.log(JSON.stringify(await page.evaluate(arg), null, 2)); break;
      case 'ss': case 'ssfull': {
        const path = `${shots}/${arg || 'shot'}.png`;
        await page.screenshot({ path, fullPage: cmd === 'ssfull' });
        console.log(`  saved ${path}`);
        break;
      }
      case 'sleep': await page.waitForTimeout(+arg); break;
      default: throw new Error(`unknown step "${cmd}"`);
    }
  }
} catch (e) {
  console.log(`FAILED: ${e.message.split('\n')[0]}`);
  await page.screenshot({ path: `${shots}/_failure.png` }).catch(() => {});
  console.log(`  saved ${shots}/_failure.png`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
if (pageErrors && !process.exitCode) process.exitCode = 2;
