export type ChatSendReference = {
    version: 1;
    actor: number;
    conversation: number;
    mutation: string;
    cancelling: boolean;
};
type StoragePort = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;
export const chatSendJournalKey = (actor: number, conversation: number) =>
    `oblivion.chat.send-recovery.v1:${actor}:${conversation}`;
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;

export function parseChatSendReference(
    value: unknown,
    actor: number,
    conversation: number,
): ChatSendReference | null {
    if (
        !value ||
        typeof value !== 'object' ||
        Array.isArray(value) ||
        !positive(actor) ||
        !positive(conversation)
    )
        return null;
    const row = value as Record<string, unknown>;
    if (
        row.version !== 1 ||
        row.actor !== actor ||
        row.conversation !== conversation ||
        typeof row.cancelling !== 'boolean' ||
        typeof row.mutation !== 'string' ||
        !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
            row.mutation,
        ) ||
        Object.keys(row).some(
            (key) =>
                ![
                    'version',
                    'actor',
                    'conversation',
                    'mutation',
                    'cancelling',
                ].includes(key),
        )
    )
        return null;
    return {
        version: 1,
        actor,
        conversation,
        mutation: row.mutation,
        cancelling: row.cancelling,
    };
}
export type ChatSendJournalRead =
    | { status: 'saved'; record: ChatSendReference }
    | { status: 'empty' | 'unavailable'; record: null };
const same = (a: unknown, b: unknown) =>
    JSON.stringify(a) === JSON.stringify(b);

/** IDs and cancel intent only. Caller holds the conversation's browser send lease. */
export class ChatSendJournal {
    constructor(
        private readonly storage: StoragePort,
        readonly actor: number,
        readonly conversation: number,
    ) {}
    read(): ChatSendJournalRead {
        try {
            if (!positive(this.actor) || !positive(this.conversation))
                return { status: 'unavailable', record: null };
            const raw = this.storage.getItem(
                chatSendJournalKey(this.actor, this.conversation),
            );
            if (raw === null) return { status: 'empty', record: null };
            const record = parseChatSendReference(
                JSON.parse(raw),
                this.actor,
                this.conversation,
            );
            return record
                ? { status: 'saved', record }
                : { status: 'unavailable', record: null };
        } catch {
            return { status: 'unavailable', record: null };
        }
    }
    write(
        next: ChatSendReference,
        expected: ChatSendReference | null,
    ): boolean {
        const safe = parseChatSendReference(
            next,
            this.actor,
            this.conversation,
        );
        const before =
            expected === null
                ? null
                : parseChatSendReference(
                      expected,
                      this.actor,
                      this.conversation,
                  );
        const current = this.read();
        if (
            !safe ||
            (expected !== null && !before) ||
            current.status === 'unavailable' ||
            !same(current.record, before) ||
            (current.record &&
                (current.record.mutation !== safe.mutation ||
                    (current.record.cancelling && !safe.cancelling)))
        )
            return false;
        try {
            const encoded = JSON.stringify(safe);
            this.storage.setItem(
                chatSendJournalKey(this.actor, this.conversation),
                encoded,
            );
            return (
                this.storage.getItem(
                    chatSendJournalKey(this.actor, this.conversation),
                ) === encoded
            );
        } catch {
            return false;
        }
    }
    /** Only clear after the server confirms this exact reference is sent or cancelled. */
    clear(expected: ChatSendReference): boolean {
        const safe = parseChatSendReference(
            expected,
            this.actor,
            this.conversation,
        );
        const current = this.read();
        if (!safe || current.status !== 'saved' || !same(current.record, safe))
            return false;
        try {
            this.storage.removeItem(
                chatSendJournalKey(this.actor, this.conversation),
            );
            return (
                this.storage.getItem(
                    chatSendJournalKey(this.actor, this.conversation),
                ) === null
            );
        } catch {
            return false;
        }
    }
}
export function browserChatSendJournal(
    actor: number,
    conversation: number,
): ChatSendJournal | null {
    try {
        return new ChatSendJournal(window.localStorage, actor, conversation);
    } catch {
        return null;
    }
}
