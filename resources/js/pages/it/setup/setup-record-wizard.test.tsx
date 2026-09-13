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
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SetupRecordWizard } from './_dialogs';
import type { Service, Team } from './_types';
import { clearSetupMemory } from './use-setup-memory';
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        props: { auth: { user: { id: 8 } } },
        url: '/it/setup?tab=teams',
    }),
}));
const agents = [
    { id: 10, name: 'Desk manager' },
    { id: 11, name: 'Current cover' },
];
const team: Team = {
    id: 20,
    name: 'Service desk',
    description: 'Shared team',
    configuration_version: 'a'.repeat(64),
    is_active: true,
    manager: agents[0],
    members: [{ ...agents[1], role: 'lead' }],
    workload: { open_tickets: 3, open_tasks: 2, queues: 1, members: 1 },
};
const service: Service = {
    id: 30,
    key: 'identity-access',
    name: 'Identity access',
    description: 'Sign-in service',
    configuration_version: 'a'.repeat(64),
    is_active: true,
    owner: agents[0],
    status: 'operational',
    criticality: 'medium',
    workload: { open_tickets: 2, sla_risk: 0 },
};
const step = (name: string) =>
    fireEvent.click(
        within(screen.getByRole('complementary')).getByRole('button', {
            name: new RegExp(name),
        }),
    );
const review = () => step('Review');
function currentResponse(current: Team | Service) {
    return {
        ok: true,
        headers: new Headers({ 'content-type': 'application/json' }),
        json: async () => ({
            resource: 'members' in current ? 'teams' : 'services',
            viewer_user_id: 8,
            records: [current],
        }),
    } as Response;
}
describe('Setup record wizards', () => {
    afterEach(() => {
        clearSetupMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });
    it.each(['team', 'service'] as const)(
        'resumes an unknown %s create after traversal and retries its exact command once',
        async (kind) => {
            const post = vi
                .spyOn(axios, 'post')
                .mockRejectedValueOnce(new Error('Lost acknowledgement'));
            const first = render(
                <SetupRecordWizard
                    kind={kind}
                    agents={agents}
                    onClose={vi.fn()}
                />,
            );
            fireEvent.change(
                screen.getByRole('textbox', {
                    name: `${kind === 'team' ? 'Team' : 'Service'} name`,
                }),
                { target: { value: 'Private original proposal' } },
            );
            if (kind === 'service')
                fireEvent.change(
                    screen.getByRole('textbox', { name: 'Stable key' }),
                    { target: { value: 'private-original' } },
                );
            review();
            fireEvent.click(
                screen.getByRole('button', { name: `Save ${kind}` }),
            );
            await screen.findByRole('button', { name: 'Check saved result' });
            const original = structuredClone(post.mock.calls[0][1]) as Record<
                string,
                unknown
            >;
            first.unmount();
            render(
                <SetupRecordWizard
                    kind={kind}
                    agents={agents}
                    onClose={vi.fn()}
                />,
            );
            expect(
                screen.getByRole('textbox', {
                    name: `${kind === 'team' ? 'Team' : 'Service'} name`,
                }),
            ).toHaveValue('');
            expect(
                screen.queryByRole('button', { name: 'Retry exact create' }),
            ).not.toBeInTheDocument();
            post.mockImplementationOnce(async (_url, raw) => {
                const data = raw as Record<string, unknown>;
                return {
                    status: 200,
                    data: {
                        candidate: {
                            candidate_uuid: data.candidate_uuid,
                            actor_user_id: 8,
                            resource: data.resource,
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
            fireEvent.click(
                screen.getByRole('button', { name: 'Resume form 1' }),
            );
            await screen.findByRole('button', { name: 'Retry exact create' });
            expect(
                screen.getByRole('button', { name: `Save ${kind}` }),
            ).toBeDisabled();
            post.mockResolvedValueOnce({
                status: 200,
                data: {
                    status: 'committed',
                    data: {
                        viewer_user_id: 8,
                        resource: kind === 'team' ? 'teams' : 'services',
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
            await screen.findByText(
                `${kind === 'team' ? 'Team' : 'Service'} saved`,
            );
            expect(post.mock.calls[2][1]).toEqual(original);
            expect(
                screen.queryByRole('button', { name: 'Resume form 1' }),
            ).not.toBeInTheDocument();
        },
    );
    it.each([
        ['team', team],
        ['service', service],
    ] as const)(
        'recovers the whole %s form after native-style unmount only after explicit fresh authorization',
        async (kind, record) => {
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
                                resource: data.resource,
                                record_id: data.record_id,
                                context_uuid: data.context_uuid,
                                configuration_version:
                                    data.configuration_version,
                                current_configuration_version: 'b'.repeat(64),
                                authorized: true,
                                capabilities: { submit: false },
                                blocker: 'configuration_changed',
                            },
                        },
                    };
                });
            const first = render(
                <SetupRecordWizard
                    kind={kind}
                    record={record}
                    agents={agents}
                    onClose={vi.fn()}
                />,
            );
            fireEvent.change(
                screen.getByRole('textbox', {
                    name: `${kind === 'team' ? 'Team' : 'Service'} name`,
                }),
                { target: { value: 'Exact retained last character!' } },
            );
            step('Accountability');
            first.unmount();
            render(
                <SetupRecordWizard
                    kind={kind}
                    record={record}
                    agents={agents}
                    onClose={vi.fn()}
                />,
            );
            expect(
                screen.queryByDisplayValue('Exact retained last character!'),
            ).not.toBeInTheDocument();
            expect(post).not.toHaveBeenCalled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Resume form 1' }),
            );
            await screen.findByText(
                'Retained work restored. Review the proposed details before saving.',
            );
            expect(
                screen.getByRole('combobox', {
                    name: kind === 'team' ? 'Team manager' : 'Service owner',
                }),
            ).toHaveValue('10');
            step('Identity');
            expect(
                screen.getByRole('textbox', {
                    name: `${kind === 'team' ? 'Team' : 'Service'} name`,
                }),
            ).toHaveValue('Exact retained last character!');
            review();
            expect(
                screen.getByRole('button', { name: `Save ${kind}` }),
            ).toBeDisabled();
            const candidate = post.mock.calls[0][1] as {
                configuration_version: string;
                base_fields: { name: string };
            };
            expect(candidate.configuration_version).toBe('a'.repeat(64));
            expect(candidate.base_fields.name).toBe(record.name);
        },
    );
    it('prefills team details, changes only intended fields and requires an explicit save after Review', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        const close = vi.fn();
        render(
            <SetupRecordWizard
                kind="team"
                record={team}
                agents={agents}
                onClose={close}
            />,
        );
        expect(screen.getByRole('textbox', { name: 'Team name' })).toHaveValue(
            team.name,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Team name' }), {
            target: { value: 'Reviewed service desk' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByRole('combobox', { name: 'Team manager' }),
        ).toHaveValue('10');
        expect(screen.getByRole('button', { name: 'Lead' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        const next = screen.getByRole('button', { name: 'Continue' });
        fireEvent.click(next);
        expect(patch).not.toHaveBeenCalled();
        const save = screen.getByRole('button', { name: 'Save team' });
        expect(save).not.toBe(next);
        expect(next).not.toBeInTheDocument();
        fireEvent.click(save);
        expect(patch.mock.calls[0][1]).toEqual({
            actor_user_id: 8,
            configuration_version: team.configuration_version,
            name: 'Reviewed service desk',
        });
        await act(async () =>
            patch.mock.calls[0][2]?.onSuccess?.({
                props: {
                    flash: { success: 'Team saved.' },
                    teams: [
                        {
                            ...team,
                            name: 'Reviewed service desk',
                            configuration_version: 'b'.repeat(64),
                        },
                    ],
                },
            } as unknown as Page),
        );
        expect(screen.getByText('Team saved')).toBeVisible();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(close).toHaveBeenCalledOnce();
    });
    it('validates and focuses identity before native implicit submission can create', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        render(
            <SetupRecordWizard
                kind="service"
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        fireEvent.submit(
            screen
                .getByRole('textbox', { name: 'Service name' })
                .closest('form')!,
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Service name' }),
            ).toHaveFocus(),
        );
        expect(post).not.toHaveBeenCalled();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Service name' }),
            { target: { value: 'New service' } },
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Stable key' }), {
            target: { value: 'service-key' },
        });
        fireEvent.submit(
            screen
                .getByRole('textbox', { name: 'Service name' })
                .closest('form')!,
        );
        expect(
            screen.getByRole('combobox', { name: 'Service owner' }),
        ).toBeVisible();
        expect(post).not.toHaveBeenCalled();
    });
    it('uses categorical status choices and confirms discard while retaining the service draft', async () => {
        const close = vi.fn();
        render(
            <SetupRecordWizard
                kind="service"
                record={service}
                agents={agents}
                onClose={close}
            />,
        );
        step('Accountability');
        fireEvent.click(screen.getByRole('button', { name: 'Degraded' }));
        fireEvent.click(screen.getByRole('button', { name: /^Critical$/ }));
        review();
        expect(screen.getByText('Degraded')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('alertdialog', {
                name: 'Discard this service draft?',
            }),
        ).toBeVisible();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByText('Degraded')).toBeVisible();
        expect(close).not.toHaveBeenCalled();
    });
    it('retains validation errors and focuses the rejected field instead of acknowledging success', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        render(
            <SetupRecordWizard
                kind="service"
                record={service}
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save service' }));
        await act(async () =>
            patch.mock.calls[0][2]?.onError?.({
                key: 'That key is already in use.',
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Stable key' }),
            ).toHaveFocus(),
        );
        expect(
            screen.getByRole('textbox', { name: 'Service name' }),
        ).toHaveValue(service.name);
        expect(screen.getByText('That key is already in use.')).toBeVisible();
        expect(screen.queryByText('Service saved')).not.toBeInTheDocument();
    });
    it('requires explicit current-version review and a separate save, retaining draft and unrelated concurrent fields', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                currentResponse({
                    ...team,
                    name: 'Another editor',
                    manager: agents[1],
                    configuration_version: 'b'.repeat(64),
                }),
            ),
        );
        render(
            <SetupRecordWizard
                kind="team"
                record={team}
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Team name' }), {
            target: { value: 'My proposal' },
        });
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        await act(async () =>
            patch.mock.calls[0][2]?.onError?.({
                configuration_version: 'The team changed. Review it first.',
            }),
        );
        step('Identity');
        review();
        expect(
            screen.getByRole('button', { name: 'Save team' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current setup' }),
        );
        await screen.findByText('Another editor');
        expect(globalThis.fetch).toHaveBeenCalledWith(
            '/it/setup?review_resource=teams&actor_user_id=8',
            expect.objectContaining({
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            }),
        );
        expect(patch).toHaveBeenCalledOnce();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        );
        expect(patch).toHaveBeenCalledOnce();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        expect(patch.mock.calls[1][1]).toEqual({
            actor_user_id: 8,
            configuration_version: 'b'.repeat(64),
            name: 'My proposal',
        });
    });
    it('keeps uncertain responses and expired-session recovery drafts until current setup can be reviewed', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        const fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                headers: new Headers({ 'content-type': 'text/html' }),
            })
            .mockResolvedValueOnce(
                currentResponse({
                    ...service,
                    configuration_version: 'b'.repeat(64),
                }),
            );
        vi.stubGlobal('fetch', fetch);
        render(
            <SetupRecordWizard
                kind="service"
                record={service}
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save service' }));
        await act(async () => patch.mock.calls[0][2]?.onFinish?.({} as never));
        expect(
            screen.getByRole('button', { name: 'Save service' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current setup' }),
        );
        await screen.findByText(/Current setup is unavailable/);
        expect(
            screen.queryByRole('button', { name: 'Use reviewed version' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current setup' }),
        );
        await screen.findByRole('button', { name: 'Use reviewed version' });
        expect(patch).toHaveBeenCalledOnce();
    });
    it('rejects a review response for another resource even when its row shape could match', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                headers: new Headers({
                    'content-type': 'application/json',
                }),
                json: async () => ({ resource: 'queues', records: [team] }),
            }),
        );
        render(
            <SetupRecordWizard
                kind="team"
                record={team}
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        await act(async () =>
            patch.mock.calls[0][2]?.onError?.({
                configuration_version: 'Review required.',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current setup' }),
        );
        await screen.findByText(
            'Current setup could not be verified. Your draft is retained.',
        );
        expect(
            screen.queryByRole('button', { name: 'Use reviewed version' }),
        ).not.toBeInTheDocument();
        expect(patch).toHaveBeenCalledOnce();
    });

    it('rejects an error acknowledgement even when another success flash and a visible record coexist', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        render(
            <SetupRecordWizard
                kind="team"
                record={team}
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        await act(async () =>
            patch.mock.calls[0][2]?.onSuccess?.({
                props: {
                    flash: {
                        error: 'The save failed.',
                        success: 'An earlier action completed.',
                    },
                    teams: [team],
                },
            } as unknown as Page),
        );
        expect(screen.queryByText('Team saved')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save team' })).toBeEnabled();
    });

    it('does not show success for a definitive create rejection and preserves correctable values', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValue({
                status: 422,
                data: { errors: { setup: ['Save failed.'] } },
            });
        render(
            <SetupRecordWizard kind="team" agents={agents} onClose={vi.fn()} />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Team name' }), {
            target: { value: 'Local draft' },
        });
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'configuration_version',
        );
        await screen.findByText('Save failed.');
        expect(screen.queryByText('Team saved')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save team' })).toBeEnabled();
        expect(screen.getByText('Save failed.')).toBeVisible();
    });
    it('keeps the corrected create proposal recoverable after a definitive rejection and native traversal', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValueOnce({
                status: 422,
                data: { errors: { name: ['Choose another name.'] } },
            });
        const first = render(
            <SetupRecordWizard kind="team" agents={agents} onClose={vi.fn()} />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Team name' }), {
            target: { value: 'Rejected name' },
        });
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Team name' }),
            ).toBeEnabled(),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Team name' }), {
            target: { value: 'Latest corrected proposal!' },
        });
        first.unmount();
        render(
            <SetupRecordWizard kind="team" agents={agents} onClose={vi.fn()} />,
        );
        post.mockImplementationOnce(async (_url, raw) => {
            const data = raw as Record<string, unknown>;
            return {
                status: 200,
                data: {
                    candidate: {
                        candidate_uuid: data.candidate_uuid,
                        actor_user_id: 8,
                        resource: 'teams',
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
        await screen.findByDisplayValue('Latest corrected proposal!');
        expect(
            screen.getByRole('textbox', { name: 'Team name' }),
        ).toBeEnabled();
        expect(
            screen.queryByRole('button', { name: 'Check saved result' }),
        ).not.toBeInTheDocument();
    });
    it('requires an explicit cancellation confirmation before releasing an unconfirmed create and retains the entered proposal', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(new Error('Lost acknowledgement'));
        render(
            <SetupRecordWizard kind="team" agents={agents} onClose={vi.fn()} />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Team name' }), {
            target: { value: 'Retained after cancellation' },
        });
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        await screen.findByRole('button', { name: 'Cancel earlier create' });
        const uuid = (post.mock.calls[0][1] as { request_uuid: string })
            .request_uuid;
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel earlier create' }),
        );
        expect(post).toHaveBeenCalledOnce();
        const confirmation = screen.getByRole('alertdialog');
        fireEvent.click(
            within(confirmation).getByRole('button', { name: 'Cancel' }),
        );
        expect(post).toHaveBeenCalledOnce();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel earlier create' }),
        );
        post.mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'cancelled',
                data: {
                    viewer_user_id: 8,
                    resource: 'teams',
                    request_uuid: uuid,
                    cancelled: true,
                },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check and cancel create' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Save team' }),
            ).toBeEnabled(),
        );
        step('Identity');
        expect(screen.getByRole('textbox', { name: 'Team name' })).toHaveValue(
            'Retained after cancellation',
        );
        expect(screen.queryByText('Team saved')).not.toBeInTheDocument();
    });

    it.each([401, 419, 403, 404])(
        'conceals private edited values after a confirmed %s review response',
        async (status) => {
            const patch = vi
                .spyOn(router, 'patch')
                .mockImplementation(() => {});
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({ ok: false, status }),
            );
            render(
                <SetupRecordWizard
                    kind="team"
                    record={team}
                    agents={agents}
                    onClose={vi.fn()}
                />,
            );
            fireEvent.change(
                screen.getByRole('textbox', { name: 'Team name' }),
                { target: { value: 'Private retained name' } },
            );
            review();
            fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
            act(() =>
                patch.mock.calls[0][2]?.onError?.({
                    configuration_version: 'Review required',
                }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Review current setup' }),
            );
            await screen.findByText(
                status === 401 || status === 419
                    ? /Your session expired/
                    : /This form is no longer available/,
            );
            expect(
                screen.queryByDisplayValue('Private retained name'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Private retained name'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Use reviewed version' }),
            ).not.toBeInTheDocument();
            if (status === 401 || status === 419) {
                vi.spyOn(axios, 'post').mockImplementation(
                    async (_url, raw) => {
                        const data = raw as Record<string, unknown>;
                        return {
                            status: 200,
                            data: {
                                candidate: {
                                    candidate_uuid: data.candidate_uuid,
                                    actor_user_id: 8,
                                    resource: 'teams',
                                    record_id: team.id,
                                    context_uuid: data.context_uuid,
                                    configuration_version:
                                        data.configuration_version,
                                    current_configuration_version: 'b'.repeat(
                                        64,
                                    ),
                                    authorized: true,
                                    capabilities: { submit: false },
                                    blocker: 'configuration_changed',
                                },
                            },
                        };
                    },
                );
                fireEvent.click(
                    screen.getByRole('button', {
                        name: 'Check access and resume',
                    }),
                );
                await screen.findByRole('button', {
                    name: 'Review current setup',
                });
                step('Identity');
                expect(
                    screen.getByRole('textbox', { name: 'Team name' }),
                ).toHaveValue('Private retained name');
            }
        },
    );

    it('cancelled review cannot publish a version from a late JSON body', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        let finish!: (value: unknown) => void;
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                status: 200,
                headers: new Headers({ 'content-type': 'application/json' }),
                json: () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            }),
        );
        render(
            <SetupRecordWizard
                kind="team"
                record={team}
                agents={agents}
                onClose={vi.fn()}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save team' }));
        act(() =>
            patch.mock.calls[0][2]?.onError?.({
                configuration_version: 'Review required',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current setup' }),
        );
        await waitFor(() => expect(finish).toBeDefined());
        fireEvent.click(screen.getByRole('button', { name: 'Cancel review' }));
        await act(async () =>
            finish({
                resource: 'teams',
                viewer_user_id: 8,
                records: [{ ...team, configuration_version: 'b'.repeat(64) }],
            }),
        );
        expect(
            screen.queryByRole('button', { name: 'Use reviewed version' }),
        ).not.toBeInTheDocument();
    });
});
