// Two-person browser call against the disposable preview, the loopback engine and the
// development gateway. Chromium's fake audio device supplies a test tone; nothing is recorded.
// Usage: node docs/chat-experience/qa/call-e2e.mjs [http://127.0.0.1:8767]
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const origin = process.argv[2] ?? 'http://127.0.0.1:8767';
const password = 'Chat-preview-only-2026!';
const out = path.join(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Z]:)/, '$1')), 'runtime', 'call-e2e');
fs.mkdirSync(out, { recursive: true });
const log = (...parts) => console.log(new Date().toISOString().slice(11, 19), ...parts);

const browser = await chromium.launch({
    headless: true,
    // The local engine only offers loopback ICE candidates, which Chromium ignores by default.
    args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required',
        '--allow-loopback-in-peer-connection', '--disable-features=WebRtcHideLocalIpsWithMdns'],
});
async function person(email) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, permissions: ['microphone'] });
    const page = await context.newPage();
    page.on('console', (message) => (message.type() === 'error' || /livekit|ice|peer|rtc|room|signal|disconnect/i.test(message.text()))
        && !/webrtc stats|signal connect/.test(message.text()) && log(email.split('@')[0], message.type(), message.text().slice(0, 160)));
    page.on('response', (response) => {
        if (!/\/chat\/api\/calls\/[^/]+\/(connected|join)/.test(response.url())) return;
        log(email.split('@')[0], 'api', response.request().method(), new URL(response.url()).pathname.replace(/[0-9a-f-]{36}/, '{call}'), response.status());
        // The client reports /connected only after its media connection is established.
        if (response.url().endsWith('/connected') && response.status() === 204) live.add(email);
    });
    await page.goto(`${origin}/login`);
    await page.getByLabel('Email address').fill(email);
    await page.keyboard.press('Enter');
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 60000 });
    await page.waitForLoadState('networkidle');
    page.actor = await page.evaluate(() => (history.state?.page ?? JSON.parse(document.getElementById('app')?.dataset.page || '{}')).props?.auth?.user?.id);
    if (!page.actor) throw new Error(`No signed-in actor for ${email}`);
    return page;
}
const calls = (page) => page.evaluate(async (actor) => {
    const response = await fetch('/chat/api/calls', { headers: { Accept: 'application/json', 'X-Chat-Actor': String(actor) } });
    return response.json();
}, page.actor);
const shot = (page, name) => page.screenshot({ path: path.join(out, `${name}.png`) });

let failed = false;
const live = new Set();
try {
    const worker = await person('worker@chat-preview.test');
    await worker.goto(`${origin}/chat`);
    await worker.waitForLoadState('networkidle');
    // The provider's first presence heartbeat is after 15 s; send one now so the worker is callable.
    log('worker heartbeat', await worker.evaluate(async (actor) => {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).slice(11));
        return (await fetch('/chat/api/availability', { method: 'PATCH', credentials: 'same-origin', body: '{}',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf, 'X-Chat-Actor': String(actor) } })).status;
    }, worker.actor));
    log('worker signed in and on Chat');
    const family = await person('family@chat-preview.test');
    await family.waitForLoadState('networkidle');
    log('family signed in at', new URL(family.url()).pathname);

    await family.getByRole('button', { name: 'Call care team' }).first().click();
    const proceed = family.getByRole('button', { name: 'Continue' });
    if (await proceed.isVisible({ timeout: 3000 }).catch(() => false)) await proceed.click();
    await family.getByRole('button', { name: 'Check microphone' }).click();
    await family.waitForTimeout(3000);
    await shot(family, '01-family-preflight');
    await family.getByRole('dialog').getByRole('button', { name: 'Call care team' }).click();
    log('family started the care-team call');

    const answer = worker.getByRole('button', { name: /^Answer/ }).first();
    await answer.waitFor({ timeout: 30000 });
    await shot(worker, '02-worker-incoming');
    log('worker sees the incoming call');
    await answer.click();
    const check = worker.getByRole('button', { name: 'Check microphone' });
    if (await check.isVisible({ timeout: 3000 }).catch(() => false)) {
        await check.click();
        await worker.waitForTimeout(3000);
        await worker.getByRole('button', { name: /^Answer|Join call/ }).first().click();
    }
    for (let i = 0; i < 20; i++) {
        const [a, b] = [await calls(worker), await calls(family)];
        const statusA = a.calls?.[0]?.status;
        const statusB = b.calls?.[0]?.status;
        log('call status', { worker: statusA, family: statusB, media_connected: [...live].map((e) => e.split('@')[0]) });
        if (statusA === 'active' && statusB === 'active' && live.size === 2) break;
        await worker.waitForTimeout(1500);
    }
    await worker.waitForTimeout(4000);
    await shot(worker, '03-worker-connected');
    await shot(family, '04-family-connected');
    const final = await calls(worker);
    const people = final.calls?.[0]?.participants?.map((p) => `${p.name ?? p.user_id}:${p.status}`) ?? [];
    log('participants', people);
    if (final.calls?.[0]?.status !== 'active' || live.size !== 2) failed = true;

    const mute = family.getByRole('button', { name: /^Mute/ }).first();
    if (await mute.isVisible().catch(() => false)) {
        await mute.click();
        await family.waitForTimeout(1000);
        log('family muted:', await family.getByRole('button', { name: /^Unmute/ }).first().isVisible().catch(() => false));
    }
    const leave = family.getByRole('button', { name: /^(Leave|End|Hang up)/ }).first();
    await leave.click();
    const confirm = family.getByRole('button', { name: /^(End call|Leave call)/ });
    if (await confirm.isVisible({ timeout: 2000 }).catch(() => false)) await confirm.click();
    await family.waitForTimeout(4000);
    await shot(family, '05-family-ended');
    await shot(worker, '06-worker-after');
    const after = (await calls(worker)).calls ?? [];
    log('after leaving, open calls for worker:', after.length);
    if (after.length) failed = true;
} catch (error) {
    failed = true;
    log('FAILED:', error.message.split('\n')[0]);
    for (const [index, context] of browser.contexts().entries()) {
        for (const page of context.pages()) await page.screenshot({ path: path.join(out, `error-${index}.png`) }).catch(() => {});
    }
} finally {
    await browser.close();
}
log(failed ? 'RESULT: FAIL' : 'RESULT: PASS', `screenshots in ${out}`);
process.exit(failed ? 1 : 0);
