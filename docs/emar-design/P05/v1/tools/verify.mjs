// P05 v1 verification harness — P07b v1.1's harness (itself P06's / P04's / P03's / P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4392). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the record-the-outcome wizard. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P05/v1/tools/verify.mjs [--only=substring,substring,…] [--core]
// A partial run (--only) replaces just its captures in report.json and notes the re-run there.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P05_URL ?? 'http://127.0.0.1:4392/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9362;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const onlyList = only ? only.split(',').map((x) => x.trim()).filter(Boolean) : null;
const picked = (name) => !onlyList || onlyList.some((x) => name.includes(x));
const profile = process.env.P05_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const openSelect = async (trigger) => { el(trigger).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' })); await wait(450); };
const fbtn = (t) => { for (const sel of ['[data-wizard-region=footer] button', '[role=alertdialog] button', '[role=dialog] button']) { const b = [...document.querySelectorAll(sel)].find((e) => vis(e) && e.getAttribute('role') !== 'combobox' && e.textContent.trim().startsWith(t)); if (b) return b; } throw new Error('No footer button: ' + t); };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const section = async (label) => { document.querySelector('[aria-label="' + label + '"]')?.scrollIntoView({ block: 'start' }); await wait(300); };
const check = async (text) => { const l = [...document.querySelectorAll('[role=dialog] label')].find((x) => x.textContent.trim().startsWith(text)); if (!l) throw new Error('No checkbox: ' + text); l.querySelector('button[role=checkbox]').click(); await wait(150); };
const rowMenu = async (text, y = 520) => { const r = [...document.querySelectorAll('[role=row]')].find((x) => x.textContent.includes(text)); if (!r) throw new Error('No row: ' + text); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 760, clientY: y })); await wait(400); };
const pickDate = async (label, d, month, year = 2026) => { const t = [...document.querySelectorAll('button[aria-label^="' + label + ':"]')].find(vis); if (!t) throw new Error('No date field: ' + label); t.click(); await wait(450); for (let i = 0; i < 14; i++) { const b = [...document.querySelectorAll('button[aria-pressed]')].find((x) => vis(x) && (x.getAttribute('aria-label') || '').includes(' ' + d + ' ' + month + ' ' + year)); if (b) { b.click(); await wait(200); break; } const nx = [...document.querySelectorAll('button[aria-label="Next month"]')].find(vis); if (!nx) throw new Error('No next month'); nx.click(); await wait(200); } const u = [...document.querySelectorAll('button')].find((x) => vis(x) && x.textContent.trim().startsWith('Use date')); if (!u) throw new Error('No Use date'); u.click(); await wait(300); };
`;
const R = '#/emar/reviews';
const V = (q) => `${R}?${q}`;
const G = (q = '') => `#/emar/mar?client_id=204&tab=clinical&view=reviews${q ? `&${q}` : ''}`;
const recTo = (n) => [
    `await cont();`,
    `await tile('yes'); await tile('told'); await set('#rc-wwho','Salote Fifita — sister, by phone'); await cont();`,
    `await pick('#rc-out-o-omeprazole','Continue'); await pick('#rc-out-o-amoxicillin','Continue'); await pick('#rc-out-o-paracetamol-mele','Stop'); await set('#rc-what-o-paracetamol-mele','Stop — use it only after checking with the GP'); await cont();`,
    `await set('#rc-summary','Reviewed after the fall. No medicine is likely to have caused it. Paracetamol to stop unless the GP says otherwise. (Synthetic.)'); await cont();`,
    `await cont();`,
].slice(0, n).join(' ');

const SHOTS = [
    // Orders & reviews › Medication reviews
    { name: '01-reviews-house-lead', hash: V('as=lead'), core: true },
    { name: '02-reviews-row-menu', hash: V('as=lead'), steps: `await rowMenu('Samuel Tuilagi');`, core: true },
    { name: '03-reviews-manager-two-houses', hash: V('as=pm'), core: true },
    { name: '04-reviews-support-worker', hash: R, core: true },
    { name: '05-reviews-clinical-lead', hash: V('as=clinical') },
    { name: '06-reviews-auditor', hash: V('as=auditor') },
    { name: '07-reviews-rimu', hash: V('as=rimu') },
    { name: '08-changes', hash: V('as=lead&sub=changes'), core: true },
    { name: '09-changes-clinical-redacted', hash: V('as=clinical&sub=changes'), core: true },
    { name: '10-booked', hash: V('as=lead&sub=booked') },
    { name: '11-recorded', hash: V('as=lead&sub=recorded'), core: true },
    { name: '12-cancelled-closed', hash: V('as=lead&sub=closed'), core: true },
    { name: '13-changes-row-menu', hash: V('as=lead&sub=changes'), steps: `await rowMenu('Sertraline');` },
    // The person record › Clinical › Medication reviews
    { name: '15-record-grace-lead', hash: G('as=lead'), core: true },
    { name: '16-record-grace-support-worker', hash: G(), core: true },
    { name: '17-record-grace-clinical-redacted', hash: G('as=clinical'), core: true },
    { name: '18-record-ben-own-interval', hash: '#/emar/mar?client_id=207&tab=clinical&view=reviews&as=rimu' },
    { name: '19-record-sam-away', hash: '#/emar/mar?client_id=205&tab=clinical&view=reviews&as=lead' },
    { name: '20-record-wiremu-left', hash: '#/emar/mar?client_id=208&tab=clinical&view=reviews&as=lead' },
    // The review (viewer)
    { name: '22-review-grace', hash: V('as=lead&open=review:R-28'), core: true },
    { name: '23-review-grace-medicines', hash: V('as=lead&open=review:R-28'), steps: `await click('Medicines');` },
    { name: '24-review-grace-summary-lead', hash: V('as=lead&open=review:R-28'), steps: `await click('Summary & letter');` },
    { name: '25-review-grace-summary-support-worker', hash: V('open=review:R-28'), steps: `await click('Summary & letter');`, core: true },
    { name: '26-review-grace-history', hash: V('as=lead&open=review:R-28'), steps: `await click('History');` },
    { name: '27-review-sam-moved', hash: V('as=lead&open=review:R-24') },
    // Book a review
    { name: '30-book-who', hash: V('as=lead&open=book'), core: true },
    { name: '31-book-validation', hash: V('as=lead&open=book'), steps: `await cont();` },
    { name: '32-book-regular-already-booked', hash: V('as=lead&open=book'), steps: `await pick('#bk-person','Mele Fifita'); await tile('regular'); await cont();`, core: true },
    { name: '33-book-triggered', hash: V('as=lead&open=book'), steps: `await pick('#bk-person','Mele Fifita'); await tile('triggered'); await pick('[aria-labelledby=bk-trigger-l]','The person, whānau or GP asked'); await set('#bk-note','Mele asked about her reflux medicine again.');` },
    { name: '34-book-when', hash: V('as=lead&open=book'), steps: `await pick('#bk-person','Mele Fifita'); await tile('triggered'); await pick('[aria-labelledby=bk-trigger-l]','The person, whānau or GP asked'); await cont(); await pickDate('Due by', 9, 'October'); await pick('#bk-with','Sarah Wong');`, core: true },
    { name: '35-book-review', hash: V('as=lead&open=book'), steps: `await pick('#bk-person','Mele Fifita'); await tile('triggered'); await pick('[aria-labelledby=bk-trigger-l]','The person, whānau or GP asked'); await cont(); await pickDate('Due by', 9, 'October'); await cont();` },
    // Record the outcome
    { name: '40-record-who', hash: V('as=lead&open=record:R-27'), core: true },
    { name: '41-record-took', hash: V('as=lead&open=record:R-27'), steps: `${recTo(1)}` },
    { name: '42-record-took-filled', hash: V('as=lead&open=record:R-27'), steps: `${recTo(1)} await tile('yes'); await tile('told'); await set('#rc-wwho','Salote Fifita — sister, by phone');` },
    { name: '43-record-medicines', hash: V('as=lead&open=record:R-27'), steps: `${recTo(2)}`, core: true },
    { name: '44-record-medicines-validation', hash: V('as=lead&open=record:R-27'), steps: `${recTo(2)} await cont();` },
    { name: '45-record-medicines-filled', hash: V('as=lead&open=record:R-27'), steps: `${recTo(2)} await pick('#rc-out-o-omeprazole','Continue'); await pick('#rc-out-o-amoxicillin','Continue'); await pick('#rc-out-o-paracetamol-mele','Stop'); await set('#rc-what-o-paracetamol-mele','Stop — use it only after checking with the GP');` },
    { name: '46-record-summary', hash: V('as=lead&open=record:R-27'), steps: `${recTo(3)}` },
    { name: '47-record-next', hash: V('as=lead&open=record:R-27'), steps: `${recTo(4)}` },
    { name: '48-record-review', hash: V('as=lead&open=record:R-27'), steps: `${recTo(5)}`, core: true },
    { name: '49-record-saved', hash: V('as=lead&open=record:R-27'), steps: `${recTo(5)} fbtn('Record the outcome').click(); await wait(1200);` },
    { name: '50-record-clinical-redacted', hash: V('as=clinical&open=record:R-31'), steps: `await cont(); await tile('yes'); await tile('none'); await set('#rc-wwhy','Aroha asked for it to be just her.'); await cont();`, core: true },
    // A change, the prescriber’s decision, entering it in Orders
    { name: '52-change-waiting', hash: V('as=lead&sub=changes&open=change:R-28:o-clonazepam'), core: true },
    { name: '53-change-phone-rule', hash: V('as=lead&sub=changes&open=change:R-26:o-levetiracetam'), core: true },
    { name: '54-change-watch', hash: V('as=lead&sub=changes&open=change:R-28:o-levothyroxine') },
    { name: '55-change-not-agreed', hash: V('as=lead&sub=recorded&open=change:R-19:o-metformin') },
    { name: '56-decision', hash: V('as=lead&sub=changes&open=decision:R-28:o-clonazepam'), core: true },
    { name: '57-decision-validation', hash: V('as=lead&sub=changes&open=decision:R-28:o-clonazepam'), steps: `await tile('agreed'); await click('Save', document.querySelector('[role=dialog]'));` },
    { name: '58-decision-agreed-phone', hash: V('as=lead&sub=changes&open=decision:R-28:o-clonazepam'), steps: `await tile('agreed'); await tile('phone');`, core: true },
    { name: '59-enter-in-orders', hash: V('as=lead&sub=changes&open=enter:R-28:o-sertraline'), core: true },
    { name: '60-decision-clinical-cant', hash: V('as=clinical&sub=changes&open=decision:R-28:o-clonazepam') },
    // Move, cancel, appointment, how often
    { name: '62-move', hash: V('as=lead&open=move:R-24'), core: true },
    { name: '63-move-validation', hash: V('as=lead&open=move:R-24'), steps: `await click('Move it', document.querySelector('[role=dialog]'));` },
    { name: '64-cancel-regular', hash: V('as=lead&open=cancel:R-31'), core: true },
    { name: '65-cancel-triggered', hash: V('as=lead&open=cancel:R-27') },
    { name: '66-appointment', hash: V('as=lead&open=appt:R-24') },
    { name: '67-how-often', hash: G('as=lead&open=interval:grace'), core: true },
    { name: '68-how-often-own', hash: G('as=lead&open=interval:grace'), steps: `await tile('own'); await set('#iv-months','6');` },
    // Settings — the addition to P11
    { name: '70-settings-reviews', hash: '#/emar/settings?view=rounds&sec=reviews&as=clinical', core: true },
    { name: '71-settings-house-lead-read-only', hash: '#/emar/settings?view=rounds&sec=reviews&as=lead', core: true },
    { name: '72-settings-changed', hash: '#/emar/settings?view=rounds&sec=reviews&as=clinical', steps: `await set('#rv-every','6');` },
    { name: '73-settings-review-changes', hash: '#/emar/settings?view=rounds&sec=reviews&as=clinical', steps: `await set('#rv-every','6'); await click('Review changes');`, core: true },
    { name: '74-settings-history', hash: '#/emar/settings?view=history&sec=changes&as=clinical' },
    { name: '75-settings-keep-default', hash: '#/emar/settings?view=rounds&sec=reviews&as=clinical&open=p11review:keep' },
    // Client profile › Actions & Reviews (NF-05)
    { name: '80-actions-grace', hash: '#/clients/profile?client_id=204&tab=actions_reviews&as=lead', core: true },
    { name: '81-actions-sam-overdue', hash: '#/clients/profile?client_id=205&tab=actions_reviews&as=lead' },
    { name: '82-actions-grace-clinical', hash: '#/clients/profile?client_id=204&tab=actions_reviews&as=clinical' },
    // States
    { name: '90-loading', hash: V('as=lead&scn=loading') },
    { name: '91-empty', hash: V('as=lead&scn=empty'), core: true },
    { name: '92-couldnt-load', hash: V('as=lead&scn=unavailable'), core: true },
    { name: '93-out-of-date', hash: V('as=lead&scn=stale') },
    { name: '94-offline-banner', hash: V('as=lead&scn=offline') },
    { name: '95-offline-move-not-saved', hash: V('as=lead&scn=offline&open=move:R-24'), steps: `await pickDate('New due date', 9, 'October'); await tile('unwell'); await click('Move it', document.querySelector('[role=dialog]'));` },
    { name: '96-support-worker-cant-record', hash: V('open=record:R-27'), core: true },
    { name: '97-not-found-ben', hash: V('as=lead&open=review:R-22'), core: true },
    { name: '98-already-recorded', hash: V('as=lead&open=record:R-28') },
    { name: '100-contract-page', hash: '#/p05/contract', core: true },
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
    await load('#/emar/reviews?as=lead');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Jordan focuses “Record the outcome” on Mele’s review, opens it with Enter, Tabs through the wizard, Escape (untouched) closes it, focus returns.
    await evaluate(`document.querySelector('[data-return="rec-R-27"]').focus();`);
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
    // The menu key on a focused review row opens the same menu as ⋯ and right-click.
    await load('#/emar/reviews?as=lead');
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Samuel Tuilagi')).focus();`);
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
