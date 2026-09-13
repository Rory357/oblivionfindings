import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    forgetIntakeDraft,
    intakeDraftLocators,
    preferredIntakeDraft,
    retainIntakeDraft,
} from './it-intake-draft-locator';
const one = '9c3e9caa-4bc9-41ba-8b90-bf654120f167';
const two = 'f024eb01-fa17-4cdb-a057-179d056973f6';
afterEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
});
describe('Opaque intake draft locators', () => {
    it('retains independent same-actor contexts and an old-tab clear cannot erase another generation', () => {
        expect(retainIntakeDraft(230, 'requester_intake', one)).toBe(true);
        expect(retainIntakeDraft(230, 'requester_intake', two)).toBe(true);
        expect(intakeDraftLocators(true, 230, 'requester_intake')).toEqual([
            one,
            two,
        ]);
        forgetIntakeDraft(230, 'requester_intake', one);
        expect(preferredIntakeDraft(true, 230, 'requester_intake')).toBe(two);
        forgetIntakeDraft(230, 'requester_intake', one);
        expect(intakeDraftLocators(true, 230, 'requester_intake')).toEqual([
            two,
        ]);
        expect(Object.values(localStorage)).toEqual([two]);
    });
    it('separates purposes/actors, ignores malformed entries and performs no reads when disabled', () => {
        retainIntakeDraft(230, 'requester_intake', one);
        expect(intakeDraftLocators(true, 231, 'requester_intake')).toEqual([]);
        expect(intakeDraftLocators(true, 230, 'technician_intake')).toEqual([]);
        localStorage.setItem(
            'it.draft-context.v1.actor.230.requester_intake.bad',
            'private forged data',
        );
        expect(intakeDraftLocators(true, 230, 'requester_intake')).toEqual([
            one,
        ]);
        const read = vi.spyOn(Storage.prototype, 'getItem');
        expect(
            preferredIntakeDraft(false, 230, 'requester_intake'),
        ).toBeUndefined();
        expect(read).not.toHaveBeenCalled();
    });
    it('reports storage failure instead of claiming a recoverable locator', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('Storage disabled');
        });
        expect(retainIntakeDraft(230, 'requester_intake', one)).toBe(false);
    });
});
