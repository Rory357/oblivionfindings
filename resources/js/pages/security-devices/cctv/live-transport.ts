import {
    recordingBase,
    recordingListHref,
    recordingRecoveryBase,
} from './_recording-contract';
import { sourceBase, sourceHref } from './_source-contract';
import { type CctvSession, cctvUuid } from './live-contract';

function csrf(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));
    const token = cookie
        ? decodeURIComponent(cookie.slice(11))
        : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
              ?.content;
    if (!token) throw Error('Sign in again before viewing a camera.');
    return cookie ? { 'X-XSRF-TOKEN': token } : { 'X-CSRF-TOKEN': token };
}
function allowedPath(path: string) {
    return (
        /^\/security-devices\/devices\/[1-9]\d*\/live-sessions$/.test(path) ||
        /^\/security-devices\/cctv\/live-sessions\/[0-9a-f-]{36}(?:\/(?:offer|renew|stop)|\?expected_actor_id=[1-9]\d*)$/.test(
            path,
        )
    );
}
export async function cctvJson(
    path: string,
    signal: AbortSignal,
    body?: unknown,
): Promise<unknown> {
    if (!allowedPath(path)) throw Error('Camera destination changed.');
    return cameraJsonRequest(path, signal, body, 4000);
}
export async function cctvSourceJson(
    camera: number,
    path: string,
    signal: AbortSignal,
    body?: unknown,
): Promise<unknown> {
    const read = body === undefined;
    if (
        read
            ? !sourceHref(path, camera)
            : !(
                  path === sourceBase(camera) ||
                  sourceHref(path, camera, 'enable-stream') ||
                  sourceHref(path, camera, 'disable')
              )
    )
        throw Error('Camera setup destination changed.');
    return cameraJsonRequest(path, signal, body, 20000);
}
export async function cctvRecordingJson(
    camera: number,
    actor: number,
    path: string,
    signal: AbortSignal,
    body?: unknown,
): Promise<unknown> {
    const recoveryPrefix = recordingRecoveryBase + '/';
    const recovery = path.startsWith(recoveryPrefix)
        ? path.slice(recoveryPrefix.length).split('?')
        : null;
    const allowed =
        body === undefined
            ? recordingListHref(path, camera) ||
              (recovery?.length === 2 &&
                  cctvUuid(recovery[0]) &&
                  recovery[1] === 'expected_actor_id=' + actor)
            : path === recordingBase(camera);
    if (
        !Number.isSafeInteger(actor) ||
        actor < 1 ||
        !Number.isSafeInteger(camera) ||
        camera < 1 ||
        !allowed
    )
        throw Error('Recording reference destination changed.');
    return cameraJsonRequest(path, signal, body, 10000);
}
export async function hikvisionJson(
    camera: number,
    actor: number,
    path: string,
    signal: AbortSignal,
    body?: unknown,
): Promise<unknown> {
    const { hikPath } = await import('./_hikvision-contract');
    if (!hikPath(camera, actor, path, body !== undefined))
        throw Error('Camera setup destination changed.');
    return cameraJsonRequest(path, signal, body, 20000, 262144);
}
async function cameraJsonRequest(
    path: string,
    signal: AbortSignal,
    body: unknown,
    timeout: number,
    maximum = 65536,
): Promise<unknown> {
    const controller = new AbortController(),
        abort = () => controller.abort();
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) abort();
    const timer = setTimeout(abort, timeout);
    try {
        const response = await fetch(path, {
            method: body === undefined ? 'GET' : 'POST',
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json', ...csrf() }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (
            !response.ok ||
            !response.headers
                .get('content-type')
                ?.includes('application/json') ||
            !response.body
        )
            throw Error(
                'Camera viewing permission or connection could not be confirmed.',
            );
        const reader = response.body.getReader(),
            chunks: Uint8Array[] = [];
        let size = 0;
        try {
            for (;;) {
                const next = await reader.read();
                if (next.done) break;
                size += next.value.byteLength;
                if (size > maximum) {
                    await reader.cancel();
                    throw Error('Camera reply is unavailable.');
                }
                chunks.push(next.value);
            }
        } finally {
            reader.releaseLock();
        }
        const bytes = new Uint8Array(size);
        let offset = 0;
        for (const chunk of chunks) {
            bytes.set(chunk, offset);
            offset += chunk.length;
        }
        return JSON.parse(
            new TextDecoder('utf-8', { fatal: true }).decode(bytes),
        );
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', abort);
    }
}
export function stopCctv(session: CctvSession) {
    if (!cctvUuid(session.uuid)) return;
    try {
        void fetch(
            '/security-devices/cctv/live-sessions/' + session.uuid + '/stop',
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                keepalive: true,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrf(),
                },
                body: JSON.stringify({
                    expected_actor_id: session.actor_id,
                    expected_generation: session.generation,
                }),
            },
        ).catch(() => {});
    } catch {
        /* Local media is already closed. Worker authority also expires. */
    }
}
