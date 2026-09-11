import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ItSetupIndex from './index';
import { clearSetupMemory } from './use-setup-memory';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        props: { auth: { user: { id: 8 } } },
        url: '/it/setup?tab=queues',
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/it/it-module-shell', () => ({
    ItModuleShell: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const manager = { id: 10, name: 'Accountable manager' };
const cover = { id: 11, name: 'Current cover' };
const unrelated = { id: 12, name: 'Other team technician' };
const queue: ComponentProps<typeof ItSetupIndex>['queues'][number] = {
    id: 20,
    key: 'service-desk',
    name: 'IT service desk',
    description: 'Current purpose',
    configuration_version: 'a'.repeat(64),
    is_active: true,
    team: { id: 1, name: 'IT team' },
    filter_rules: {
        is_default: true,
        cover_user_id: cover.id,
        site_ids: [100],
        routing_priority: 5,
    },
    readiness: { ready: true, gaps: [], accountable_owner: manager, cover },
    workload: { open_tickets: 0, unassigned: 0, sla_risk: 0 },
};
const props: ComponentProps<typeof ItSetupIndex> = {
    teams: [
        {
            id: 1,
            name: 'IT team',
            description: null,
            manager,
            members: [{ ...cover, role: 'member' }],
            is_active: true,
            workload: { open_tickets: 0, open_tasks: 0, queues: 1, members: 2 },
        },
    ],
    queues: [queue],
    services: [],
    agents: [manager, cover, unrelated].map((agent) => ({
        ...agent,
        site_ids: [100],
        organisation_wide: false,
    })),
    sites: [
        { id: 100, name: 'Approved Site A' },
        { id: 101, name: 'Approved Site B' },
    ],
    apiIdentities: [],
    oneTimeApiCredential: null,
    provisioningTemplates: [],
};
function openQueue() {
    const view = render(<ItSetupIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: /^IT service desk/ }));
    return view;
}
function step(label: string) {
    fireEvent.click(
        within(screen.getByRole('complementary')).getByRole('button', {
            name: new RegExp(label),
        }),
    );
}
function save() {
    step('Review');
    fireEvent.click(screen.getByRole('button', { name: 'Save queue' }));
}
function currentResponse(
    current: unknown = {
        ...queue,
        name: 'Saved by another editor',
        configuration_version: 'b'.repeat(64),
        filter_rules: { ...queue.filter_rules, routing_priority: 40 },
    },
) {
    return {
        ok: true,
        headers: new Headers({ 'content-type': 'application/json' }),
        json: async () => ({
            resource: 'queues',
            viewer_user_id: 8,
            records: [current],
        }),
    } as Response;
}

describe('accountable queue setup', () => {
    it('recovers selected routing values and the original version after leaving without a modal close', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockImplementation(async (_url, raw) => {
                const data = raw as Record<string, unknown>;
                return {
                    status: 200,
                    data: {
                        candidate: {
                            candidate_uuid: data.candidate_uuid,
                            actor_user_id: 8,
                            resource: 'queues',
                            record_id: queue.id,
                            context_uuid: data.context_uuid,
                            configuration_version: data.configuration_version,
                            current_configuration_version: 'b'.repeat(64),
                            authorized: true,
                            capabilities: { submit: false },
                            blocker: 'configuration_changed',
                        },
                    },
                };
            });
        const first = openQueue();
        fireEvent.change(screen.getByLabelText('Queue name'), {
            target: { value: 'Latest retained queue!' },
        });
        step('Accountability');
        first.unmount();
        openQueue();
        expect(
            screen.queryByDisplayValue('Latest retained queue!'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Resume form 1' }));
        await screen.findByText(
            'Retained work restored. Review the proposed details before saving.',
        );
        expect(
            screen.getByRole('combobox', { name: 'Absence cover' }),
        ).toHaveValue('11');
        step('Queue details');
        expect(screen.getByLabelText('Queue name')).toHaveValue(
            'Latest retained queue!',
        );
        step('Review');
        expect(
            screen.getByRole('button', { name: 'Save queue' }),
        ).toBeDisabled();
        const body = post.mock.calls[0][1] as {
            fields: { site_ids: number[] };
            configuration_version: string;
        };
        expect(body.fields.site_ids).toEqual([100]);
        expect(body.configuration_version).toBe('a'.repeat(64));
    });
    afterEach(() => {
        clearSetupMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('requires an explicit save after advancing to routing rules and focuses corrected identity errors', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockImplementation(() => new Promise(() => {}));
        render(<ItSetupIndex {...props} queues={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'New queue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Queue name' }),
            ).toHaveFocus(),
        );
        expect(
            screen.getByRole('textbox', { name: 'Queue name' }),
        ).toHaveAttribute('aria-invalid', 'true');
        fireEvent.change(screen.getByRole('textbox', { name: 'Queue name' }), {
            target: { value: 'Reviewed queue' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Stable key' }), {
            target: { value: 'reviewed-queue' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.queryByText('Enter a queue name.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Enter a stable key.'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        const continueButton = screen.getByRole('button', { name: 'Continue' });
        fireEvent.click(continueButton);
        const saveButton = screen.getByRole('button', { name: 'Save queue' });
        // A clicked node must not turn into a submit control during native activation.
        expect(saveButton).not.toBe(continueButton);
        expect(continueButton).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(saveButton);
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('treats implicit form submission on an earlier step as navigation without saving', () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        render(<ItSetupIndex {...props} queues={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'New queue' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Queue name' }), {
            target: { value: 'Reviewed queue' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Stable key' }), {
            target: { value: 'reviewed-queue' },
        });
        fireEvent.submit(
            screen
                .getByRole('textbox', { name: 'Queue name' })
                .closest('form')!,
        );
        expect(
            screen.getByRole('combobox', { name: 'Accountable team' }),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });

    it('retains a now-ineligible configured cover visibly while excluding candidates outside any selected Site', () => {
        const acrossSites = {
            id: 13,
            name: 'Two-Site cover',
            site_ids: [100, 101],
            organisation_wide: false,
        };
        const wideOnly = {
            id: 14,
            name: 'Organisation-wide only',
            site_ids: [],
            organisation_wide: true,
        };
        render(
            <ItSetupIndex
                {...props}
                teams={[
                    {
                        ...props.teams[0],
                        members: [
                            ...props.teams[0].members,
                            { ...acrossSites, role: 'member' },
                            { ...wideOnly, role: 'member' },
                        ],
                    },
                ]}
                agents={[...props.agents, acrossSites, wideOnly]}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /^IT service desk/ }),
        );
        step('Routing rules');
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Approved Site B' }),
        );
        step('Accountability');
        const selected = screen.getByRole('combobox', {
            name: 'Absence cover',
        });
        expect(selected).toHaveValue(String(cover.id));
        expect(
            within(selected).getByRole('option', {
                name: 'Unavailable — choose another person',
            }),
        ).toBeDisabled();
        expect(
            within(selected).getByRole('option', { name: acrossSites.name }),
        ).toBeVisible();
        expect(
            within(selected).queryByRole('option', { name: wideOnly.name }),
        ).not.toBeInTheDocument();
    });

    it('retains the draft and permits review retry after a session-expired HTML response', async () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce({
                    ok: true,
                    json: async () => {
                        throw new SyntaxError('Unexpected HTML from sign-in');
                    },
                })
                .mockResolvedValueOnce(currentResponse()),
        );
        openQueue();
        fireEvent.change(screen.getByLabelText('Queue name'), {
            target: { value: 'Retain across sign-in' },
        });
        save();
        act(() => {
            patch.mock.calls[0][2]?.onError?.({
                configuration_version: 'Review required.',
            });
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current queue' }),
        );
        expect(
            await screen.findByText(
                /Your session may have expired; sign in in another tab/,
            ),
        ).toBeVisible();
        expect(
            screen.queryByText('Unexpected HTML from sign-in'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current queue' }),
        );
        await screen.findByText('Saved by another editor');
        step('Queue details');
        expect(screen.getByLabelText('Queue name')).toHaveValue(
            'Retain across sign-in',
        );
        expect(patch).toHaveBeenCalledOnce();
    });

    it('uses the approved wizard, restricts cover to a distinct team member and persists selected Sites', () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        openQueue();
        expect(
            screen.getByRole('dialog', { name: 'Edit queue' }),
        ).toBeVisible();
        step('Accountability');
        const select = screen.getByRole('combobox', { name: 'Absence cover' });
        expect(
            within(select).getByRole('option', { name: cover.name }),
        ).toBeVisible();
        expect(
            within(select).queryByRole('option', { name: manager.name }),
        ).not.toBeInTheDocument();
        expect(
            within(select).queryByRole('option', { name: unrelated.name }),
        ).not.toBeInTheDocument();
        fireEvent.change(select, { target: { value: '' } });
        step('Routing rules');
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Approved Site B' }),
        );
        step('Review');
        fireEvent.click(screen.getByRole('button', { name: 'Save queue' }));
        expect(patch.mock.calls[0][0]).toBe('/it/setup/queues/20');
        expect(patch.mock.calls[0][1]).toEqual({
            actor_user_id: 8,
            configuration_version: queue.configuration_version,
            cover_user_id: '',
            site_ids: [100, 101],
        });
    });

    it('keeps a failed save visible with its draft instead of treating an error flash as success', () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        openQueue();
        fireEvent.change(screen.getByLabelText('Queue name'), {
            target: { value: 'Retained proposal' },
        });
        save();
        act(() => {
            patch.mock.calls[0][2]?.onSuccess?.({
                props: {
                    flash: { error: 'The cover needs approved Site access.' },
                },
            } as unknown as Page);
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The cover needs approved Site access.',
        );
        step('Queue details');
        expect(screen.getByLabelText('Queue name')).toHaveValue(
            'Retained proposal',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('alertdialog', {
                name: 'Discard this queue draft?',
            }),
        ).toBeVisible();
    });

    it('reviews and explicitly adopts a current version without submitting or overwriting unrelated concurrent fields', async () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        const fetch = vi.fn().mockResolvedValue(currentResponse());
        vi.stubGlobal('fetch', fetch);
        openQueue();
        fireEvent.change(screen.getByLabelText('Queue name'), {
            target: { value: 'Retained proposal' },
        });
        save();
        act(() => {
            patch.mock.calls[0][2]?.onError?.({
                configuration_version: 'The queue changed. Review it first.',
            });
        });
        expect(
            screen.getByRole('button', { name: 'Save queue' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current queue' }),
        );
        expect(
            await screen.findByText('Saved by another editor'),
        ).toBeVisible();
        expect(screen.getByText(/Routing priority: 40/)).toBeVisible();
        expect(patch).toHaveBeenCalledOnce();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use current version and keep my draft',
            }),
        );
        expect(patch).toHaveBeenCalledOnce();
        step('Queue details');
        expect(screen.getByLabelText('Queue name')).toHaveValue(
            'Retained proposal',
        );
        save();
        expect(patch.mock.calls[1][1]).toEqual({
            actor_user_id: 8,
            configuration_version: 'b'.repeat(64),
            name: 'Retained proposal',
        });
        expect(fetch.mock.calls[0][0]).toBe(
            '/it/setup?review_resource=queues&actor_user_id=8',
        );
        expect(fetch.mock.calls[0][1].headers).not.toHaveProperty('X-Inertia');
    });

    it('keeps malformed and unavailable review responses recoverable without adopting them', async () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    currentResponse({
                        ...queue,
                        filter_rules: { categories: 'malformed' },
                    }),
                )
                .mockResolvedValueOnce({ ok: false, status: 403 }),
        );
        openQueue();
        save();
        act(() => {
            patch.mock.calls[0][2]?.onError?.({
                configuration_version: 'Review required.',
            });
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current queue' }),
        );
        expect(
            await screen.findByText(/The current queue could not be verified/),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Use current version and keep my draft',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current queue' }),
        );
        expect(
            await screen.findByText(/This form is no longer available/),
        ).toBeVisible();
        expect(patch).toHaveBeenCalledOnce();
    });

    it('requires review after a cancelled response and blocks another save until adoption', async () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(currentResponse()));
        openQueue();
        save();
        act(() => {
            patch.mock.calls[0][2]?.onCancel?.();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The save response was cancelled',
        );
        expect(
            screen.getByRole('button', { name: 'Save queue' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current queue' }),
        );
        await screen.findByText('Saved by another editor');
        expect(patch).toHaveBeenCalledOnce();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use current version and keep my draft',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Save queue' }),
        ).toBeEnabled();
    });

    it('does not treat an absent exact receipt as proof an uncertain create failed', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(new Error('Lost create acknowledgement'));
        render(<ItSetupIndex {...props} queues={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'New queue' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Queue name' }), {
            target: { value: 'Unconfirmed queue' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Stable key' }), {
            target: { value: 'unconfirmed-queue' },
        });
        save();
        await screen.findByRole('button', { name: 'Check saved result' });
        const requestUuid = (post.mock.calls[0][1] as { request_uuid: string })
            .request_uuid;
        post.mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'not_found',
                data: {
                    viewer_user_id: 8,
                    resource: 'queues',
                    request_uuid: requestUuid,
                    retry_same_command: true,
                },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved result' }),
        );
        await screen.findByText(/Retry the exact retained create/);
        step('Review');
        expect(
            screen.getByRole('button', { name: 'Save queue' }),
        ).toBeDisabled();
        expect(post).toHaveBeenCalledTimes(2);
    });
    it('focuses the current cover selector after a definitive create validation rejection', async () => {
        vi.spyOn(axios, 'post').mockResolvedValueOnce({
            status: 422,
            data: {
                errors: { cover_user_id: ['Choose a current eligible cover.'] },
            },
        });
        render(<ItSetupIndex {...props} queues={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'New queue' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Queue name' }), {
            target: { value: 'Draft with missing cover' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Stable key' }), {
            target: { value: 'missing-cover' },
        });
        save();
        await waitFor(() =>
            expect(
                screen.getByRole('combobox', { name: 'Absence cover' }),
            ).toHaveFocus(),
        );
        expect(
            screen.getByRole('combobox', { name: 'Absence cover' }),
        ).toHaveAttribute('aria-invalid', 'true');
        expect(screen.queryByText('Queue saved')).not.toBeInTheDocument();
    });
    it('recovers the original queue create fields and UUID after native traversal with an uncertain response', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(new Error('Lost acknowledgement'));
        const first = render(<ItSetupIndex {...props} queues={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'New queue' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Queue name' }), {
            target: { value: 'Private original queue' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Stable key' }), {
            target: { value: 'private-original-queue' },
        });
        save();
        await screen.findByRole('button', { name: 'Check saved result' });
        const original = structuredClone(post.mock.calls[0][1]) as Record<
            string,
            unknown
        >;
        first.unmount();
        render(<ItSetupIndex {...props} queues={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'New queue' }));
        expect(screen.getByRole('textbox', { name: 'Queue name' })).toHaveValue(
            '',
        );
        post.mockImplementationOnce(async (_url, raw) => {
            const data = raw as Record<string, unknown>;
            return {
                status: 200,
                data: {
                    candidate: {
                        candidate_uuid: data.candidate_uuid,
                        actor_user_id: 8,
                        resource: 'queues',
                        record_id: null,
                        context_uuid: data.context_uuid,
                        configuration_version: null,
                        current_configuration_version: null,
                        authorized: true,
                        capabilities: { submit: true },
                        blocker: null,
                    },
                },
            };
        });
        fireEvent.click(screen.getByRole('button', { name: 'Resume form 1' }));
        await screen.findByRole('button', { name: 'Retry exact create' });
        post.mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'committed',
                data: {
                    viewer_user_id: 8,
                    resource: 'queues',
                    request_uuid: original.request_uuid,
                    id: 66,
                    configuration_version: 'a'.repeat(64),
                    committed_configuration_version: 'a'.repeat(64),
                    replayed: true,
                },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry exact create' }),
        );
        await screen.findByText('Queue saved');
        expect(post.mock.calls[2][1]).toEqual(original);
    });

    it('confirms discard only when closing an edited draft', async () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        openQueue();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Edit queue' }),
            ).not.toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /^IT service desk/ }),
        );
        fireEvent.change(screen.getByLabelText('Queue name'), {
            target: { value: 'Draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('alertdialog', {
                name: 'Discard this queue draft?',
            }),
        ).toBeVisible();
        expect(patch).not.toHaveBeenCalled();
    });
});
