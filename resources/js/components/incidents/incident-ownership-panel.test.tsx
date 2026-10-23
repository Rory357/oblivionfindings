import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useRef, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    post: vi.fn(),
    retry: vi.fn(),
    fetch: vi.fn(),
    processing: false,
}));
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {}, post: state.retry },
    useForm: function useForm(initial: Record<string, unknown>) {
        const defaults = useRef(initial);
        const [data, setData] = useState(initial);
        return {
            data,
            errors: {},
            processing: state.processing,
            isDirty: JSON.stringify(defaults.current) !== JSON.stringify(data),
            setData: (key: string, value: unknown) =>
                setData((previous) => ({ ...previous, [key]: value })),
            post: (url: string, options: unknown) =>
                state.post(url, data, options),
            clearErrors: vi.fn(),
        };
    },
}));

import {
    IncidentOwnershipPanel,
    type IncidentOwnership,
} from './incident-ownership-panel';

function ownership(
    overrides: Partial<IncidentOwnership> = {},
): IncidentOwnership {
    return {
        revision: 0,
        state: 'unassigned',
        owner: null,
        can_assign: true,
        can_retry_delivery: true,
        history: [],
        ...overrides,
    };
}
function panel(value: IncidentOwnership) {
    return (
        <IncidentOwnershipPanel
            incidentId={42}
            reference="INC-42"
            ownership={value}
        />
    );
}
beforeEach(() => {
    vi.clearAllMocks();
    state.processing = false;
    state.fetch.mockResolvedValue({
        ok: true,
        json: async () => ({
            owners: [{ id: 8, name: 'Test Eligible Coordinator' }],
        }),
    });
    vi.stubGlobal('fetch', state.fetch);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Incident ownership controls', () => {
    it('locks the submitted selection and reason until the server responds and rejects duplicate submission', async () => {
        const record = ownership();
        const view = render(panel(record));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            {
                target: { value: 'Agreed handover.' },
            },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        state.processing = true;
        view.rerender(panel(record));
        expect(
            screen.getByLabelText('Search eligible incident owners'),
        ).toBeDisabled();
        expect(
            screen.getByRole('option', { name: 'Test Eligible Coordinator' }),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.submit(
            screen
                .getByRole('button', { name: 'Saving owner…' })
                .closest('form')!,
        );
        expect(state.post).toHaveBeenCalledTimes(1);
        state.processing = false;
        view.rerender(panel(record));
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toBeEnabled();
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Agreed handover.');
    });

    it('retries unchanged input with one identity and uses a new identity when submitted input changes', async () => {
        render(panel(ownership()));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        const first = state.post.mock.calls[0][1];
        expect(first).toMatchObject({
            owner_user_id: '8',
            expected_revision: 0,
            reason: '',
        });
        expect(first.request_uuid).toMatch(/^[0-9a-f-]{36}$/);
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        expect(state.post.mock.calls[1][1]).toEqual(first);
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Agreed responsibility.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        expect(state.post).toHaveBeenLastCalledWith(
            '/incidents/42/owner',
            expect.objectContaining({
                reason: 'Agreed responsibility.',
            }),
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
        const changed = state.post.mock.calls[2][1];
        expect(changed.request_uuid).not.toBe(first.request_uuid);
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        expect(state.post.mock.calls[3][1]).toEqual(changed);
    });

    it('keeps an open draft disabled after assignment authority is withdrawn and requires a fresh eligible selection on restoration', async () => {
        const record = ownership();
        const view = render(panel(record));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Keep this reason.' } },
        );
        view.rerender(panel({ ...record, can_assign: false }));
        expect(
            screen.getByText(
                /You cannot change the incident owner with your current access/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeDisabled();
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        expect(screen.queryByText(/Selected owner:/)).not.toBeInTheDocument();
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Keep this reason.');
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toBeDisabled();
        fireEvent.submit(
            screen.getByRole('button', { name: 'Save owner' }).closest('form')!,
        );
        expect(state.post).not.toHaveBeenCalled();
        view.rerender(panel(record));
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeDisabled();
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeEnabled();
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Keep this reason.');
    });

    it('preserves the draft after an unrelated successful response and closes only for its exact persisted owner change', async () => {
        render(panel(ownership()));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Agreed responsibility.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        const [, , options] = state.post.mock.calls[0];
        const submitted = state.post.mock.calls[0][1];
        const persisted = {
            id: 1,
            request_uuid: submitted.request_uuid,
            owner_user_id: 8,
            revision: 1,
            previous_owner: null,
            owner: 'Test Eligible Coordinator',
            changed_by: 'Test assigner',
            changed_at: '2026-10-12T01:00:00Z',
            reason: 'Agreed responsibility.',
            source: 'manual',
            deliveries: [],
        };
        act(() =>
            options.onSuccess({
                props: {
                    detail: {
                        id: 42,
                        ownership: ownership({
                            history: [
                                {
                                    ...persisted,
                                    request_uuid: crypto.randomUUID(),
                                },
                            ],
                        }),
                    },
                },
            }),
        );
        expect(
            await screen.findByText(/The owner save could not be confirmed/),
        ).toBeVisible();
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Agreed responsibility.');
        act(() =>
            options.onSuccess({
                props: {
                    detail: {
                        id: 99,
                        ownership: ownership({ history: [persisted] }),
                    },
                },
            }),
        );
        expect(screen.getByRole('dialog')).toBeVisible();
        act(() =>
            options.onSuccess({
                props: {
                    detail: {
                        id: 42,
                        ownership: ownership({ history: [persisted] }),
                    },
                },
            }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('requires a reassignment reason and preserves it when dismissal is cancelled', async () => {
        render(
            panel(
                ownership({
                    revision: 2,
                    state: 'assigned',
                    owner: { id: 7, name: 'Current Coordinator' },
                }),
            ),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Change owner' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeDisabled();
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Explicit cover arrangement.' } },
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Explicit cover arrangement.');
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeEnabled();
    });

    it('requires an explicit new selection after a concurrent revision change while keeping the reason', async () => {
        const original = ownership({
            revision: 1,
            state: 'assigned',
            owner: { id: 7, name: 'Original Owner' },
        });
        const view = render(panel(original));
        fireEvent.click(screen.getByRole('button', { name: 'Change owner' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Cover for next week.' } },
        );
        view.rerender(
            panel({
                ...original,
                revision: 2,
                owner: { id: 9, name: 'Updated Owner' },
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use current owner version' }),
        );
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Cover for next week.');
        expect(screen.queryByText('Selected owner:')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save owner' }),
        ).toBeDisabled();
    });

    it('does not present an ineligible owner as currently responsible and keeps notification failure separate', () => {
        render(
            panel(
                ownership({
                    revision: 1,
                    state: 'replacement_needed',
                    owner: { id: 7, name: 'Former Owner' },
                    history: [
                        {
                            id: 1,
                            revision: 1,
                            previous_owner: null,
                            owner: 'Former Owner',
                            changed_by: 'Coordinator',
                            changed_at: '2026-10-07T01:00:00Z',
                            reason: null,
                            source: 'manual',
                            deliveries: [
                                {
                                    id: 'notice-1',
                                    role: 'incoming',
                                    status: 'failed',
                                },
                            ],
                        },
                    ],
                }),
            ),
        );
        expect(screen.getByText('Owner needs replacing')).toBeVisible();
        expect(screen.getByText(/Previous owner: Former Owner/)).toBeVisible();
        expect(
            screen.getByText(/New owner notification: Delivery failed/),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry notification' }),
        );
        expect(state.retry).toHaveBeenCalledWith(
            '/incidents/42/owner-deliveries/notice-1/retry',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('preserves reason text through an eligible-staff lookup failure and retry', async () => {
        state.fetch.mockRejectedValueOnce(
            new Error('Synthetic connection loss'),
        );
        render(panel(ownership()));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Keep this reason.' } },
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Retry staff lookup' }),
        );
        expect(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        ).toBeVisible();
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Keep this reason.');
    });

    it.each([
        { label: 'null response', response: null },
        { label: 'missing owners', response: {} },
        { label: 'non-array owners', response: { owners: null } },
        {
            label: 'invalid owner identity',
            response: { owners: [{ id: '8', name: 'Invalid identity' }] },
        },
        {
            label: 'invalid owner name',
            response: { owners: [{ id: 8, name: null }] },
        },
        {
            label: 'duplicate owner identity',
            response: {
                owners: [
                    { id: 8, name: 'First result' },
                    { id: 8, name: 'Duplicate result' },
                ],
            },
        },
    ])('recovers from $label without losing the reason', async ({ response }) => {
        state.fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => response,
        });
        render(panel(ownership()));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Keep the agreed handover.' } },
        );
        const retry = await screen.findByRole('button', {
            name: 'Retry staff lookup',
        });
        expect(screen.queryAllByRole('option')).toHaveLength(0);
        expect(screen.getByRole('button', { name: 'Save owner' })).toBeDisabled();
        fireEvent.click(retry);
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        expect(
            screen.getByLabelText(/Reason for this ownership change/),
        ).toHaveValue('Keep the agreed handover.');
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        expect(state.post).toHaveBeenCalledWith(
            '/incidents/42/owner',
            expect.objectContaining({
                owner_user_id: '8',
                reason: 'Keep the agreed handover.',
            }),
            expect.any(Object),
        );
    });

    it('keeps an empty successful lookup distinct from a failed lookup', async () => {
        state.fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ owners: [] }),
        });
        render(panel(ownership()));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        expect(
            await screen.findByText('No eligible staff match this search.'),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Retry staff lookup' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save owner' })).toBeDisabled();
    });

    it('blocks a retained selection during lookup failure and retries the same owner command after recovery', async () => {
        render(panel(ownership()));
        fireEvent.click(screen.getByRole('button', { name: 'Assign someone' }));
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Test Eligible Coordinator',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(/Reason for this ownership change/),
            { target: { value: 'Keep this owner and reason.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        const submitted = state.post.mock.calls[0][1];
        state.fetch.mockRejectedValueOnce(new Error('Synthetic connection loss'));
        fireEvent.change(screen.getByLabelText('Search eligible incident owners'), {
            target: { value: 'Coordinator' },
        });
        const save = screen.getByRole('button', { name: 'Save owner' });
        expect(save).toBeDisabled();
        fireEvent.submit(save.closest('form')!);
        expect(state.post).toHaveBeenCalledTimes(1);
        const retry = await screen.findByRole('button', {
            name: 'Retry staff lookup',
        });
        expect(save).toBeDisabled();
        fireEvent.submit(save.closest('form')!);
        expect(state.post).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Selected owner:')).toHaveTextContent(
            'Test Eligible Coordinator',
        );
        fireEvent.click(retry);
        await screen.findByRole('option', { name: 'Test Eligible Coordinator' });
        fireEvent.click(screen.getByRole('button', { name: 'Save owner' }));
        expect(state.post).toHaveBeenCalledTimes(2);
        expect(state.post.mock.calls[1][1]).toEqual(submitted);
    });

    it('preserves historical ownership without offering assignment on a closed record', () => {
        render(panel(ownership({ state: 'historical', can_assign: false })));
        expect(screen.getByText('Owner not recorded')).toBeVisible();
        expect(screen.queryByText('Needs an owner')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Assign someone' }),
        ).not.toBeInTheDocument();
    });
});
