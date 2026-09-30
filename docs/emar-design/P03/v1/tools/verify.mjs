// P03 v1 verification harness — P08a v1's harness (itself P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4387). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the reassessment wizard. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P03/v1/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P03_URL ?? 'http://127.0.0.1:4387/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9359;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P03_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button, [role=dialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const section = async (label) => { document.querySelector('[aria-label="' + label + '"]')?.scrollIntoView({ block: 'start' }); await wait(300); };
const score = async (key, n) => { const b = [...document.querySelectorAll('#as-score-' + key + ' button')][n - 1]; if (!b) throw new Error('No score: ' + key); b.click(); await wait(150); };
const scores = async (n) => { for (const k of ['cognitive','dexterity','vision','swallowing','understanding']) await score(k, n); };
const tg = async (labelledby, text) => { const g = el('[aria-labelledby="' + labelledby + '"]'); const b = [...g.querySelectorAll('button')].find((x) => x.textContent.trim() === text); if (!b) throw new Error('No toggle: ' + text); b.click(); await wait(200); };
const check = async (text) => { const l = [...document.querySelectorAll('[role=dialog] label')].find((x) => x.textContent.trim() === text); if (!l) throw new Error('No checkbox: ' + text); l.querySelector('button[role=checkbox]').click(); await wait(150); };
`;
const R = '#/emar/self-admin';
const rec = (cid, extra = '') => `#/emar/mar?client_id=${cid}&tab=support${extra}`;
const A = '201', T = '202', ME = '203', G = '204', SA = '205', B = '207';

const SHOTS = [
    // The register (MAR & medicines › Support & self-administration)
    { name: '01-register-house-lead', hash: `${R}?as=lead`, core: true },
    { name: '02-register-reassess-now', hash: `${R}?as=lead&show=reassess` },
    { name: '03-register-no-assessment', hash: `${R}?as=lead&show=none` },
    { name: '04-register-self-managed-filter', hash: `${R}?as=lead&sup=selfmanaged` },
    { name: '05-register-row-menu', hash: `${R}?as=lead`, steps: `const r=[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('Grace Liu')); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:760,clientY:520})); await wait(400);`, core: true },
    { name: '06-register-manager-two-houses', hash: `${R}?as=pm`, core: true },
    { name: '07-register-clinical-no-cd', hash: `${R}?as=clinical`, core: true },
    { name: '08-register-support-worker', hash: `${R}`, core: true },
    { name: '09-register-auditor', hash: `${R}?as=auditor` },
    { name: '10-register-rimu-house', hash: `${R}?as=rimu` },
    { name: '11-register-recent-changes', hash: `${R}?as=lead`, steps: `await section('Recent changes');` },
    // Support plan › By medicine
    { name: '20-aroha-asked-staff-to-do-more', hash: rec(A, '&as=lead'), core: true },
    { name: '21-tama-asked-to-do-more', hash: rec(T, '&as=lead'), core: true },
    { name: '22-mele-no-assessment', hash: rec(ME, '&as=lead'), core: true },
    { name: '23-grace-back-from-hospital', hash: rec(G, '&as=lead'), core: true },
    { name: '24-sam-review-date-passed', hash: rec(SA, '&as=lead'), core: true },
    { name: '25-grace-clinical-lead-concealed', hash: rec(G, '&as=clinical'), core: true },
    { name: '26-aroha-support-worker', hash: rec(A), core: true },
    { name: '27-by-medicine-row-menu', hash: rec(SA, '&as=lead'), steps: `const r=[...document.querySelectorAll('[role=row]')].find(x=>x.textContent.includes('Melatonin')); r.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:760,clientY:620})); await wait(400);` },
    { name: '28-support-filter', hash: rec(A, '&as=lead&sup=administer') },
    // Assessment
    { name: '30-aroha-assessment', hash: rec(A, '&as=lead&view=assessment'), core: true },
    { name: '31-mele-assessment-empty', hash: rec(ME, '&as=lead&view=assessment') },
    { name: '32-earlier-assessment', hash: rec(A, '&as=lead&view=assessment&open=assessment:as-aroha-2025') },
    { name: '33-sam-assessment-overdue', hash: rec(SA, '&as=lead&view=assessment') },
    // Agreement and changes
    { name: '35-sam-agreement-signed', hash: rec(SA, '&as=lead&view=agreement'), core: true },
    { name: '36-aroha-agreement-verbal', hash: rec(A, '&as=lead&view=agreement') },
    { name: '37-grace-agreement-guardian', hash: rec(G, '&as=lead&view=agreement') },
    { name: '38-tama-agreement-not-needed', hash: rec(T, '&as=lead&view=agreement') },
    { name: '40-aroha-changes', hash: rec(A, '&as=lead&view=changes') },
    { name: '41-grace-changes-concealed', hash: rec(G, '&as=clinical&view=changes'), core: true },
    // Assess or reassess
    { name: '50-reassess-why-now', hash: rec(G, '&as=lead&open=assess:grace'), core: true },
    { name: '51-new-assessment-validation', hash: rec(ME, '&as=lead&open=assess:mele'), steps: `await cont();`, core: true },
    { name: '52-what-they-can-do', hash: rec(ME, '&as=lead&open=assess:mele'), steps: `await tile('yes'); await check('The person'); await cont(); await scores(4); await scrollBody(0);` },
    { name: '53-scores-validation', hash: rec(ME, '&as=lead&open=assess:mele'), steps: `await tile('yes'); await check('The person'); await cont(); await score('cognitive', 4); await cont();` },
    { name: '54-support-each-medicine', hash: rec(SA, '&as=lead&open=assess:sam'), steps: `await cont(); await score('understanding', 2); await score('cognitive', 2); await cont();`, core: true },
    { name: '55-controlled-limited', hash: rec(A, '&as=lead&open=assess:aroha'), steps: `await cont(); await cont(); await scrollBody(300);` },
    { name: '56-storage-next-review', hash: rec(T, '&as=lead&open=assess:tama'), steps: `await cont(); await cont(); await cont();` },
    { name: '57-review-loosens', hash: rec(T, '&as=lead&open=assess:tama'), steps: `await cont(); await scores(4); await check('Takes it at the right times'); await check('Can read and follow the label'); await cont(); await tg('as-sup-macrogol-l','Prompt'); await cont(); await cont();`, core: true },
    { name: '58-saved-record-agreement-next', hash: rec(T, '&as=lead&open=assess:tama'), steps: `await cont(); await scores(4); await check('Takes it at the right times'); await check('Can read and follow the label'); await cont(); await tg('as-sup-macrogol-l','Prompt'); await cont(); await cont(); fbtn('Save reassessment').click();`, after: 1200, core: true },
    { name: '59-discard-guard', hash: rec(G, '&as=lead&open=assess:grace'), steps: `await cont(); await click('Back'); await click('Cancel');` },
    { name: '60-clinical-lead-reassess', hash: rec(G, '&as=clinical&open=assess:grace'), steps: `await cont(); await cont();`, core: true },
    // Agreement dialog
    { name: '65-agreement-who', hash: rec(SA, '&as=lead&view=agreement&open=agreement:sam'), core: true },
    { name: '66-agreement-validation', hash: rec(SA, '&as=lead&view=agreement&open=agreement:sam'), steps: `await tile('signed'); await cont();` },
    { name: '67-agreement-verbal-witness', hash: rec(SA, '&as=lead&view=agreement&open=agreement:sam'), steps: `await tile('verbal'); await pick('#ag-witness','Priya Shah');` },
    { name: '68-agreement-terms', hash: rec(SA, '&as=lead&view=agreement&open=agreement:sam'), steps: `await tile('verbal'); await pick('#ag-witness','Priya Shah'); await cont();` },
    { name: '69-agreement-review', hash: rec(SA, '&as=lead&view=agreement&open=agreement:sam'), steps: `await tile('verbal'); await pick('#ag-witness','Priya Shah'); await cont(); await cont();`, core: true },
    // One medicine’s support
    { name: '70-more-staff-support', hash: rec(T, '&as=lead&open=support:macrogol'), core: true },
    { name: '71-controlled-choices', hash: rec(A, '&as=lead&open=support:methylphenidate') },
    { name: '72-new-order-loosens-confirm', hash: rec(A, '&as=lead&open=support:omega3'), steps: `await tile('prompt'); await set('#sp-why','Aroha takes it with breakfast when reminded, like her vitamin D used to be.'); await click('Set support');`, core: true },
    { name: '73-new-order-set-support', hash: rec(ME, '&as=lead&open=support:amoxicillin') },
    { name: '74-support-validation', hash: rec(T, '&as=lead&open=support:macrogol'), steps: `await click('Save');` },
    // A change the person asked for
    { name: '80-consent-support-worker', hash: rec(A, '&open=consent:aroha:metformin'), core: true },
    { name: '81-consent-staff-do-more', hash: rec(A, '&open=consent:aroha:metformin'), steps: `await tile('less');` },
    { name: '82-consent-do-more-themselves', hash: rec(T, '&as=lead&open=consent:tama:macrogol'), steps: `await tile('more'); await scrollBody(300);` },
    { name: '83-consent-validation', hash: rec(A, '&open=consent:aroha:metformin'), steps: `await click('Record it'); await scrollBody(0);` },
    { name: '84-consent-offline', hash: rec(SA, '&scn=offline&open=consent:sam:melatonin'), steps: `await tile('less'); await set('#cc-said','Can you give it to me at bedtime? I keep forgetting.'); await click('Record it'); await wait(500);`, core: true },
    { name: '85-after-consent-lowered', hash: rec(SA, '&open=consent:sam:melatonin'), steps: `await tile('less'); await set('#cc-said','Can you give it to me at bedtime? I keep forgetting.'); await click('Record it'); await wait(500);` },
    // Page states and access
    { name: '90-loading', hash: `${R}?as=lead&scn=loading`, core: true },
    { name: '91-no-assessments-yet', hash: `${R}?as=lead&scn=empty`, core: true },
    { name: '92-couldnt-load', hash: `${R}?as=lead&scn=unavailable`, core: true },
    { name: '93-out-of-date', hash: `${R}?as=lead&scn=stale` },
    { name: '94-offline-banner', hash: rec(A, '&scn=offline') },
    { name: '95-support-worker-cant-assess', hash: rec(A, '&open=assess:aroha'), core: true },
    { name: '96-not-found-ben', hash: rec(B, '&as=lead'), core: true },
    { name: '97-not-found-controlled', hash: rec(G, '&as=clinical&open=support:clonazepam') },
    { name: '98-record-loading', hash: rec(A, '&as=lead&scn=loading') },
    { name: '100-contract-page', hash: '#/p03/contract', core: true },
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
    await load('#/emar/self-admin?as=lead');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Focus Grace's "Reassess" in the register, open with Enter, Tab through the wizard, Escape (clean form closes), check focus returns.
    await evaluate(`document.querySelector('[data-return="reg-grace"]').focus();`);
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
    // Shift+F10 (or the menu key) on a focused register row opens the same menu as ⋯ and right-click.
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

writeFileSync(path.join(outDir, only ? `report-${only}.json` : 'report.json'), JSON.stringify(report, null, 2));
const bad = report.shots.filter((r) => r.stepError || r.errors.length || r.overflow > 0);
process.stdout.write(`\n${report.shots.length} captures · ${bad.length} with problems\n`);
ws.close();
chrome.kill();
