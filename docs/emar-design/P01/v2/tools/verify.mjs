// P01 v2 verification harness — drives headless Chrome over the DevTools
// Protocol (Node 22 WebSocket) against the running preview (serve.mjs).
// For every state: load at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px
// at device scale 2), run the steps, capture a screenshot, and record
// horizontal overflow, console errors and worker buttons under 44 px (the
// app's .frontline-tap size). Also records a real-Tab keyboard
// walk through the recording dialog. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P01/v2/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P01_URL ?? 'http://127.0.0.1:4384/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9348;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P01_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const toGiven = `await cont(); await tile('given');`;
const recordFlow = (id, extra = '') => ({ hash: `#/meds/today?open=record:${id}`, steps: `${toGiven} ${extra} await cont(); await save();` });

const SHOTS = [
    // Meds today
    { name: '01-meds-today-schedule', hash: '#/meds/today', core: true },
    { name: '02-schedule-scrolled', hash: '#/meds/today', steps: `window.scrollTo(0, 700); await wait(300);` },
    { name: '03-schedule-by-person', hash: '#/meds/today?group=person' },
    { name: '04-rounds', hash: '#/meds/today?view=rounds', core: true },
    { name: '05-guided-round', hash: '#/meds/today?view=rounds&round=am9', core: true },
    { name: '06-as-needed', hash: '#/meds/today?view=asneeded', core: true },
    { name: '07-activity-paginated', hash: '#/meds/today?view=activity' },
    { name: '08-follow-ups-link-only', hash: '#/meds/today?view=followups' },
    { name: '09-row-context-menu', hash: '#/meds/today', steps: `const r=[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('Levetiracetam')); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:700,clientY:420})); await wait(400);` },
    // The recording dialog
    { name: '10-record-step1', hash: '#/meds/today?open=record:r16', core: true },
    { name: '11-record-step1-medicine-photo', hash: '#/meds/today?open=record:r16', steps: `await scrollBody(420);` },
    { name: '12-record-step2-validation', hash: '#/meds/today?open=record:r16', steps: `await cont(); await cont();`, core: true },
    { name: '13-record-step2-given', hash: '#/meds/today?open=record:r16', steps: toGiven, core: true },
    { name: '14-record-review', hash: '#/meds/today?open=record:r16', steps: `${toGiven} await cont();`, core: true },
    { name: '15-record-sending', hash: '#/meds/today?open=record:r16', steps: `${toGiven} await cont(); el('[data-wizard-region=footer]') && [...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Record outcome').click(); await wait(250);` },
    { name: '16-record-success', ...recordFlow('r16', ''), after: 1600, core: true },
    { name: '17-row-after-recording', ...recordFlow('r16', ''), after: 1600, steps2: `await click('Done'); await wait(400); window.scrollTo(0, 560); await wait(300);` },
    { name: '18-controlled-witness', hash: '#/meds/today?open=record:r11', steps: `${toGiven} await scrollBody(200);`, core: true },
    { name: '19-witness-picker', hash: '#/meds/today?open=record:r11', steps: `${toGiven} await scrollBody(200); el('#w-second').click(); await wait(400);`, core: true },
    { name: '20-wrong-pin', hash: '#/meds/today?open=record:r11', steps: `${toGiven} el('#w-second').click(); await wait(400); await click('DADaniel'); await set('#w-pin','000000'); await cont(); await save(); await wait(1500); await scrollBody(160);` },
    { name: '21-insulin-rules-step1', hash: '#/meds/today?open=record:r12', steps: `await scrollBody(520);`, core: true },
    { name: '22-insulin-reading-and-second-person', hash: '#/meds/today?open=record:r12', steps: `${toGiven} await scrollBody(240);` },
    { name: '23-insulin-reading-field', hash: '#/meds/today?open=record:r12', steps: `${toGiven} await cont(); await scrollTo('#w-obs-bsl');` },
    { name: '24-amount-less-colleague', hash: '#/meds/today?open=record:r16', steps: `${toGiven} await click('Record a different amount'); await scrollTo('#w-amtReason');`, core: true },
    { name: '25-amount-less-nobody', hash: '#/meds/today?scn=alone&open=record:r16', steps: `${toGiven} await click('Record a different amount'); await scrollTo('#w-amtReason');`, core: true },
    { name: '26-amount-more-than-ordered', hash: '#/meds/today?open=record:r16', steps: `${toGiven} await click('Record a different amount'); await click('More than ordered was given'); await scrollTo('#w-sev');`, core: true },
    { name: '27-prescriber-instruction', hash: '#/meds/today?open=record:r16', steps: `${toGiven} await click('Prescriber asked for a different dose?'); await scrollTo('#w-rxWho');`, core: true },
    { name: '28b-countersign-nobody-on-shift', hash: '#/meds/today?scn=alone&open=record:r12', steps: `${toGiven} await scrollBody(240);`, core: true },
    { name: '28c-countersign-nobody-review', hash: '#/meds/today?scn=alone&open=record:r12', steps: `${toGiven} await set('#w-obs-bsl','6.4'); await cont();` },
    { name: '28d-countersign-nobody-recorded', hash: '#/meds/today?scn=alone&open=record:r12', steps: `${toGiven} await set('#w-obs-bsl','6.4'); await cont(); await save();`, after: 1600, core: true },
    { name: '28e-countersign-nobody-lead-follow-up', hash: '#/meds/today?scn=alone&open=record:r12', steps: `${toGiven} await set('#w-obs-bsl','6.4'); await cont(); await save(); await wait(1500); await click('Done'); await wait(300); location.hash = '#/tasks?scn=alone&as=lead'; await wait(700);` },
    { name: '28-forgotten-pin-fallback', hash: '#/meds/today?open=record:r12', steps: `${toGiven} el('#w-forgot').click(); await wait(300); el('#w-second').click(); await wait(400); await click('DADaniel'); await scrollTo('#w-forgot');`, core: true },
    { name: '29-allergy-warn', hash: '#/meds/today?open=record:r3', steps: `await scrollBody(300);`, core: true },
    { name: '30-allergy-block-unless-confirmed', hash: '#/meds/today?allergy=confirm&open=why:r3' },
    { name: '31-not-given-while-blocked', hash: '#/meds/today?open=notgiven:r7', steps: `await tile('withheld');`, core: true },
    { name: '32-re-offer', hash: '#/meds/today?open=reoffer:r4', core: true },
    { name: '33-prompt-brand-changed', hash: '#/meds/today?open=record:r6', steps: `await scrollBody(380);` },
    { name: '34-prompt-outcomes', hash: '#/meds/today?open=record:r6', steps: `await cont();` },
    { name: '35-covert-plan', hash: '#/meds/today?open=record:r13', steps: `await scrollBody(420);` },
    { name: '36-late-dose', hash: '#/meds/today?open=record:r2', steps: `await scrollBody(640);`, core: true },
    { name: '37-late-reason', hash: '#/meds/today?open=record:r2', steps: `${toGiven} await cont(); await scrollTo('#w-lateReason');` },
    { name: '38-order-changed-mid-round', hash: '#/meds/today?scn=orderChanged&view=rounds&round=am8&open=record:r2&from=round', steps: `await scrollBody(120);`, core: true },
    { name: '38b-order-changed-new-amount', hash: '#/meds/today?scn=orderChanged&view=rounds&round=am8&open=record:r2&from=round', steps: `el('#w-change-ack').click(); await wait(250); ${toGiven} await scrollTo('[data-field="amt"]');`, core: true },
    { name: '38c-order-changed-round-rows', hash: '#/meds/today?scn=orderChanged&view=rounds&round=am8' },
    { name: '39-as-needed-dose', hash: '#/meds/today?view=asneeded&open=prn:p1', steps: toGiven, core: true },
    { name: '40-as-needed-limit', hash: '#/meds/today?view=asneeded&open=prn:p4', steps: `await scrollBody(560);` },
    { name: '41-as-needed-picker', hash: '#/meds/today?open=prn-pick' },
    // Blocked reasons (NF-07)
    { name: '50-not-clocked-in', hash: '#/meds/today?scn=notClockedIn', core: true },
    { name: '51-why-not-clocked-in', hash: '#/meds/today?scn=notClockedIn&open=why:r16' },
    { name: '52-not-on-shift-section', hash: '#/meds/today?scn=notOnShift', steps: `window.scrollTo(0, document.body.scrollHeight); await wait(300);` },
    { name: '53-why-not-on-shift', hash: '#/meds/today?scn=notOnShift&open=why:r20' },
    { name: '54-why-site-not-approved', hash: '#/meds/today?scn=siteNotApproved&open=why:r21' },
    { name: '55-why-competency-expired', hash: '#/meds/today?scn=competencyExpired&open=why:r16', core: true },
    { name: '56-why-restricted', hash: '#/meds/today?scn=restrictedBlock&open=why:r16' },
    { name: '57-why-awaiting-verification', hash: '#/meds/today?open=why:r7' },
    { name: '58-why-covert-missing', hash: '#/meds/today?scn=covertMissing&open=why:r13' },
    { name: '59-why-no-witness', hash: '#/meds/today?scn=cdNoWitness&open=why:r11', core: true },
    { name: '60-witness-override-request', hash: '#/meds/today?scn=cdNoWitness&open=ovr-request:r11', core: true },
    { name: '61-manager-one-screen-approval', hash: '#/tasks?scn=cdNoWitness&as=pm&open=ovr-approve', core: true },
    { name: '62-manager-decline', hash: '#/tasks?scn=cdNoWitness&as=pm&open=ovr-approve:decline', steps: `await scrollBody(900);` },
    { name: '63-my-eligibility', hash: '#/meds/today?open=eligibility' },
    // Saving states
    { name: '70-not-recorded', hash: '#/meds/today?scn=reject&open=record:r16', steps: recordFlow('r16').steps, after: 1600, core: true },
    { name: '71-not-confirmed', hash: '#/meds/today?scn=uncertain&open=record:r16', steps: recordFlow('r16').steps, after: 1600, core: true },
    { name: '72-already-recorded', hash: '#/meds/today?scn=duplicate&open=record:r2', steps: `${toGiven} await pick('#w-lateReason','Staff were supporting someone else'); await cont(); await save();`, after: 1600, core: true },
    { name: '73-offline-saved-on-device', hash: '#/meds/today?scn=offline&open=record:r16', steps: recordFlow('r16').steps, after: 1600, steps2: `window.scrollTo(0, 560); await wait(300);`, core: true },
    { name: '74-offline-item-refused-banner', hash: '#/meds/today?scn=offlineRefused', core: true },
    { name: '75-offline-item-refused-dialog', hash: '#/meds/today?scn=offlineRefused&open=rejected' },
    { name: '76-recorded-and-reported', hash: '#/meds/today?open=error:r16|2 tablets (100 mg)' },
    // Page states
    { name: '80-loading', hash: '#/meds/today?scn=loading', core: true },
    { name: '81-no-work-left', hash: '#/meds/today?scn=empty', core: true },
    { name: '82-couldnt-load', hash: '#/meds/today?scn=unavailable', core: true },
    { name: '83-out-of-date', hash: '#/meds/today?scn=stale' },
    { name: '84-no-access', hash: '#/emar/prescriptions', core: true },
    { name: '85-not-found', hash: '#/emar/mar?client=ben', core: true },
    // My Day, tasks, calendar
    { name: '90-my-day', hash: '#/my-day', core: true },
    { name: '91-my-day-medicines-card', hash: '#/my-day', steps: `window.scrollTo(0, 330); await wait(300);` },
    { name: '91b-my-day-person-not-shown', hash: '#/my-day?scn=myDayHidden', steps: `window.scrollTo(0, 330); await wait(300);`, core: true },
    { name: '92-my-day-couldnt-load', hash: '#/my-day?scn=unavailable' },
    { name: '93-colleague-were-you-there', hash: '#/meds/today?open=record:r12', steps: `${toGiven} el('#w-forgot').click(); await wait(300); el('#w-second').click(); await wait(400); await click('DADaniel'); await set('#w-obs-bsl','6.4'); await cont(); await save(); await wait(1500); await click('Done'); await wait(300); location.hash = '#/my-day?as=daniel'; await wait(700); window.scrollTo(0, 330); await wait(300);`, core: true },
    { name: '94-colleague-answer-dialog', hash: '#/meds/today?open=record:r12', steps: `${toGiven} el('#w-forgot').click(); await wait(300); el('#w-second').click(); await wait(400); await click('DADaniel'); await set('#w-obs-bsl','6.4'); await cont(); await save(); await wait(1500); await click('Done'); await wait(300); location.hash = '#/my-day?as=daniel&open=confirm:r12'; await wait(700);` },
    { name: '95-all-tasks-support-worker', hash: '#/tasks', core: true },
    { name: '96-all-tasks-house-lead', hash: '#/meds/today?scn=alone&open=record:r16', steps: `${toGiven} await click('Record a different amount'); await set('#w-amt','0.5'); await pick('#w-amtReason','Only part taken'); await cont(); await save(); await wait(1500); await click('Done'); await wait(300); location.hash = '#/tasks?scn=alone&as=lead'; await wait(700);`, core: true },
    { name: '97-my-calendar', hash: '#/my-calendar', core: true },
    // Other entry points
    { name: '100-mar-chart', hash: '#/emar/mar?client=aroha', steps: `window.scrollTo(0, 380); await wait(300);`, core: true },
    { name: '101-mar-mark-given-menu', hash: '#/emar/mar?client=aroha', steps: `window.scrollTo(0, 380); await wait(300); const c=[...document.querySelectorAll('button')].filter(b=>b.textContent.includes('Due'))[1]; c.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:c.getBoundingClientRect().x+20,clientY:c.getBoundingClientRect().y+20})); await wait(400);`, core: true },
    { name: '102-mar-full-record-menu', hash: '#/emar/mar?client=aroha', steps: `window.scrollTo(0, 380); await wait(300); const c=[...document.querySelectorAll('button')].filter(b=>b.textContent.includes('Due'))[3]; c.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:c.getBoundingClientRect().x+20,clientY:c.getBoundingClientRect().y+20})); await wait(400);` },
    { name: '103-client-profile', hash: '#/operations/clients/201', core: true },
    { name: '104-client-profile-choose-dose', hash: '#/operations/clients/201?open=dose-pick:aroha' },
    { name: '105-transport', hash: '#/fleet-assets/transports/12', core: true },
    { name: '106-transport-record', hash: '#/fleet-assets/transports/12?open=record:r2&from=transport', core: true },
    { name: '110-contract-page', hash: '#/p01/contract', core: true },
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
        // Worker controls (the app's Button, Select/combobox triggers, date and time pickers) inside the page body or a dialog must meet .frontline-tap (44 px).
        // Excluded: PageHeader actions and tabs (shared chrome, sized by their own components).
        const small = await evaluate(`return [...document.querySelectorAll('main [data-slot=button], main [role=combobox], [role=dialog] [data-slot=button], [role=dialog] [role=combobox], [role=dialog] fieldset.date-time-field button, [role=alertdialog] [data-slot=button]')].filter((b) => b.offsetParent !== null && !b.closest('header.eh-header, [role=tablist]')).map((b) => { const r = b.getBoundingClientRect(); return { text: (b.getAttribute('aria-label') || b.textContent).trim().replace(/\\s+/g, ' ').slice(0, 60), w: Math.round(r.width), h: Math.round(r.height) }; }).filter((x) => x.h < 43.5 || x.w < 43.5);`);
        const shotPng = await send('Page.captureScreenshot', { format: 'png' });
        const file = `${size.key}-${shot.name}.png`;
        writeFileSync(path.join(outDir, file), Buffer.from(shotPng.data, 'base64'));
        const row = { shot: shot.name, size: size.key, file, overflow, header, small, errors: errors.slice(before), stepError };
        report.shots.push(row);
        process.stdout.write(`${row.stepError || row.errors.length || row.overflow > 0 ? '✗' : '✓'} ${size.key} ${shot.name}${overflow > 0 ? ` overflow=${overflow}` : ''}${row.errors.length ? ` errors=${row.errors.length}` : ''}${small.length ? ` small=${small.length}` : ''}${stepError ? ` step: ${stepError}` : ''}\n`);
    }
}

/* ───────────── keyboard walk (real key events) ───────────── */
if (!only || only === 'keyboard') {
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await load('#/meds/today');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Focus the losartan row's Record button, open with Enter, Tab through step 1, Escape, check focus returns.
    await evaluate(`[...document.querySelectorAll('[data-return=r16]')][0].focus();`);
    const trail = [await who()];
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: String.fromCharCode(13), unmodifiedText: String.fromCharCode(13) });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await sleep(500);
    for (let i = 0; i < 12; i++) {
        await key('Tab', 'Tab', 9);
        trail.push(await who());
    }
    await key('Escape', 'Escape', 27);
    await sleep(500);
    const afterEscape = await who();
    const dialogOpen = await evaluate(`return !!document.querySelector('[role=dialog]');`);
    // Shift+F10 on a focused row opens the same actions menu as ⋯ and right-click.
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Levetiracetam')).focus();`);
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

const smallAll = [...new Set(report.shots.flatMap((r) => (r.small ?? []).map((x) => `${x.text} (${x.w}×${x.h})`)))];
report.buttonsUnder44 = smallAll;
writeFileSync(path.join(outDir, only ? `report-${only}.json` : 'report.json'), JSON.stringify(report, null, 2));
const bad = report.shots.filter((r) => r.stepError || r.errors.length || r.overflow > 0);
process.stdout.write(`\n${report.shots.length} captures · ${bad.length} with problems · buttons under 44 px: ${smallAll.length ? smallAll.join('; ') : 'none'}\n`);
ws.close();
chrome.kill();
