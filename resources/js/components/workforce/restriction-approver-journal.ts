import {
    approverCommand,
    matchedApproverSave,
    type ApproverSaveCorrelation,
} from './restriction-approver-contract';

type AttemptStorage = Pick<Storage, 'length' | 'key' | 'getItem' | 'setItem' | 'removeItem'>;
const prefix = 'oblivion.work-restriction-approver-attempt.v1:';
const positiveId = (id: number) => Number.isSafeInteger(id) && id > 0;

function keyFor(attempt: ApproverSaveCorrelation): string {
    return `${prefix}${attempt.actorId}:${attempt.command.command_id}`;
}

function encode(attempt: ApproverSaveCorrelation): string {
    return JSON.stringify({
        version: 1,
        actor_id: attempt.actorId,
        command_id: attempt.command.command_id,
        expected_revision: attempt.command.expected_revision,
        intent_hash: attempt.intentHash,
    });
}

function decode(raw: string): ApproverSaveCorrelation | null {
    try {
        const data: unknown = JSON.parse(raw);
        if (!data || typeof data !== 'object' || Array.isArray(data)) return null;
        const entry = data as Record<string, unknown>;
        if (Object.keys(entry).sort().join(',') !==
            'actor_id,command_id,expected_revision,intent_hash,version' ||
            entry.version !== 1 || typeof entry.actor_id !== 'number' || !positiveId(entry.actor_id) ||
            typeof entry.command_id !== 'string' ||
            (entry.expected_revision !== null && typeof entry.expected_revision !== 'string') ||
            typeof entry.intent_hash !== 'string' || entry.intent_hash.length !== 64 ||
            !/^[a-f0-9]{64}$/.test(entry.intent_hash)) return null;
        // Use the actual command validator for the exact UUID/revision grammar.
        // This synthetic value is never dispatched or used as an intent hash.
        const identity = approverCommand(entry.command_id, entry.expected_revision, [], 'Recovery identity');
        if (!identity) return null;
        return Object.freeze({
            actorId: entry.actor_id,
            command: Object.freeze({
                command_id: identity.command_id,
                expected_revision: identity.expected_revision,
            }),
            intentHash: entry.intent_hash,
        });
    } catch {
        return null;
    }
}

/** Use the page's sessionStorage after current read admission. This stores only
 * original outcome correlation, cannot replay a write, and has no discard API.
 * The owning command must remember successfully BEFORE invoking transport. */
export class ApproverAttemptJournal {
    constructor(private readonly storage: AttemptStorage) {}

    read(actorId: number): Readonly<{
        status: 'available' | 'unavailable';
        attempts: readonly ApproverSaveCorrelation[];
    }> {
        if (!positiveId(actorId)) return { status: 'unavailable', attempts: [] };
        try {
            const attempts: ApproverSaveCorrelation[] = [];
            const actorPrefix = `${prefix}${actorId}:`;
            for (let index = 0; index < this.storage.length; index++) {
                const key = this.storage.key(index);
                if (!key?.startsWith(actorPrefix)) continue;
                const raw = this.storage.getItem(key);
                if (raw === null) return { status: 'unavailable', attempts: [] };
                const attempt = decode(raw);
                if (!attempt || attempt.actorId !== actorId || keyFor(attempt) !== key) {
                    return { status: 'unavailable', attempts: [] };
                }
                attempts.push(attempt);
            }
            return { status: 'available', attempts: Object.freeze(attempts) };
        } catch {
            return { status: 'unavailable', attempts: [] };
        }
    }

    remember(attempt: ApproverSaveCorrelation): boolean {
        try {
            const raw = encode(attempt);
            if (!decode(raw)) return false;
            const key = keyFor(attempt);
            const existing = this.storage.getItem(key);
            // Never overwrite a different original command correlation.
            if (existing !== null) return existing === raw;
            this.storage.setItem(key, raw);
            return this.storage.getItem(key) === raw;
        } catch {
            return false;
        }
    }

    /** Server confirmation and local cleanup are separate facts. A failed
     * storage operation cannot undo or conceal an exactly confirmed save. */
    settle(attempt: ApproverSaveCorrelation, response: unknown): Readonly<{
        confirmed: boolean;
        cleared: boolean;
    }> {
        if (!matchedApproverSave(response, attempt)) return { confirmed: false, cleared: false };
        try {
            const key = keyFor(attempt);
            if (this.storage.getItem(key) !== encode(attempt)) return { confirmed: true, cleared: false };
            this.storage.removeItem(key);
            return { confirmed: true, cleared: this.storage.getItem(key) === null };
        } catch {
            return { confirmed: true, cleared: false };
        }
    }
}
