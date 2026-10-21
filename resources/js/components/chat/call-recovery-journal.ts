import {
    acquireChatBrowserLease,
    type ChatBrowserLease,
} from './chat-browser-lease';

/** Local recovery references only. No names, conversation content, device labels or media credentials. */
export type CallRecoveryRecord = {
    version: 1;
    actor: number;
    device: string;
    conversation: number;
} & (
    | {
          kind: 'start';
          mutation: string;
          participants: number[];
          routing: 'invited' | 'care_team';
          stopping: boolean;
      }
    | {
          kind: 'call';
          call: string;
          invitation: string;
          stopping: 'none' | 'leave' | 'end';
      }
);
type StoragePort = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;
const prefix = 'oblivion.chat.call-recovery.v1:';
export const callRecoveryKey = (actor: number) => `${prefix}${actor}`;
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const uuid = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
        value,
    );
// Calls/invitations are opaque identifiers; constrain them to one safe URL segment.
const reference = (value: unknown): value is string =>
    typeof value === 'string' && /^[a-zA-Z0-9_-]{1,128}$/.test(value);
export function parseCallRecovery(
    value: unknown,
    actor: number,
): CallRecoveryRecord | null {
    if (
        !value ||
        typeof value !== 'object' ||
        Array.isArray(value) ||
        !positive(actor)
    )
        return null;
    const data = value as Record<string, unknown>;
    if (
        data.version !== 1 ||
        data.actor !== actor ||
        !uuid(data.device) ||
        !positive(data.conversation)
    )
        return null;
    const base = {
        version: 1 as const,
        actor,
        device: data.device,
        conversation: data.conversation,
    };
    const keys = ['version', 'actor', 'device', 'conversation', 'kind'];
    if (data.kind === 'start') {
        if (
            !uuid(data.mutation) ||
            !Array.isArray(data.participants) ||
            data.participants.length > 7 ||
            !data.participants.every(positive) ||
            data.participants.includes(actor) ||
            new Set(data.participants).size !== data.participants.length ||
            !['invited', 'care_team'].includes(String(data.routing)) ||
            typeof data.stopping !== 'boolean'
        )
            return null;
        if (
            (data.routing === 'care_team' && data.participants.length) ||
            (data.routing === 'invited' && !data.participants.length)
        )
            return null;
        if (
            Object.keys(data).some(
                (key) =>
                    ![
                        ...keys,
                        'mutation',
                        'participants',
                        'routing',
                        'stopping',
                    ].includes(key),
            )
        )
            return null;
        return {
            ...base,
            kind: 'start',
            mutation: data.mutation,
            participants: [...data.participants].sort((a, b) => a - b),
            routing: data.routing as 'invited' | 'care_team',
            stopping: data.stopping,
        };
    }
    if (data.kind === 'call') {
        if (
            !reference(data.call) ||
            !reference(data.invitation) ||
            !['none', 'leave', 'end'].includes(String(data.stopping))
        )
            return null;
        if (
            Object.keys(data).some(
                (key) =>
                    ![...keys, 'call', 'invitation', 'stopping'].includes(key),
            )
        )
            return null;
        return {
            ...base,
            kind: 'call',
            call: data.call,
            invitation: data.invitation,
            stopping: data.stopping as 'none' | 'leave' | 'end',
        };
    }
    return null;
}
export type CallJournalRead =
    | { status: 'empty'; record: null }
    | { status: 'saved'; record: CallRecoveryRecord }
    | { status: 'unavailable'; record: null };
export class CallRecoveryJournal {
    constructor(
        private readonly storage: StoragePort,
        readonly actor: number,
    ) {}
    read(): CallJournalRead {
        if (!positive(this.actor))
            return { status: 'unavailable', record: null };
        try {
            const raw = this.storage.getItem(callRecoveryKey(this.actor));
            if (raw === null) return { status: 'empty', record: null };
            const record = parseCallRecovery(JSON.parse(raw), this.actor);
            return record
                ? { status: 'saved', record }
                : { status: 'unavailable', record: null };
        } catch {
            return { status: 'unavailable', record: null };
        }
    }
    /** Caller must hold the actor's browser lease. Compare-and-readback prevents stale local replacement. */
    write(
        next: CallRecoveryRecord,
        expected: CallRecoveryRecord | null,
    ): boolean {
        const safe = parseCallRecovery(next, this.actor);
        if (!safe) return false;
        const current = this.read();
        if (
            current.status === 'unavailable' ||
            JSON.stringify(current.record) !==
                JSON.stringify(
                    expected ? parseCallRecovery(expected, this.actor) : null,
                )
        )
            return false;
        // Stop intent is monotonic for this identity, even if another local callback arrives late.
        if (current.record) {
            if (
                current.record.device !== safe.device ||
                current.record.conversation !== safe.conversation
            )
                return false;
            if (
                current.record.kind === 'call' &&
                (safe.kind !== 'call' ||
                    safe.call !== current.record.call ||
                    safe.invitation !== current.record.invitation)
            )
                return false;
            if (
                current.record.kind === 'start' &&
                safe.kind === 'start' &&
                (safe.mutation !== current.record.mutation ||
                    safe.routing !== current.record.routing ||
                    JSON.stringify(safe.participants) !==
                        JSON.stringify(current.record.participants))
            )
                return false;
            if (
                current.record.kind === 'start' &&
                current.record.stopping &&
                (safe.kind !== 'start' || !safe.stopping)
            )
                return false;
            if (
                current.record.kind === 'call' &&
                current.record.stopping !== 'none' &&
                (safe.kind !== 'call' ||
                    safe.call !== current.record.call ||
                    safe.invitation !== current.record.invitation ||
                    safe.stopping !== current.record.stopping)
            )
                return false;
        }
        try {
            const encoded = JSON.stringify(safe);
            this.storage.setItem(callRecoveryKey(this.actor), encoded);
            return (
                this.storage.getItem(callRecoveryKey(this.actor)) === encoded
            );
        } catch {
            return false;
        }
    }
    /** Only call after a matching server-confirmed terminal outcome or definitive pre-dispatch refusal. */
    clear(expected: CallRecoveryRecord): boolean {
        const safe = parseCallRecovery(expected, this.actor);
        const current = this.read();
        if (
            !safe ||
            current.status !== 'saved' ||
            JSON.stringify(current.record) !== JSON.stringify(safe)
        )
            return false;
        try {
            this.storage.removeItem(callRecoveryKey(this.actor));
            return this.storage.getItem(callRecoveryKey(this.actor)) === null;
        } catch {
            return false;
        }
    }
}
export function browserCallJournal(actor: number): CallRecoveryJournal | null {
    try {
        return new CallRecoveryJournal(window.localStorage, actor);
    } catch {
        return null;
    }
}

export type CallBrowserLease = ChatBrowserLease;
/** Calls and message sends have separate lock names, so talking never blocks sending. */
export function acquireCallBrowserLease(
    actor: number,
    signal: AbortSignal,
    locks: LockManager | undefined = navigator.locks,
): Promise<CallBrowserLease | null> {
    const unavailable =
        'This browser cannot coordinate calls across tabs. Use a browser with Web Locks support.';
    if (!positive(actor)) return Promise.reject(new Error(unavailable));
    return acquireChatBrowserLease(
        `oblivion-chat-call-${actor}`,
        signal,
        locks,
        unavailable,
    );
}
