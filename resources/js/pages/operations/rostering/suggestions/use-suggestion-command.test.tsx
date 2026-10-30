import { act, renderHook } from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    currentSuggestionPage,
    suggestionHash,
    suggestionReceipt,
    useSuggestionCommand,
    type SuggestionIntent,
    type SuggestionSource,
} from './use-suggestion-command';
const transport = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    on: vi.fn<(name: string, handler: (event: unknown) => void) => () => void>(
        () => vi.fn(),
    ),
}));
vi.mock('@inertiajs/react', () => ({ router: transport }));
const source: SuggestionSource = {
    run_id: 17,
    site_id: 2,
    suggestion_id: 23,
    shift_id: 42,
    candidate_user_id: 3,
    status: 'suggested',
    source_revision: 'a'.repeat(64),
};
const intent: SuggestionIntent = { action: 'accept', source };
const requestId = '718d9ab2-3476-4bdf-a75d-a5f020890e25';
const stamp = '2026-10-08T01:00:00.000Z';
function page(receipt?: unknown) {
    return {
        props: {
            auth: { user: { id: 1 } },
            errors: {},
            flash: { roster_suggestion_result: receipt },
            run: {
                id: 17,
                site: { id: 2 },
                status: 'completed',
                is_expired: false,
                can: { apply_accepted: true },
                urls: { apply_accepted: '/apply-accepted' },
            },
            suggestions: [
                {
                    id: 23,
                    shift_id: 42,
                    candidate_user_id: 3,
                    status: 'accepted',
                    source_revision: 'b'.repeat(64),
                    can: { accept: true, dismiss: true, apply: true },
                    urls: {
                        accept: '/accept',
                        dismiss: '/dismiss',
                        apply: '/apply',
                    },
                },
            ],
            worker_timezone: 'Pacific/Auckland',
            suggestion_visibility: {
                basis: 'current_canonical_run_site',
                recorded_count: 1,
                visible_count: 1,
                withheld_count: 0,
            },
        },
    };
}
async function receipt(command = intent, uuid = requestId) {
    return {
        action: command.action,
        actor_id: 1,
        request_id: uuid,
        scope: 'single',
        run_id: 17,
        site_id: 2,
        suggestion_id: 23,
        expected_source: command.source,
        values_hash: await suggestionHash(command),
        outcome: 'accepted',
        changed: true,
        disposition: 'single',
        counts: { selected: 1, applied: 0, stale: 0, failed: 0 },
        suggestion: {
            id: 23,
            status: 'accepted',
            accepted_by: 1,
            accepted_at: stamp,
            dismissed_by: null,
            dismissed_at: null,
            applied_by: null as number | null,
            applied_at: null as string | null,
        },
        assignments: [] as Array<{
            suggestion_id: number;
            shift_id: number;
            user_id: number;
            status: string;
            starts_at: string;
            ends_at: string;
        }>,
    };
}
const expectation = { ...intent, actorId: 1, requestId };
beforeEach(() => {
    vi.stubGlobal('crypto', webcrypto);
    transport.post.mockReset();
    transport.reload.mockReset();
    transport.on.mockClear();
});
afterEach(() => vi.unstubAllGlobals());

describe('suggestion command confirmation', () => {
    it('binds success to the current actor, attempt, source and exact submitted intent', async () => {
        const good = await receipt();
        expect(
            suggestionReceipt(page(good), expectation, good.values_hash)
                ?.outcome,
        ).toBe('accepted');
        for (const wrong of [
            { actor_id: 2 },
            { request_id: 'previous-attempt' },
            { action: 'dismiss' },
            { scope: 'accepted_run' },
            { run_id: 18 },
            { site_id: 3 },
            { suggestion_id: 24 },
            { values_hash: 'b'.repeat(64) },
            { expected_source: { ...source, source_revision: 'c'.repeat(64) } },
            { suggestion: { ...good.suggestion, accepted_by: 2 } },
        ])
            expect(
                suggestionReceipt(
                    page({ ...good, ...wrong }),
                    expectation,
                    good.values_hash,
                ),
            ).toBeNull();
        const differentActor = page(good);
        differentActor.props.auth.user.id = 2;
        expect(
            suggestionReceipt(differentActor, expectation, good.values_hash),
        ).toBeNull();
        expect(
            suggestionReceipt(
                { props: { ...page().props, flash: { success: 'Saved' } } },
                expectation,
                good.values_hash,
            ),
        ).toBeNull();
    });
    it('confirms expiry as stale even with a warning, without saying the choice was accepted', async () => {
        const saved = await receipt();
        saved.outcome = 'expired_marked_stale';
        saved.disposition = 'expired';
        saved.suggestion.status = 'stale';
        saved.counts.stale = 1;
        const response = {
            props: {
                ...page(saved).props,
                flash: { roster_suggestion_result: saved, warning: 'Expired' },
            },
        };
        expect(
            suggestionReceipt(response, expectation, saved.values_hash)
                ?.outcome,
        ).toBe('expired_marked_stale');
        saved.suggestion.status = 'accepted';
        expect(
            suggestionReceipt(response, expectation, saved.values_hash),
        ).toBeNull();
    });
    it('requires the assignment to identify the selected worker and full saved duty', async () => {
        const command = { ...intent, action: 'apply' as const };
        const saved = await receipt(command);
        saved.outcome = 'applied';
        saved.counts.applied = 1;
        saved.suggestion.status = 'applied';
        saved.suggestion.applied_by = 1;
        saved.suggestion.applied_at = stamp;
        saved.assignments = [
            {
                suggestion_id: 23,
                shift_id: 42,
                user_id: 3,
                status: 'scheduled',
                starts_at: stamp,
                ends_at: '2026-10-08T08:00:00.000Z',
            },
        ];
        const expected = { ...expectation, ...command };
        expect(
            suggestionReceipt(page(saved), expected, saved.values_hash)
                ?.outcome,
        ).toBe('applied');
        saved.assignments[0].user_id = 4;
        expect(
            suggestionReceipt(page(saved), expected, saved.values_hash),
        ).toBeNull();
        saved.assignments[0].user_id = 3;
        saved.assignments[0].ends_at = stamp;
        expect(
            suggestionReceipt(page(saved), expected, saved.values_hash),
        ).toBeNull();
    });
    it('accepts a whole-run preflight with unclassified choices and distinguishes an empty run', async () => {
        const command = {
            action: 'apply_accepted' as const,
            source: { run_id: 17, site_id: 2 },
        };
        const saved = {
            ...(await receipt(command)),
            scope: 'accepted_run',
            suggestion_id: null,
            suggestion: null,
            outcome: 'not_applied',
            disposition: 'preflight_no_change',
            changed: false,
            counts: { selected: 3, applied: 0, stale: 0, failed: 1 },
        };
        const expected = { ...expectation, ...command };
        expect(
            suggestionReceipt(page(saved), expected, saved.values_hash)?.counts
                .selected,
        ).toBe(3);
        const empty = {
            ...saved,
            outcome: 'unchanged',
            disposition: 'empty',
            counts: { selected: 0, applied: 0, stale: 0, failed: 0 },
        };
        expect(
            suggestionReceipt(page(empty), expected, saved.values_hash)
                ?.outcome,
        ).toBe('unchanged');
        expect(
            suggestionReceipt(
                page({ ...empty, changed: true }),
                expected,
                saved.values_hash,
            ),
        ).toBeNull();
    });
    it('checks the whole applied cohort and rejects duplicate saved assignments', async () => {
        const command = {
            action: 'apply_accepted' as const,
            source: { run_id: 17, site_id: 2 },
        };
        const assignment = {
            suggestion_id: 23,
            shift_id: 42,
            user_id: 3,
            status: 'scheduled',
            starts_at: stamp,
            ends_at: '2026-10-08T08:00:00.000Z',
        };
        const saved = {
            ...(await receipt(command)),
            scope: 'accepted_run',
            suggestion_id: null,
            suggestion: null,
            outcome: 'applied',
            disposition: 'applied',
            counts: { selected: 2, applied: 2, stale: 0, failed: 0 },
            assignments: [
                assignment,
                { ...assignment, suggestion_id: 24, shift_id: 43 },
            ],
        };
        const expected = { ...expectation, ...command };
        expect(
            suggestionReceipt(page(saved), expected, saved.values_hash)?.counts
                .applied,
        ).toBe(2);
        saved.assignments[1].shift_id = 42;
        expect(
            suggestionReceipt(page(saved), expected, saved.values_hash),
        ).toBeNull();
    });
});

describe('suggestion command recovery', () => {
    it('locks immediately during preparation and requires matching success plus the current list', async () => {
        const { result } = renderHook(() => useSuggestionCommand(1, source));
        await act(async () => {
            await Promise.all([
                result.current.submit('/accept', intent),
                result.current.submit('/accept', intent),
            ]);
        });
        expect(transport.post).toHaveBeenCalledTimes(1);
        expect(result.current.busy).toBe(true);
        const payload = transport.post.mock.calls[0][1],
            callbacks = transport.post.mock.calls[0][2];
        expect(payload).toEqual({
            request_id: expect.any(String),
            expected_source: source,
        });
        expect(callbacks.headers).toEqual({
            'X-Roster-Suggestion-Result': 'committed-v1',
        });
        const saved = await receipt(intent, payload.request_id);
        act(() => {
            callbacks.onSuccess(page(saved));
            callbacks.onFinish();
        });
        expect(result.current.blocked).toBe(false);
        expect(result.current.notice?.message).toContain('Choice accepted');
    });
    it('keeps a confirmed action readable but held when the following list is incomplete', async () => {
        const { result } = renderHook(() => useSuggestionCommand(1, source));
        await act(async () => result.current.submit('/accept', intent));
        const saved = await receipt(
            intent,
            transport.post.mock.calls[0][1].request_id,
        );
        const incomplete = {
            props: { ...page(saved).props, suggestions: undefined },
        };
        act(() => {
            transport.post.mock.calls[0][2].onSuccess(incomplete);
            transport.post.mock.calls[0][2].onFinish();
        });
        expect(result.current.notice?.message).toContain('Choice accepted');
        expect(result.current.needsRead).toBe(true);
    });
    it.each(['onFinish', 'onCancel', 'onError'])(
        'holds %s without confirmation, and never replays during read recovery',
        async (callback) => {
            const { result } = renderHook(() =>
                useSuggestionCommand(1, source),
            );
            await act(async () => result.current.submit('/accept', intent));
            act(() =>
                transport.post.mock.calls[0][2][callback]({
                    message: 'Please reload',
                }),
            );
            expect(result.current.needsRead).toBe(true);
            await act(async () => result.current.submit('/accept', intent));
            expect(transport.post).toHaveBeenCalledTimes(1);
            act(() => result.current.refresh());
            const read = transport.reload.mock.calls[0][0];
            act(() => {
                read.onSuccess(page());
                read.onFinish();
            });
            expect(result.current.blocked).toBe(false);
            expect(result.current.notice?.message).toContain(
                'Current suggestions loaded',
            );
            expect(transport.post).toHaveBeenCalledTimes(1);
            act(() => transport.post.mock.calls[0][2].onFinish());
            expect(result.current.blocked).toBe(false);
        },
    );
    it('keeps failed or mismatched reads held and does not confuse other users or runs', () => {
        const { result } = renderHook(() => useSuggestionCommand(1, source));
        act(() => result.current.refresh());
        act(() => transport.reload.mock.calls[0][0].onFinish());
        expect(result.current.needsRead).toBe(true);
        act(() => result.current.refresh());
        const wrong = page();
        wrong.props.run.id = 18;
        act(() => {
            transport.reload.mock.calls[1][0].onSuccess(wrong);
            transport.reload.mock.calls[1][0].onFinish();
        });
        expect(result.current.needsRead).toBe(true);
        expect(currentSuggestionPage(page(), 2, source)).toBe(false);
        const legacy = page();
        delete (
            legacy.props.suggestions[0] as Partial<
                (typeof legacy.props.suggestions)[0]
            >
        ).source_revision;
        expect(currentSuggestionPage(legacy, 1, source)).toBe(false);
    });
    it('serializes reads and commands and ignores a response after the signed-in user changes', async () => {
        const { result, rerender } = renderHook(
            ({ actor }) => useSuggestionCommand(actor, source),
            { initialProps: { actor: 1 } },
        );
        act(() => result.current.refresh());
        await act(async () => result.current.submit('/accept', intent));
        expect(transport.post).not.toHaveBeenCalled();
        rerender({ actor: 2 });
        act(() => {
            transport.reload.mock.calls[0][0].onSuccess(page());
            transport.reload.mock.calls[0][0].onFinish();
        });
        expect(result.current.needsRead).toBe(true);
        expect(result.current.busy).toBe(false);
    });
    it('reports a preparation failure without sending and ignores callbacks after unmount', async () => {
        const { result, unmount } = renderHook(() =>
            useSuggestionCommand(1, source),
        );
        vi.stubGlobal('crypto', {
            randomUUID: () => {
                throw new Error('Unavailable');
            },
        });
        await act(async () => result.current.submit('/accept', intent));
        expect(transport.post).not.toHaveBeenCalled();
        expect(result.current.notice?.message).toContain('Nothing was sent');
        expect(result.current.blocked).toBe(false);
        vi.stubGlobal('crypto', webcrypto);
        await act(async () => result.current.submit('/accept', intent));
        unmount();
        expect(() => transport.post.mock.calls[0][2].onFinish()).not.toThrow();
        expect(transport.reload).not.toHaveBeenCalled();
    });
});

it('handles only its own invalid response and removes the listener after recovery', async () => {
    const { result } = renderHook(() => useSuggestionCommand(1, source));
    await act(async () => result.current.submit('/accept', intent));
    const payload = transport.post.mock.calls[0][1];
    const listener = transport.on.mock.calls[0][1] as (event: unknown) => void;
    const preventDefault = vi.fn();
    const event = {
        preventDefault,
        detail: {
            response: {
                status: 409,
                config: {
                    headers: { 'X-Roster-Suggestion-Result': 'committed-v1' },
                    data: JSON.stringify({
                        ...payload,
                        request_id: 'other-attempt',
                    }),
                },
            },
        },
    };
    act(() => listener(event));
    expect(preventDefault).not.toHaveBeenCalled();
    expect(result.current.busy).toBe(true);
    event.detail.response.config.data = JSON.stringify(payload);
    act(() => listener(event));
    expect(preventDefault).toHaveBeenCalledTimes(1);
    expect(result.current.needsRead).toBe(true);
    expect(transport.on.mock.results[0].value).toHaveBeenCalledTimes(1);
    expect(transport.post).toHaveBeenCalledTimes(1);
});
