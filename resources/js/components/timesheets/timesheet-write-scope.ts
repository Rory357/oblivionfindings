/** Page-local coordination only. This is neither permission nor server proof. */
export type TimesheetWriteState = 'idle' | 'busy' | 'needs_check';
export type TimesheetWriteLease = symbol;
export type TimesheetNoSendBasis = 'preparation_exit' | 'exact_before_veto';
type Owner = {
    token: TimesheetWriteLease;
    actor: number;
    generation: number;
    invoked: boolean;
    started: boolean;
    callback: boolean;
    uncertain: boolean;
};
const actorId = (value: unknown): number | null =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : null;

/** One stable instance must live in the owning page, outside transient dialogs.
 * Owning callers must acquire BEFORE pending/hash and bind the exact visit.
 * The completion methods accept caller assessments; they do not authenticate
 * receipts, field errors, current reads or a server transaction. */
export class TimesheetWriteScope {
    #owner: Owner | null = null;
    #actor: number | null;
    #generation = 0;
    #listeners = new Set<() => void>();
    constructor(private readonly readActor: () => unknown) {
        this.#actor = this.currentActor();
    }
    private currentActor(): number | null {
        try { return actorId(this.readActor()); } catch { return null; }
    }
    private notify(): void {
        for (const listener of [...this.#listeners]) {
            // A rendering subscriber must not strand an already acquired lease
            // by throwing before its token is returned to the caller.
            try { listener(); } catch { /* Ownership still remains intact. */ }
        }
    }
    subscribe = (listener: () => void): (() => void) => {
        this.#listeners.add(listener);
        return () => { this.#listeners.delete(listener); };
    };
    snapshot = (): TimesheetWriteState => !this.#owner ? 'idle'
        : this.#owner.uncertain ? 'needs_check' : 'busy';
    observeActor(): void {
        const current = this.currentActor();
        if (current === this.#actor) return;
        this.#actor = current;
        this.#generation += 1;
        if (this.#owner) {
            this.#owner.uncertain = true;
            this.notify();
        }
    }
    tryPrepare(actor: unknown): TimesheetWriteLease | null {
        this.observeActor();
        const candidate = actorId(actor);
        if (this.#owner || candidate === null || candidate !== this.#actor) return null;
        const token = Symbol('local-timesheet-write');
        this.#owner = { token, actor: candidate, generation: this.#generation,
            invoked: false, started: false, callback: false, uncertain: false };
        this.notify();
        return token;
    }
    private original(token: TimesheetWriteLease): Owner | null {
        return this.#owner?.token === token ? this.#owner : null;
    }
    canDispatch(token: TimesheetWriteLease): boolean {
        this.observeActor();
        const owner = this.original(token);
        return owner !== null && !owner.invoked && !owner.uncertain &&
            owner.generation === this.#generation && owner.actor === this.#actor;
    }
    /** Call immediately before invoking router transport, not after awaiting it.
     * Invocation is not evidence of a request reaching the server. */
    markInvoked(token: TimesheetWriteLease): boolean {
        if (!this.canDispatch(token)) return false;
        this.original(token)!.invoked = true;
        return true;
    }
    markStarted(token: TimesheetWriteLease): void {
        const owner = this.original(token);
        if (owner?.invoked) owner.started = true;
    }
    markCallback(token: TimesheetWriteLease): void {
        const owner = this.original(token);
        if (owner?.invoked) owner.callback = true;
    }
    holdUnknown(token: TimesheetWriteLease): void {
        const owner = this.original(token);
        if (!owner || owner.uncertain) return;
        owner.uncertain = true;
        this.notify();
    }
    completeNoSend(token: TimesheetWriteLease, basis: TimesheetNoSendBasis): boolean {
        if (basis !== 'preparation_exit' && basis !== 'exact_before_veto') return false;
        const owner = this.original(token);
        if (!owner || owner.started || owner.callback) return false;
        if (basis === 'preparation_exit' ? owner.invoked : !owner.invoked) return false;
        this.#owner = null;
        this.notify();
        return true;
    }
    private completeAssessed(token: TimesheetWriteLease): boolean {
        this.observeActor();
        const owner = this.original(token);
        if (!owner || !owner.invoked || !owner.callback || owner.uncertain ||
            owner.actor !== this.#actor || owner.generation !== this.#generation) return false;
        this.#owner = null;
        this.notify();
        return true;
    }
    /** Caller must first match the original command/visit/receipt and current
     * owning context. This method itself has no receipt or authority decoder. */
    completeMatched(token: TimesheetWriteLease): boolean {
        return this.completeAssessed(token);
    }
    /** Requires a separately assessed no-commit rejection. A generic redirect,
     * error bag, current row or missing receipt is not that assessment. */
    completeKnownRejection(token: TimesheetWriteLease): boolean {
        return this.completeAssessed(token);
    }
}
