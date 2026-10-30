import { prepareHandoverCommand, type PreparedHandoverCommand } from './handover-command-prepare';
import {
    confirmedHandoverCommand, currentHandoverCommandSource, recoveredHandoverCommand, refusedHandoverCommand,
    type HandoverCommandSaved, type HandoverCommandSource,
} from './handover-command-result';

export type HandoverCommandTransport = {
    request: (method: 'get' | 'post' | 'put' | 'patch', url: string, body?: unknown) =>
        Promise<{ status: number; data: unknown }>;
};
export type HandoverCommandState = Readonly<{
    phase: 'editing' | 'preparing' | 'saving' | 'uncertain' | 'confirmed' | 'unavailable';
    message: string | null;
    saved: HandoverCommandSaved | null;
}>;

/** One document's original attempt. No clinical content or credentials are put
 * in browser storage, and recovery is always a read of the original UUID. */
export class HandoverCommandSession {
    private actorId: number;
    private epoch = 0;
    private active = true;
    private attempt: PreparedHandoverCommand | null = null;
    private recovering = false;
    private invoked = false;
    private confirmationEpoch: number | null = null;
    private contextKey: string | null = null;
    private context: Readonly<{ recordId: number | null; version: number | null; shiftId: number | null }> | null = null;
    private canWrite = true;
    private state: HandoverCommandState = Object.freeze({ phase: 'editing', message: null, saved: null });
    private listeners = new Set<() => void>();

    constructor(actorId: number, private readonly transport: HandoverCommandTransport) {
        this.actorId = actorId;
    }
    readonly snapshot = () => this.state;
    readonly subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => { this.listeners.delete(listener); };
    };
    /** Committed actor changes permanently invalidate callbacks, including ABA. */
    publishActor(actorId: number): void {
        if (actorId === this.actorId) return;
        this.actorId = actorId;
        ++this.epoch;
        this.active = false;
        this.state = Object.freeze({ phase: 'unavailable', message: 'Your access changed. Reopen Handovers to continue.', saved: null });
    }
    notify(): boolean {
        // React subscription churn cannot extend one notification forever.
        let complete = true;
        for (const listener of [...this.listeners]) {
            if (!this.listeners.has(listener)) continue;
            try { listener(); } catch { complete = false; }
        }
        return complete;
    }
    publishContext(context: Readonly<{ recordId: number | null; version: number | null;
        shiftId: number | null; canRead: boolean; canWrite: boolean }>): void {
        const copied = Object.freeze({ recordId: context.recordId, version: context.version,
            shiftId: context.shiftId, canRead: context.canRead, canWrite: context.canWrite });
        const key = JSON.stringify(copied);
        if (this.contextKey !== null && this.contextKey !== key) {
            ++this.epoch;
            const sameReadableAttempt = this.invoked && this.attempt && context.canRead && this.context &&
                this.context.recordId === context.recordId && this.context.shiftId === context.shiftId;
            const editedVersionChanged = this.state.phase === 'editing' && this.context?.recordId !== null &&
                this.context?.recordId === context.recordId && this.context?.version !== context.version;
            if (sameReadableAttempt && ['saving', 'uncertain'].includes(this.state.phase)) {
                // Invalidate the old write callback, while permitting a NEW
                // read-only lookup under the newly committed current frame.
                this.state = Object.freeze({ phase: 'uncertain', message: 'This handover or your access changed. Check whether your save went through; it will not be sent again.', saved: null });
            } else if (editedVersionChanged || !['editing', 'confirmed'].includes(this.state.phase)) {
                this.active = false;
                this.state = Object.freeze({ phase: 'unavailable', message: 'This handover or your access changed. Reopen Handovers to continue.', saved: null });
            }
        }
        this.contextKey = key;
        this.context = copied;
        this.canWrite = context.canWrite;
        if (!context.canRead) {
            ++this.epoch;
            this.active = false;
            this.state = Object.freeze({ phase: 'unavailable', message: 'This handover is no longer available.', saved: null });
        }
    }
    retire(): void { ++this.epoch; this.active = false; }
    private publish(phase: HandoverCommandState['phase'], message: string | null = null, saved: HandoverCommandSaved | null = null) {
        this.state = Object.freeze({ phase, message, saved });
        return this.notify();
    }
    private withdrawRead(): void {
        ++this.epoch;
        this.active = false;
        this.publish('unavailable', 'You no longer have access to this handover.');
    }
    canEdit(): boolean { return this.active && this.canWrite && this.state.phase === 'editing'; }
    confirmedFor(saved: HandoverCommandSaved, actorId: number): boolean {
        return this.belongsTo(actorId) && this.state.phase === 'confirmed' && this.state.saved === saved && this.confirmationEpoch === this.epoch;
    }
    belongsTo(actorId: number): boolean { return this.active && this.actorId === actorId; }
    canClose(): boolean { return this.active && ['editing', 'confirmed'].includes(this.state.phase); }
    reset(): boolean {
        if (!this.canClose()) return false;
        ++this.epoch;
        this.attempt = null;
        this.invoked = false;
        this.confirmationEpoch = null;
        this.publish('editing');
        return true;
    }

    async save(shiftId: number, recordId: number | null, version: number | null, fields: unknown,
        credential?: string | null, submitOnly = false): Promise<HandoverCommandSaved | null> {
        if (!this.canEdit()) return null;
        if (this.context && (this.context.shiftId !== shiftId || this.context.recordId !== recordId || this.context.version !== version)) {
            this.publish('editing', 'The selected handover changed. Nothing was sent. Check the handover you have open.');
            return null;
        }
        const epoch = this.epoch;
        const actorId = this.actorId;
        const current = () => this.active && epoch === this.epoch && actorId === this.actorId;
        const preparationShown = this.publish('preparing', 'Checking this shift before saving…');
        if (!current()) return null;
        if (!preparationShown) {
            this.publish('editing', 'The handover could not be saved. Nothing was sent, and your typing is still here.');
            return null;
        }
        let source: HandoverCommandSource | null;
        try {
            const response = await this.transport.request('get', `/operations/shifts/${shiftId}/handover-command-source`);
            if (!current()) return null;
            if ([401, 403, 404].includes(response.status)) { this.withdrawRead(); return null; }
            source = response.status === 200 ? currentHandoverCommandSource(response.data, actorId, shiftId) : null;
        } catch {
            if (current()) this.publish('editing', 'The shift could not be checked. Your typing is still here. Try again once you are connected.');
            return null;
        }
        if (!source || source.handover_id !== recordId || source.expected_version !== version) {
            this.publish('editing', 'This handover has changed or is no longer available. Nothing was sent. Check the latest version before saving.');
            return null;
        }
        let command: PreparedHandoverCommand | null;
        try {
            command = await prepareHandoverCommand({ source, isCurrent: current },
                submitOnly ? 'submit' : recordId === null ? 'create' : 'update', crypto.randomUUID(), fields);
        } catch {
            if (current()) this.publish('editing', 'Your browser could not save this handover. Nothing was sent, and your typing is still here.');
            return null;
        }
        if (!current()) return null;
        if (!command) {
            this.publish('editing', 'This handover cannot be saved with your current access or entries. Your typing is still here.');
            return null;
        }
        this.attempt = command;
        const savingShown = this.publish('saving', 'Saving handover…');
        // A subscriber can revoke the owner synchronously. This is still a
        // proved no-send point; never invoke transport after that revocation.
        if (!current()) return null;
        if (!savingShown) {
            this.attempt = null;
            this.invoked = false;
            this.publish('editing', 'The handover could not be saved. Nothing was sent, and your typing is still here.');
            return null;
        }
        const identity = command.identity;
        const url = recordId === null ? `/operations/shifts/${shiftId}/handover-commands`
            : `/operations/shifts/${shiftId}/handovers/${recordId}/command${submitOnly ? '/submit' : ''}`;
        const body = {
            request_id: identity.request_id, expected_source: identity.expected_source,
            expected_version: identity.expected_version, intent_hash: identity.intent_hash,
            fields: command.fields,
            ...(credential ? { cd_witness_credential: credential } : {}),
        };
        try {
            this.invoked = true;
            const response = await this.transport.request(submitOnly ? 'patch' : recordId === null ? 'post' : 'put', url, body);
            if (!current()) return null;
            if ([401, 403, 404].includes(response.status)) { this.withdrawRead(); return null; }
            const saved = confirmedHandoverCommand(response.data, identity, command.expectedStatus, response.status);
            if (saved) { this.confirmationEpoch = epoch; this.publish('confirmed', null, saved); return current() ? saved : null; }
            const refusal = refusedHandoverCommand(response.data, identity, response.status);
            if (refusal) {
                this.attempt = null;
                this.invoked = false;
                this.publish('editing', Object.values(refusal).join(' '));
                return null;
            }
        } catch { /* A transport error says nothing about a committed save. */ }
        if (current()) this.publish('uncertain', 'The save could not be confirmed. Keep this window open and check whether it saved before trying anything else.');
        return null;
    }

    async recover(): Promise<HandoverCommandSaved | null> {
        const attempt = this.attempt;
        if (!this.active || this.state.phase !== 'uncertain' || !attempt || !this.invoked || this.recovering) return null;
        this.recovering = true;
        const epoch = this.epoch;
        const current = () => this.active && epoch === this.epoch;
        const identity = attempt.identity;
        const query = new URLSearchParams({ action: identity.action,
            requested_handover_id: String(identity.requested_handover_id ?? 'none'),
            expected_version: String(identity.expected_version ?? 'none'),
            expected_source: identity.expected_source, intent_hash: identity.intent_hash });
        try {
            this.publish('uncertain', 'Checking whether your handover saved…');
            if (!current()) return null;
            const response = await this.transport.request('get', `/operations/shifts/${identity.shift_id}/handover-attempts/${identity.request_id}?${query}`);
            if (!current()) return null;
            if ([401, 403, 404].includes(response.status)) { this.withdrawRead(); return null; }
            const result = recoveredHandoverCommand(response.data, identity, attempt.expectedStatus, response.status);
            if (result?.saved) { this.confirmationEpoch = epoch; this.publish('confirmed', null, result.saved); return current() ? result.saved : null; }
            this.publish('uncertain', 'The save still cannot be confirmed. Your typing is kept, and checking again will not send it twice.');
        } catch {
            if (current()) this.publish('uncertain', 'The save could not be checked. Reconnect, then check again.');
        } finally { this.recovering = false; }
        return null;
    }
}
