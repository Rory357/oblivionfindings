// P08a v1 verification harness — P07a v1's harness (itself P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4386). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the effect-check dialog. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P08a/v1/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P08A_URL ?? 'http://127.0.0.1:4386/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9358;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P08A_PROFILE ?? path.join(here, '..', '.chrome-profile');

const SIZES = [
    { key: '1440', width: 1440, height: 900, dsf: 1 },
    { key: '1280', width: 1280, height: 800, dsf: 1 },
    { key: 'zoom200', width: 720, height: 450, dsf: 2 },
];

const H = `
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const vis = (e) => e.offsetParent !== null || e.getClientRects().length > 0;
const click = async (t, root = document) => { const b = [...root.querySelectorAll('button,a,[role=menuitem],[role=option],[cmdk-item]')].find((e) => vis(e) && e.textContent.trim().replace(/\\s+/g,' ').startsWith(t)); if (!b) throw new Error('No control: ' + t); b.click(); await wait(350); };
const tile = async (k) => { const t = document.querySelector('[data-tile="' + k + '"]'); if (!t) throw new Error('No tile: ' + k); t.click(); await wait(300); };
const el = (s) => document.querySelector(s);
const set = async (s, v) => { const e = el(s); if (!e) throw new Error('No field: ' + s); const proto = e.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v); e.dispatchEvent(new Event('input', { bubbles: true })); await wait(200); };
const pick = async (trigger, option) => { el(trigger).dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, pointerType: 'mouse' })); await wait(400); const o = [...document.querySelectorAll('[role=option]')].find((e) => e.textContent.trim().startsWith(option)); if (!o) throw new Error('No option: ' + option); o.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerType: 'mouse' })); o.click(); await wait(350); };
const scrollBody = async (y) => { const b = el('[data-wizard-region="body"]') || [...document.querySelectorAll('[role=dialog] .overflow-y-auto')].pop(); if (b) b.scrollTop = y; await wait(250); };
const scrollTo = async (s) => { el(s)?.scrollIntoView({ block: 'center' }); await wait(250); };
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button, [role=dialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const section = async (label) => { document.querySelector('[aria-label="' + label + '"]')?.scrollIntoView({ block: 'start' }); await wait(300); };
`;
const M = '#/meds/today?view=followups';
const S = '#/emar/safety?view=followups';
const SH = '#/emar/safety?view=handovers';

const SHOTS = [
    // Meds today › Follow-ups (support worker)
    { name: '01-follow-ups-worker', hash: M, core: true },
    { name: '02-follow-ups-others-and-done', hash: M, steps: `await section('Others at the house');`, core: true },
    { name: '03-only-mine', hash: `${M}&who=mine` },
    { name: '04-type-refusals', hash: `${M}&type=reoffer` },
    { name: '05-row-context-menu', hash: M, steps: `const r=[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('ibuprofen')); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:760,clientY:560})); await wait(400);`, core: true },
    // The effect check
    { name: '10-effect-overdue-carried', hash: `${M}&open=effect:fu-ibu`, core: true },
    { name: '11-effect-validation', hash: `${M}&open=effect:fu-ibu`, steps: `await click('Record effect');`, core: true },
    { name: '12-effect-helped', hash: `${M}&open=effect:fu-para-aroha`, steps: `await tile('helped'); await set('#ec-noticed','Headache gone, back to reading.');` },
    { name: '13-effect-didnt-help', hash: `${M}&open=effect:fu-para-aroha`, steps: `await tile('none'); await scrollBody(400);`, core: true },
    { name: '14-effect-couldnt-check', hash: `${M}&open=effect:fu-para-aroha`, steps: `await tile('couldnt'); await scrollBody(400);`, core: true },
    { name: '15-effect-check-again-after-shift', hash: `${M}&open=effect:fu-para-aroha`, steps: `await tile('couldnt'); await pick('#ec-reason','Asleep'); el('#ec-again-time').click(); await wait(400); el('[aria-label="Hour 4"]').click(); await wait(250); await click('PM'); await click('Use time'); await click('Save — check again later'); await scrollBody(500);` },
    { name: '15b-effect-time-picker', hash: `${M}&open=effect:fu-para-aroha`, steps: `await tile('couldnt'); await pick('#ec-reason','Asleep'); el('#ec-again-time').click(); await wait(500);` },
    { name: '16-effect-done-late-row', hash: `${M}&open=effect:fu-ibu`, steps: `await tile('helped'); await click('Record effect'); await wait(400);`, after: 400, steps2: `await section('Done today');`, core: true },
    // Refusal follow-ups
    { name: '20-refusal-short', hash: `${M}&open=refusal:fu-losartan`, core: true },
    { name: '21-refusal-couldnt-offer', hash: `${M}&open=refusal:fu-losartan`, steps: `await tile('couldnt'); await scrollBody(300);` },
    { name: '22-refusal-short-review', hash: `${M}&open=refusal:fu-losartan`, steps: `await tile('notneeded'); await pick('#rf-notneeded','The next dose is due soon'); await set('#rf-note','Next dose is at 12:00 pm — Jordan agreed to skip this one.'); await cont();`, core: true },
    { name: '23-refusal-escalated-step1', hash: `${M}&open=refusal:fu-sertraline`, core: true },
    { name: '24-refusal-escalated-details', hash: `${M}&open=refusal:fu-sertraline`, steps: `await tile('again'); await cont();`, core: true },
    { name: '25-refusal-details-validation', hash: `${M}&open=refusal:fu-sertraline`, steps: `await tile('again'); await cont(); await cont();` },
    { name: '26-refusal-escalated-review', hash: `${M}&open=refusal:fu-sertraline`, steps: `await tile('again'); await cont(); await pick('#rf-why','Side effects'); await tile('fluctuating'); el('#rf-gp').click(); await wait(200); await set('#rf-gptext','Dr Lena Chen’s nurse, 9:40 am — keep offering, review Friday.'); await set('#rf-next','Offer again with lunch; Jordan to raise it at Friday’s review.'); await cont();`, core: true },
    { name: '27-refusal-saved', hash: `${M}&open=refusal:fu-sertraline`, steps: `await tile('again'); await cont(); await pick('#rf-why','Side effects'); await tile('fluctuating'); await set('#rf-next','Offer again with lunch.'); await cont(); await click('Save follow-up');`, after: 1100 },
    // Detail, reassign, as-needed dose
    { name: '30-detail-history-carried', hash: `${M}&open=fu:fu-ibu`, core: true },
    { name: '31-detail-couldnt-reassigned', hash: `${M}&open=fu:fu-para-mele`, core: true },
    { name: '32-reassign', hash: `${M}&as=lead&open=reassign:fu-para-mele`, core: true },
    { name: '33-as-needed-dose', hash: `${M}&open=prn:fu-para-aroha`, core: true },
    { name: '34-done-late-detail', hash: `${M}&open=fu:fu-done-late` },
    { name: '35-detail-before-acknowledging', hash: `${M}&scn=ackPending&open=fu:fu-ibu` },
    // Second person
    { name: '40-were-you-there', hash: `${M}&as=daniel&open=confirm:fu-were`, core: true },
    { name: '41-were-you-there-no', hash: `${M}&as=daniel&open=confirm:fu-were`, steps: `await click('I wasn’t there');` },
    { name: '42-waiting-for-daniel-row', hash: M, steps: `await section('Others at the house');` },
    // House lead
    { name: '50-lead-oversight', hash: `${S}&as=lead`, core: true },
    { name: '51-lead-signoff-disputed', hash: `${S}&as=lead&open=signoff:fu-disputed`, core: true },
    { name: '52-lead-signoff-validation', hash: `${S}&as=lead&open=signoff:fu-partial`, steps: `await click('Sign off');` },
    { name: '53-countersign', hash: `${S}&as=lead&open=countersign:fu-phone`, core: true },
    { name: '54-worker-sees-lead-item', hash: `${M}&open=fu:fu-partial`, core: true },
    { name: '55-override-handoff', hash: `${S}&as=lead&open=fu:fu-override` },
    // Oversight across houses
    { name: '60-manager-two-houses', hash: `${S}&as=pm`, core: true },
    { name: '61-handover-heads-up', hash: `${S}&as=pm&open=headsup:fu-rimu-handover`, core: true },
    { name: '62-reminders-escalation-on', hash: `${S}&as=lead&scn=delivery`, core: true },
    { name: '63-done-filter', hash: `${S}&as=pm&st=done` },
    { name: '64-carried-filter', hash: `${S}&as=pm&st=carried` },
    { name: '65-clinical-lead-no-cd', hash: `${S}&as=clinical`, core: true },
    // Handovers
    { name: '70-handover-register', hash: `${SH}&as=lead`, core: true },
    { name: '71-handover-register-manager', hash: `${SH}&as=pm`, core: true },
    { name: '72-handover-medication-lens', hash: `${M}&open=handover:h-kow-am`, core: true },
    { name: '73-ack-pending-follow-ups', hash: `${M}&scn=ackPending`, core: true },
    { name: '74-ack-pending-handover', hash: `${M}&scn=ackPending&open=handover:h-kow-am`, core: true },
    { name: '75-after-acknowledging', hash: `${M}&scn=ackPending&open=handover:h-kow-am`, steps: `await click('I’ve read this handover'); await wait(500);`, core: true },
    { name: '76-handover-notes-section', hash: `${M}&open=handover:h-kow-am`, steps: `await click('Shift notes');` },
    { name: '77-outgoing-draft-medication', hash: `${M}&open=handover-draft`, core: true },
    { name: '78-outgoing-draft-review', hash: `${M}&open=handover-draft`, steps: `await cont();` },
    { name: '79-lens-no-cd-access', hash: `${SH}&as=clinical&open=handover:h-kow-am` },
    // All Tasks
    { name: '80-all-tasks-worker', hash: '#/tasks', core: true },
    { name: '81-all-tasks-lead', hash: '#/tasks?as=lead', core: true },
    { name: '82-all-tasks-clinical-no-cd', hash: '#/tasks?as=clinical' },
    // Page states and access
    { name: '90-loading', hash: `${M}&scn=loading`, core: true },
    { name: '91-nothing-to-follow-up', hash: `${M}&scn=empty`, core: true },
    { name: '92-couldnt-load', hash: `${M}&scn=unavailable`, core: true },
    { name: '93-out-of-date', hash: `${M}&scn=stale` },
    { name: '94-offline-saved-on-device', hash: `${M}&scn=offline&open=effect:fu-para-aroha`, steps: `await tile('helped'); await click('Record effect'); await wait(400); await section('Done today');`, core: true },
    { name: '94b-offline-banner', hash: `${M}&scn=offline` },
    { name: '95-no-access-safety', hash: S, core: true },
    { name: '96-not-found-other-house', hash: `${M}&open=fu:fu-rimu-unconf`, core: true },
    { name: '97-oversight-loading', hash: `${S}&as=lead&scn=loading` },
    { name: '100-contract-page', hash: '#/p08a/contract', core: true },
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
    await load('#/meds/today?view=followups');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Focus Tama's overdue "Check effect", open with Enter, Tab through the dialog, Escape (clean form closes), check focus returns.
    await evaluate(`document.querySelector('[data-return="mine-fu-ibu"]').focus();`);
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
    // Shift+F10 (or the menu key) on a focused follow-up row opens the same menu as ⋯ and right-click.
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('ibuprofen')).focus();`);
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
