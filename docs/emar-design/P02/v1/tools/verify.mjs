// P02 v1 verification harness (copied from P01 v1's and adapted) — drives headless Chrome over the DevTools
// Protocol (Node 22 WebSocket) against the running preview (serve.mjs).
// For every state: load at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px
// at device scale 2), run the steps, capture a screenshot, and record
// horizontal overflow and console errors. Also records a real-Tab keyboard
// walk: a chart cell opens P01's dialog and focus returns to the cell. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P02/v1/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P02_URL ?? 'http://127.0.0.1:4383/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9348;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P02_PROFILE ?? path.join(here, '..', '.chrome-profile');

const SIZES = [
    { key: '1440', width: 1440, height: 900, dsf: 1 },
    { key: '1280', width: 1280, height: 800, dsf: 1 },
    { key: 'zoom200', width: 720, height: 450, dsf: 2 },
];

// Steps run in the page. Helpers: click(text), tile(key), wait(ms), set(sel, v), pick(text), scrollBody(y), el(sel).
const H = `
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const click = async (t, root = document) => { const b = [...root.querySelectorAll('button,a,[role=menuitem],[role=option],[cmdk-item]')].find((e) => e.offsetParent !== null && e.textContent.trim().replace(/\\s+/g,' ').startsWith(t)); if (!b) throw new Error('No control: ' + t); b.click(); await wait(350); };
const tile = async (k) => { document.querySelector('[data-tile="' + k + '"]').click(); await wait(300); };
const el = (s) => document.querySelector(s);
const set = async (s, v) => { const e = el(s); const proto = e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); await wait(200); };
const pick = async (trigger, option) => { el(trigger).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' })); await wait(400); const o = [...document.querySelectorAll('[role=option]')].find((e) => e.textContent.trim().startsWith(option)); if (!o) throw new Error('No option: ' + option); o.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerType: 'mouse' })); o.click(); await wait(350); };
const scrollBody = async (y) => { const b = el('[data-wizard-region="body"]') || [...document.querySelectorAll('[role=dialog] .overflow-y-auto')].pop(); if (b) b.scrollTop = y; await wait(250); };
const scrollTo = async (s) => { el(s)?.scrollIntoView({ block: 'center' }); await wait(250); };
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button')].find((e) => e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const save = async () => { fbtn('Record outcome').click(); await wait(350); };
`;
const R = (q = '') => `#/emar/mar?client_id=201${q ? `&${q}` : ''}`;
const P = (id, q = '') => `#/emar/mar?client_id=${id}${q ? `&${q}` : ''}`;
const CP = (id, q = '') => `#/operations/clients/${id}?${q}`;
const cellMenu = (cell) => `window.scrollTo(0, 420); await wait(250); const c=document.querySelector('[data-cell="${cell}"]'); const b=c.getBoundingClientRect(); c.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:b.x+30,clientY:b.y+20})); await wait(400);`;
const rowMenu = (text) => `const r=[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('${text}')); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:640,clientY:r.getBoundingClientRect().y+20})); await wait(400);`;

const SHOTS = [
    // Chart
    { name: '01-chart-day', hash: R(), core: true },
    { name: '02-chart-day-scrolled', hash: R(), steps: `window.scrollTo(0, 520); await wait(300);`, core: true },
    { name: '03-chart-week', hash: R('mode=week'), steps: `window.scrollTo(0, 480); await wait(300);`, core: true },
    { name: '04-chart-earlier-day', hash: R('day=2026-09-27'), steps: `window.scrollTo(0, 480); await wait(300);` },
    { name: '05-chart-cell-menu-mark-given', hash: R(), steps: cellMenu('losartan-9:00 am'), core: true },
    { name: '06-chart-cell-menu-full-record', hash: R(), steps: cellMenu('insulin-9:00 am') },
    { name: '07-chart-record-opens-p01-dialog', hash: R('open=record:r16'), core: true },
    { name: '08-chart-alerts-to-read', hash: R('dlg=warnings'), core: true },
    { name: '09-as-needed', hash: R('view=asneeded') },
    { name: '10-chart-allergy-match-critical', hash: P(203), steps: `window.scrollTo(0, 440); await wait(300);`, core: true },
    { name: '11-chart-row-menu', hash: R(), steps: `window.scrollTo(0, 420); await wait(250); const r=document.querySelector('tr[data-row=warfarin]'); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:640,clientY:r.getBoundingClientRect().y+20})); await wait(400);` },
    // Medicines
    { name: '20-medicines-current', hash: R('tab=medicines'), core: true },
    { name: '21-medicine-details-order', hash: R('tab=medicines&dlg=med:insulin'), core: true },
    { name: '22-medicine-details-how', hash: R('tab=medicines&dlg=med:insulin:how') },
    { name: '23-medicine-details-photo', hash: R('tab=medicines&dlg=med:vitd:photo') },
    { name: '24-medicine-details-doses', hash: R('tab=medicines&dlg=med:metformin:doses') },
    { name: '25-medicines-stopped', hash: R('tab=medicines&view=stopped') },
    { name: '26-pack-photos', hash: R('tab=medicines&view=photos'), core: true },
    { name: '27-pack-photo-preview', hash: R('tab=medicines&view=photos'), steps: `[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('Metformin')).click(); await wait(900);` },
    // Support plan
    { name: '30-support-by-medicine', hash: R('tab=support'), core: true },
    { name: '31-support-assessment', hash: R('tab=support&view=assessment') },
    { name: '32-support-no-assessment', hash: P(203, 'tab=support&view=assessment') },
    // Allergies & alerts
    { name: '40-allergies-recorded-reviewed', hash: R('tab=allergies'), core: true },
    { name: '41-allergies-not-reviewed', hash: P(203, 'tab=allergies'), core: true },
    { name: '42-allergies-none-recorded', hash: P(202, 'tab=allergies'), core: true },
    { name: '43-allergies-couldnt-load', hash: P(204, 'tab=allergies'), core: true },
    { name: '44-allergies-no-known', hash: P(205, 'tab=allergies') },
    { name: '45-chart-alerts-lead', hash: R('as=lead&tab=allergies&view=alerts'), core: true },
    { name: '46-add-chart-alert', hash: R('as=lead&tab=allergies&view=alerts&dlg=alert:new'), core: true },
    { name: '47-add-chart-alert-validation', hash: R('as=lead&tab=allergies&view=alerts&dlg=alert:new'), steps: `[...document.querySelectorAll('[role=dialog] button')].find(b=>b.textContent.trim()==='Add alert').click(); await wait(400);` },
    { name: '48-pause-dose-alerts', hash: R('as=lead&tab=allergies&view=alerts&dlg=pause'), core: true },
    { name: '49-pause-confirm-loosens', hash: R('as=lead&tab=allergies&view=alerts&dlg=pause'), steps: `document.querySelector('[data-tile="Team decision"]').click(); await wait(200); await set('#p-reason','Aroha manages her own reminders this month'); [...document.querySelectorAll('[role=dialog] button')].find(b=>b.textContent.trim()==='Continue').click(); await wait(600);`, core: true },
    { name: '50-stop-showing-on-open-loosens', hash: R('as=lead&tab=allergies&view=alerts&dlg=onopen-off:a1') },
    { name: '51-alerts-paused', hash: R('as=lead&tab=allergies&view=alerts&dlg=pause'), steps: `document.querySelector('[data-tile="Team decision"]').click(); await wait(200); await set('#p-reason','Aroha manages her own reminders this month'); [...document.querySelectorAll('[role=dialog] button')].find(b=>b.textContent.trim()==='Continue').click(); await wait(600); [...document.querySelectorAll('[role=alertdialog] button')].find(b=>b.textContent.trim()==='Pause alerts').click(); await wait(700);` },
    { name: '52-alerts-support-worker-read-only', hash: R('tab=allergies&view=alerts') },
    { name: '53-interactions', hash: R('tab=allergies&view=interactions'), core: true },
    // Clinical
    { name: '60-inr', hash: R('tab=clinical'), core: true },
    { name: '61-inr-test-overdue', hash: R('tab=clinical&state=inrStale'), core: true },
    { name: '62-inr-saved-without-medicine-nf23', hash: R('as=lead&tab=clinical&state=inrUnlinked'), steps: `window.scrollTo(0, 300); await wait(300);`, core: true },
    { name: '63-record-inr', hash: R('as=lead&tab=clinical&dlg=inr:new'), core: true },
    { name: '64-record-inr-out-of-range', hash: R('as=lead&tab=clinical&dlg=inr:new'), steps: `await set('#inr-value','3.6'); await scrollBody(260);` },
    { name: '65-record-inr-validation', hash: R('as=lead&tab=clinical&dlg=inr:new'), steps: `[...document.querySelectorAll('[role=dialog] button')].find(b=>b.textContent.trim()==='Save INR result').click(); await wait(400);` },
    { name: '66-inr-entered-in-error', hash: R('as=lead&tab=clinical&dlg=inr-error:i4') },
    { name: '67-syringe-driver-none', hash: R('as=lead&tab=clinical&view=driver') },
    { name: '68-syringe-driver-running', hash: P(204, 'as=lead&tab=clinical&view=driver'), core: true },
    { name: '69-syringe-driver-check', hash: P(204, 'as=lead&tab=clinical&view=driver&dlg=check'), steps: `document.querySelector('[data-tile="no"]').click(); await wait(300);` },
    { name: '70-syringe-driver-start', hash: R('as=lead&tab=clinical&view=driver&dlg=driver:new') },
    { name: '71-syringe-driver-concealed', hash: P(204, 'as=clinical&tab=clinical&view=driver'), core: true },
    { name: '72-observations', hash: R('tab=clinical&view=observations') },
    // History
    { name: '80-history-doses', hash: R('tab=history'), core: true },
    { name: '81-history-page-2', hash: R('tab=history&page=2') },
    { name: '82-dose-with-correction-chain', hash: R('tab=history&dlg=dose:h-2026-09-23-1'), core: true },
    { name: '83-request-correction', hash: R('tab=history&dlg=correct:h-2026-09-26-0'), core: true },
    { name: '84-request-correction-review', hash: R('tab=history&dlg=correct:h-2026-09-26-0'), steps: `await tile('refused'); await cont(); await set('#c-reason','Recorded against the wrong dose time'); await cont();` },
    { name: '85-request-correction-sent', hash: R('tab=history&dlg=correct:h-2026-09-26-0'), steps: `await tile('refused'); await cont(); await set('#c-reason','Recorded against the wrong dose time'); await cont(); fbtn('Send for approval').click(); await wait(500);` },
    { name: '86-corrections-lead', hash: R('as=lead&tab=history&view=corrections'), core: true },
    { name: '87-review-a-correction', hash: R('as=lead&tab=history&view=corrections&dlg=correction:h-2026-09-27-3'), core: true },
    { name: '88-approve-correction-confirm', hash: R('as=lead&tab=history&view=corrections&dlg=correction:h-2026-09-27-3'), steps: `[...document.querySelectorAll('[role=dialog] button')].find(b=>b.textContent.trim()==='Approve correction').click(); await wait(500);` },
    { name: '89-own-correction-two-person-rule', hash: R('tab=history&dlg=correct:h-2026-09-26-0'), steps: `await tile('refused'); await cont(); await set('#c-reason','Recorded against the wrong dose time'); await cont(); fbtn('Send for approval').click(); await wait(500); location.hash = '#/emar/mar?client_id=201&tab=history&view=corrections&dlg=correction:h-2026-09-26-0'; await wait(700);` },
    { name: '90-all-changes', hash: R('as=lead&tab=history&view=changes'), core: true },
    { name: '91-a-change', hash: R('as=lead&tab=history&view=changes&dlg=event:e8') },
    // Personas and controlled-medicine concealment
    { name: '100-clinical-lead-chart-concealed', hash: R('as=clinical'), steps: `window.scrollTo(0, 480); await wait(300);`, core: true },
    { name: '101-clinical-lead-medicines-concealed', hash: R('as=clinical&tab=medicines'), core: true },
    { name: '102-clinical-lead-history-concealed', hash: R('as=clinical&tab=history&range=7d&out=all'), steps: `window.scrollTo(0, 300); await wait(300);` },
    { name: '103-auditor-all-changes', hash: R('as=auditor&tab=history&view=changes'), core: true },
    { name: '104-auditor-chart-read-only', hash: R('as=auditor') },
    { name: '105-print-mar-controlled-left-out', hash: R('as=clinical&dlg=print') },
    // Page states
    { name: '110-loading', hash: R('state=loading'), core: true },
    { name: '111-no-medicines', hash: R('as=lead&state=empty'), core: true },
    { name: '112-couldnt-load', hash: R('state=unavailable'), core: true },
    { name: '113-out-of-date', hash: R('state=stale'), core: true },
    { name: '114-no-access', hash: R('as=hr'), core: true },
    { name: '115-not-found', hash: P(999), core: true },
    { name: '116-moved-old-house-not-found', hash: P(207), core: true },
    { name: '117-moved-new-house', hash: P(207, 'as=rimu&tab=history'), core: true },
    { name: '118-find-in-record', hash: R(), steps: `[...document.querySelectorAll('header button')].find(b=>b.textContent.includes('Find in this record')).click(); await wait(500);` },
    // Client profile
    { name: '120-client-profile-mar-tab', hash: CP(201, 'tab=mar'), core: true },
    { name: '121-client-profile-mar-tab-scrolled', hash: CP(201, 'tab=mar'), steps: `window.scrollTo(0, 560); await wait(300);`, core: true },
    { name: '122-client-profile-mar-concealed', hash: CP(201, 'tab=mar&as=clinical'), steps: `window.scrollTo(0, 560); await wait(300);` },
    { name: '123-client-profile-mar-none-recorded', hash: CP(202, 'tab=mar'), steps: `window.scrollTo(0, 420); await wait(300);` },
    { name: '124-client-profile-mar-couldnt-load', hash: CP(201, 'tab=mar&state=unavailable') },
    { name: '125-client-profile-record-dose', hash: CP(201, 'tab=mar&open=dose-pick:aroha') },
    { name: '126-health-profile-allergy-card', hash: CP(201, 'tab=medical&as=lead'), steps: `window.scrollTo(0, 420); await wait(300);`, core: true },
    { name: '127-review-allergies', hash: CP(203, 'tab=medical&as=lead&dlg=allergy-review'), core: true },
    { name: '128-review-allergies-no-known', hash: CP(202, 'tab=medical&as=lead&dlg=allergy-review'), steps: `document.querySelector('#ar-none').click(); await wait(300);` },
    { name: '130-contract-page', hash: '#/p02/contract', core: true },
];

/* ───────────── CDP plumbing ───────────── */
const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', '--force-color-profile=srgb', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let ws;
for (let i = 0; i < 40; i++) {
    try {
        const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
        const page = list.find((t) => t.type === 'page');
        if (page) {
            ws = new WebSocket(page.webSocketDebuggerUrl);
            break;
        }
    } catch {}
    await sleep(250);
}
if (!ws) throw new Error('Chrome did not start');
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let seq = 0;
const pending = new Map();
const errors = [];
ws.addEventListener('message', (m) => {
    const msg = JSON.parse(m.data);
    if (msg.id && pending.has(msg.id)) {
        pending.get(msg.id)(msg);
        pending.delete(msg.id);
    } else if (msg.method === 'Runtime.exceptionThrown') errors.push(msg.params.exceptionDetails?.exception?.description ?? msg.params.exceptionDetails?.text);
    else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') errors.push(msg.params.args.map((a) => a.value ?? a.description).join(' '));
    else if (msg.method === 'Log.entryAdded' && msg.params.entry.level === 'error') errors.push(msg.params.entry.text + ' ' + (msg.params.entry.url ?? ''));
});
const send = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++seq;
        pending.set(id, (msg) => (msg.error ? reject(new Error(`${method}: ${msg.error.message}`)) : resolve(msg.result)));
        ws.send(JSON.stringify({ id, method, params }));
    });
await send('Page.enable');
await send('Runtime.enable');
await send('Log.enable');
const evaluate = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: `(async () => { ${H} ${expr} })()`, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? r.exceptionDetails.text);
    return r.result.value;
};
let loadN = 0;
async function load(hash) {
    const done = new Promise((resolve) => {
        const h = (m) => {
            const msg = JSON.parse(m.data);
            if (msg.method === 'Page.loadEventFired') {
                ws.removeEventListener('message', h);
                resolve();
            }
        };
        ws.addEventListener('message', h);
    });
    await send('Page.navigate', { url: `${BASE}?load=${++loadN}${hash}` });
    await done;
    await sleep(700);
}

const report = { base: BASE, when: new Date().toISOString(), shots: [], keyboard: null };
const core = process.argv.includes('--core');
for (const shot of SHOTS.filter((s) => !only || s.name.includes(only))) {
    for (const size of SIZES) {
        if (size.key !== '1440' && !shot.core) continue;
        if (core && !shot.core) continue;
        await send('Emulation.setDeviceMetricsOverride', { width: size.width, height: size.height, deviceScaleFactor: size.dsf, mobile: false });
        const before = errors.length;
        let stepError = null;
        try {
            await load(shot.hash);
            if (shot.steps) await evaluate(shot.steps);
            if (shot.after) await sleep(shot.after);
            if (shot.steps2) await evaluate(shot.steps2);
            await sleep(250);
        } catch (e) {
            stepError = String(e.message ?? e).slice(0, 300);
        }
        const overflow = await evaluate(`return document.documentElement.scrollWidth - document.documentElement.clientWidth;`);
        const header = await evaluate(`const p = document.querySelector('header.eh-header p'); if (!p) return null; const lh = parseFloat(getComputedStyle(p).lineHeight) || 16; return { sublineLines: Math.round(p.getBoundingClientRect().height / lh), truncatedCaptions: [...document.querySelectorAll('.eh-meter span.truncate')].filter((c) => c.scrollWidth > c.clientWidth).map((c) => c.textContent) };`);
        const shotPng = await send('Page.captureScreenshot', { format: 'png' });
        const file = `${size.key}-${shot.name}.png`;
        writeFileSync(path.join(outDir, file), Buffer.from(shotPng.data, 'base64'));
        const row = { shot: shot.name, size: size.key, file, overflow, header, errors: errors.slice(before), stepError };
        report.shots.push(row);
        process.stdout.write(`${row.stepError || row.errors.length || row.overflow > 0 ? '✗' : '✓'} ${size.key} ${shot.name}${overflow > 0 ? ` overflow=${overflow}` : ''}${row.errors.length ? ` errors=${row.errors.length}` : ''}${stepError ? ` step: ${stepError}` : ''}\n`);
    }
}

/* ───────────── keyboard walk (real key events) ───────────── */
if (!only || only === 'keyboard') {
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await load('#/emar/mar?client_id=201');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('data-cell') || a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Focus the losartan 9:00 am cell, open it with Enter (P01's dialog), Tab through, Escape, check focus returns.
    await evaluate(`document.querySelector('[data-cell="losartan-9:00 am"]').focus();`);
    const trail = [await who()];
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: String.fromCharCode(13), unmodifiedText: String.fromCharCode(13) });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await sleep(600);
    const dialogTitle = await evaluate(`const d=document.querySelector('[role=dialog]'); return d ? (d.querySelector('[data-wizard-region=rail]')?.textContent || d.textContent).trim().replace(/\\s+/g,' ').slice(0,80) : null;`);
    for (let i = 0; i < 12; i++) {
        await key('Tab', 'Tab', 9);
        trail.push(await who());
    }
    await key('Escape', 'Escape', 27);
    await sleep(500);
    const afterEscape = await who();
    const dialogOpen = await evaluate(`return !!document.querySelector('[role=dialog]');`);
    // Shift+F10 / the menu key on a focused cell opens the same actions menu as right-click.
    await evaluate(`document.querySelector('[data-cell="insulin-9:00 am"]').focus();`);
    await key('F10', 'F10', 121, 8);
    await sleep(400);
    let via = 'Shift+F10';
    if (!(await evaluate(`return !!document.querySelector('[role=menu]');`))) {
        via = 'ContextMenu key';
        await key('ContextMenu', 'ContextMenu', 93);
        await sleep(400);
    }
    const menu = await evaluate(`const m=document.querySelector('[role=menu]'); return m ? [...m.querySelectorAll('[role=menuitem]')].map(x=>x.textContent.trim()) : null;`);
    report.keyboard = { openedFrom: trail[0], dialog: dialogTitle, tabOrder: trail.slice(1), afterEscape, dialogStillOpen: dialogOpen, keyboardMenu: menu, keyboardMenuVia: via };
    process.stdout.write(`keyboard: dialog = ${dialogTitle}; after Escape focus = ${afterEscape}; ${via} menu = ${JSON.stringify(menu)}\n`);
}

writeFileSync(path.join(outDir, only ? `report-${only}.json` : 'report.json'), JSON.stringify(report, null, 2));
const bad = report.shots.filter((r) => r.stepError || r.errors.length || r.overflow > 0);
process.stdout.write(`\n${report.shots.length} captures · ${bad.length} with problems\n`);
ws.close();
chrome.kill();
