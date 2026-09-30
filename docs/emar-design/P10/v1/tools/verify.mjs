// P10 v1 verification harness — P09 v1.1's harness, itself P08b v1.1's harness (itself P07b's / P06's / P04's / P03's / P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4395). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the start-emergency-access wizard, and the menu key on a grant row. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P10/v1/tools/verify.mjs [--only=substring,substring,…] [--core]
// A partial run (--only) replaces just its captures in report.json and notes the re-run there.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P10_URL ?? 'http://127.0.0.1:4395/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9365;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const onlyList = only ? only.split(',').map((x) => x.trim()).filter(Boolean) : null;
const picked = (name) => !onlyList || onlyList.some((x) => name.includes(x));
const profile = process.env.P10_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const A = (q) => `#/emar/emergency-access?${q}`;
const M = (q) => `#/emar/mar?${q}`;
const T = (q) => `#/meds/today?${q}`;
const D = (q) => `#/emar/downtime?${q}`;
const R = (q) => `#/emar/reports?${q}`;
const S = (q) => `#/emar/settings?view=alerts&sec=emergency&${q}`;
const toWhy = `await cont();`;
const why = `await cont(); await tile('cover'); await set('#rq-why','Mele’s support worker went home unwell — her 9:00 am dose is due');`;
const toSecond = `${why} await cont(); await cont();`;
const second = `${toSecond} await tile('ask'); await pick('#rq-who','Hana Kereama'); await set('#rq-pin','482913');`;
const toCheck = `${second} await cont();`;
const ticks = `el('#rq-a1').click(); await wait(150); el('#rq-a2').click(); await wait(200);`;
const rqPin = `${toSecond} await pick('#rq-who','Hana Kereama'); await set('#rq-pin','482913');`;
const reviewFill = `await tile('justified'); await set('#rv-notes','Reasonable cover — the roster had no one signed off that afternoon.');`;
const endFill = `await set('#en-why','Mere Kahu is on shift now and can give Aroha’s doses.');`;
const paperOwn = `await tile('given'); await pickDate('Time on the paper date', 19, 'September'); await pickTime('Time on the paper time', '10', '05', 'AM'); await pick('#pe-by','Sione Taufa');`;

const SHOTS = [
    // Emergency access — the page (Q1, Q5, Q7)
    { name: '01-running-rangi', hash: A('as=pm'), core: true },
    { name: '02-running-row-menu', hash: A('as=pm'), steps: `await rowMenu('Aroha Mere Ngata', 560);`, core: true },
    { name: '03-running-hana-read-only', hash: A('as=clinical&view=active'), core: true },
    { name: '04-to-review-hana', hash: A('as=clinical'), core: true },
    { name: '05-to-review-rangi-own', hash: A('as=pm&view=review'), core: true },
    { name: '06-history-auditor', hash: A('as=auditor&view=history'), core: true },
    { name: '07-history-repeat-use-filter', hash: A('as=clinical&view=history&show=flagged') },
    { name: '08-ending-soon', hash: A('as=pm&scn=ending'), core: true },
    { name: '09-not-available-house-lead', hash: A('as=lead'), core: true },
    { name: '10-empty', hash: A('as=clinical&scn=empty'), core: true },
    { name: '11-loading', hash: A('as=pm&scn=loading') },
    { name: '12-couldnt-load', hash: A('as=pm&scn=unavailable'), core: true },
    { name: '13-out-of-date', hash: A('as=pm&scn=stale') },
    { name: '14-coordinator', hash: A('as=coord') },
    // Start emergency access (Q3, Q4)
    { name: '20-request-from-mar', hash: M('as=pm&person=mele'), steps: `await click('Start emergency access');`, core: true },
    { name: '21-request-person-fixed', hash: A('as=pm&open=request:mele'), core: true },
    { name: '22-request-why', hash: A('as=pm&open=request:mele'), steps: why, core: true },
    { name: '23-request-why-validation', hash: A('as=pm&open=request:mele'), steps: `${toWhy} await cont();` },
    { name: '24-request-length', hash: A('as=pm&open=request:mele'), steps: `${why} await cont();`, core: true },
    { name: '25-request-second-optional', hash: A('as=pm&open=request:mele'), steps: second, core: true },
    { name: '26-request-pin-wrong', hash: A('as=pm&open=request:mele'), steps: `${toSecond} await tile('ask'); await pick('#rq-who','Hana Kereama'); await set('#rq-pin','000000'); await cont();`, core: true },
    { name: '27-request-check', hash: A('as=pm&open=request:mele'), steps: toCheck, core: true },
    { name: '28-request-ticks-validation', hash: A('as=pm&open=request:mele'), steps: `${toCheck} fbtn('Start emergency access').click(); await wait(300);` },
    { name: '29-request-started', hash: A('as=pm&open=request:mele'), steps: `${toCheck} ${ticks} fbtn('Start emergency access').click(); await wait(900);`, core: true },
    { name: '29b-started-running-now', hash: A('as=pm&open=request:mele'), steps: `${toCheck} ${ticks} fbtn('Start emergency access').click(); await wait(900); fbtn('Done').click(); await wait(500);`, core: true },
    { name: '30-request-required-nobody', hash: A('as=pm&scn=required&open=request:mele'), steps: `${toSecond} await click('Nobody who can confirm is here');`, core: true },
    { name: '31-request-required-no-oncall', hash: A('as=pm&scn=oncallnone&open=request:mele'), steps: `${toSecond} await click('Nobody who can confirm is here');`, core: true },
    { name: '31b-request-required-pin', hash: A('as=pm&scn=required&open=request:mele'), steps: rqPin },
    { name: '32-request-duplicate', hash: A('as=pm&open=request:aroha'), core: true },
    { name: '33-request-offline', hash: A('as=pm&scn=offline&open=request:mele'), core: true },
    { name: '34-request-choose-person', hash: A('as=pm&open=request') },
    { name: '35-request-discard', hash: A('as=pm&open=request'), steps: `await tile('mele'); fbtn('Cancel').click(); await wait(400);` },
    { name: '36-request-logdown', hash: A('as=pm&scn=logdown&open=request:mele'), steps: `${toCheck} ${ticks} fbtn('Start emergency access').click(); await wait(400);` },
    // One grant, review, correction (Q6)
    { name: '40-grant-ea13-rangi', hash: A('as=pm&open=grant:EA-13'), core: true },
    { name: '41-grant-ea8-not-justified', hash: A('as=clinical&view=history&open=grant:EA-8'), core: true },
    { name: '42-review-ea11-hana', hash: A('as=clinical&open=review:EA-11'), core: true },
    { name: '43-review-not-justified-validation', hash: A('as=clinical&open=review:EA-11'), steps: `await tile('notJustified'); fbtn('Save the review').click(); await wait(300);`, core: true },
    { name: '44-review-saved', hash: A('as=clinical&open=review:EA-11'), steps: `${reviewFill} fbtn('Save the review').click(); await wait(600);`, core: true },
    { name: '45-review-own-blocked', hash: A('as=pm&view=review&open=review:EA-11'), core: true },
    { name: '46-review-confirmed-blocked', hash: A('as=clinical&view=active&open=review:EA-13'), core: true },
    { name: '47-correct-ea8-tomasi', hash: A('as=coord&view=history&open=correct:EA-8'), core: true },
    { name: '48-correct-saved', hash: A('as=coord&view=history&open=correct:EA-8'), steps: `await set('#rv-why','The roster shows no one else was on shift that evening'); await tile('justified'); fbtn('Save the correction').click(); await wait(500); await go('#/emar/emergency-access?as=coord&view=history&open=grant:EA-8');`, core: true },
    { name: '49-review-logdown', hash: A('as=clinical&scn=logdown&open=review:EA-11'), steps: `${reviewFill} fbtn('Save the review').click(); await wait(400);`, core: true },
    { name: '49b-review-already-done', hash: A('as=clinical&view=history&open=review:EA-10') },
    // Extend and end (Q5)
    { name: '50-extend-ending', hash: A('as=pm&scn=ending&open=extend:EA-13'), core: true },
    { name: '51-extend-validation', hash: A('as=pm&scn=ending&open=extend:EA-13'), steps: `fbtn('Extend to').click(); await wait(300);` },
    { name: '52-extended', hash: A('as=pm&scn=ending&open=extend:EA-13'), steps: `await set('#ex-why','Relief hasn’t arrived yet'); fbtn('Extend to').click(); await wait(600);`, core: true },
    { name: '53-extend-not-yet', hash: A('as=pm&open=extend:EA-13'), core: true },
    { name: '54-done-confirm', hash: A('as=pm&open=done:EA-13'), core: true },
    { name: '55-done-ended', hash: A('as=pm&open=done:EA-13'), steps: `fbtn('End it now').click(); await wait(600);`, core: true },
    { name: '56-end-by-hana', hash: A('as=clinical&view=active&open=end:EA-13'), core: true },
    { name: '57-end-validation', hash: A('as=clinical&view=active&open=end:EA-13'), steps: `fbtn('End their access').click(); await wait(300);` },
    { name: '58-end-confirm', hash: A('as=clinical&view=active&open=end:EA-13'), steps: `${endFill} fbtn('End their access').click(); await wait(400);`, core: true },
    { name: '59-ended-by-hana', hash: A('as=clinical&view=active&open=end:EA-13'), steps: `${endFill} fbtn('End their access').click(); await wait(400); [...document.querySelectorAll('[role=alertdialog] button')].find((b) => b.textContent.trim() === 'End their access').click(); await wait(600); await go('#/emar/emergency-access?as=clinical&view=review');`, core: true },
    { name: '60-end-auditor-cant', hash: A('as=auditor&view=active&open=end:EA-13'), core: true },
    // Repeat use and the history export (Q7, Q8)
    { name: '61-ack', hash: A('as=clinical&view=history&open=ack:F-3'), core: true },
    { name: '62-ack-saved', hash: A('as=clinical&view=history&open=ack:F-3'), steps: `await set('#ak-why','Talked with Rangi — the roster gaps are being fixed this week.'); fbtn('Acknowledge').click(); await wait(600);`, core: true },
    { name: '63-ack-own-cant', hash: A('as=pm&view=history&open=ack:F-3'), core: true },
    { name: '64-export-history', hash: A('as=auditor&view=history&open=export:ea'), core: true },
    { name: '65-export-history-hana-cant', hash: A('as=clinical&view=history&open=export:ea') },
    // The person’s chart (P02 frame) and the record dialog (P01 frame)
    { name: '70-mar-aroha-rangi-strip', hash: M('as=pm&person=aroha'), core: true },
    { name: '71-mar-mele-rangi-blocked', hash: M('as=pm&person=mele'), core: true },
    { name: '72-mar-aroha-priya-blocked', hash: M('as=sw&person=aroha'), core: true },
    { name: '73-mar-aroha-hana-blocked', hash: M('as=clinical&person=aroha') },
    { name: '74-record-under-grant', hash: M('as=pm&person=aroha&open=record:aroha:o-insulin'), core: true },
    { name: '75-recorded-under-grant', hash: M('as=pm&person=aroha&open=record:aroha:o-insulin'), steps: `await tile('given'); fbtn('Save').click(); await wait(600);`, core: true },
    { name: '76-record-expired', hash: M('as=pm&person=aroha&scn=expired&open=record:aroha:o-insulin'), steps: `await tile('given'); fbtn('Save').click(); await wait(400);`, core: true },
    { name: '77-record-expired-restart', hash: M('as=pm&person=aroha&scn=expired&open=record:aroha:o-insulin'), steps: `await tile('given'); fbtn('Save').click(); await wait(400); await click('Start it again'); await wait(300);`, core: true },
    { name: '78-mar-expired', hash: M('as=pm&person=aroha&scn=expired'), core: true },
    { name: '79-mar-ending', hash: M('as=pm&person=aroha&scn=ending') },
    { name: '80-request-link-house-lead', hash: M('as=lead&person=aroha&open=request:aroha'), core: true },
    { name: '81-record-offline-saved-on-device', hash: M('as=pm&person=aroha&scn=offline&open=record:aroha:o-insulin'), steps: `await tile('given'); fbtn('Save').click(); await wait(600);` },
    { name: '82-record-controlled-witness', hash: M('as=pm&person=aroha&open=record:aroha:o-methylphenidate') },
    // Meds today (P01 frame)
    { name: '85-today-offline-no-pack', hash: T('as=sw&scn=offline'), core: true },
    { name: '86-today-offline-with-pack', hash: R('as=lead&view=exports&open=export:pack'), steps: `fbtn('Make the pack').click(); await wait(500); location.hash = '#/meds/today?as=lead&scn=offline'; await wait(700);`, core: true },
    { name: '87-today-stale', hash: T('as=lead&scn=stale'), core: true },
    { name: '88-today-couldnt-load', hash: T('as=lead&scn=unavailable') },
    { name: '89-today-sione-paper-records', hash: T('as=rimu'), core: true },
    { name: '90-today-not-on-shift', hash: T('as=pm') },
    // Downtime & paper records (Q11)
    { name: '91-downtime-list-sione', hash: D('as=rimu'), core: true },
    { name: '92-downtime-dt4', hash: D('as=rimu&dt=DT-4'), core: true },
    { name: '93-paper-enter-own', hash: D('as=rimu&dt=DT-4&open=paper:PI-3'), core: true },
    { name: '94-paper-enter-validation', hash: D('as=rimu&dt=DT-4&open=paper:PI-3'), steps: `fbtn('Enter it').click(); await wait(300);`, core: true },
    { name: '95-paper-time-outside', hash: D('as=rimu&dt=DT-4&open=paper:PI-3'), steps: `await tile('given'); await pickDate('Time on the paper date', 19, 'September'); await pickTime('Time on the paper time', '11', '30', 'AM'); await pick('#pe-by','Sione Taufa'); fbtn('Enter it').click(); await wait(300);` },
    { name: '96-paper-entered-own', hash: D('as=rimu&dt=DT-4&open=paper:PI-3'), steps: `${paperOwn} fbtn('Enter it').click(); await wait(600);`, core: true },
    { name: '97-paper-enter-for-ana', hash: D('as=rimu&dt=DT-4&open=paper:PI-2'), steps: `await tile('given'); await pick('#pe-by','Ana Lemalu');`, core: true },
    { name: '98-paper-entered-for-ana', hash: D('as=rimu&dt=DT-4&open=paper:PI-2'), steps: `await tile('given'); await pickDate('Time on the paper date', 19, 'September'); await pickTime('Time on the paper time', '09', '15', 'AM'); await pick('#pe-by','Ana Lemalu'); fbtn('Enter it').click(); await wait(600);`, core: true },
    { name: '99-paper-add', hash: D('as=rimu&dt=DT-4&open=paperadd:DT-4'), core: true },
    { name: '100-declare', hash: D('as=lead&open=declare'), core: true },
    { name: '101-declare-validation', hash: D('as=lead&open=declare'), steps: `fbtn('Record the downtime').click(); await wait(300);` },
    { name: '102-downtime-lead-empty', hash: D('as=lead'), core: true },
    { name: '103-downtime-support-worker', hash: D('as=sw') },
    { name: '104-finish-not-yet', hash: D('as=rimu&dt=DT-4&open=finish:DT-4') },
    // Reports & audit (P09 frame): events and the pack
    { name: '110-audit-access-events', hash: R('as=pm&view=audit&kind=access'), core: true },
    { name: '111-audit-access-hana', hash: R('as=clinical&view=audit&kind=access') },
    { name: '112-audit-downtime-events', hash: R('as=pm&view=audit&kind=downtime') },
    { name: '113-audit-event-ea13', hash: R('as=pm&view=audit&open=event:E-EA-13-open'), core: true },
    { name: '114-exports-house-lead', hash: R('as=lead&view=exports'), core: true },
    { name: '115-pack-house-lead', hash: R('as=lead&view=exports&open=export:pack'), core: true },
    { name: '116-pack-hana-no-controlled', hash: R('as=clinical&view=exports&open=export:pack'), core: true },
    { name: '117-pack-offline', hash: R('as=lead&view=exports&scn=offline&open=export:pack'), steps: `fbtn('Make the pack').click(); await wait(300);`, core: true },
    { name: '118-pack-made-in-audit', hash: R('as=lead&view=exports&open=export:pack'), steps: `fbtn('Make the pack').click(); await wait(500); location.hash = '#/emar/reports?as=pm&view=audit&sub=exports'; await wait(700);`, core: true },
    { name: '119-pack-auditor-cant', hash: R('as=auditor&view=exports&open=export:pack') },
    // Settings (P11 frame) — P10’s additions
    { name: '120-settings-ea-rangi', hash: S('as=pm'), core: true },
    { name: '121-settings-ea-hana-read-only', hash: S('as=clinical'), core: true },
    { name: '122-settings-required-review', hash: S('as=pm'), steps: `await click('Required'); await click('Review changes');`, core: true },
    { name: '123-settings-saved', hash: S('as=pm'), steps: `await click('Required'); await click('Review changes'); fbtn('Save changes').click(); await wait(500);`, core: true },
    { name: '124-settings-keep-default', hash: S('as=pm&open=p11review:keep-second') },
    { name: '125-settings-auditor', hash: S('as=auditor') },
    // The contract, and not found
    { name: '130-contract-page', hash: '#/p10/contract', core: true },
    { name: '131-not-found-grant', hash: A('as=pm&open=grant:EA-99') },
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
    await load('#/emar/mar?as=pm&person=mele');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Rangi focuses “Start emergency access” on Mele’s chart, opens it with Enter, Tabs through the wizard, Escape closes it (untouched), focus returns.
    await evaluate(`document.querySelector('[data-return="request-mele"]').focus();`);
    const trail = [await who()];
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: String.fromCharCode(13), unmodifiedText: String.fromCharCode(13) });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await sleep(700);
    for (let i = 0; i < 10; i++) {
        await key('Tab', 'Tab', 9);
        trail.push(await who());
    }
    await key('Escape', 'Escape', 27);
    await sleep(500);
    const afterEscape = await who();
    const dialogOpen = await evaluate(`return !!document.querySelector('[role=dialog]');`);
    // The menu key on a focused grant row opens the same menu as ⋯ and right-click.
    await load('#/emar/emergency-access?as=pm');
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
