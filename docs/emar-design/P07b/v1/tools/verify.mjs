// P07b v1 verification harness — P06 v1's harness (itself P04's / P03's / P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4390). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the resolve-discrepancy wizard. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P07b/v1/tools/verify.mjs [--only=substring,substring,…] [--core]
// A partial run (--only) replaces just its captures in report.json and notes the re-run there.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P07B_URL ?? 'http://127.0.0.1:4390/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9362;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const onlyList = only ? only.split(',').map((x) => x.trim()).filter(Boolean) : null;
const picked = (name) => !onlyList || onlyList.some((x) => name.includes(x));
const profile = process.env.P07B_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button, [role=dialog] button, [role=alertdialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const section = async (label) => { document.querySelector('[aria-label="' + label + '"]')?.scrollIntoView({ block: 'start' }); await wait(300); };
const check = async (text) => { const l = [...document.querySelectorAll('[role=dialog] label')].find((x) => x.textContent.trim().startsWith(text)); if (!l) throw new Error('No checkbox: ' + text); l.querySelector('button[role=checkbox]').click(); await wait(150); };
const rowMenu = async (text, y = 520) => { const r = [...document.querySelectorAll('[role=row]')].find((x) => x.textContent.includes(text)); if (!r) throw new Error('No row: ' + text); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 760, clientY: y })); await wait(400); };
`;
const C = '#/emar/controlled';
const O = (q) => `${C}?${q}`;
const SV = (q) => `#/emar/safety?view=overrides&${q}`;
const witness = (id, who = 'Daniel Ahn') => `await pick('#${id}-who','${who}'); await set('#${id}-pin','123456');`;
const voidFill = `await tile('Wrong amount'); await set('#vo-note','Two were recorded; Aroha had one.'); await set('#vo-amount','1');`;
const recountTo = (n) => [`await tile('recount'); await cont();`, `await set('#rs-note','Recounted with Mere — 22. A tablet was stuck in the blister.'); await cont();`, `${witness('rs-w', 'Mere Kahu')} await cont();`].slice(0, n).join(' ');
const returnTo = (n) => [`await set('#rt-qty','1'); await tile('expired'); await cont();`, `await cont();`, `${witness('rt-w', 'Mere Kahu')} await cont();`].slice(0, n).join(' ');

const SHOTS = [
    // The controlled register
    { name: '01-register-house-lead', hash: O('as=lead'), core: true },
    { name: '02-register-class-to-review', hash: O('as=lead&show=class') },
    { name: '03-register-row-menu', hash: O('as=lead'), steps: `await rowMenu('Methylphenidate');`, core: true },
    { name: '04-register-manager-two-houses', hash: O('as=pm'), core: true },
    { name: '05-register-clinical-no-view', hash: O('as=clinical'), core: true },
    { name: '06-register-support-worker', hash: C, core: true },
    { name: '07-register-auditor', hash: O('as=auditor') },
    { name: '08-register-rimu-house', hash: O('as=rimu') },
    { name: '09-register-recent-entries', hash: O('as=lead'), steps: `await section('Recent entries');` },
    { name: '10-discrepancies', hash: O('as=lead&view=discrepancies'), core: true },
    { name: '11-discrepancies-support-worker', hash: O('view=discrepancies') },
    { name: '12-losses', hash: O('as=lead&view=losses'), core: true },
    { name: '13-destructions', hash: O('as=lead&view=destructions'), core: true },
    { name: '14-destructions-redirect', hash: '#/emar/destructions?as=lead' },
    // Safety & oversight › Witness overrides
    { name: '15-overrides-manager', hash: SV('as=pm'), core: true },
    { name: '16-overrides-house-lead', hash: SV('as=lead'), core: true },
    { name: '16b-overrides-filter-empty', hash: SV('as=rimu&st=declined') },
    { name: '17-overrides-clinical-no-view', hash: SV('as=clinical') },
    { name: '18-safety-other-view-link-only', hash: '#/emar/safety?as=lead&view=followups' },
    // The medicine’s register, voids, class, breakage
    { name: '20-med-register-entries', hash: O('as=lead&open=med:cd-mph'), steps: `await click('Entries');`, core: true },
    { name: '21-med-register-this', hash: O('as=lead&open=med:cd-czp') },
    { name: '22-void-entry', hash: O('as=lead&open=void:en-mph-5'), core: true },
    { name: '23-void-validation', hash: O('as=lead&open=void:en-mph-5'), steps: `fbtn('Void the entry').click(); await wait(300);`, core: true },
    { name: '24-void-witness-picker', hash: O('as=lead&open=void:en-mph-5'), steps: `${voidFill} await openSelect('#vo-w-who');`, core: true },
    { name: '25-void-confirm', hash: O('as=lead&open=void:en-mph-5'), steps: `${voidFill} ${witness('vo-w')} fbtn('Void the entry').click(); await wait(400);`, core: true },
    { name: '26-class-set', hash: O('as=lead&open=class:cd-mdz') },
    { name: '27-breakage', hash: O('open=breakage:cd-czp'), core: true },
    { name: '28-discrepancy-view', hash: O('as=lead&view=discrepancies&open=disc:D-14') },
    // Resolve a discrepancy
    { name: '30-resolve-outcome', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), core: true },
    { name: '31-resolve-recount-details', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), steps: recountTo(1) },
    { name: '32-resolve-loss-details', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), steps: `await tile('loss'); await cont();`, core: true },
    { name: '33-resolve-witness', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), steps: recountTo(2), core: true },
    { name: '34-resolve-review', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), steps: recountTo(3), core: true },
    { name: '35-resolve-saved', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), steps: `${recountTo(3)} fbtn('Resolve').click(); await wait(1200);` },
    { name: '36-resolve-counter-cant', hash: O('view=discrepancies&open=resolve:D-14'), core: true },
    { name: '37-resolve-escalate-review', hash: O('as=lead&view=discrepancies&open=resolve:D-14'), steps: `await tile('escalate'); await cont(); await set('#rs-note','Two differences this week on clonazepam — needs a manager.'); await cont();` },
    // Losses
    { name: '40-loss-report', hash: O('view=losses&open=loss:new:cd-mdz'), core: true },
    { name: '41-loss-validation', hash: O('view=losses&open=loss:new:cd-mdz'), steps: `await cont();` },
    { name: '42-loss-who-told', hash: O('view=losses&open=loss:new:cd-mdz'), steps: `await set('#ls-qty','1'); await cont(); await set('#ls-circ','The day bag was left on the bus.'); await set('#ls-imm','Rang the bus company; told Jordan.'); await cont(); await check('Theft is suspected');` },
    { name: '43-loss-view', hash: O('as=lead&view=losses&open=loss:L-7'), core: true },
    { name: '44-loss-add-note', hash: O('as=lead&view=losses&open=lossnote:L-7'), steps: `await check('The police have now been told');` },
    { name: '45-loss-close-manager', hash: O('as=pm&view=losses&open=lossclose:L-7'), core: true },
    { name: '45b-loss-close-theft-needs-police', hash: O('as=pm&view=losses&open=lossclose:L-7'), steps: `await tile('Theft'); await set('#lc-finding','Two handback sheets disagree.'); await check('I’ve checked who needs to know'); await click('Close the loss', document.querySelector('[role=dialog]'));` },
    { name: '45c-loss-close-theft-chosen', hash: O('as=pm&view=losses&open=lossclose:L-7'), steps: `await tile('Theft');` },
    { name: '46-loss-close-support-worker', hash: O('view=losses&open=lossclose:L-7') },
    // Destructions
    { name: '50-return-what', hash: O('as=lead&view=destructions&open=destroy:new:cd-mdz'), core: true },
    { name: '51-return-how', hash: O('as=lead&view=destructions&open=destroy:new:cd-mdz'), steps: returnTo(1), core: true },
    { name: '52-return-witness', hash: O('as=lead&view=destructions&open=destroy:new:cd-mdz'), steps: returnTo(2) },
    { name: '53-return-review', hash: O('as=lead&view=destructions&open=destroy:new:cd-mdz'), steps: returnTo(3), core: true },
    { name: '55-receipt', hash: O('as=lead&view=destructions&open=receipt:DS-21'), core: true },
    { name: '56-receipt-validation', hash: O('as=lead&view=destructions&open=receipt:DS-21'), steps: `fbtn('Record it').click(); await wait(300);` },
    { name: '57-void-destruction', hash: O('as=lead&view=destructions&open=voiddest:DS-21'), core: true },
    { name: '58-destruction-view', hash: O('as=lead&view=destructions&open=dest:DS-19') },
    // Witness overrides
    { name: '60-override-overdue', hash: SV('as=lead&open=override:OV-7'), core: true },
    { name: '61-override-declined', hash: SV('as=pm&open=override:OV-8') },
    { name: '62-override-rimu-due', hash: SV('as=rimu&open=override:OV-9') },
    // States, access and not found
    { name: '90-loading', hash: O('as=lead&scn=loading') },
    { name: '91-empty', hash: O('as=lead&scn=empty'), core: true },
    { name: '92-couldnt-load', hash: O('as=lead&scn=unavailable'), core: true },
    { name: '93-out-of-date', hash: O('as=lead&scn=stale') },
    { name: '94-offline-banner', hash: O('as=lead&scn=offline') },
    { name: '95-offline-breakage-not-saved', hash: O('as=lead&scn=offline&open=breakage:cd-czp'), steps: `await set('#br-qty','1'); await set('#br-what','Dropped and crushed.'); ${witness('br-w')} fbtn('Record it').click(); await wait(400);` },
    { name: '96-support-worker-cant-void', hash: O('open=void:en-mph-5'), core: true },
    { name: '97-not-found-ben', hash: O('as=lead&open=med:cd-oxy'), core: true },
    { name: '98-count-isnt-voided', hash: O('as=lead&open=void:en-mph-6') },
    { name: '100-contract-page', hash: '#/p07b/contract', core: true },
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
    await load('#/emar/controlled?as=lead&view=discrepancies');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Jordan focuses “Resolve” on D-14, opens it with Enter, Tabs through the wizard, Escape (untouched) closes it, focus returns.
    await evaluate(`document.querySelector('[data-return="res-D-14"]').focus();`);
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
    // The menu key on a focused register row opens the same menu as ⋯ and right-click.
    await load('#/emar/controlled?as=lead');
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Methylphenidate')).focus();`);
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
