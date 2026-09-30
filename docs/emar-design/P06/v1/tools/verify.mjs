// P06 v1 verification harness — P04 v1's harness (itself P03's / P08a's / P07a's / P01's), adapted:
// drives headless Chrome over the DevTools Protocol (Node 22 WebSocket)
// against the running preview (serve.mjs, port 4389). For every state: load
// at 1440×900, 1280×800 and 200 % zoom (720×450 CSS px at device scale 2),
// run the steps, capture a screenshot, and record horizontal overflow,
// console errors, the header subline's line count, truncated meter captions
// and truncated table cells. Also records a real-key keyboard walk through
// the receive wizard from Meds today › Stock alerts. Output: screenshots/*.png + report.json.
//   node docs/emar-design/P06/v1/tools/verify.mjs [--only=name-substring] [--core]
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, '..', 'screenshots');
mkdirSync(outDir, { recursive: true });
const BASE = process.env.P06_URL ?? 'http://127.0.0.1:4389/';
const CHROME = process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9361;
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const profile = process.env.P06_PROFILE ?? path.join(here, '..', '.chrome-profile');

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
const scrollBody = async (y) => { const b = el('[data-wizard-region="body"]') || [...document.querySelectorAll('[role=dialog] .overflow-y-auto')].pop(); if (b) b.scrollTop = y; await wait(250); };
const fbtn = (t) => { const b = [...document.querySelectorAll('[data-wizard-region=footer] button, [role=dialog] button, [role=alertdialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No footer button: ' + t); return b; };
const cont = async () => { fbtn('Continue').click(); await wait(350); };
const section = async (label) => { document.querySelector('[aria-label="' + label + '"]')?.scrollIntoView({ block: 'start' }); await wait(300); };
const check = async (text) => { const l = [...document.querySelectorAll('[role=dialog] label')].find((x) => x.textContent.trim().startsWith(text)); if (!l) throw new Error('No checkbox: ' + text); l.querySelector('button[role=checkbox]').click(); await wait(150); };
const attach = async (name, type = 'image/jpeg') => { const i = [...document.querySelectorAll('[role=dialog] input[type=file]')].pop(); if (!i) throw new Error('No file input'); const dt = new DataTransfer(); dt.items.add(new File(['synthetic'], name, { type })); i.files = dt.files; i.dispatchEvent(new Event('change', { bubbles: true })); await wait(300); };
const confirmIt = async (t) => { const b = [...document.querySelectorAll('[role=alertdialog] button')].find((e) => vis(e) && e.textContent.trim().startsWith(t)); if (!b) throw new Error('No confirm: ' + t); b.click(); await wait(500); };
const rowMenu = async (text, y = 520) => { const r = [...document.querySelectorAll('[role=row]')].find((x) => x.textContent.includes(text)); if (!r) throw new Error('No row: ' + text); r.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 760, clientY: y })); await wait(400); };
`;
const S = '#/emar/stock';
const O = (q) => `${S}?${q}`;
const MT = (q = '') => `#/meds/today?view=stockalerts${q ? `&${q}` : ''}`;
/* Blind count: the harness knows the synthetic expected numbers (the page doesn’t show them until step 2). */
const EXPECTED = { metformin: 68, losartan: 5, insulin: 3, levetiracetam: 62, loratadine: 9, amoxicillin: 11, 'paracetamol-mele': 12, sertraline: 28, levothyroxine: 60, melatonin: 20, citalopram: 5, salbutamol: 1 };
const fillCount = (over = {}) => Object.entries({ ...EXPECTED, ...over }).map(([k, v]) => `await set('#ct-st-${k}', '${v}');`).join(' ');
const showExpected = `fbtn('Show what was expected').click(); await wait(350);`;
const cefalexinToPhoto = `await cont(); await set('#rc-qty-p0','21'); await check('The pharmacy label'); await cont();`;

const SHOTS = [
    // Stock & controlled drugs › Stock
    { name: '01-stock-house-lead', hash: O('as=lead'), core: true },
    { name: '02-stock-needs-attention', hash: O('as=lead&show=attention') },
    { name: '03-stock-running-low', hash: O('as=lead&show=low') },
    { name: '04-stock-self-managed', hash: O('as=lead&show=self') },
    { name: '05-stock-row-menu', hash: O('as=lead'), steps: `await rowMenu('Losartan');`, core: true },
    { name: '06-stock-manager-two-houses', hash: O('as=pm'), core: true },
    { name: '07-stock-clinical-no-cd', hash: O('as=clinical'), core: true },
    { name: '08-stock-support-worker', hash: S, core: true },
    { name: '09-stock-auditor', hash: O('as=auditor') },
    { name: '10-stock-rimu-house', hash: O('as=rimu') },
    { name: '11-stock-recent-movements', hash: O('as=lead'), steps: `await section('Recent movements');` },
    { name: '12-orders', hash: O('as=lead&view=orders'), core: true },
    { name: '13-orders-clinical-read-only', hash: O('as=clinical&view=orders') },
    { name: '14-counts', hash: O('as=lead&view=counts'), core: true },
    { name: '15-expiring', hash: O('as=lead&view=expiring'), core: true },
    { name: '16-removals', hash: O('as=lead&view=removals'), core: true },
    { name: '17-controlled-link-only', hash: O('as=lead&view=controlled') },
    // Meds today › Stock alerts (frontline)
    { name: '18-stock-alerts-support-worker', hash: MT(), core: true },
    { name: '19-stock-alerts-house-lead', hash: MT('as=lead'), core: true },
    { name: '20-meds-today-schedule-link-only', hash: '#/meds/today' },
    // The stock item
    { name: '21-item-packs', hash: O('as=lead&open=item:st-levetiracetam'), steps: `await click('Packs');`, core: true },
    { name: '22-item-pack-photos', hash: O('as=lead&open=item:st-levetiracetam'), steps: `await click('Pack photos');`, core: true },
    { name: '23-item-this-medicine', hash: O('as=lead&open=item:st-metformin') },
    { name: '24-item-movements', hash: O('as=lead&open=item:st-levetiracetam'), steps: `await click('Movements');` },
    { name: '25-item-controlled', hash: O('as=lead&open=item:st-methylphenidate') },
    // Receive a delivery
    { name: '30-receive-what-arrived', hash: O('as=lead&view=orders&open=receive:PO-1040'), core: true },
    { name: '31-receive-packs-prefilled', hash: O('as=lead&view=orders&open=receive:PO-1040'), steps: `await cont();`, core: true },
    { name: '32-receive-packs-validation', hash: O('as=lead&view=orders&open=receive:PO-1040'), steps: `await cont(); await cont();`, core: true },
    { name: '33-receive-photo-prompt', hash: O('as=lead&view=orders&open=receive:PO-1040'), steps: cefalexinToPhoto, core: true },
    { name: '34-receive-review', hash: O('as=lead&view=orders&open=receive:PO-1040'), steps: `${cefalexinToPhoto} await attach('cefalexin-pack.jpg'); await cont();`, core: true },
    { name: '35-receive-saved', hash: O('as=lead&view=orders&open=receive:PO-1040'), steps: `${cefalexinToPhoto} await attach('cefalexin-pack.jpg'); await cont(); fbtn('Receive it').click(); await wait(1200);`, core: true },
    { name: '36-receive-part-delivery', hash: O('as=lead&view=orders&open=receive:PO-1039'), steps: `await cont(); await set('#rc-batch-p0','LV221'); await set('#rc-exp-p0','05/2027'); await set('#rc-qty-p0','28'); await cont();` },
    { name: '37-receive-expired-refused', hash: O('as=lead&open=receive:new'), steps: `await tile('broughtIn'); await pick('#rc-pid','Tamati James Walker'); await pick('#rc-item','Loratadine'); await set('#rc-from','Tama’s mum, from home'); await cont(); await set('#rc-batch-p0','LR118'); await set('#rc-exp-p0','08/2026'); await set('#rc-qty-p0','5'); await cont();`, core: true },
    { name: '38-receive-short-expiry-reason', hash: O('as=lead&open=receive:new'), steps: `await tile('broughtIn'); await pick('#rc-pid','Tamati James Walker'); await pick('#rc-item','Loratadine'); await set('#rc-from','Tama’s mum, from home'); await cont(); await set('#rc-batch-p0','LR118'); await set('#rc-exp-p0','09/2026'); await set('#rc-qty-p0','5'); await cont();` },
    { name: '39-receive-not-printed', hash: O('as=lead&open=receive:new'), steps: `await tile('broughtIn'); await pick('#rc-pid','Hine Rāwiri'); await pick('#rc-item','Salbutamol'); await set('#rc-from','Hine, from home'); await cont(); await check('Not printed on the pack'); await set('#rc-exp-p0','03/2027'); await set('#rc-qty-p0','1');` },
    { name: '40-receive-controlled-register', hash: O('as=lead&view=orders&open=receive:PO-1036'), steps: `await cont(); await set('#rc-qty-p0','30'); await check('The pharmacy label'); await cont(); await cont();`, core: true },
    { name: '41-receive-controlled-support-worker', hash: O('view=orders&open=receive:PO-1036'), core: true },
    // Pharmacy orders
    { name: '45-order-new', hash: O('as=lead&open=order:new:st-omeprazole'), core: true },
    { name: '46-order-part-received', hash: O('as=lead&view=orders&open=po:PO-1039'), core: true },
    { name: '47-order-draft', hash: O('as=lead&view=orders&open=po:PO-1042') },
    { name: '48-order-received-read-only', hash: O('as=lead&view=orders&open=po:PO-1038') },
    { name: '49-order-dispensed', hash: O('as=lead&view=orders&open=dispensed:PO-1041'), core: true },
    { name: '50-order-dispensed-validation', hash: O('as=lead&view=orders&open=dispensed:PO-1041'), steps: `fbtn('Save').click(); await wait(300);` },
    { name: '51-order-cancel-confirm', hash: O('as=lead&view=orders&open=cancel:PO-1041'), steps: `await tile('Ordered by mistake'); fbtn('Cancel the order').click(); await wait(400);`, core: true },
    { name: '52-order-close-short', hash: O('as=lead&view=orders&open=short:PO-1039') },
    // Counts
    { name: '55-count-house-blind', hash: O('view=counts&open=count:house'), core: true },
    { name: '56-count-validation', hash: O('view=counts&open=count:house'), steps: showExpected },
    { name: '57-count-differences', hash: O('view=counts&open=count:house'), steps: `${fillCount({ melatonin: 19 })} ${showExpected}`, core: true },
    { name: '58-count-review', hash: O('view=counts&open=count:house'), steps: `${fillCount({ melatonin: 19 })} ${showExpected} await pick('#ct-why-st-melatonin','Given but not recorded'); await cont();` },
    { name: '59-count-one-matches', hash: O('view=counts&open=count:st-losartan'), steps: `await set('#ct-st-losartan','5'); ${showExpected}` },
    { name: '60-signoff-lead', hash: O('as=lead&view=counts&open=signoff:cnt-12'), core: true },
    { name: '61-signoff-accept', hash: O('as=lead&view=counts&open=signoff:cnt-12'), steps: `await tile('accept');` },
    { name: '62-signoff-support-worker-read-only', hash: O('view=counts&open=signoff:cnt-12') },
    // Adjust or remove
    { name: '65-remove-expired', hash: O('as=lead&view=expiring&open=adjust:st-levothyroxine:lot-lt0712'), core: true },
    { name: '66-remove-confirm', hash: O('as=lead&view=expiring&open=adjust:st-levothyroxine:lot-lt0712'), steps: `fbtn('Remove from stock').click(); await wait(400);`, core: true },
    { name: '67-adjust-validation', hash: O('as=lead&open=adjust:st-paracetamol-mele'), steps: `await tile('damaged'); fbtn('Remove from stock').click(); await wait(300);` },
    // Going out / coming back
    { name: '70-coming-back', hash: MT('open=move:st-levetiracetam:back:mv-1'), core: true },
    { name: '71-coming-back-given-while-out', hash: MT('open=move:st-levetiracetam:back:mv-1'), steps: `await set('#mv-qty','0');` },
    { name: '72-going-out', hash: O('as=lead&open=move:st-metformin'), steps: `await tile('out');` },
    // States, access and not found
    { name: '90-loading', hash: O('as=lead&scn=loading') },
    { name: '91-no-stock-yet', hash: O('as=lead&scn=empty'), core: true },
    { name: '92-couldnt-load', hash: O('as=lead&scn=unavailable'), core: true },
    { name: '93-out-of-date', hash: O('as=lead&scn=stale') },
    { name: '94-offline-banner', hash: O('as=lead&scn=offline') },
    { name: '95-offline-adjust-not-saved', hash: O('as=lead&scn=offline&open=adjust:st-paracetamol-mele'), steps: `await tile('damaged'); await set('#ad-qty','1'); await set('#ad-note','Dropped'); fbtn('Remove from stock').click(); await wait(400);` },
    { name: '96-support-worker-cant-order', hash: O('open=order:new'), core: true },
    { name: '97-not-found-ben', hash: O('as=lead&open=item:st-amlodipine'), core: true },
    { name: '98-not-found-controlled', hash: O('as=clinical&open=item:st-clonazepam') },
    { name: '99-nothing-to-receive', hash: O('as=lead&open=receive:PO-1038') },
    { name: '100-contract-page', hash: '#/p06/contract', core: true },
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
    await load('#/meds/today?view=stockalerts');
    const key = async (k, code, keyCode, mods = 0) => {
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: keyCode, modifiers: mods });
        await sleep(120);
    };
    const who = () => evaluate(`const a=document.activeElement; return (a.getAttribute('aria-label') || a.textContent || a.id || a.tagName).trim().replace(/\\s+/g,' ').slice(0,70);`);
    // Priya focuses “Receive” on the cefalexin delivery, opens it with Enter, Tabs through the wizard, Escape (untouched) closes it, focus returns.
    await evaluate(`document.querySelector('[data-return="rcv-PO-1040"]').focus();`);
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
    // The menu key on a focused stock row opens the same menu as ⋯ and right-click.
    await load('#/emar/stock?as=lead');
    await evaluate(`[...document.querySelectorAll('[role=row][tabindex="0"]')].find(r=>r.textContent.includes('Losartan')).focus();`);
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
