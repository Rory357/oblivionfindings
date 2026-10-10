import { act, renderHook } from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    currentTemplateLibrary,
    normalizeTemplateValues,
    templateHash,
    templateReceipt,
    useTemplateCommand,
    type TemplateIntent,
    type TemplateLibraryData,
    type TemplateValues,
} from './use-template-command';
const transport = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    on: vi.fn<(name: string, listener: (event: unknown) => void) => () => void>(
        () => vi.fn(),
    ),
}));
vi.mock('@inertiajs/react', () => ({ router: transport }));
const capabilities = {
    can_view: true,
    can_create: true,
    can_edit: true,
    can_delete: true,
    can_duplicate: true,
    can_apply: true,
};
const revision = 'a'.repeat(64),
    changed = 'b'.repeat(64),
    uuid = '718d9ab2-3476-4bdf-a75d-a5f020890e25';
const values: TemplateValues = {
    name: 'Pattern',
    description: 'Support',
    template_type: 'weekly',
    is_active: true,
    template_shifts: [
        {
            client_id: 10,
            user_id: 30,
            service_context_id: 20,
            day_of_week: 0,
            start_time: '22:15',
            end_time: '07:05',
            shift_type: 'sleepover',
            is_sleepover: true,
            is_on_call: false,
            is_lone_worker: true,
            expected_break_minutes: 0,
            required_skills: ['Hoist'],
            location: 'North house',
            notes: 'Keep handover detail',
        },
    ],
};
const data: TemplateLibraryData = {
    rosterTemplates: [
        {
            ...values,
            id: 1,
            source_revision: revision,
            capabilities,
            template_shifts_count: 1,
            urls: {
                update: '/update',
                duplicate: '/copy',
                delete: '/delete',
                apply: '/apply',
            },
        },
    ],
    templateCapabilities: capabilities,
    templateOptions: {
        clients: [{ id: 10, name: 'Person', site_id: 1 }],
        staff: [{ id: 30, name: 'Worker' }],
        serviceContexts: [{ id: 20, name: 'Support', site_id: 1 }],
    },
    workerTimezone: 'Pacific/Auckland',
};
const intent: TemplateIntent = {
    action: 'update',
    source: { template_id: 1, source_revision: revision },
    values,
    rowCount: 1,
};
const expected = { ...intent, actorId: 7, requestId: uuid };
function page(receipt?: unknown, library = data) {
    return {
        props: {
            ...structuredClone(library),
            auth: { user: { id: 7 } },
            errors: {},
            flash: { roster_template_result: receipt },
        },
    };
}
async function receipt(command = intent, requestId = uuid) {
    return {
        version: 1,
        scope: 'library',
        action: command.action,
        actor_id: 7,
        request_id: requestId,
        template_id: command.action === 'create' ? 2 : 1,
        copy_id: command.action === 'duplicate' ? 2 : null,
        expected_source: command.source,
        source_revision: command.source?.source_revision ?? null,
        result_revision: command.action === 'delete' ? null : changed,
        values_hash: await templateHash(command),
        outcome:
            command.action === 'delete'
                ? 'deleted'
                : command.action === 'duplicate'
                  ? 'copied'
                  : 'saved',
        changed: true,
        template_shifts_count: 1,
        committed_at: '2026-10-08T01:00:00.000Z',
    };
}
function currentPage(saved: Awaited<ReturnType<typeof receipt>>) {
    const response = page(saved);
    if (saved.action === 'delete') response.props.rosterTemplates = [];
    else
        response.props.rosterTemplates = [
            {
                ...data.rosterTemplates[0],
                id: saved.copy_id ?? saved.template_id,
                source_revision: saved.result_revision!,
            },
        ];
    return response;
}
beforeEach(() => {
    vi.stubGlobal('crypto', webcrypto);
    transport.visit.mockReset();
    transport.reload.mockReset();
    transport.on.mockClear();
});
afterEach(() => vi.unstubAllGlobals());
describe('template intent and committed outcome', () => {
    it('preserves all support fields while matching middleware normalization', async () => {
        const draft = structuredClone(values);
        draft.name = '\u200D Pattern \u034F';
        draft.description = '0';
        Object.assign(draft.template_shifts[0], {
            shift_type: ' sleepover ',
            is_sleepover: false,
            required_skills: [' Hoist ', '0', '', 'Hoist'],
            location: '0',
            notes: ' handover ',
        });
        const result = normalizeTemplateValues(draft);
        expect(result).toEqual({
            ...values,
            description: '0',
            template_shifts: [
                {
                    ...values.template_shifts[0],
                    required_skills: ['Hoist', 'Hoist'],
                    location: null,
                    notes: 'handover',
                },
            ],
        });
        expect(await templateHash({ ...intent, values: draft })).toBe(
            await templateHash({ ...intent, values: result }),
        );
        const modified = structuredClone(intent);
        modified.values!.template_shifts[0].is_lone_worker = false;
        expect(await templateHash(modified)).not.toBe(
            await templateHash(intent),
        );
    });
    it.each(['create', 'update', 'duplicate', 'delete'] as const)(
        'confirms %s only with a complete matching receipt',
        async (action) => {
            const command = {
                ...intent,
                action,
                source: action === 'create' ? null : intent.source,
                values:
                    action === 'delete' || action === 'duplicate'
                        ? null
                        : values,
            };
            const saved = await receipt(command);
            expect(
                templateReceipt(
                    page(saved),
                    { ...command, actorId: 7, requestId: uuid },
                    saved.values_hash,
                )?.action,
            ).toBe(action);
            for (const wrong of [
                { actor_id: 8 },
                { request_id: 'old' },
                { values_hash: changed },
                { source_revision: 'c'.repeat(64) },
                { template_shifts_count: 2 },
                { scope: 'apply' },
                { committed_at: '2026-10-08' },
                { changed: false },
            ])
                expect(
                    templateReceipt(
                        page({ ...saved, ...wrong }),
                        { ...command, actorId: 7, requestId: uuid },
                        saved.values_hash,
                    ),
                ).toBeNull();
        },
    );
    it('matches the independently pinned PHP normalization vector', async () => {
        const normalized: TemplateValues = {
            name: '\u200D Pattern \u034F',
            description: '0',
            template_type: 'weekly',
            is_active: true,
            template_shifts: [
                {
                    client_id: 1,
                    user_id: null,
                    service_context_id: null,
                    day_of_week: 1,
                    start_time: '09:17',
                    end_time: '11:43',
                    shift_type: 'sleepover',
                    is_sleepover: false,
                    is_on_call: false,
                    is_lone_worker: false,
                    expected_break_minutes: 0,
                    required_skills: [' Hoist ', '0', '', 'Hoist'],
                    location: '0',
                    notes: ' handover ',
                },
            ],
        };
        expect(await templateHash({ ...intent, values: normalized })).toBe(
            '9a87af7d4027ec3bd1d92aced72c3898fabcf2d33700ca795404effb592e4b40',
        );
    });
    it('accepts unchanged update only with the unchanged aggregate revision', async () => {
        const saved = {
            ...(await receipt()),
            outcome: 'unchanged',
            changed: false,
            result_revision: revision,
        };
        expect(
            templateReceipt(page(saved), expected, saved.values_hash)?.outcome,
        ).toBe('unchanged');
        expect(
            templateReceipt(
                page({ ...saved, result_revision: changed }),
                expected,
                saved.values_hash,
            ),
        ).toBeNull();
    });
    it('does not treat generic success, stale receipts or another user as confirmation', async () => {
        const saved = await receipt();
        const response = page(saved);
        response.props.auth.user.id = 8;
        expect(
            templateReceipt(response, expected, saved.values_hash),
        ).toBeNull();
        expect(
            templateReceipt(
                {
                    props: {
                        ...page().props,
                        flash: { success: 'Roster template updated.' },
                    },
                },
                expected,
                saved.values_hash,
            ),
        ).toBeNull();
        expect(
            templateReceipt(
                page(saved),
                {
                    ...expected,
                    source: { template_id: 1, source_revision: changed },
                },
                saved.values_hash,
            ),
        ).toBeNull();
    });
    it('requires current actor, complete options, capabilities and complete row counts for recovery', () => {
        expect(currentTemplateLibrary(page(), 7)).not.toBeNull();
        for (const props of [
            { auth: { user: { id: 8 } } },
            { errors: { name: 'Required' } },
            { templateCapabilities: { can_view: true } },
            { templateOptions: {} },
            {
                rosterTemplates: [
                    { ...data.rosterTemplates[0], template_shifts_count: 2 },
                ],
            },
            {
                rosterTemplates: [
                    { ...data.rosterTemplates[0], source_revision: null },
                ],
            },
        ])
            expect(
                currentTemplateLibrary(
                    { props: { ...page().props, ...props } },
                    7,
                ),
            ).toBeNull();
        expect(
            currentTemplateLibrary(
                { props: { ...page().props, errors: { name: 'Required' } } },
                7,
                true,
            ),
        ).not.toBeNull();
    });
});
describe('template command recovery', () => {
    it('locks synchronously before hashing and sends the displayed source exactly once', async () => {
        const { result } = renderHook(() => useTemplateCommand(7, data));
        await act(async () => {
            await Promise.all([
                result.current.submit(intent),
                result.current.submit(intent),
            ]);
        });
        expect(transport.visit).toHaveBeenCalledTimes(1);
        const [url, options] = transport.visit.mock.calls[0];
        expect(url).toBe('/update');
        expect(options.method).toBe('put');
        expect(options.data.expected_source).toEqual(intent.source);
        expect(options.headers).toEqual({
            'X-Roster-Template-Result': 'committed-v1',
        });
        expect(result.current.busy).toBe(true);
    });
    it('calls the confirmation handler only once for the matching committed attempt', async () => {
        const confirmed = vi.fn();
        const { result } = renderHook(() => useTemplateCommand(7, data));
        await act(async () => {
            await result.current.submit(intent, confirmed);
        });
        const options = transport.visit.mock.calls[0][1],
            saved = await receipt(intent, options.data.request_id);
        act(() => {
            options.onSuccess(currentPage(saved));
            options.onFinish();
            options.onFinish();
        });
        expect(confirmed).toHaveBeenCalledTimes(1);
        expect(result.current.needsRead).toBe(false);
    });
    it('keeps a confirmed save held if the response list does not contain that revision', async () => {
        const confirmed = vi.fn();
        const { result } = renderHook(() => useTemplateCommand(7, data));
        await act(async () => {
            await result.current.submit(intent, confirmed);
        });
        const options = transport.visit.mock.calls[0][1],
            saved = await receipt(intent, options.data.request_id);
        act(() => {
            options.onSuccess(page(saved));
            options.onFinish();
        });
        expect(confirmed).toHaveBeenCalledTimes(1);
        expect(result.current.needsRead).toBe(true);
    });
    it.each(['onCancel', 'onFinish'] as const)(
        'holds an uncertain %s outcome until an explicit current read',
        async (callback) => {
            const { result } = renderHook(() => useTemplateCommand(7, data));
            await act(async () => {
                await result.current.submit(intent);
            });
            const options = transport.visit.mock.calls[0][1];
            act(() => options[callback]());
            await act(async () => {
                await result.current.submit(intent);
            });
            expect(transport.visit).toHaveBeenCalledTimes(1);
            expect(result.current.needsRead).toBe(true);
            act(() => result.current.refresh());
            const read = transport.reload.mock.calls[0][0];
            act(() => {
                read.onSuccess(page());
                read.onFinish();
            });
            expect(result.current.needsRead).toBe(false);
        },
    );
    it('serializes reads and writes and keeps failed reads held', async () => {
        const { result } = renderHook(() => useTemplateCommand(7, data));
        act(() => result.current.refresh());
        await act(async () => {
            await result.current.submit(intent);
        });
        expect(transport.visit).not.toHaveBeenCalled();
        const read = transport.reload.mock.calls[0][0];
        act(() => {
            read.onSuccess({ props: {} });
            read.onFinish();
        });
        expect(result.current.needsRead).toBe(true);
    });
    it('preserves field errors and requires current library read before retry', async () => {
        const errors = vi.fn();
        const { result } = renderHook(() => useTemplateCommand(7, data));
        await act(async () => {
            await result.current.submit(intent, undefined, errors);
        });
        act(() =>
            transport.visit.mock.calls[0][1].onError({
                'template_shifts.0.client_id': 'Choose an accessible client.',
            }),
        );
        expect(errors).toHaveBeenCalledWith({
            'template_shifts.0.client_id': 'Choose an accessible client.',
        });
        expect(result.current.notice?.kind).toBe('error');
        expect(result.current.needsRead).toBe(true);
    });
    it('blocks stale revisions and missing row or route permissions before sending', async () => {
        const { result } = renderHook(() =>
            useTemplateCommand(7, {
                ...data,
                rosterTemplates: [
                    {
                        ...data.rosterTemplates[0],
                        capabilities: { ...capabilities, can_edit: false },
                    },
                ],
            }),
        );
        await act(async () => {
            await result.current.submit(intent);
        });
        expect(transport.visit).not.toHaveBeenCalled();
    });
    it('ignores callbacks after actor change and after unmount', async () => {
        const confirmed = vi.fn();
        const { result, rerender, unmount } = renderHook(
            ({ actor }) => useTemplateCommand(actor, data),
            { initialProps: { actor: 7 } },
        );
        await act(async () => {
            await result.current.submit(intent, confirmed);
        });
        const options = transport.visit.mock.calls[0][1],
            saved = await receipt(intent, options.data.request_id);
        rerender({ actor: 8 });
        act(() => {
            options.onSuccess(currentPage(saved));
            options.onFinish();
        });
        expect(confirmed).not.toHaveBeenCalled();
        unmount();
        options.onFinish();
        expect(confirmed).not.toHaveBeenCalled();
    });
    it('catches only its own invalid response and leaves unrelated errors alone', async () => {
        const { result } = renderHook(() => useTemplateCommand(7, data));
        await act(async () => {
            await result.current.submit(intent);
        });
        const options = transport.visit.mock.calls[0][1],
            listener = transport.on.mock.calls[0][1] as (
                event: unknown,
            ) => void,
            preventDefault = vi.fn();
        act(() =>
            listener({
                preventDefault,
                detail: {
                    response: {
                        config: {
                            headers: options.headers,
                            data: { request_id: 'other' },
                        },
                    },
                },
            }),
        );
        expect(preventDefault).not.toHaveBeenCalled();
        act(() =>
            listener({
                preventDefault,
                detail: {
                    response: {
                        config: {
                            headers: options.headers,
                            data: JSON.stringify(options.data),
                        },
                    },
                },
            }),
        );
        expect(preventDefault).toHaveBeenCalledTimes(1);
        expect(result.current.needsRead).toBe(true);
    });
});
