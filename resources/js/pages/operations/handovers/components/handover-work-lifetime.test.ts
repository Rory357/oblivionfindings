import { describe, expect, it } from 'vitest';
import { HandoverWorkLifetime, retainHandoverDraft, type HandoverWorkDraft } from './handover-work-lifetime';

const context = { actorId: 4, recordId: 12, expectedVersion: 3, canRead: true, canWrite: true };
const uuid = '7d886442-95d4-4bb6-a8b5-364489f0d22d';
const draft: HandoverWorkDraft = {
    client_id: '7', outgoing: '4', outgoing_shift: '20', incoming: '9', incoming_shift: '21',
    leave_open: false, narrative: 'Aroha ate dinner and needs support at bedtime.', mood: 'Settled',
    medications: ['Recorded medicines'], incidents: [], followups: ['Check dinner intake'], tasks: [],
    cd_result: '', cd_witness: '', cd_notes: '',
};

describe('operations handover retained work lifetime', () => {
    it('retains unsent content and step when closed, while requiring a deliberate navigation decision', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        work.capture({ ...draft, narrative: 'Changed private narrative' }, 2);
        expect(work.canClose()).toBe(true);
        expect(work.canNavigate()).toBe(false);
        expect(work.resume()).toMatchObject({ draft: { narrative: 'Changed private narrative' }, step: 2 });
    });

    it('never retains one-shot credentials or unexpected private fields', () => {
        const wider = { ...draft, cd_witness_credential: 'SECRET', unexpected_private_field: 'Omitted' };
        const retained = retainHandoverDraft(wider);
        expect(retained).not.toHaveProperty('cd_witness_credential');
        expect(retained).not.toHaveProperty('unexpected_private_field');
        expect(JSON.stringify(retained)).not.toContain('SECRET');
        expect(Object.isFrozen(retained.followups)).toBe(true);
    });

    it('blocks close, navigation and duplicate submit from the first synchronous begin', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        const attempt = work.begin(uuid, false)!;
        expect(work.snapshot().phase).toBe('pending');
        expect(work.canClose()).toBe(false);
        expect(work.canNavigate()).toBe(false);
        expect(work.begin(uuid, false)).toBeNull();
        expect(work.discardUnsent()).toBe(false);
        expect(attempt.draft.narrative).toBe(draft.narrative);
    });

    it('keeps the original immutable attempt separate from later typing', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        const attempt = work.begin(uuid, true)!;
        work.capture({ ...draft, followups: ['Later typing'] }, 3);
        expect(attempt.draft.followups).toEqual(['Check dinner intake']);
        expect(work.resume()?.draft.followups).toEqual(['Later typing']);
        expect(work.finish(attempt)).toBe(true);
        expect(work.snapshot().phase).toBe('uncertain');
        expect(work.begin('d7b1e824-942a-4ac9-8ef2-fbd4d895bd33', true)).toBeNull();
    });

    it('does not label a legacy finish saved or not saved, or allow uncertainty to be discarded', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        const attempt = work.begin(uuid, false)!;
        work.finish(attempt);
        expect(work.snapshot().phase).toBe('uncertain');
        expect(work.canClose()).toBe(false);
        expect(work.discardUnsent()).toBe(false);
        expect(work.resume()?.draft).toEqual(draft);
    });

    it('conceals prior-account work and permanently invalidates a late callback after context restoration', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        const attempt = work.begin(uuid, false)!;
        work.publishContext({ ...context, actorId: 5 });
        work.observeContext();
        expect(work.resume()).toBeNull();
        expect(work.snapshot().phase).toBe('unavailable');
        work.publishContext(context);
        work.observeContext();
        expect(work.finish(attempt)).toBe(false);
        expect(work.snapshot().phase).toBe('uncertain');
    });

    it('conceals changed-record, withdrawn-read and retired-owner content', () => {
        for (const changed of [{ ...context, recordId: 13 }, { ...context, canRead: false }]) {
            const work = new HandoverWorkLifetime(context);
            work.initialise(draft);
            work.publishContext(changed);
            work.observeContext();
            expect(work.resume()).toBeNull();
            expect(work.canClose()).toBe(false);
        }
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        work.retire();
        expect(work.resume()).toBeNull();
    });

    it('preserves read-only content without permitting a save and rejects invalid record/version pairs', () => {
        const work = new HandoverWorkLifetime({ ...context, canWrite: false });
        work.initialise(draft);
        expect(work.resume()?.draft).toEqual(draft);
        expect(work.begin(uuid, false)).toBeNull();
        const invalid = new HandoverWorkLifetime({ ...context, recordId: null });
        expect(invalid.initialise(draft)).toBe(false);
        expect(invalid.snapshot().phase).toBe('unavailable');
    });

    it('does not invalidate an attempt when equivalent context fields arrive in another order', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        const attempt = work.begin(uuid, false)!;
        work.publishContext({ canWrite: true, canRead: true, expectedVersion: 3, recordId: 12, actorId: 4 });
        expect(work.finish(attempt)).toBe(true);
    });

    it('requires a known baseline before capturing typing and refuses writes after authority withdrawal', () => {
        const work = new HandoverWorkLifetime(context);
        expect(work.capture(draft, 1)).toBe(false);
        work.initialise(draft);
        work.publishContext({ ...context, canWrite: false });
        work.observeContext();
        expect(work.capture({ ...draft, narrative: 'Later typing' }, 1)).toBe(false);
        expect(work.resume()?.draft.narrative).toBe(draft.narrative);
        expect(work.begin(uuid, false)).toBeNull();
    });

    it('retires without notifying during the commit phase and denies callbacks immediately', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        const attempt = work.begin(uuid, false)!;
        let notifications = 0;
        work.subscribe(() => { ++notifications; });
        work.retire();
        expect(notifications).toBe(0);
        expect(work.resume()).toBeNull();
        expect(work.finish(attempt)).toBe(false);
        expect(work.snapshot().phase).toBe('unavailable');
    });

    it('rechecks authority after a synchronous pending subscriber before admitting dispatch', () => {
        for (const change of ['account', 'write', 'retire'] as const) {
            const work = new HandoverWorkLifetime(context);
            work.initialise(draft);
            work.subscribe(() => {
                if (work.snapshot().phase !== 'pending') return;
                if (change === 'retire') work.retire();
                else work.publishContext(change === 'account'
                    ? { ...context, actorId: 5 }
                    : { ...context, canWrite: false });
            });
            expect(work.begin(uuid, false)).toBeNull();
            expect(work.snapshot().phase).toBe(change === 'write' ? 'uncertain' : 'unavailable');
            work.publishContext(context);
            work.observeContext();
            expect(work.begin('d7b1e824-942a-4ac9-8ef2-fbd4d895bd33', false)).toBeNull();
            expect(work.discardUnsent()).toBe(false);
        }
    });

    it('guards dirty navigation from committed work before the restored-context UI notification', () => {
        const work = new HandoverWorkLifetime(context);
        work.initialise(draft);
        work.capture({ ...draft, narrative: 'Retained unsent typing' }, 1);
        work.publishContext({ ...context, canRead: false });
        work.observeContext();
        expect(work.snapshot().dirty).toBe(false);
        work.publishContext(context);
        expect(work.canClose()).toBe(true);
        expect(work.canNavigate()).toBe(false);
        expect(work.resume()?.draft.narrative).toBe('Retained unsent typing');
        work.observeContext();
        expect(work.snapshot().dirty).toBe(true);
    });
});
