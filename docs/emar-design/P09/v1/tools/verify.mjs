// P09 v1 verification harness — P08b v1.1's harness (itself P07b's / P06's / P04's / P03's / P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4394). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the export dialog. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P09/v1/tools/verify.mjs [--only=substring,substring,…] [--core]
// A partial run (--only) replaces just its captures in report.json and notes the re-run there.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P09_URL ?? 'http://127.0.0.1:4394/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9364;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const onlyList = only ? only.split(',').map((x) => x.trim()).filter(Boolean) : null;
const picked = (name) => !onlyList || onlyList.some((x) => name.includes(x));
const profile = process.env.P09_PROFILE ?? path.join(here, '..', '.chrome-profile');

const SIZES = [
    { key: '1440', width: 1440, height: 900, dsf: 1 },
    { key: '1280', width: 1280, height: 800, dsf: 1 },
    { key: 'zoom200', width: 720, height: 450, dsf: 2 },
];

const H = `
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const vis = (e) => e.offsetParent !== null || e.getClientRects().length > 0;
const click = async (t, root = document) => { const b = [...root.querySelectorAll('button,a,[role=menuitem],[role=option],[cmdk-item]')].find((e) => vis(e) && e.textContent.trim().replace(/\\s+/g,' ').startsWith(t)); if (!b) throw new Error('No control: ' + t); b.click(); await wait(350); };
const tile = async (k) => { const t = [...document.querySelectorAll('[data-tile="' + k + '"]')].find(vis); if (!t) throw new Error('No tile: ' + k); t.click(); await wait(300); };
const el = (s) => document.querySelector(s);
const set = async (s, v) => { const e = el(s); if (!e) throw new Error('No field: ' + s); const proto = e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); await wait(150); };
const pick = async (trigger, option) => { el(trigger).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' })); await wait(400); const o = [...document.querySelectorAll('[role=option]')].find((e) => e.textContent.trim().startsWith(option)); if (!o) throw new Error('No option: ' + option); o.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerType: 'mouse' })); o.click(); await wait(350); };
const fbtn = (t) => { for (const sel of ['[data-wizard-region=footer] button', '[role=alertdialog] button', '[role=dialog] button']) { const b = [...document.querySelectorAll(sel)].find((e) => vis(e) && e.getAttribute('role') !== 'combobox' && e.textContent.trim().startsWith(t)); if (b) return b; } throw new Error('No footer button: ' + t); };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const tick = async (id) => { const c = el('#' + id); if (!c) throw new Error('No checkbox: ' + id); c.click(); await wait(200); };
const rowMenu = async (text, y = 520) => { const r = [...document.querySelectorAll('[role=row]')].find((x) => x.textContent.includes(text)); if (!r) throw new Error('No row: ' + text); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 760, clientY: y })); await wait(400); };
const pickDate = async (label, d, month, year = 2026) => { const t = [...document.querySelectorAll('button[aria-label^="' + label + ':"]')].find(vis); if (!t) throw new Error('No date field: ' + label); t.click(); await wait(450); for (let i = 0; i < 14; i++) { const b = [...document.querySelectorAll('button[aria-pressed]')].find((x) => vis(x) && (x.getAttribute('aria-label') || '').includes(' ' + d + ' ' + month + ' ' + year)); if (b) { b.click(); await wait(200); break; } const nx = [...document.querySelectorAll('button[aria-label="Next month"]')].find(vis); if (!nx) throw new Error('No next month'); nx.click(); await wait(200); } const u = [...document.querySelectorAll('button')].find((x) => vis(x) && x.textContent.trim().startsWith('Use date')); if (!u) throw new Error('No Use date'); u.click(); await wait(300); };
const pickTime = async (label, h, m, ap) => { const t = [...document.querySelectorAll('button[aria-label^="' + label + ':"]')].find(vis); if (!t) throw new Error('No time field: ' + label); t.click(); await wait(450); const hh = [...document.querySelectorAll('input[aria-label="' + label + ' hour"]')].find(vis); const mm = [...document.querySelectorAll('input[aria-label="' + label + ' minute"]')].find(vis); if (!hh || !mm) throw new Error('No time inputs: ' + label); const sv = (e, v) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); }; sv(hh, h); await wait(120); sv(mm, m); await wait(120); const p = [...document.querySelectorAll('[aria-label="' + label + ' AM or PM"] button')].find((b) => b.textContent.trim() === ap); if (p) { p.click(); await wait(150); } const u = [...document.querySelectorAll('button')].find((x) => vis(x) && x.textContent.trim().startsWith('Use time')); if (!u) throw new Error('No Use time'); u.click(); await wait(300); };
const go = async (h) => { location.hash = h; await wait(700); };
`;
const R = '#/emar/reports';
const V = (q) => `${R}?${q}`;
const B = (q) => `#/emar/reports/builder?${q}`;
const F = (q) => `#/emar/errors?${q}`;
const S = (q) => `#/emar/settings?view=rules&sec=records&${q}`;
const closeFill = `await set('#cs-note','Looked into it: the pharmacy label and the round list are clearer now. The person and whānau were told.');`;
const runBuilder = `await set('#preview-purpose','Monthly medication review for the quality meeting'); await click('Run preview'); await wait(2200);`;

const SHOTS = [
    // Standard reports
    { name: '01-doses-house-lead', hash: V('as=lead'), core: true },
    { name: '02-doses-row-menu', hash: V('as=lead'), steps: `await rowMenu('Aroha Mere Ngata');`, core: true },
    { name: '03-doses-manager-two-houses', hash: V('as=pm'), core: true },
    { name: '04-doses-rimu-not-applicable', hash: V('as=rimu'), core: true },
    { name: '05-doses-clinical', hash: V('as=clinical') },
    { name: '06-support-worker-no-access', hash: R, core: true },
    { name: '07-doses-today', hash: V('as=lead&period=today'), core: true },
    { name: '08-rounds', hash: V('as=lead&sub=rounds'), core: true },
    { name: '09-rounds-today-not-applicable', hash: V('as=lead&sub=rounds&period=today'), core: true },
    { name: '10-as-needed', hash: V('as=pm&sub=prn') },
    { name: '11-controlled', hash: V('as=pm&sub=controlled'), core: true },
    { name: '12-controlled-clinical-locked', hash: V('as=clinical&sub=controlled'), core: true },
    { name: '13-errors', hash: V('as=pm&sub=errors'), core: true },
    { name: '14-errors-clinical-redacted', hash: V('as=clinical&sub=errors') },
    { name: '15-reviews', hash: V('as=lead&sub=reviews') },
    { name: '16-stock', hash: V('as=pm&sub=stock'), core: true },
    { name: '17-stock-finance', hash: V('as=finance'), core: true },
    { name: '18-stock-clinical-no-controlled', hash: V('as=clinical&sub=stock') },
    { name: '19-competency', hash: V('as=pm&sub=competency') },
    { name: '20-custom-period', hash: V('as=lead&open=range'), core: true },
    { name: '21-custom-period-validation', hash: V('as=lead&open=range'), steps: `await pickDate('First day', 28, 'September'); await pickDate('Last day', 1, 'September'); await click('Use these dates', document.querySelector('[role=dialog]'));` },
    { name: '22-last-month', hash: V('as=lead&period=last') },
    { name: '23-person-filter', hash: V('as=pm&person=aroha') },
    { name: '24-errors-sac-column', hash: V('as=pm&sub=errors&sac=on') },
    // Audit trail
    { name: '30-audit-events', hash: V('as=clinical&view=audit'), core: true },
    { name: '31-audit-page-2', hash: V('as=clinical&view=audit&pg=2'), core: true },
    { name: '32-audit-controlled-redacted', hash: V('as=clinical&view=audit&kind=controlled'), core: true },
    { name: '33-audit-controlled-manager', hash: V('as=pm&view=audit&kind=controlled') },
    { name: '34-audit-event', hash: V('as=pm&view=audit&open=event:E-o-metformin-2026-09-25-08:00'), core: true },
    { name: '35-audit-verify', hash: V('as=pm&view=audit&open=verify'), core: true },
    { name: '36-audit-unrecorded-doses', hash: V('as=pm&view=audit&sub=gaps'), core: true },
    { name: '37-audit-exports-made', hash: V('as=pm&view=audit&sub=exports') },
    { name: '38-audit-house-lead-locked', hash: V('as=lead&view=audit'), core: true },
    { name: '39-audit-row-menu', hash: V('as=pm&view=audit'), steps: `await rowMenu('Dose', 420);` },
    { name: '40-audit-event-log-down', hash: V('as=pm&view=audit&scn=logdown'), core: true },
    { name: '41-audit-last-month', hash: V('as=clinical&view=audit&period=last') },
    // Print & exports
    { name: '50-exports-manager', hash: V('as=pm&view=exports'), core: true },
    { name: '51-exports-house-lead-denied', hash: V('as=lead&view=exports'), core: true },
    { name: '52-exports-finance', hash: V('as=finance&view=exports'), core: true },
    { name: '53-exports-auditor', hash: V('as=auditor&view=exports'), core: true },
    { name: '54-export-mar', hash: V('as=pm&view=exports&open=export:mar:aroha'), core: true },
    { name: '55-export-validation', hash: V('as=pm&view=exports&open=export:doses'), steps: `fbtn('Make the file').click(); await wait(300);` },
    { name: '56-export-purpose', hash: V('as=pm&view=exports&open=export:doses'), steps: `await tile('audit'); await set('#ex-detail','Certification audit on 6 October');`, core: true },
    { name: '57-export-made-in-audit', hash: V('as=pm&view=exports&open=export:doses'), steps: `await tile('audit'); fbtn('Make the file').click(); await wait(500); await go('#/emar/reports?as=pm&view=audit&sub=exports');`, core: true },
    { name: '58-export-denied-link', hash: V('as=lead&view=exports&open=export:mar'), core: true },
    { name: '59-export-about', hash: V('as=lead&view=exports&open=about:mar') },
    { name: '60-export-event-log-down', hash: V('as=pm&view=exports&scn=logdown&open=export:stock'), steps: `fbtn('Make the file').click(); await wait(300);`, core: true },
    { name: '61-export-offline', hash: V('as=pm&view=exports&scn=offline&open=export:stock'), steps: `fbtn('Make the file').click(); await wait(300);` },
    { name: '62-export-cd-register', hash: V('as=pm&view=exports&open=export:cdreg:o-clonazepam') },
    // Report builder (the real workspace)
    { name: '70-builder', hash: B('as=pm'), after: 800, core: true },
    { name: '71-builder-run', hash: B('as=pm'), after: 800, steps: runBuilder, core: true },
    { name: '72-builder-clinical', hash: B('as=clinical'), after: 800 },
    { name: '73-builder-support-worker', hash: B(''), core: true },
    // Closing with SAC — P09’s addition to P08b
    { name: '80-errors-frame-sac-off', hash: F('as=pm'), core: true },
    { name: '81-close-sac-off', hash: F('as=pm&open=close:MED-0044') },
    { name: '82-close-sac-preselected', hash: F('as=pm&sac=on&open=close:MED-0044'), core: true },
    { name: '83-close-sac-severe', hash: F('as=rimu&sac=on&open=close:MED-0049'), core: true },
    { name: '84-close-sac-severe-validation', hash: F('as=rimu&sac=on&open=close:MED-0049'), steps: `${closeFill} fbtn('Close the error').click(); await wait(300);`, core: true },
    { name: '85-close-sac-confirm', hash: F('as=pm&sac=on&open=close:MED-0044'), steps: `${closeFill} fbtn('Close the error').click(); await wait(400);` },
    { name: '86-close-sac-in-report', hash: F('as=pm&sac=on&open=close:MED-0044'), steps: `${closeFill} fbtn('Close the error').click(); await wait(400); fbtn('Close it').click(); await wait(500); await go('#/emar/reports?as=pm&sub=errors&sac=on');`, core: true },
    { name: '87-close-event-log-down', hash: F('as=pm&sac=on&scn=logdown&open=close:MED-0044'), steps: `${closeFill} fbtn('Close the error').click(); await wait(400); fbtn('Close it').click(); await wait(400);`, core: true },
    // Settings — the additions to P11
    { name: '90-settings-records', hash: S('as=clinical'), core: true },
    { name: '91-settings-house-lead-read-only', hash: S('as=lead'), core: true },
    { name: '92-settings-sac-on', hash: S('as=clinical'), steps: `el('#rr-sac').click(); await wait(300);` },
    { name: '93-settings-review-changes', hash: S('as=clinical'), steps: `el('#rr-sac').click(); await wait(300); await click('15 years'); await click('Review changes');`, core: true },
    { name: '94-settings-saved', hash: S('as=clinical'), steps: `el('#rr-sac').click(); await wait(300); await click('Review changes'); fbtn('Save changes').click(); await wait(500);` },
    { name: '95-settings-keep-default', hash: S('as=clinical&open=p11review:keep-retention') },
    { name: '96-settings-history', hash: '#/emar/settings?view=history&sec=changes&as=clinical' },
    { name: '97-settings-then-close', hash: S('as=clinical'), steps: `el('#rr-sac').click(); await wait(300); await click('Review changes'); fbtn('Save changes').click(); await wait(500); await go('#/emar/errors?as=clinical&open=close:MED-0049');`, core: true },
    // States
    { name: '100-loading', hash: V('as=lead&scn=loading') },
    { name: '101-empty', hash: V('as=lead&scn=empty'), core: true },
    { name: '102-couldnt-load', hash: V('as=lead&scn=unavailable'), core: true },
    { name: '103-out-of-date', hash: V('as=lead&scn=stale') },
    { name: '104-offline-banner', hash: V('as=lead&scn=offline') },
    { name: '105-not-found-event', hash: V('as=pm&view=audit&open=event:E-nothing-here') },
    { name: '110-contract-page', hash: '#/p09/contract', core: true },
];

/* ───────────── CDP plumbing (P01 v1, unchanged) ───────────── */
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
    // A stopped preview server shows Chrome's error page, which would otherwise pass as a clean capture.
    if (!(await evaluate(`return !!document.querySelector('#root') && document.querySelector('#root').childElementCount > 0;`))) throw new Error(`The preview didn’t render at ${BASE} — is serve.mjs running?`);
}

const report = { base: BASE, when: new Date().toISOString(), shots: [], keyboard: null };
const core = process.argv.includes('--core');
for (const shot of SHOTS.filter((s) => picked(s.name))) {
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
        const header = await evaluate(`const p = document.querySelector('header.eh-header p'); if (!p) return null; const lh = parseFloat(getComputedStyle(p).lineHeight) || 16; return { sublineLines: Math.round(p.getBoundingClientRect().height / lh), truncatedCaptions: [...document.querySelectorAll('.eh-meter span.truncate')].filter((c) => c.scrollWidth > c.clientWidth).map((c) => c.textContent), truncatedCells: [...document.querySelectorAll('[role=row] .truncate, [role=row] [class*=truncate]')].filter((c) => c.offsetParent !== null && c.scrollWidth > c.clientWidth + 1).map((c) => c.textContent.trim().slice(0, 60)) };`);
        const shotPng = await send('Page.captureScreenshot', { format: 'png' });
        const file = `${size.key}-${shot.name}.png`;
        writeFileSync(path.join(outDir, file), Buffer.from(shotPng.data, 'base64'));
        const row = { shot: shot.name, size: size.key, file, overflow, header, errors: errors.slice(before), stepError };
        report.shots.push(row);
        process.stdout.write(`${row.stepError || row.errors.length || row.overflow > 0 ? '✗' : '✓'} ${size.key} ${shot.name}${overflow > 0 ? ` overflow=${overflow}` : ''}${row.errors.length ? ` errors=${row.errors.length}` : ''}${stepError ? ` step: ${stepError}` : ''}${header && (header.sublineLines > 1 || header.truncatedCaptions.length || header.truncatedCells.length) ? ` header=${JSON.stringify(header)}` : ''}\n`);
    }
}

/* ───────────── keyboard walk (real key events) ───────────── */
if (!onlyList || onlyList.includes('keyboard')) {
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await load('#/emar/reports?as=pm&view=exports');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Rangi focuses “Make it” on the Doses file, opens it with Enter, Tabs through the dialog, Escape closes it, focus returns.
    await evaluate(`document.querySelector('[data-return="ex-doses"]').focus();`);
    const trail = [await who()];
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: String.fromCharCode(13), unmodifiedText: String.fromCharCode(13) });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await sleep(600);
    for (let i = 0; i < 10; i++) {
        await key('Tab', 'Tab', 9);
        trail.push(await who());
    }
    await key('Escape', 'Escape', 27);
    await sleep(500);
    const afterEscape = await who();
    const dialogOpen = await evaluate(`return !!document.querySelector('[role=dialog]');`);
    // The menu key on a focused report row opens the same menu as ⋯ and right-click.
    await load('#/emar/reports?as=pm');
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Aroha Mere Ngata')).focus();`);
    await key('F10', 'F10', 121, 8);
    await sleep(400);
    let via = 'Shift+F10';
    if (!(await evaluate(`return !!document.querySelector('[role=menu]');`))) {
        via = 'ContextMenu key';
        await key('ContextMenu', 'ContextMenu', 93);
        await sleep(400);
    }
    const menu = await evaluate(`const m=document.querySelector('[role=menu]'); return m ? [...m.querySelectorAll('[role=menuitem]')].map(x=>x.textContent.trim()) : null;`);
    report.keyboard = { openedFrom: trail[0], tabOrder: trail.slice(1), afterEscape, dialogStillOpen: dialogOpen, keyboardMenu: menu, keyboardMenuVia: via };
    process.stdout.write(`keyboard: after Escape focus = ${afterEscape}; ${via} menu = ${JSON.stringify(menu)}\n`);
}

const reportPath = path.join(outDir, 'report.json');
const ran = report.shots;
if (onlyList && existsSync(reportPath)) {
    const full = JSON.parse(readFileSync(reportPath, 'utf8'));
    const key = (r) => `${r.size} ${r.shot}`;
    const fresh = new Set(ran.map(key));
    full.shots = [...full.shots.filter((r) => !fresh.has(key(r))), ...ran];
    if (report.keyboard) full.keyboard = report.keyboard;
    full.reruns = [...(full.reruns ?? []), { when: report.when, only: onlyList, captures: ran.length }];
    writeFileSync(reportPath, JSON.stringify(full, null, 2));
} else writeFileSync(reportPath, JSON.stringify(report, null, 2));
const bad = ran.filter((r) => r.stepError || r.errors.length || r.overflow > 0);
process.stdout.write(`\n${ran.length} captures · ${bad.length} with problems\n`);
ws.close();
chrome.kill();
