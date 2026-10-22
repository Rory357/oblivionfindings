// Typing (spec section 6): the composer tells the app at most once every 3 seconds while the
// person types, and "stop" when they pause, send or clear the box. Readers show a typing line
// for 6 seconds unless it is refreshed. Nothing is stored.

import { useEffect, useState } from 'react';
import { chatRequest } from '../api';
import type { LiveEvent } from './events';
import { chatSync, type ChatSync } from './sync';

const SEND_EVERY_MS = 3_000;
const IDLE_STOP_MS = 5_000;
export const SHOW_FOR_MS = 6_000;

type TypingState = 'start' | 'stop';

export type TypingSignal = {
    /** Call with the draft text whenever it changes. */
    input: (text: string) => void;
    /** The draft was sent, cleared or the composer closed. */
    stop: () => void;
};

export function createTypingSignal(options: {
    actorId: number;
    conversationId: number;
    sync?: ChatSync;
    send?: (state: TypingState) => Promise<unknown>;
    now?: () => number;
}): TypingSignal {
    const sync = options.sync ?? chatSync;
    const now = options.now ?? (() => Date.now());
    const send =
        options.send ??
        ((state: TypingState) =>
            chatRequest(
                `/chat/api/conversations/${options.conversationId}/typing`,
                {
                    method: 'POST',
                    body: JSON.stringify({ state }),
                    headers: { 'X-Chat-Actor': String(options.actorId) },
                },
            ));
    let started = false;
    let lastStart = -Infinity;
    let idle: ReturnType<typeof setTimeout> | undefined;
    const quiet = (state: TypingState) =>
        void send(state).catch(() => undefined);
    const stop = () => {
        clearTimeout(idle);
        idle = undefined;
        if (!started) return;
        started = false;
        lastStart = -Infinity;
        if (sync.live) quiet('stop');
    };

    return {
        input(text) {
            // Typing is only sent while live; polling never shows it.
            if (!sync.live || text.trim() === '') {
                stop();
                return;
            }
            clearTimeout(idle);
            idle = setTimeout(stop, IDLE_STOP_MS);
            if (now() - lastStart < SEND_EVERY_MS) return;
            lastStart = now();
            started = true;
            quiet('start');
        },
        stop,
    };
}

/** "Hana is typing…", "Hana and Rohan are typing…", "3 people are typing…". */
export function typingLabel(names: string[]): string | null {
    const shown = names.map((name) =>
        name === 'Care team' ? name : (name.trim().split(/\s+/)[0] ?? name),
    );
    if (shown.length === 0) return null;
    if (shown.length === 1) return `${shown[0]} is typing…`;
    if (shown.length === 2) return `${shown[0]} and ${shown[1]} are typing…`;
    return `${shown.length} people are typing…`;
}

/** Who is typing in this conversation right now, from live typing events. */
export function useTypingNames(
    conversationId: number | null,
    sync: ChatSync = chatSync,
): string[] {
    const [names, setNames] = useState<string[]>([]);
    useEffect(() => {
        setNames([]);
        if (!conversationId) return;
        const until = new Map<string, number>();
        let timer: ReturnType<typeof setTimeout> | undefined;
        const publish = () => {
            const now = Date.now();
            for (const [name, expires] of until)
                if (expires <= now) until.delete(name);
            setNames([...until.keys()]);
            clearTimeout(timer);
            if (until.size)
                timer = setTimeout(
                    publish,
                    Math.max(50, Math.min(...until.values()) - now),
                );
        };
        const unsubscribe = sync.subscribe(['typing'], (event: LiveEvent) => {
            if (
                event.type !== 'typing' ||
                event.conversation_id !== conversationId
            )
                return;
            if (event.state === 'stop') until.delete(event.who);
            else {
                until.delete(event.who);
                until.set(event.who, Date.now() + SHOW_FOR_MS);
            }
            publish();
        });
        // Without a live connection no typing can be seen, so an old line must not linger.
        const offState = sync.onState((state) => {
            if (state !== 'live') {
                until.clear();
                publish();
            }
        });
        return () => {
            unsubscribe();
            offState();
            clearTimeout(timer);
        };
    }, [conversationId, sync]);
    return names;
}
