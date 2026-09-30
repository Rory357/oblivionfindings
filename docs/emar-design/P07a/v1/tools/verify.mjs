// P07a v1 verification harness — P01 v1's harness (tools/verify.mjs), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4385). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count and truncated meter
// captions. Also records a real-key keyboard walk through the count dialog.
// Output: screenshots/*.png + report.json.
//   node docs/emar-design/P07a/v1/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P07A_URL ?? 'http://127.0.0.1:4385/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9357;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P07A_PROFILE ?? path.join(here, '..', '.chrome-profile');

const SIZES = [
    { key: '1440', width: 1440, height: 900, dsf: 1 },
    { key: '1280', width: 1280, height: 800, dsf: 1 },
    { key: 'zoom200', width: 720, height: 450, dsf: 2 },
];

// Steps run in the page. Helpers: click(text), tile(key), wait(ms), set(sel, v), pick(trigger, option), scrollBody(y), scrollTo(sel), cont(), save(), chooseWitness(name), fullCount(...).
const H = `
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const vis = (e) => e.offsetParent !== null || e.getClientRects().length > 0;
const click = async (t, root = document) => { const b = [...root.querySelectorAll('button,a,[role=menuitem],[role=option],[cmdk-item]')].find((e) => vis(e) && e.textContent.trim().replace(/\\s+/g,' ').startsWith(t)); if (!b) throw new Error('No control: ' + t); b.click(); await wait(350); };
const tile = async (k) => { document.querySelector('[data-tile="' + k + '"]').click(); await wait(300); };
const el = (s) => document.querySelector(s);
const set = async (s, v) => { const e = el(s); if (!e) throw new Error('No field: ' + s); const proto = e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); await wait(200); };
const pick = async (trigger, option) => { el(trigger).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' })); await wait(400); const o = [...document.querySelectorAll('[role=option]')].find((e) => e.textContent.trim().startsWith(option)); if (!o) throw new Error('No option: ' + option); o.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerType: 'mouse' })); o.click(); await wait(350); };
const scrollBody = async (y) => { const b = el('[data-wizard-region="body"]') || [...document.querySelectorAll('[role=dialog] .overflow-y-auto')].pop(); if (b) b.scrollTop = y; await wait(250); };
const scrollTo = async (s) => { el(s)?.scrollIntoView({ block: 'center' }); await wait(250); };
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button, [role=dialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const save = async (t = 'Record') => { fbtn(t).click(); await wait(350); };
const chooseWitness = async (name) => { el('#w-witness').click(); await wait(400); const o = [...document.querySelectorAll('[cmdk-item]')].find((e) => e.textContent.includes(name)); if (!o) throw new Error('No witness: ' + name); o.click(); await wait(350); };
const countAll = async (v = {}) => { for (const id of ['cd1','cd2','cd3']) { if (el('#c-' + id)) await set('#c-' + id, String(v[id] ?? ({cd1:27,cd2:41,cd3:19})[id])); } };
const toWitness = async (v) => { await countAll(v); await cont(); };
const toReview = async (v, pin = '123456') => { await toWitness(v); await chooseWitness('Jordan Tipene'); await set('#w-pin', pin); await cont(); };
`;
const C = '#/meds/today?view=controlled';
const discFlow = `await countAll({ cd1: 26 }); await set('#r-cd1','26'); await set('#f-cd1','One blister empty, nothing on the chart. Checked the bin and floor.'); await set('#a-cd1','Locked the cupboard and phoned Jordan Tipene.');`;

const SHOTS = [
    // The view
    { name: '01-controlled-checks', hash: C, core: true },
    { name: '02-controlled-requests-followups', hash: C, steps: `document.querySelector('[aria-label="Witness requests"]').scrollIntoView({block:'start'}); await wait(300);`, core: true },
    { name: '03-register', hash: C, steps: `document.querySelector('[aria-label="Register"]').scrollIntoView({block:'start'}); await wait(300);` },
    { name: '04-register-page-2', hash: `${C}&page=2`, steps: `document.querySelector('[aria-label="Register"]').scrollIntoView({block:'start'}); await wait(300);` },
    { name: '05-needs-doing-now', hash: `${C}&show=now`, core: true },
    { name: '06-row-context-menu', hash: C, steps: `const r=[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('Methylphenidate') && x.textContent.includes('Due now')); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:760,clientY:560})); await wait(400);`, core: true },
    // The count
    { name: '10-count-step1', hash: `${C}&open=count:all`, core: true },
    { name: '11-count-validation', hash: `${C}&open=count:all`, steps: `await cont();`, core: true },
    { name: '12-count-differs-count-again', hash: `${C}&open=count:all`, steps: `await set('#c-cd1','26'); await scrollBody(0);`, core: true },
    { name: '13-count-matches-second', hash: `${C}&open=count:all`, steps: `await set('#c-cd1','26'); await set('#r-cd1','27'); await scrollBody(0);` },
    { name: '14-count-discrepancy-fields', hash: `${C}&open=count:all`, steps: `await set('#c-cd1','26'); await set('#r-cd1','26'); await scrollBody(120);`, core: true },
    { name: '15-count-witness-step', hash: `${C}&open=count:all`, steps: `await toWitness();`, core: true },
    { name: '16-witness-picker', hash: `${C}&open=count:all`, steps: `await toWitness(); el('#w-witness').click(); await wait(450);`, core: true },
    { name: '17-count-review', hash: `${C}&open=count:all`, steps: `await toReview();`, core: true },
    { name: '18-count-review-discrepancy', hash: `${C}&open=count:all`, steps: `${discFlow} await cont(); await chooseWitness('Jordan Tipene'); await set('#w-pin','123456'); await cont();`, core: true },
    { name: '19-count-success', hash: `${C}&open=count:all`, steps: `await toReview(); await save('Record count');`, after: 1400, core: true },
    { name: '20-count-success-discrepancy', hash: `${C}&open=count:all`, steps: `${discFlow} await cont(); await chooseWitness('Jordan Tipene'); await set('#w-pin','123456'); await cont(); await save('Record count');`, after: 1400, core: true },
    { name: '21-wrong-pin', hash: `${C}&open=count:all`, steps: `await toReview(undefined, '000000'); await save('Record count');`, after: 1300, core: true },
    { name: '22-locked-pin', hash: `${C}&open=count:all`, steps: `await toReview(undefined, '999999'); await save('Record count');`, after: 1300 },
    { name: '23-register-changed-during-count', hash: `#/meds/today?view=controlled&scn=balanceChanged&open=count:all`, steps: `await toReview(); await save('Record count');`, after: 1300, core: true },
    { name: '24-offline-count-not-saved', hash: `#/meds/today?view=controlled&scn=offline&open=count:cd1`, steps: `await set('#c-cd1','27'); await cont(); await chooseWitness('Jordan Tipene'); await set('#w-pin','123456'); await cont(); await save('Record count');`, after: 1300, core: true },
    { name: '25-discard-guard', hash: `${C}&open=count:all`, steps: `await set('#c-cd1','27'); fbtn('Cancel').click(); await wait(400);` },
    { name: '26-after-count-rows', hash: `${C}&open=count:all`, steps: `await toReview(); await save('Record count');`, after: 1400, steps2: `await click('Done'); await wait(500);`, core: true },
    { name: '27-count-one-medicine', hash: `${C}&open=count:cd2` },
    // Witness requests
    { name: '30-ask-someone-to-witness', hash: `${C}&open=ask:count`, core: true },
    { name: '31-colleague-answers', hash: `${C}&as=lead&open=answer:ask-1`, core: true },
    { name: '32-cant-come-now', hash: `${C}&as=lead&open=answer:ask-1`, steps: `await click('Can’t come now'); await wait(300);` },
    { name: '33-requester-sees-answer', hash: `${C}&as=lead&open=answer:ask-1`, steps: `await click('On my way'); await wait(300); location.hash = '#/meds/today?view=controlled'; await wait(700); document.querySelector('[aria-label="Witness requests"]').scrollIntoView({block:'start'}); await wait(300);`, core: true },
    { name: '34-cancel-request', hash: `${C}&open=cancelask:ask-1` },
    // No witness and overrides
    { name: '40-nobody-can-witness', hash: `#/meds/today?view=controlled&scn=noWitness`, core: true },
    { name: '41-why-cant-i-count', hash: `#/meds/today?view=controlled&scn=noWitness&open=cantcount`, core: true },
    { name: '42-override-request', hash: `#/meds/today?view=controlled&scn=noWitness&open=ovr-request:d8`, core: true },
    { name: '43-override-waiting-row', hash: `#/meds/today?view=controlled&scn=noWitness&open=ovr-request:d8`, steps: `await click('Send request'); await wait(500); document.querySelector('[aria-label="Controlled doses today"]').scrollIntoView({block:'start'}); await wait(300);`, core: true },
    { name: '44-manager-one-screen-approval', hash: `#/meds/today?view=controlled&scn=noWitness&open=ovr-request:d8`, steps: `await click('Send request'); await wait(400); location.hash = '#/meds/today?view=controlled&scn=noWitness&as=pm&open=ovr-approve'; await wait(700);`, core: true },
    { name: '45-manager-decline', hash: `#/meds/today?view=controlled&scn=noWitness&open=ovr-request:d8`, steps: `await click('Send request'); await wait(400); location.hash = '#/meds/today?view=controlled&scn=noWitness&as=pm&open=ovr-approve:decline'; await wait(700); await scrollBody(900);` },
    { name: '46-override-approved-row', hash: `#/meds/today?view=controlled&scn=noWitness&open=ovr-request:d8`, steps: `await click('Send request'); await wait(400); location.hash = '#/meds/today?view=controlled&scn=noWitness&as=pm&open=ovr-approve'; await wait(700); await click('Approve override'); await wait(400); location.hash = '#/meds/today?view=controlled&scn=noWitness'; await wait(700); document.querySelector('[aria-label="Controlled doses today"]').scrollIntoView({block:'start'}); await wait(300);`, core: true },
    { name: '47-manager-view-requests', hash: `#/meds/today?view=controlled&scn=noWitness&open=ovr-request:d8`, steps: `await click('Send request'); await wait(400); location.hash = '#/meds/today?view=controlled&scn=noWitness&as=pm'; await wait(700); document.querySelector('[aria-label="Witness requests"]').scrollIntoView({block:'start'}); await wait(300);` },
    { name: '48-override-detail', hash: `${C}&open=ovr` },
    // House lead
    { name: '50-house-lead-view', hash: `${C}&as=lead`, core: true },
    { name: '51-followup-doses', hash: `${C}&as=lead&open=followup`, core: true },
    { name: '52-followup-count', hash: `${C}&as=lead&open=followup`, steps: `await cont();`, core: true },
    { name: '53-followup-sign-off', hash: `${C}&as=lead&open=followup`, steps: `await cont(); await set('#c-cd1','27'); await set('#c-cd2','41'); await chooseWitness('Priya Shah'); await set('#w-pin','123456'); await cont();`, core: true },
    { name: '54-followup-signed', hash: `${C}&as=lead&open=followup`, steps: `await cont(); await set('#c-cd1','27'); await set('#c-cd2','41'); await chooseWitness('Priya Shah'); await set('#w-pin','123456'); await cont(); el('#fu-check').click(); await wait(200); await save('Sign off');`, after: 1400, core: true },
    { name: '55-followup-covered-by-count', hash: `${C}&as=lead&open=count:all`, steps: `await countAll(); await cont(); await chooseWitness('Priya Shah'); await set('#w-pin','123456'); await cont(); await save('Record count'); await wait(1300); await click('Done'); await wait(400); location.hash = '#/meds/today?view=controlled&as=lead&open=followup'; await wait(700); await cont();`, core: true },
    // Other personas and states
    { name: '60-cant-witness-yet', hash: `${C}&as=mere`, core: true },
    { name: '61-my-witness-eligibility', hash: `${C}&as=mere&open=elig`, core: true },
    { name: '62-count-overdue', hash: `#/meds/today?view=controlled&scn=countOverdue`, core: true },
    { name: '63-cadence-not-configured', hash: `#/meds/today?view=controlled&scn=cadenceNotSet`, core: true },
    { name: '64-discrepancy-open', hash: `#/meds/today?view=controlled&scn=discrepancyOpen`, steps: `document.querySelector('[aria-label="Open discrepancies and follow-ups"]').scrollIntoView({block:'start'}); await wait(300);`, core: true },
    { name: '65-discrepancy-detail', hash: `#/meds/today?view=controlled&scn=discrepancyOpen&open=disc:disc-14`, core: true },
    { name: '66-not-clocked-in', hash: `#/meds/today?view=controlled&scn=notClockedIn`, core: true },
    { name: '67-why-cant-i-count-not-clocked-in', hash: `#/meds/today?view=controlled&scn=notClockedIn&open=cantcount` },
    // Movements and history
    { name: '70-movement-step1', hash: `${C}&open=move:cd1`, core: true },
    { name: '71-movement-balance-witness', hash: `${C}&open=move:cd1`, steps: `await tile('out'); await set('#mv-amount','7'); await pick('#mv-place','Whānau or family'); await set('#mv-person','Hine Ngata (Aroha’s mother)'); await cont(); await set('#mv-left','20');`, core: true },
    { name: '72-movement-left-doesnt-match', hash: `${C}&open=move:cd1`, steps: `await tile('out'); await set('#mv-amount','7'); await pick('#mv-place','Whānau or family'); await set('#mv-person','Hine Ngata (Aroha’s mother)'); await cont(); await set('#mv-left','19'); await chooseWitness('Jordan Tipene'); await set('#w-pin','123456'); await cont();` },
    { name: '73-movement-review', hash: `${C}&open=move:cd1`, steps: `await tile('out'); await set('#mv-amount','7'); await pick('#mv-place','Whānau or family'); await set('#mv-person','Hine Ngata (Aroha’s mother)'); await cont(); await set('#mv-left','20'); await chooseWitness('Jordan Tipene'); await set('#w-pin','123456'); await cont();`, core: true },
    { name: '74-movement-success', hash: `${C}&open=move:cd1`, steps: `await tile('out'); await set('#mv-amount','7'); await pick('#mv-place','Whānau or family'); await set('#mv-person','Hine Ngata (Aroha’s mother)'); await cont(); await set('#mv-left','20'); await chooseWitness('Jordan Tipene'); await set('#w-pin','123456'); await cont(); await save('Record movement');`, after: 1400 },
    { name: '75-movement-validation', hash: `${C}&open=move`, steps: `await cont();` },
    { name: '76-counts-and-movements', hash: `${C}&open=hist:cd1`, core: true },
    // Page states
    { name: '80-loading', hash: `#/meds/today?view=controlled&scn=loading`, core: true },
    { name: '81-no-controlled-medicines', hash: `#/meds/today?view=controlled&scn=noCd`, core: true },
    { name: '82-couldnt-load', hash: `#/meds/today?view=controlled&scn=unavailable`, core: true },
    { name: '83-out-of-date', hash: `#/meds/today?view=controlled&scn=stale`, core: true },
    { name: '84-offline', hash: `#/meds/today?view=controlled&scn=offline` },
    // Concealment, access and scope
    { name: '90-no-cd-access-schedule', hash: `#/meds/today?as=tomasi`, core: true },
    { name: '91-no-cd-access-search', hash: `#/meds/today?as=tomasi`, steps: `const i=[...document.querySelectorAll('input')].find(x=>x.placeholder?.startsWith('Search people')); const d=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value'); d.set.call(i,'clonazepam'); i.dispatchEvent(new Event('input',{bubbles:true})); await wait(400);`, core: true },
    { name: '92-no-access-controlled-checks', hash: `#/meds/today?view=controlled&as=tomasi`, core: true },
    { name: '93-not-found-controlled-record', hash: `#/meds/today?as=tomasi&scn=discrepancyOpen&open=disc:disc-14`, core: true },
    { name: '94-with-cd-access-schedule', hash: `#/meds/today` },
    { name: '95-manager-two-houses', hash: `${C}&as=pm`, core: true },
    { name: '96-manager-cant-count-rimu', hash: `${C}&as=pm&open=cantcount` },
    { name: '100-contract-page', hash: `#/p07a/contract`, core: true },
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
    await load('#/meds/today?view=controlled');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Focus "Start the 3:00 pm count", open with Enter, Tab through step 1, Escape (clean form closes), check focus returns.
    await evaluate(`document.querySelector('[data-return=count-all]').focus();`);
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
    // Shift+F10 (or the menu key) on a focused count row opens the same menu as ⋯ and right-click.
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Clonazepam') && r.textContent.includes('Due now')).focus();`);
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
