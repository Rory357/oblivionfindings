// P08b v1 verification harness — P05 v1.1's harness (itself P07b's / P06's / P04's / P03's / P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4393). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the triage wizard. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P08b/v1/tools/verify.mjs [--only=substring,substring,…] [--core]
// A partial run (--only) replaces just its captures in report.json and notes the re-run there.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P08B_URL ?? 'http://127.0.0.1:4393/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9363;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const onlyList = only ? only.split(',').map((x) => x.trim()).filter(Boolean) : null;
const picked = (name) => !onlyList || onlyList.some((x) => name.includes(x));
const profile = process.env.P08B_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const E = '#/emar/errors';
const V = (q) => `${E}?${q}`;
const S = (q) => `#/emar/settings?view=alerts&sec=triage&${q}`;
/** Sam, a missed evening melatonin, reached with moderate harm — a report from the page, as Jordan. */
const rep = (n) =>
    [
        `await pick('#rp-person','Samuel Tuilagi'); await tick('rp-med-o-melatonin'); await tile('omission'); await pickDate('When it happened date', 27, 'September'); await pickTime('When it happened time', '8', '30', 'PM'); await cont();`,
        `await tile('yes'); await tile('moderate'); await cont();`,
        `await set('#rp-account','Sam’s 8:30 pm melatonin wasn’t given — the evening round finished before he was back from his outing. He didn’t sleep until 3 am. (Synthetic.)'); await set('#rp-immediate','Told the house lead at handover.'); await cont();`,
    ]
        .slice(0, n)
        .join(' ');
/** Priya, from the dose menu on Grace’s clonazepam — the open MED-0048 matches. */
const dose = (n) =>
    [
        `await tile('wrongTime'); await cont(); await tile('yes'); await tile('none'); await cont();`,
        `await set('#rp-account','Gave Grace her clonazepam at 8:05 with breakfast — I think I reported this already. (Synthetic.)'); await set('#rp-immediate','Told Jordan.');`,
        `await cont();`,
    ]
        .slice(0, n)
        .join(' ');
const doneA31 = `await set('#dn-note','Old pen model back from the pharmacy; two-person check stopped.'); fbtn('Mark it done').click(); await wait(500);`;

const SHOTS = [
    // Safety & oversight › Medication errors
    { name: '01-errors-house-lead', hash: V('as=lead'), core: true },
    { name: '02-errors-row-menu', hash: V('as=lead'), steps: `await rowMenu('Grace Liu');`, core: true },
    { name: '03-errors-manager-two-houses', hash: V('as=pm'), core: true },
    { name: '04-errors-support-worker', hash: E, core: true },
    { name: '05-errors-clinical-redacted', hash: V('as=clinical'), core: true },
    { name: '06-errors-auditor', hash: V('as=auditor') },
    { name: '07-errors-rimu', hash: V('as=rimu') },
    { name: '08-investigating', hash: V('as=lead&sub=investigating'), core: true },
    { name: '09-actions', hash: V('as=lead&sub=actions'), core: true },
    { name: '10-incidents-lead', hash: V('as=lead&sub=incidents'), core: true },
    { name: '11-incidents-closer', hash: V('as=pm&sub=incidents'), core: true },
    { name: '12-closed', hash: V('as=lead&sub=closed'), core: true },
    { name: '13-trends', hash: V('as=pm&sub=trends'), core: true },
    { name: '14-actions-row-menu', hash: V('as=lead&sub=actions'), steps: `await rowMenu('A-31', 360);` },
    { name: '15-incidents-row-menu', hash: V('as=pm&sub=incidents'), steps: `await rowMenu('INC-2229', 360);` },
    // Report a medication error
    { name: '20-report-what', hash: V('as=lead&open=report'), core: true },
    { name: '21-report-validation', hash: V('as=lead&open=report'), steps: `await cont();` },
    { name: '22-report-what-filled', hash: V('as=lead&open=report'), steps: `await pick('#rp-person','Samuel Tuilagi'); await tick('rp-med-o-melatonin'); await tile('omission'); await pickDate('When it happened date', 27, 'September'); await pickTime('When it happened time', '8', '30', 'PM');` },
    { name: '23-report-harm-incident', hash: V('as=lead&open=report'), steps: `${rep(1)} await tile('yes'); await tile('moderate');`, core: true },
    { name: '24-report-harm-severe', hash: V('as=lead&open=report'), steps: `${rep(1)} await tile('yes'); await tile('severe');` },
    { name: '25-report-account', hash: V('as=lead&open=report'), steps: `${rep(2)}` },
    { name: '26-report-review', hash: V('as=lead&open=report'), steps: `${rep(3)}`, core: true },
    { name: '27-report-sent', hash: V('as=lead&open=report'), steps: `${rep(3)} fbtn('Send the report').click(); await wait(1200);`, core: true },
    { name: '28-report-dose', hash: V('open=report:dose:o-clonazepam'), core: true },
    { name: '29-report-cd-prompt', hash: V('open=report:dose:o-clonazepam'), steps: `${dose(2)}`, core: true },
    { name: '30-report-duplicate', hash: V('open=report:dose:o-clonazepam'), steps: `${dose(3)}`, core: true },
    { name: '31-report-duplicate-clinical', hash: V('as=clinical&open=report:person:grace'), steps: `await tick('rp-med-o-clonazepam'); await tile('wrongTime'); await pickDate('When it happened date', 28, 'September'); await pickTime('When it happened time', '8', '00', 'AM'); await cont(); await tile('yes'); await tile('none'); await cont(); await set('#rp-account','Grace had her 9:00 am dose at 8:00 with breakfast. (Synthetic.)'); await set('#rp-immediate','Told Jordan.'); await cont();`, core: true },
    { name: '32-report-not-right', hash: V('as=lead&open=report:notright:o-omeprazole'), core: true },
    { name: '33-report-more-than-ordered', hash: V('open=report:more:o-metformin'), core: true },
    { name: '34-report-more-harm', hash: V('open=report:more:o-metformin'), steps: `await cont(); await tile('minor');` },
    { name: '35-report-add-to-existing', hash: V('open=report:dose:o-clonazepam'), steps: `${dose(3)} await tile('same'); await cont();` },
    { name: '36-report-added', hash: V('open=report:dose:o-clonazepam'), steps: `${dose(3)} await tile('same'); await cont(); fbtn('Add to MED-0048').click(); await wait(1200);` },
    // The report (viewer)
    { name: '40-error-grace-lead', hash: V('as=lead&open=error:MED-0048'), core: true },
    { name: '41-error-aroha-accounts', hash: V('as=lead&open=error:MED-0045:accounts') },
    { name: '42-error-aroha-actions', hash: V('as=lead&open=error:MED-0045:actions'), core: true },
    { name: '43-error-aroha-incident', hash: V('as=lead&open=error:MED-0045:incident'), core: true },
    { name: '44-error-aroha-history', hash: V('as=lead&open=error:MED-0045:history') },
    { name: '45-error-tama-telling', hash: V('as=lead&open=error:MED-0047:telling') },
    { name: '46-error-grace-clinical-redacted', hash: V('as=clinical&open=error:MED-0048'), core: true },
    { name: '47-error-grace-clinical-accounts', hash: V('as=clinical&open=error:MED-0048:accounts'), core: true },
    { name: '48-error-support-worker-own', hash: V('open=error:MED-0045:investigation'), core: true },
    { name: '49-error-closed', hash: V('as=lead&sub=closed&open=error:MED-0042') },
    // Triage
    { name: '50-triage-check', hash: V('as=lead&open=triage:MED-0048'), core: true },
    { name: '51-triage-owner', hash: V('as=lead&open=triage:MED-0048'), steps: `await cont();`, core: true },
    { name: '52-triage-harm-up-incident', hash: V('as=lead&open=triage:MED-0048'), steps: `await tile('minor'); await tile('moderate'); await cont();` },
    { name: '53-triage-review', hash: V('as=lead&open=triage:MED-0048'), steps: `await cont(); await cont();` },
    { name: '54-triage-saved', hash: V('as=lead&open=triage:MED-0048'), steps: `await cont(); await cont(); fbtn('Save the triage').click(); await wait(1200);` },
    { name: '55-triage-clinical-cant', hash: V('as=clinical&open=triage:MED-0048'), core: true },
    // Notes, actions, telling the person
    { name: '56-note', hash: V('as=lead&sub=investigating&open=note:MED-0047'), core: true },
    { name: '57-note-validation', hash: V('as=lead&sub=investigating&open=note:MED-0047'), steps: `fbtn('Add the note').click(); await wait(300);` },
    { name: '58-action', hash: V('as=lead&sub=investigating&open=action:MED-0047'), core: true },
    { name: '59-action-filled', hash: V('as=lead&sub=investigating&open=action:MED-0047'), steps: `await set('#ac-what','Restart the round from the top after any interruption'); await pickDate('Due', 2, 'October');` },
    { name: '60-done', hash: V('as=lead&sub=actions&open=done:MED-0045:A-31'), core: true },
    { name: '62-disclose-ben', hash: V('as=rimu&sub=investigating&open=disclose:MED-0046'), core: true },
    { name: '63-disclose-told-filled', hash: V('as=rimu&sub=investigating&open=disclose:MED-0046'), steps: `await tile('told'); await tick('ds-p'); await tick('ds-w'); await set('#ds-how','In person with Ben; Tom by phone'); await pickDate('When date', 28, 'September'); await pickTime('When time', '8', '30', 'AM');` },
    // Close the error — both incident paths
    { name: '65-close-no-incident', hash: V('as=pm&sub=actions&open=close:MED-0044'), core: true },
    { name: '66-close-lead-not-closer', hash: V('as=lead&sub=actions&open=done:MED-0045:A-31'), steps: `${doneA31} await go('#/emar/errors?as=lead&sub=actions&open=close:MED-0045');`, core: true },
    { name: '67-close-lead-confirm', hash: V('as=lead&sub=actions&open=done:MED-0045:A-31'), steps: `${doneA31} await go('#/emar/errors?as=lead&sub=actions&open=close:MED-0045'); await set('#cl-note','Two-person dial check ran until the old pen model came back. Aroha and Wiki told.'); fbtn('Close the error').click(); await wait(400);`, core: true },
    { name: '68-close-lead-incident-ready', hash: V('as=lead&sub=actions&open=done:MED-0045:A-31'), steps: `${doneA31} await go('#/emar/errors?as=lead&sub=actions&open=close:MED-0045'); await set('#cl-note','Two-person dial check ran until the old pen model came back. Aroha and Wiki told.'); fbtn('Close the error').click(); await wait(400); fbtn('Close it').click(); await wait(500); await go('#/emar/errors?as=lead&sub=incidents');`, core: true },
    { name: '70-close-closer-path', hash: V('as=pm&sub=actions&open=done:MED-0045:A-31'), steps: `${doneA31} await go('#/emar/errors?as=pm&sub=actions&open=close:MED-0045');`, core: true },
    { name: '71-close-closer-confirm', hash: V('as=pm&sub=actions&open=done:MED-0045:A-31'), steps: `${doneA31} await go('#/emar/errors?as=pm&sub=actions&open=close:MED-0045'); await set('#cl-note','Two-person dial check ran until the old pen model came back. Aroha and Wiki told.'); await set('#cl-outcome','Dose error with moderate harm — dial check added; no further harm.'); fbtn('Close the error').click(); await wait(400);` },
    { name: '72-close-closer-incident-closed', hash: V('as=pm&sub=actions&open=done:MED-0045:A-31'), steps: `${doneA31} await go('#/emar/errors?as=pm&sub=actions&open=close:MED-0045'); await set('#cl-note','Two-person dial check ran until the old pen model came back. Aroha and Wiki told.'); await set('#cl-outcome','Dose error with moderate harm — dial check added; no further harm.'); fbtn('Close the error').click(); await wait(400); fbtn('Close it').click(); await wait(500); await go('#/emar/errors?as=pm&sub=incidents');`, core: true },
    { name: '73-close-reporter-cant', hash: V('as=rimu&open=close:MED-0046'), core: true },
    { name: '74-close-not-ready', hash: V('as=pm&open=close:MED-0046') },
    // Incidents to close
    { name: '76-incclose-error', hash: V('as=pm&sub=incidents&open=incclose:INC-2229'), core: true },
    { name: '77-incclose-confirm', hash: V('as=pm&sub=incidents&open=incclose:INC-2229'), steps: `await set('#ic-outcome','Wrong medicine — packs now stored in time order; no further harm.'); fbtn('Review and close').click(); await wait(400);` },
    { name: '78-incclose-discrepancy', hash: V('as=pm&sub=incidents&open=incclose:INC-2219') },
    { name: '79-incclose-lead-cant', hash: V('as=lead&sub=incidents&open=incclose:INC-2229'), core: true },
    { name: '80-incidents-queue-closer', hash: '#/incidents?tab=awaiting&as=pm', core: true },
    { name: '81-incidents-queue-lead', hash: '#/incidents?tab=awaiting&as=lead' },
    // Reopen, add an account, export
    { name: '82-reopen', hash: V('as=lead&sub=closed&open=reopen:MED-0043'), core: true },
    { name: '83-account', hash: V('open=account:MED-0045') },
    { name: '84-export-lead', hash: V('as=lead&open=export'), core: true },
    { name: '85-export-clinical', hash: V('as=clinical&open=export'), core: true },
    // Settings — the addition to P11
    { name: '86-settings-triage', hash: S('as=clinical'), core: true },
    { name: '87-settings-house-lead-read-only', hash: S('as=lead'), core: true },
    { name: '88-settings-changed', hash: S('as=clinical'), steps: `await click('Within 4 hours');` },
    { name: '89-settings-review-changes', hash: S('as=clinical'), steps: `await click('Within 4 hours'); await click('Review changes');`, core: true },
    { name: '90-settings-keep-default', hash: S('as=clinical&open=p11review:keep') },
    { name: '91-settings-history', hash: '#/emar/settings?view=history&sec=changes&as=clinical' },
    // States
    { name: '93-loading', hash: V('as=lead&scn=loading') },
    { name: '94-empty', hash: V('as=lead&scn=empty'), core: true },
    { name: '95-empty-support-worker', hash: V('scn=empty') },
    { name: '96-couldnt-load', hash: V('as=lead&scn=unavailable'), core: true },
    { name: '97-out-of-date', hash: V('as=lead&scn=stale') },
    { name: '98-offline-banner', hash: V('as=lead&scn=offline') },
    { name: '99-offline-note-not-saved', hash: V('as=lead&scn=offline&sub=investigating&open=note:MED-0047'), steps: `await set('#nt-text','Checked the round list after the alarm — it restarts from the next person.'); fbtn('Add the note').click(); await wait(300);` },
    { name: '100-not-found-ben', hash: V('as=lead&open=error:MED-0046'), core: true },
    { name: '101-support-worker-not-found', hash: V('open=error:MED-0047') },
    { name: '102-auditor-cant-report', hash: V('as=auditor&open=report'), core: true },
    { name: '110-contract-page', hash: '#/p08b/contract', core: true },
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
    await load('#/emar/errors?as=lead');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Jordan focuses “Triage it” on Grace’s MED-0048, opens it with Enter, Tabs through the wizard, Escape (untouched) closes it, focus returns.
    await evaluate(`document.querySelector('[data-return="tri-MED-0048"]').focus();`);
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
    // The menu key on a focused error row opens the same menu as ⋯ and right-click.
    await load('#/emar/errors?as=lead');
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Grace Liu')).focus();`);
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
