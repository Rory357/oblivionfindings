import { useCallback, useEffect, useRef, useState } from 'react';

// Browser sound is explicitly unlocked by a user action. A Web Lock gives one
// tab ownership; other tabs retain their visual invitation without echoing it.
export function useCallRingtone(
    actorId: number | null,
    callId: string | null,
    enabled: boolean,
) {
    const audio = useRef<AudioContext | null>(null);
    const currentActor = useRef(actorId);
    currentActor.current = actorId;
    const [ready, setReady] = useState(false);
    useEffect(() => {
        setReady(false);
        return () => {
            const previous = audio.current;
            audio.current = null;
            if (previous && previous.state !== 'closed')
                void previous.close().catch(() => undefined);
        };
    }, [actorId]);
    const enable = useCallback(async () => {
        if (!window.AudioContext || !navigator.locks) return false;
        try {
            const context = audio.current ?? new AudioContext();
            audio.current = context;
            context.onstatechange = () => {
                if (
                    currentActor.current === actorId &&
                    audio.current === context
                )
                    setReady(context.state === 'running');
            };
            await context.resume();
            if (currentActor.current !== actorId || audio.current !== context)
                return false;
            const available = context.state === 'running';
            setReady(available);
            return available;
        } catch {
            return false;
        }
    }, [actorId]);
    useEffect(() => {
        const context = audio.current;
        if (
            !actorId ||
            !callId ||
            !enabled ||
            !ready ||
            !context ||
            !navigator.locks
        )
            return;
        let cancelled = false;
        const controller = new AbortController();
        let release: (() => void) | undefined;
        let ringTimer: number | undefined;
        const nodes = new Set<OscillatorNode>();
        const ring = () => {
            if (cancelled || context.state !== 'running') return;
            for (const offset of [0, 0.25]) {
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.frequency.value = 660;
                gain.gain.setValueAtTime(0, context.currentTime + offset);
                gain.gain.linearRampToValueAtTime(
                    0.035,
                    context.currentTime + offset + 0.02,
                );
                gain.gain.linearRampToValueAtTime(
                    0,
                    context.currentTime + offset + 0.18,
                );
                oscillator.connect(gain);
                gain.connect(context.destination);
                nodes.add(oscillator);
                oscillator.onended = () => {
                    nodes.delete(oscillator);
                    oscillator.disconnect();
                    gain.disconnect();
                };
                oscillator.start(context.currentTime + offset);
                oscillator.stop(context.currentTime + offset + 0.2);
            }
        };
        // Queue ownership rather than polling: a background tab can take over
        // promptly when the ringing tab closes. Silence/answer cancels the queue.
        void navigator.locks
            .request(
                `oblivion-chat-ring-${actorId}`,
                { mode: 'exclusive', signal: controller.signal },
                async (lock) => {
                    if (!lock || cancelled) return;
                    ring();
                    ringTimer = window.setInterval(ring, 3000);
                    await new Promise<void>((resolve) => {
                        release = resolve;
                    });
                },
            )
            .catch(() => undefined);
        return () => {
            cancelled = true;
            controller.abort();
            clearInterval(ringTimer);
            nodes.forEach((node) => {
                try {
                    node.stop();
                } catch {
                    /* Already stopped. */
                }
            });
            release?.();
        };
    }, [actorId, callId, enabled, ready]);
    return { ready, enable };
}
