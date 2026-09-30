// P04 v1 verification harness — P03 v1's harness (itself P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4388). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the check dialog. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P04/v1/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P04_URL ?? 'http://127.0.0.1:4388/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9360;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P04_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const set = async (s, v) => { const e = el(s); if (!e) throw new Error('No field: ' + s); const proto = e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); await wait(200); };
const pick = async (trigger, option) => { el(trigger).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' })); await wait(400); const o = [...document.querySelectorAll('[role=option]')].find((e) => e.textContent.trim().startsWith(option)); if (!o) throw new Error('No option: ' + option); o.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerType: 'mouse' })); o.click(); await wait(350); };
const scrollBody = async (y) => { const b = el('[data-wizard-region="body"]') || [...document.querySelectorAll('[role=dialog] .overflow-y-auto')].pop(); if (b) b.scrollTop = y; await wait(250); };
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button, [role=dialog] button, [role=alertdialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const section = async (label) => { document.querySelector('[aria-label="' + label + '"]')?.scrollIntoView({ block: 'start' }); await wait(300); };
const check = async (text) => { const l = [...document.querySelectorAll('[role=dialog] label')].find((x) => x.textContent.trim().startsWith(text)); if (!l) throw new Error('No checkbox: ' + text); l.querySelector('button[role=checkbox]').click(); await wait(150); };
const attach = async (name) => { const i = [...document.querySelectorAll('[role=dialog] input[type=file]')].pop(); if (!i) throw new Error('No file input'); const dt = new DataTransfer(); dt.items.add(new File(['synthetic'], name, { type: 'application/pdf' })); i.files = dt.files; i.dispatchEvent(new Event('change', { bubbles: true })); await wait(300); };
const confirmIt = async (t) => { const b = [...document.querySelectorAll('[role=alertdialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No confirm: ' + t); b.click(); await wait(500); };
const rowMenu = async (text, y = 520) => { const r = [...document.querySelectorAll('[role=row]')].find((x) => x.textContent.includes(text)); if (!r) throw new Error('No row: ' + text); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 760, clientY: y })); await wait(400); };
`;
const P = '#/emar/prescriptions';
const O = (q) => `${P}?${q}`;
const writtenSource = `await tile('written'); await pick('#en-prescriber','Dr Lena Chen'); await attach('Prescription.pdf'); await cont();`;
const amoxAroha = `${writtenSource} await pick('#en-pid','Aroha Mere Ngata'); await set('#en-med','Amoxicillin'); await set('#en-strength','500 mg capsule'); await set('#en-dose','1 capsule'); await set('#en-when','8:00 am, 2:00 pm, 8:00 pm for 5 days');`;
const phoneTama = `await tile('phone'); await pick('#en-prescriber','Dr Lena Chen'); await cont(); await pick('#en-pid','Tamati James Walker'); await set('#en-med','Cetirizine'); await set('#en-strength','10 mg tablet'); await set('#en-dose','1 tablet'); await set('#en-when','8:00 am, once a day');`;
const covertTo = (n) => {
    const s = [`await pick('#cv-assessor','Dr Lena Chen'); await tile('cannot'); await cont();`, `await set('#cv-cview','Agrees it’s still in Grace’s best interests; review again in 3 months.'); await cont();`, `await set('#cv-advice','Can still be crushed into yoghurt. Give at the same time each day; not with iron.'); await cont();`, `await attach('Covert authorisation — Grace — signed.pdf'); await cont();`];
    return s.slice(0, n).join(' ');
};
const hineDecide = `await pick('#rc-d-codeine','Ask the GP first'); await pick('#rc-d-b12','Ask the GP first');`;

const SHOTS = [
    // Orders & reviews › Orders
    { name: '01-orders-house-lead', hash: O('as=lead'), core: true },
    { name: '02-orders-needs-attention', hash: O('as=lead&show=attention') },
    { name: '03-orders-ending', hash: O('as=lead&show=ending') },
    { name: '04-orders-stopped', hash: O('as=lead&show=stopped') },
    { name: '05-orders-row-menu', hash: O('as=lead'), steps: `await rowMenu('Omeprazole');`, core: true },
    { name: '06-orders-manager-two-houses', hash: O('as=pm'), core: true },
    { name: '07-orders-clinical-no-cd', hash: O('as=clinical'), core: true },
    { name: '08-orders-support-worker', hash: P, core: true },
    { name: '09-orders-auditor', hash: O('as=auditor') },
    { name: '10-orders-rimu-house', hash: O('as=rimu') },
    { name: '11-orders-recent-changes', hash: O('as=lead'), steps: `await section('Recent changes');` },
    { name: '12-to-check-house-lead', hash: O('as=lead&view=check'), core: true },
    { name: '13-to-check-clinical-lead', hash: O('as=clinical&view=check'), core: true },
    { name: '14-to-check-phone-instruction', hash: O('as=lead&view=check'), steps: `await section('Phone instruction for one dose');` },
    { name: '15-covert', hash: O('as=lead&view=covert'), core: true },
    { name: '16-covert-review-due', hash: O('as=lead&view=covert&scn=covertDue') },
    { name: '17-covert-review-overdue', hash: O('as=lead&view=covert&scn=covertOverdue'), core: true },
    { name: '18-reconciliation', hash: O('as=lead&view=reconcile'), core: true },
    { name: '19-medication-reviews-link', hash: O('as=lead&view=reviews') },
    // The order
    { name: '20-order-waiting-omeprazole', hash: O('as=lead&open=order:o-omeprazole'), core: true },
    { name: '21-order-versions-melatonin', hash: O('as=lead&open=order:o-melatonin'), steps: `await click('Versions');`, core: true },
    { name: '22-order-checks-loratadine', hash: O('as=lead&open=order:o-loratadine'), steps: `await click('Checks and confirmations');`, core: true },
    { name: '23-order-allergy-confirmed-amoxicillin', hash: O('as=lead&open=order:o-amoxicillin'), steps: `await click('Checks and confirmations');` },
    { name: '24-order-supply-read-only', hash: O('as=lead&open=order:o-metformin'), steps: `await click('Supply');` },
    { name: '25-order-covert-levothyroxine', hash: O('as=lead&open=order:o-levothyroxine') },
    { name: '26-order-allergy-blocked-cefalexin', hash: O('as=lead&open=order:o-cefalexin'), core: true },
    // Enter an order or a change
    { name: '30-new-order-source', hash: O('as=lead&open=new'), core: true },
    { name: '31-new-order-validation', hash: O('as=lead&open=new'), steps: `await cont();`, core: true },
    { name: '32-new-order-allergy-match', hash: O('as=lead&open=new'), steps: amoxAroha, core: true },
    { name: '33-new-order-review', hash: O('as=lead&open=new'), steps: `${amoxAroha} await cont();`, core: true },
    { name: '34-new-order-saved', hash: O('as=lead&open=new'), steps: `${amoxAroha} await cont(); fbtn('Save the order').click(); await wait(1200);`, core: true },
    { name: '35-phone-order-read-back', hash: O('as=lead&open=new'), steps: `${phoneTama} await cont();`, core: true },
    { name: '36-phone-read-back-validation', hash: O('as=lead&open=new'), steps: `${phoneTama} await cont(); await cont();` },
    { name: '36b-new-order-already-has-it', hash: O('as=lead&open=new'), steps: `await tile('phone'); await pick('#en-prescriber','Dr Lena Chen'); await cont(); await pick('#en-pid','Tamati James Walker'); await set('#en-med','Loratadine');` },
    { name: '37-change-levetiracetam', hash: O('as=lead&open=change:o-levetiracetam'), steps: `${writtenSource} await set('#en-dose','1.5 tablets');`, core: true },
    { name: '38-change-review', hash: O('as=lead&open=change:o-levetiracetam'), steps: `${writtenSource} await set('#en-dose','1.5 tablets'); await cont();`, core: true },
    { name: '39-change-nothing-changed', hash: O('as=lead&open=change:o-levetiracetam'), steps: `${writtenSource} await cont();` },
    // Check a version
    { name: '40-check-omeprazole-clinical-lead', hash: O('as=clinical&view=check&open=check:o-omeprazole'), core: true },
    { name: '41-check-ticks', hash: O('as=clinical&view=check&open=check:o-omeprazole'), steps: `await tile('ok'); await check('It matches'); await check('The dose'); await check('I checked');` },
    { name: '42-check-validation', hash: O('as=clinical&view=check&open=check:o-omeprazole'), steps: `await tile('ok'); fbtn('Save the check').click(); await wait(300);`, core: true },
    { name: '43-check-send-back', hash: O('as=clinical&view=check&open=check:o-omeprazole'), steps: `await tile('back');` },
    { name: '44-check-you-entered-it', hash: O('as=lead&view=check&open=check:o-omeprazole'), core: true },
    { name: '45-check-alone-reason', hash: O('as=lead&view=check&open=check:o-omeprazole'), steps: `await click('Nobody else can check today');`, core: true },
    { name: '46-second-check-ben', hash: O('as=pm&view=check&open=check:o-paracetamol-ben'), core: true },
    { name: '47-checked-result', hash: O('as=clinical&view=check&open=check:o-omeprazole'), steps: `await tile('ok'); await check('It matches'); await check('The dose'); await check('I checked'); fbtn('Save the check').click(); await wait(600);`, core: true },
    { name: '48-check-blocked-by-allergy', hash: O('as=pm&view=check&open=check:o-cefalexin'), core: true },
    // The prescriber’s written confirmation
    { name: '50-written-loratadine', hash: O('as=lead&view=check&open=written:o-loratadine'), core: true },
    { name: '51-written-validation', hash: O('as=lead&view=check&open=written:o-loratadine'), steps: `fbtn('Attach it').click(); await wait(300);` },
    { name: '52-written-filled', hash: O('as=lead&view=check&open=written:o-loratadine'), steps: `await tile('email'); await attach('Email from Dr Chen.pdf'); await tile('yes');` },
    // The prescriber confirmed it’s safe (allergy)
    { name: '55-allergy-confirmation', hash: O('as=lead&view=check&open=allergy:o-cefalexin'), core: true },
    { name: '56-allergy-validation', hash: O('as=lead&view=check&open=allergy:o-cefalexin'), steps: `fbtn('Record it').click(); await wait(300);` },
    // The P01 phone instruction (P08a v1’s approved countersign dialog)
    { name: '57-phone-instruction-countersign', hash: O('as=lead&view=check&open=countersign:phone'), core: true },
    // Stop an order
    { name: '60-stop-amoxicillin', hash: O('as=lead&open=stop:o-amoxicillin'), core: true },
    { name: '61-stop-confirm', hash: O('as=lead&open=stop:o-amoxicillin'), steps: `await tile('The course finished'); await set('#st-reason','Chest infection cleared — Dr Chen stopped it at the review.'); fbtn('Stop the order').click(); await wait(400);`, core: true },
    { name: '62-stopped-result', hash: O('as=lead&show=stopped&open=stop:o-amoxicillin'), steps: `await tile('The course finished'); await set('#st-reason','Chest infection cleared — Dr Chen stopped it at the review.'); fbtn('Stop the order').click(); await wait(400); await confirmIt('Stop the order');` },
    // Covert
    { name: '70-covert-review-capacity', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), core: true },
    { name: '71-covert-review-overdue', hash: O('as=lead&view=covert&scn=covertOverdue&open=covert:o-levothyroxine') },
    { name: '72-covert-can-decide', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), steps: `await pick('#cv-assessor','Dr Lena Chen'); await tile('can'); await cont();` },
    { name: '73-covert-consulted', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), steps: covertTo(1), core: true },
    { name: '74-covert-pharmacist', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), steps: covertTo(2) },
    { name: '75-covert-gp-method', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), steps: covertTo(3), core: true },
    { name: '76-covert-review', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), steps: covertTo(4), core: true },
    { name: '77-covert-saved', hash: O('as=lead&view=covert&open=covert:o-levothyroxine'), steps: `${covertTo(4)} fbtn('Save the review').click(); await wait(1200);` },
    { name: '78-covert-stop', hash: O('as=lead&view=covert&open=revoke:o-levothyroxine'), core: true },
    { name: '79-covert-stop-confirm', hash: O('as=lead&view=covert&open=revoke:o-levothyroxine'), steps: `await tile('Grace takes it openly'); await set('#rv-note','Grace has taken the tablet openly with breakfast every day this month.'); fbtn('Stop covert giving').click(); await wait(400);` },
    // Reconciliation
    { name: '80-reconcile-hine-match', hash: O('as=lead&view=reconcile&open=reconcile:rec-hine'), core: true },
    { name: '81-reconcile-validation', hash: O('as=lead&view=reconcile&open=reconcile:rec-hine'), steps: `await cont();` },
    { name: '82-reconcile-decided', hash: O('as=lead&view=reconcile&open=reconcile:rec-hine'), steps: `${hineDecide} await scrollBody(400);` },
    { name: '83-reconcile-changes', hash: O('as=lead&view=reconcile&open=reconcile:rec-hine'), steps: `${hineDecide} await cont();`, core: true },
    { name: '84-reconcile-sign-off', hash: O('as=lead&view=reconcile&open=reconcile:rec-hine'), steps: `${hineDecide} await cont(); await cont();`, core: true },
    { name: '85-reconcile-signed-off', hash: O('as=lead&view=reconcile&open=reconcile:rec-hine'), steps: `${hineDecide} await cont(); await cont(); await check('I matched every medicine'); fbtn('Sign it off').click(); await wait(1200);`, core: true },
    { name: '86-reconcile-start', hash: O('as=lead&view=reconcile&open=reconcile:new'), core: true },
    { name: '87-reconcile-start-match', hash: O('as=lead&view=reconcile&open=reconcile:new'), steps: `await pick('#rc-pid','Samuel Tuilagi'); await tile('hospital'); await check('Discharge summary'); await cont();` },
    { name: '87b-reconcile-clinical-controlled-counted', hash: O('as=clinical&view=reconcile&open=reconcile:new'), steps: `await pick('#rc-pid','Grace Liu'); await tile('hospital'); await check('Discharge summary'); await cont();` },
    { name: '88-reconcile-signed-off-view', hash: O('as=lead&view=reconcile&open=reconcile:rec-grace'), core: true },
    { name: '89-reconcile-auditor-view', hash: O('as=auditor&view=reconcile&open=reconcile:rec-hine') },
    // States, access and not found
    { name: '90-loading', hash: O('as=lead&scn=loading') },
    { name: '91-no-orders-yet', hash: O('as=lead&scn=empty'), core: true },
    { name: '92-couldnt-load', hash: O('as=lead&scn=unavailable'), core: true },
    { name: '93-out-of-date', hash: O('as=lead&scn=stale') },
    { name: '94-offline-banner', hash: O('as=lead&scn=offline') },
    { name: '95-offline-stop-not-saved', hash: O('as=lead&scn=offline&open=stop:o-amoxicillin'), steps: `await tile('The course finished'); await set('#st-reason','Course finished.'); fbtn('Stop the order').click(); await wait(400);` },
    { name: '96-support-worker-cant-check', hash: O('open=check:o-omeprazole'), core: true },
    { name: '97-not-found-ben', hash: O('as=lead&open=order:o-amlodipine'), core: true },
    { name: '98-not-found-controlled', hash: O('as=clinical&open=order:o-clonazepam') },
    { name: '99-nothing-to-check', hash: O('as=lead&open=check:o-metformin') },
    { name: '100-contract-page', hash: '#/p04/contract', core: true },
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
if (!only || only === 'keyboard') {
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await load('#/emar/prescriptions?as=clinical&view=check');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Focus Hana's "Check version 1" on Mele's omeprazole, open with Enter, Tab through the dialog, Escape, check focus returns.
    await evaluate(`document.querySelector('[data-return="chk-o-omeprazole"]').focus();`);
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
    // Shift+F10 (or the menu key) on a focused order row opens the same menu as ⋯ and right-click.
    await load('#/emar/prescriptions?as=lead');
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Omeprazole')).focus();`);
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

writeFileSync(path.join(outDir, only ? `report-${only}.json` : 'report.json'), JSON.stringify(report, null, 2));
const bad = report.shots.filter((r) => r.stepError || r.errors.length || r.overflow > 0);
process.stdout.write(`\n${report.shots.length} captures · ${bad.length} with problems\n`);
ws.close();
chrome.kill();
