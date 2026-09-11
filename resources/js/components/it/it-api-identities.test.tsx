import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ItApiIdentities, type ItApiIdentity } from './it-api-identities';

vi.mock('axios', () => ({
    default: {
        request: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'isAxiosError' in value,
    },
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({
        request: (run: () => void) => run(),
        confirmation: null,
    }),
}));
vi.mock('@/components/wizard/shell', async () => {
    const React = await import('react');
    return {
        WizardShell: ({
            children,
            footerEnd,
            success,
        }: {
            children: React.ReactNode;
            footerEnd: React.ReactNode;
            success?: React.ReactNode;
        }) => (
            <div>
                {success ?? (
                    <>
                        {children}
                        {footerEnd}
                    </>
                )}
            </div>
        ),
        WizardStepPane: ({ children }: { children: React.ReactNode }) => (
            <>{children}</>
        ),
        WizardSuccessPane: ({
            title,
            blurb,
            actions,
        }: {
            title: string;
            blurb: React.ReactNode;
            actions: React.ReactNode;
        }) => (
            <div>
                <h2>{title}</h2>
                {blurb}
                {actions}
            </div>
        ),
        ReviewCard: ({
            title,
            children,
        }: {
            title: string;
            children: React.ReactNode;
        }) => <section aria-label={title}>{children}</section>,
        ReviewRow: ({
            label,
            value,
        }: {
            label: string;
            value: React.ReactNode;
        }) => (
            <p>
                {label}: {value}
            </p>
        ),
    };
});

const identity = (overrides: Partial<ItApiIdentity> = {}): ItApiIdentity => ({
    id: 41,
    public_id: 'api_identity_41',
    name: 'Monitoring intake',
    description: 'Sends approved network findings.',
    actor: { id: 7, name: 'Taylor Technician' },
    creator: { id: 7, name: 'Taylor Technician' },
    abilities: ['work:create', 'work:read'],
    allowed_work_types: ['incident'],
    allowed_site_ids: [2],
    allowed_fields: {
        create: ['title', 'category', 'priority', 'work_type'],
        read: [],
        update: [],
    },
    require_signature: true,
    rate_limit_per_minute: 60,
    expires_at: null,
    revoked_at: null,
    last_used_at: null,
    last_rotated_at: null,
    created_at: '2026-09-11T10:00:00Z',
    configuration_version: 1,
    is_active: true,
    ...overrides,
});

const props = (
    overrides: Partial<React.ComponentProps<typeof ItApiIdentities>> = {},
) => ({
    identities: [],
    agents: [{ id: 7, name: 'Taylor Technician' }],
    sites: [{ id: 2, name: 'North Site' }],
    viewerUserId: 7,
    canManage: true,
    ...overrides,
});

const commandResponse = (
    operation: 'issue' | 'update' | 'rotate' | 'revoke',
    requestUuid: string,
    data: Partial<ItApiIdentity> = {},
    credential: {
        identity_id: number;
        name: string;
        token: string;
    } | null = null,
) => ({
    data: {
        viewer_user_id: 7,
        request_uuid: requestUuid,
        operation,
        state: 'confirmed',
        identity_id: 41,
        configuration_version: data.configuration_version ?? 2,
        replayed: false,
        credential,
        credential_unavailable: false,
    },
    headers: { 'content-type': 'application/json' },
    request: {
        responseURL:
            operation === 'issue'
                ? `${window.location.origin}/it/setup/api-identities`
                : `${window.location.origin}/it/setup/api-identities/41/${operation}`,
    },
});
const review = (identities: ItApiIdentity[]) => ({
    data: {
        viewer_user_id: 7,
        can_manage: true,
        identities,
        agents: [{ id: 7, name: 'Taylor Technician' }],
        sites: [{ id: 2, name: 'North Site' }],
    },
    headers: { 'content-type': 'application/json' },
    request: {
        responseURL: `${window.location.origin}/it/setup/api-identities`,
    },
});

async function reachReview() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

describe('ItApiIdentities', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(globalThis.crypto, 'randomUUID', {
            configurable: true,
            value: () => '11111111-1111-4111-8111-111111111111',
        });
        Object.assign(navigator, { clipboard: { writeText: vi.fn() } });
    });
    afterEach(() => vi.restoreAllMocks());

    it.each(['cards', 'table'] as const)(
        'omits empty actions for revoked identities in the %s register',
        (layout) => {
            render(
                <ItApiIdentities
                    {...props({
                        layout,
                        identities: [
                            identity({
                                is_active: false,
                                revoked_at: '2026-09-11T11:00:00Z',
                            }),
                            identity({ id: 42, name: 'Active connector' }),
                        ],
                    })}
                />,
            );
            expect(
                screen.queryByRole('button', {
                    name: 'Actions for Monitoring intake',
                }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: 'Actions for Active connector',
                }),
            ).toBeEnabled();
            fireEvent.contextMenu(screen.getByText('Monitoring intake'));
            expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        },
    );

    it('issues update and link grants with the five update field choices in its frozen JSON command', async () => {
        const saved = identity({
            configuration_version: 2,
            abilities: ['work:create', 'work:update', 'work:link'],
            allowed_fields: {
                create: ['title', 'category', 'priority', 'work_type'],
                read: [],
                update: ['category'],
            },
        });
        vi.mocked(axios.request).mockResolvedValue(
            commandResponse(
                'issue',
                '11111111-1111-4111-8111-111111111111',
                saved,
                { identity_id: 41, name: saved.name, token: 'secret-once' },
            ),
        );
        vi.mocked(axios.get).mockRejectedValue(new Error('refresh failed'));
        render(<ItApiIdentities {...props()} />);

        fireEvent.click(
            screen.getAllByRole('button', { name: 'New API identity' })[0],
        );
        fireEvent.change(screen.getAllByRole('textbox')[0], {
            target: { value: 'Network connector' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Update delegated triage fields',
            }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Manage related-work links',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            within(
                screen.getByRole('group', {
                    name: 'Triage fields this identity may update',
                }),
            ).getByRole('checkbox', { name: 'Category' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Issue identity' }));

        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        const request = vi.mocked(axios.request).mock.calls[0][0];
        expect(request).toMatchObject({
            method: 'post',
            url: '/it/setup/api-identities',
            headers: { Accept: 'application/json' },
        });
        expect(request.data).toMatchObject({
            request_uuid: '11111111-1111-4111-8111-111111111111',
            viewer_user_id: 7,
            abilities: expect.arrayContaining(['work:update', 'work:link']),
            update_fields: ['category'],
        });
        await screen.findByRole('heading', { name: 'API identity issued' });
        expect(screen.getByDisplayValue('secret-once')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear credential and done' }),
        );
        expect(
            screen.queryByDisplayValue('secret-once'),
        ).not.toBeInTheDocument();
    });

    it('keeps a direct credential visible after clipboard failure and clears it when access changes', async () => {
        const saved = identity({ configuration_version: 2 });
        vi.mocked(axios.request).mockImplementation(async (request) =>
            commandResponse(
                'issue',
                (request.data as { request_uuid: string }).request_uuid,
                saved,
                { identity_id: 41, name: saved.name, token: 'secret-once' },
            ),
        );
        vi.mocked(axios.get).mockResolvedValue(review([saved]));
        vi.mocked(navigator.clipboard.writeText).mockRejectedValue(
            new Error('blocked'),
        );
        const view = render(<ItApiIdentities {...props()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'New API identity' })[0],
        );
        fireEvent.change(screen.getAllByRole('textbox')[0], {
            target: { value: 'Network connector' },
        });
        await reachReview();
        fireEvent.click(screen.getByRole('button', { name: 'Issue identity' }));
        await screen.findByDisplayValue('secret-once');
        fireEvent.click(
            screen.getByRole('button', { name: 'Copy credential' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Copy was blocked by the browser',
        );
        expect(screen.getByDisplayValue('secret-once')).toBeInTheDocument();

        view.rerender(
            <ItApiIdentities
                {...props({ viewerUserId: 9, canManage: false })}
            />,
        );
        expect(
            screen.queryByDisplayValue('secret-once'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('API identity details are unavailable'),
        ).toBeInTheDocument();
    });

    it('retains an unknown command for recovery and does not confuse cancellation with revoking a saved identity', async () => {
        const failure = {
            isAxiosError: true,
            response: { status: 503, data: {} },
        };
        vi.mocked(axios.request).mockRejectedValueOnce(failure);
        vi.mocked(axios.post)
            .mockResolvedValueOnce({
                data: {
                    viewer_user_id: 7,
                    request_uuid: '22222222-2222-4222-8222-222222222222',
                    state: 'not_found',
                },
                headers: { 'content-type': 'application/json' },
                request: {
                    responseURL: `${window.location.origin}/it/setup/api-identities/commands/recover`,
                },
            })
            .mockResolvedValueOnce({
                data: {
                    viewer_user_id: 7,
                    request_uuid: '11111111-1111-4111-8111-111111111111',
                    state: 'not_found',
                },
                headers: { 'content-type': 'application/json' },
                request: {
                    responseURL: `${window.location.origin}/it/setup/api-identities/commands/recover`,
                },
            })
            .mockResolvedValueOnce({
                data: {
                    viewer_user_id: 7,
                    request_uuid: '11111111-1111-4111-8111-111111111111',
                    operation: null,
                    state: 'cancelled',
                },
                headers: { 'content-type': 'application/json' },
                request: {
                    responseURL: `${window.location.origin}/it/setup/api-identities/commands/cancel`,
                },
            });
        render(<ItApiIdentities {...props()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'New API identity' })[0],
        );
        fireEvent.change(screen.getAllByRole('textbox')[0], {
            target: { value: 'Network connector' },
        });
        await reachReview();
        fireEvent.click(screen.getByRole('button', { name: 'Issue identity' }));
        await screen.findByRole('button', {
            name: 'Cancel unconfirmed request',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Recover request' }),
        );
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(
                '/it/setup/api-identities/commands/recover',
                {
                    request_uuid: '11111111-1111-4111-8111-111111111111',
                    viewer_user_id: 7,
                },
                { headers: { Accept: 'application/json' } },
            ),
        );
        expect(
            await screen.findByText(
                'The command could not be confirmed. Recover the same request before trying again.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Recover request' }),
        );
        expect(
            await screen.findByText(
                'This command has not been found. Retry uses the exact same request reference and unchanged entries.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel unconfirmed request' }),
        );
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(3));
        expect(
            await screen.findByText(
                'The unconfirmed command was cancelled. No saved identity was cancelled.',
            ),
        ).toBeInTheDocument();
    });

    it('revokes an active identity through a versioned JSON command only after confirmation', async () => {
        const current = identity();
        const revoked = identity({
            configuration_version: 2,
            revoked_at: '2026-09-11T11:00:00Z',
            is_active: false,
        });
        vi.mocked(axios.request).mockResolvedValue(
            commandResponse(
                'revoke',
                '11111111-1111-4111-8111-111111111111',
                revoked,
            ),
        );
        vi.mocked(axios.get).mockResolvedValue(review([revoked]));
        render(<ItApiIdentities {...props({ identities: [current] })} />);

        fireEvent.contextMenu(screen.getByText('Monitoring intake'));
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Revoke identity' }),
        );
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Revoke identity' }),
        );

        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[0][0]).toMatchObject({
            method: 'post',
            url: '/it/setup/api-identities/41/revoke',
            data: {
                expected_version: 1,
                request_uuid: '11111111-1111-4111-8111-111111111111',
                viewer_user_id: 7,
            },
        });
        expect(
            await screen.findByRole('heading', {
                name: 'API identity revoked',
            }),
        ).toBeInTheDocument();
    });

    it('permits an unrevoked expired identity to be edited so its expiry can be extended', async () => {
        const expired = identity({
            expires_at: '2025-09-01T10:00:00Z',
            is_active: false,
            revoked_at: null,
        });
        render(<ItApiIdentities {...props({ identities: [expired] })} />);

        fireEvent.contextMenu(screen.getByText('Monitoring intake'));
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Edit grants' }),
        );

        expect(
            screen.getByDisplayValue('Taylor Technician'),
        ).toBeInTheDocument();
    });

    it('requires explicit adoption after a stale edit refreshes a newer version', async () => {
        const reviewed = identity();
        const current = identity({
            configuration_version: 2,
            abilities: ['work:read', 'work:comment'],
        });
        vi.mocked(axios.request).mockRejectedValue({
            isAxiosError: true,
            response: { status: 409, data: { message: 'Identity changed.' } },
        });
        vi.mocked(axios.get).mockResolvedValue(review([current]));
        render(<ItApiIdentities {...props({ identities: [reviewed] })} />);

        fireEvent.contextMenu(screen.getByText('Monitoring intake'));
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Edit grants' }),
        );
        await reachReview();
        fireEvent.click(screen.getByRole('button', { name: 'Save grants' }));

        expect(
            await screen.findByText('Current grants changed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/current saved identity is version 2/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save grants' }),
        ).toBeDisabled();
        expect(axios.request).toHaveBeenCalledOnce();

        fireEvent.click(
            screen.getByRole('button', { name: 'Use current saved grants' }),
        );
        expect(
            screen.getByDisplayValue('Monitoring intake'),
        ).toBeInTheDocument();
        expect(axios.request).toHaveBeenCalledOnce();
    });

    it('conceals identity details when the confirmed reconciliation says management access is gone', async () => {
        const saved = identity({ configuration_version: 2 });
        vi.mocked(axios.request).mockResolvedValue(
            commandResponse(
                'issue',
                '11111111-1111-4111-8111-111111111111',
                saved,
                {
                    identity_id: 41,
                    name: saved.name,
                    token: 'secret-once',
                },
            ),
        );
        vi.mocked(axios.get).mockResolvedValue({
            ...review([saved]),
            data: { ...review([saved]).data, can_manage: false },
        });
        render(<ItApiIdentities {...props()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'New API identity' })[0],
        );
        fireEvent.change(screen.getAllByRole('textbox')[0], {
            target: { value: 'Network connector' },
        });
        await reachReview();
        fireEvent.click(screen.getByRole('button', { name: 'Issue identity' }));

        expect(
            await screen.findByText('API identity details are unavailable'),
        ).toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('secret-once'),
        ).not.toBeInTheDocument();
    });

    it('sends only one revoke command while the first confirmation is in flight', async () => {
        vi.mocked(axios.request).mockImplementation(
            () => new Promise(() => undefined),
        );
        render(<ItApiIdentities {...props({ identities: [identity()] })} />);

        fireEvent.contextMenu(screen.getByText('Monitoring intake'));
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Revoke identity' }),
        );
        const confirm = screen.getByRole('button', {
            name: 'Revoke identity',
        });
        fireEvent.click(confirm);
        fireEvent.click(confirm);

        expect(axios.request).toHaveBeenCalledOnce();
    });

    it.each([
        [
            'same-origin non-JSON response',
            { headers: { 'content-type': 'text/html' } },
        ],
        [
            'valid JSON cross-origin redirect response',
            {
                request: { responseURL: 'https://example.test/sign-in' },
            },
        ],
    ])(
        'refuses a %s and retains the frozen request for recovery',
        async (_, fence) => {
            const saved = identity({ configuration_version: 2 });
            vi.mocked(axios.request).mockResolvedValue({
                ...commandResponse(
                    'issue',
                    '11111111-1111-4111-8111-111111111111',
                    saved,
                    { identity_id: 41, name: saved.name, token: 'secret-once' },
                ),
                ...fence,
            });
            render(<ItApiIdentities {...props()} />);
            fireEvent.click(
                screen.getAllByRole('button', { name: 'New API identity' })[0],
            );
            fireEvent.change(screen.getAllByRole('textbox')[0], {
                target: { value: 'Network connector' },
            });
            await reachReview();
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue identity' }),
            );

            expect(
                await screen.findByRole('button', {
                    name: 'Recover request',
                }),
            ).toBeInTheDocument();
            expect(
                screen.queryByDisplayValue('secret-once'),
            ).not.toBeInTheDocument();
        },
    );

    it.each(['mismatched identity', 'missing credential'])(
        'recovers a direct response with %s',
        async (invalidKind) => {
            const saved = identity({ configuration_version: 2 });
            vi.mocked(axios.request).mockResolvedValue(
                commandResponse(
                    'issue',
                    '11111111-1111-4111-8111-111111111111',
                    saved,
                    invalidKind === 'missing credential'
                        ? null
                        : {
                              identity_id: 99,
                              name: saved.name,
                              token: 'secret-once',
                          },
                ),
            );
            render(<ItApiIdentities {...props()} />);
            fireEvent.click(
                screen.getAllByRole('button', { name: 'New API identity' })[0],
            );
            fireEvent.change(screen.getAllByRole('textbox')[0], {
                target: { value: 'Network connector' },
            });
            await reachReview();
            fireEvent.click(
                screen.getByRole('button', { name: 'Issue identity' }),
            );

            expect(
                await screen.findByRole('button', {
                    name: 'Recover request',
                }),
            ).toBeInTheDocument();
            expect(
                screen.queryByDisplayValue('secret-once'),
            ).not.toBeInTheDocument();
        },
    );

    it('routes nested Site and rate validation back to scope while retaining the issue draft', async () => {
        vi.mocked(axios.request).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        'allowed_site_ids.0': [
                            'North Site is no longer approved for this identity.',
                        ],
                        rate_limit_per_minute: [
                            'Choose a request limit between 1 and 300.',
                        ],
                    },
                },
            },
        });
        render(<ItApiIdentities {...props()} />);
        fireEvent.click(
            screen.getAllByRole('button', { name: 'New API identity' })[0],
        );
        fireEvent.change(screen.getAllByRole('textbox')[0], {
            target: { value: 'Network connector' },
        });
        await reachReview();
        fireEvent.click(screen.getByRole('button', { name: 'Issue identity' }));

        expect(
            await screen.findByText(
                'North Site is no longer approved for this identity.',
            ),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(
                screen.getAllByText(
                    'Choose a request limit between 1 and 300.',
                ),
            ).toHaveLength(2),
        );
        expect(screen.getByDisplayValue('60')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(
            screen.getByDisplayValue('Network connector'),
        ).toBeInTheDocument();
    });
});
