import { describe, expect, it } from 'vitest';
import {
    changedChannels,
    mergeDraft,
    mergeMapDraft,
    type MapValues,
} from './_types';

describe('notification conflict recovery', () => {
    it('keeps edited channels and adopts concurrent changes to untouched channels', () => {
        const base = { booking: { inapp: true, email: false } };
        expect(
            mergeDraft(
                base,
                { booking: { inapp: false, email: false } },
                {
                    booking: { inapp: true, email: true },
                    import: { email: false },
                },
            ),
        ).toEqual({
            booking: { inapp: false, email: true },
            import: { email: false },
        });
    });
    it('preserves an intentional return to inheritance without dropping other concurrent changes', () => {
        expect(
            mergeDraft(
                { booking: { email: false } },
                {},
                { booking: { inapp: false, email: true } },
            ),
        ).toEqual({ booking: { inapp: false } });
    });
    it('treats equal effective values with different inheritance as a meaningful change', () => {
        expect(changedChannels({}, { booking: { inapp: true } })).toEqual([
            { key: 'booking', channel: 'inapp' },
        ]);
    });
});
it('preserves edited map fields while adopting a concurrent capability change', () => {
    const base: MapValues = {
        google: false,
        project: '',
        display: false,
        places: false,
        geocoding: false,
        routes: false,
        restrictions_reviewed: false,
        terms_reviewed: false,
    };
    expect(
        mergeMapDraft(
            base,
            { ...base, project: 'my-project' },
            { ...base, display: true },
        ),
    ).toEqual({ ...base, project: 'my-project', display: true });
});
