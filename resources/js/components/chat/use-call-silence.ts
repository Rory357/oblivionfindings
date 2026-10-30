import { useEffect, useRef, useState } from 'react';

/** Per-invitation sound control, shared by this browser's tabs; no preference write. */
export function useCallSilence(actorId: number | null, callId: string | null) {
    const source = useRef(crypto.randomUUID());
    const [value, setValue] = useState({
        actor: actorId,
        call: callId,
        silent: false,
        revision: 0,
        source: '',
    });
    const current = useRef(value);
    useEffect(() => {
        current.current = value;
    }, [value]);
    const channel = useRef<BroadcastChannel | null>(null);
    const silent =
        value.actor === actorId && value.call === callId && value.silent;
    useEffect(() => {
        if (!actorId || !callId || typeof BroadcastChannel === 'undefined')
            return;
        const peer = new BroadcastChannel(
            `oblivion-chat-call-sound-${actorId}`,
        );
        channel.current = peer;
        let disposed = false;
        peer.onmessage = ({ data }) => {
            if (disposed || data?.call !== callId) return;
            if (data.type === 'sync') {
                const state = current.current;
                if (
                    state.actor === actorId &&
                    state.call === callId &&
                    state.revision > 0
                )
                    peer.postMessage({
                        type: 'state',
                        call: callId,
                        silent: state.silent,
                        revision: state.revision,
                        source: state.source,
                    });
            } else if (
                data.type === 'state' &&
                typeof data.silent === 'boolean' &&
                Number.isSafeInteger(data.revision) &&
                data.revision > 0 &&
                data.revision < Number.MAX_SAFE_INTEGER &&
                typeof data.source === 'string' &&
                /^[0-9a-f-]{36}$/.test(data.source)
            ) {
                const previous = current.current;
                // Snapshot replies may arrive after a newer user choice. A
                // deterministic revision/source order also resolves simultaneous clicks.
                if (
                    previous.actor === actorId &&
                    previous.call === callId &&
                    (previous.revision > data.revision ||
                        (previous.revision === data.revision &&
                            previous.source >= data.source))
                )
                    return;
                const next = {
                    actor: actorId,
                    call: callId,
                    silent: data.silent,
                    revision: data.revision,
                    source: data.source,
                };
                current.current = next;
                setValue(next);
            }
        };
        peer.postMessage({ type: 'sync', call: callId });
        return () => {
            disposed = true;
            peer.close();
            if (channel.current === peer) channel.current = null;
        };
    }, [actorId, callId]);
    return {
        silent,
        toggle: () => {
            const previous = current.current;
            const same = previous.actor === actorId && previous.call === callId;
            const next = {
                actor: actorId,
                call: callId,
                silent: !(same && previous.silent),
                revision: (same ? previous.revision : 0) + 1,
                source: source.current,
            };
            current.current = next;
            setValue(next);
            channel.current?.postMessage({
                type: 'state',
                call: callId,
                silent: next.silent,
                revision: next.revision,
                source: next.source,
            });
        },
    };
}
