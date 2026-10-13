/** Operations/Attendance only. No storage, network, timers or eMAR effects.
 * A redirect or validation error cannot settle the original save attempt. */
export type HandoverWorkContext = Readonly<{
    actorId: number;
    recordId: number | null;
    expectedVersion: number | null;
    canRead: boolean;
    canWrite: boolean;
}>;

export type HandoverWorkDraft = Readonly<{
    client_id: string;
    outgoing: string;
    outgoing_shift: string;
    incoming: string;
    incoming_shift: string;
    leave_open: boolean;
    narrative: string;
    mood: string;
    medications: readonly string[];
    incidents: readonly string[];
    followups: readonly string[];
    tasks: readonly string[];
    cd_result: '' | 'verified' | 'discrepancy';
    cd_witness: string;
    cd_notes: string;
}>;

export type HandoverWorkAttempt = Readonly<{
    requestId: string;
    actorId: number;
    recordId: number | null;
    expectedVersion: number | null;
    asDraft: boolean;
    draft: HandoverWorkDraft;
}>;

export type HandoverWorkState = Readonly<{
    phase: 'editing' | 'pending' | 'uncertain' | 'unavailable';
    dirty: boolean;
    revision: number;
}>;

const positive = (value: number) => Number.isSafeInteger(value) && value > 0;
const validContext = (context: HandoverWorkContext) => positive(context.actorId) &&
    (context.recordId === null || positive(context.recordId)) &&
    (context.expectedVersion === null || positive(context.expectedVersion)) &&
    (context.recordId === null) === (context.expectedVersion === null) &&
    typeof context.canRead === 'boolean' && typeof context.canWrite === 'boolean';
const copyContext = (context: HandoverWorkContext): HandoverWorkContext => Object.freeze({
    actorId: context.actorId,
    recordId: context.recordId,
    expectedVersion: context.expectedVersion,
    canRead: context.canRead,
    canWrite: context.canWrite,
});
const contextKey = (context: HandoverWorkContext) => JSON.stringify(copyContext(context));

/** Explicit allowlist: a structurally wider form may contain a witness PIN.
 * That credential is never copied, even temporarily, into the retained draft. */
export function retainHandoverDraft(form: HandoverWorkDraft): HandoverWorkDraft {
    return Object.freeze({
        client_id: form.client_id,
        outgoing: form.outgoing,
        outgoing_shift: form.outgoing_shift,
        incoming: form.incoming,
        incoming_shift: form.incoming_shift,
        leave_open: form.leave_open,
        narrative: form.narrative,
        mood: form.mood,
        medications: Object.freeze([...form.medications]),
        incidents: Object.freeze([...form.incidents]),
        followups: Object.freeze([...form.followups]),
        tasks: Object.freeze([...form.tasks]),
        cd_result: form.cd_result,
        cd_witness: form.cd_witness,
        cd_notes: form.cd_notes,
    });
}

export class HandoverWorkLifetime {
    private readonly origin: HandoverWorkContext;
    private current: HandoverWorkContext;
    private active = true;
    private epoch = 0;
    private baseline: string | null = null;
    private draft: HandoverWorkDraft | null = null;
    private step = 0;
    private attempt: HandoverWorkAttempt | null = null;
    private attemptEpoch: number | null = null;
    private phase: 'editing' | 'pending' | 'uncertain' = 'editing';
    private state: HandoverWorkState = Object.freeze({ phase: 'editing', dirty: false, revision: 0 });
    private readonly listeners = new Set<() => void>();

    constructor(context: HandoverWorkContext) {
        this.origin = copyContext(context);
        this.current = this.origin;
        this.publish();
    }

    readonly subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => { this.listeners.delete(listener); };
    };
    readonly snapshot = () => this.state;

    /** Publish committed context before descendant callbacks. Notification is
     * separate so React owners can publish in insertion, notify in layout. */
    publishContext(context: HandoverWorkContext): void {
        if (contextKey(context) !== contextKey(this.current)) {
            ++this.epoch;
            if (this.attempt) this.phase = 'uncertain';
        }
        this.current = copyContext(context);
    }
    observeContext(): void { this.publish(); }

    private readable(): boolean {
        return this.active && validContext(this.current) && this.current.canRead &&
            this.current.actorId === this.origin.actorId &&
            this.current.recordId === this.origin.recordId &&
            this.current.expectedVersion === this.origin.expectedVersion;
    }

    private publish(): void {
        this.state = Object.freeze({
            phase: this.readable() ? this.phase : 'unavailable',
            dirty: this.readable() && this.draft !== null &&
                JSON.stringify(this.draft) !== this.baseline,
            revision: this.state.revision + 1,
        });
        this.listeners.forEach((listener) => listener());
    }

    initialise(form: HandoverWorkDraft): boolean {
        if (!this.readable() || this.draft !== null) return false;
        this.draft = retainHandoverDraft(form);
        this.baseline = JSON.stringify(this.draft);
        this.publish();
        return true;
    }

    capture(form: HandoverWorkDraft, step: number): boolean {
        if (!this.readable() || !this.current.canWrite || this.baseline === null || !Number.isInteger(step) || step < 0 || step > 3) return false;
        this.draft = retainHandoverDraft(form);
        this.step = step;
        this.publish();
        return true;
    }

    resume(): Readonly<{ draft: HandoverWorkDraft; step: number }> | null {
        return this.readable() && this.draft ? Object.freeze({ draft: this.draft, step: this.step }) : null;
    }

    begin(requestId: string, asDraft: boolean): HandoverWorkAttempt | null {
        if (!this.readable() || !this.current.canWrite || !this.draft || this.attempt ||
            requestId.length !== 36 || !/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(requestId)) return null;
        this.attempt = Object.freeze({
            requestId,
            actorId: this.origin.actorId,
            recordId: this.origin.recordId,
            expectedVersion: this.origin.expectedVersion,
            asDraft,
            draft: retainHandoverDraft(this.draft),
        });
        this.attemptEpoch = this.epoch;
        this.phase = 'pending';
        this.publish();
        // Subscribers can synchronously change the committed context or retire
        // this owner. Recheck admission after notification, before returning a
        // dispatch-capable attempt; retain its original identity regardless.
        if (!this.readable() || !this.current.canWrite || this.attemptEpoch !== this.epoch) {
            this.phase = 'uncertain';
            this.publish();
            return null;
        }
        return this.attempt;
    }

    /** Every legacy finish—including success/error/cancel—is uncertain. The
     * future exact receipt collector must own confirmation/recovery separately. */
    finish(attempt: HandoverWorkAttempt): boolean {
        if (attempt !== this.attempt) return false;
        this.phase = 'uncertain';
        this.publish();
        return this.readable() && this.attemptEpoch === this.epoch;
    }

    canClose(): boolean { return this.readable() && this.phase === 'editing'; }
    canNavigate(): boolean {
        // The observed UI snapshot may still belong to the previous commit.
        // Descendant callbacks must read retained work immediately after the
        // new committed context is published, before layout notification.
        return this.canClose() && (this.draft === null || JSON.stringify(this.draft) === this.baseline);
    }

    /** Explicitly discard unsent typing only; never erase an uncertain attempt. */
    discardUnsent(): boolean {
        if (!this.canClose() || this.attempt) return false;
        this.draft = null;
        this.baseline = null;
        this.step = 0;
        this.publish();
        return true;
    }

    /** Inert retirement can run during an owner's insertion-effect cleanup.
     * Notify remaining subscribers separately, after that commit phase. */
    retire(): void {
        this.active = false;
        if (this.attempt) this.phase = 'uncertain';
    }
}
