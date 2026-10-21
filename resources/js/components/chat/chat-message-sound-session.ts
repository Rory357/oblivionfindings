import { isChatAccessDenied } from './api';
import { chatActorRequest } from './chat-actor-request';
import { claimChatSound, readChatSoundContext } from './chat-sound-receipts';
import { visibleChatSoundViews } from './chat-sound-views';
import { watchChatUpdates } from './chat-update-watch';

export type MessageSoundState = { enabled: boolean; activeCall: boolean };
type Arrival = { id: string; conversation_id: number };
type Snapshot = {
    actor_id: number;
    cursor: number;
    alerts: Arrival[];
    has_more: boolean;
};
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function validate(value: Snapshot, actorId: number, after?: number) {
    if (
        !value ||
        value.actor_id !== actorId ||
        !Number.isSafeInteger(value.cursor) ||
        value.cursor < 0 ||
        (after !== undefined && value.cursor < after) ||
        typeof value.has_more !== 'boolean' ||
        !Array.isArray(value.alerts) ||
        value.alerts.length > 100 ||
        value.alerts.some(
            (row) =>
                !row ||
                typeof row.id !== 'string' ||
                !uuid.test(row.id) ||
                !Number.isSafeInteger(row.conversation_id) ||
                row.conversation_id <= 0,
        ) ||
        (value.has_more && (after === undefined || value.cursor === after))
    )
        throw new Error('Unverified arrival');
    return value;
}

/** One account lifecycle. Browser receipts coordinate actual sound with native push. */
export function startChatMessageSounds(
    actorId: number,
    state: () => MessageSoundState,
) {
    let disposed = false;
    let denied = false;
    let audio: AudioContext | null = null;
    let stopWatch: (() => void) | undefined;
    let request: AbortController | undefined;
    let wakeTimer: ReturnType<typeof setTimeout> | undefined;
    let channel: BroadcastChannel | undefined;
    const nodes = new Set<OscillatorNode>();
    const key = `oblivion:chat-arrivals:v1:${actorId}`;
    const stopNodes = () => {
        for (const node of nodes) {
            try {
                node.stop();
            } catch {
                /* Already ended. */
            }
        }
        nodes.clear();
    };
    const updated = () => {
        if (!state().enabled || state().activeCall) stopNodes();
    };
    try {
        channel = new BroadcastChannel(
            `oblivion-chat-sound-context-${actorId}`,
        );
        channel.onmessage = (event) => {
            const data = event.data;
            if (
                disposed ||
                data?.type !== 'probe' ||
                typeof data.request !== 'string' ||
                !uuid.test(data.request)
            )
                return;
            channel?.postMessage({
                type: 'context',
                request: data.request,
                active: state().activeCall,
                conversations: visibleChatSoundViews(actorId),
            });
        };
    } catch {
        /* Native notifications retain their server-side permission checks. */
    }

    const play = () => {
        if (
            disposed ||
            !audio ||
            audio.state !== 'running' ||
            !state().enabled ||
            state().activeCall
        )
            return;
        const node = audio.createOscillator();
        const gain = audio.createGain();
        node.frequency.value = 740;
        gain.gain.setValueAtTime(0, audio.currentTime);
        gain.gain.linearRampToValueAtTime(0.025, audio.currentTime + 0.015);
        gain.gain.linearRampToValueAtTime(0, audio.currentTime + 0.14);
        node.connect(gain);
        gain.connect(audio.destination);
        nodes.add(node);
        node.onended = () => {
            nodes.delete(node);
            node.disconnect();
            gain.disconnect();
        };
        node.start();
        node.stop(audio.currentTime + 0.16);
    };
    const refresh = async () => {
        if (
            disposed ||
            denied ||
            audio?.state !== 'running' ||
            !navigator.locks ||
            !channel
        )
            return false;
        try {
            // Short ownership: a closed or suspended tab never owns a permanent poll loop.
            await navigator.locks.request(
                `oblivion-chat-arrivals-${actorId}`,
                { ifAvailable: true },
                async (lock) => {
                    if (!lock || disposed) return;
                    const started = Date.now();
                    let saved: { cursor: number; at: number } | null = null;
                    try {
                        saved = JSON.parse(localStorage.getItem(key) ?? 'null');
                    } catch {
                        saved = null;
                    }
                    const validSaved =
                        saved &&
                        Number.isSafeInteger(saved.cursor) &&
                        saved.cursor >= 0 &&
                        Number.isFinite(saved.at) &&
                        saved.at <= started &&
                        started - saved.at < 15000;
                    if (validSaved && started - saved!.at < 750) {
                        clearTimeout(wakeTimer);
                        wakeTimer = setTimeout(
                            () =>
                                window.dispatchEvent(
                                    new Event('oblivion:chat-sound-wake'),
                                ),
                            750 - (started - saved!.at),
                        );
                        return;
                    }
                    let after = validSaved ? saved!.cursor : undefined;
                    const candidates: Arrival[] = [];
                    const controller = new AbortController();
                    request = controller;
                    let more = false;
                    for (let page = 0; page < 5; page++) {
                        const next = validate(
                            await chatActorRequest<Snapshot>(
                                actorId,
                                `/chat/api/message-alerts${after === undefined ? '' : `?after=${after}`}`,
                                {
                                    signal: AbortSignal.any([
                                        controller.signal,
                                        AbortSignal.timeout(5000),
                                    ]),
                                },
                            ),
                            actorId,
                            after,
                        );
                        if (
                            disposed ||
                            controller.signal.aborted ||
                            audio?.state !== 'running'
                        )
                            return;
                        // A baseline is always silent, even if an invalid server projects candidates.
                        if (after !== undefined)
                            candidates.push(...next.alerts);
                        after = next.cursor;
                        localStorage.setItem(
                            key,
                            JSON.stringify({ cursor: after, at: Date.now() }),
                        );
                        more = next.has_more;
                        if (!more) break;
                    }
                    if (more) {
                        clearTimeout(wakeTimer);
                        wakeTimer = setTimeout(
                            () =>
                                window.dispatchEvent(
                                    new Event('oblivion:chat-sound-wake'),
                                ),
                            800,
                        );
                    }
                    if (
                        !candidates.length ||
                        disposed ||
                        Date.now() - started > 8000
                    )
                        return;
                    const context = await readChatSoundContext(actorId);
                    if (!context || disposed || audio?.state !== 'running')
                        return;
                    const silent = new Set([
                        ...context.conversations,
                        ...visibleChatSoundViews(actorId),
                    ]);
                    const blocked =
                        context.active ||
                        state().activeCall ||
                        !state().enabled;
                    const suppressed = candidates.filter(
                        (row) => blocked || silent.has(row.conversation_id),
                    );
                    // Consume silent candidates too, so a later OS push cannot sound for the same read view/call.
                    for (let index = 0; index < suppressed.length; index += 100)
                        await claimChatSound(
                            suppressed
                                .slice(index, index + 100)
                                .map((row) => row.id),
                        );
                    const audible = candidates.filter(
                        (row) => !blocked && !silent.has(row.conversation_id),
                    );
                    let claimed = false;
                    for (let index = 0; index < audible.length; index += 100)
                        claimed =
                            (await claimChatSound(
                                audible
                                    .slice(index, index + 100)
                                    .map((row) => row.id),
                            )) || claimed;
                    if (
                        claimed &&
                        !disposed &&
                        !controller.signal.aborted &&
                        Date.now() - started <= 8000
                    )
                        play();
                },
            );
            return true;
        } catch (failure) {
            if (isChatAccessDenied(failure)) {
                denied = true;
                stopWatch?.();
                stopWatch = undefined;
                stopNodes();
            }
            // Reconnect quietly; no buffered history or uncertain response is played later.
            try {
                localStorage.removeItem(key);
            } catch {
                /* No audio when browser storage is unavailable. */
            }
            return false;
        }
    };
    const unlock = (event: Event) => {
        if (
            !event.isTrusted ||
            disposed ||
            denied ||
            !window.AudioContext ||
            !navigator.locks ||
            !channel
        )
            return;
        try {
            audio ??= new AudioContext();
            const current = audio;
            current.onstatechange = () => {
                if (current.state !== 'running') {
                    stopWatch?.();
                    stopWatch = undefined;
                    request?.abort();
                    stopNodes();
                }
            };
            void current
                .resume()
                .then(() => {
                    if (
                        disposed ||
                        audio !== current ||
                        current.state !== 'running' ||
                        stopWatch
                    )
                        return;
                    stopWatch = watchChatUpdates(refresh, {
                        interval: () => 5000,
                        whenHidden: true,
                        events: [
                            'oblivion:chat-refresh',
                            'oblivion:chat-sound-wake',
                        ],
                    });
                })
                .catch(() => undefined);
        } catch {
            /* Browser sound remains optional; visual messages still work. */
        }
    };
    window.addEventListener('pointerdown', unlock, true);
    window.addEventListener('keydown', unlock, true);
    return {
        updated,
        dispose() {
            disposed = true;
            stopWatch?.();
            request?.abort();
            clearTimeout(wakeTimer);
            stopNodes();
            channel?.close();
            window.removeEventListener('pointerdown', unlock, true);
            window.removeEventListener('keydown', unlock, true);
            const previous = audio;
            audio = null;
            if (previous) {
                previous.onstatechange = null;
                void previous.close().catch(() => undefined);
            }
        },
    };
}
