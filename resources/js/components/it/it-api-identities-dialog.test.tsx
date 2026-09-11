import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ItApiIdentities, type ItApiIdentity } from './it-api-identities';

vi.mock('axios', () => ({
    default: {
        request: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        isAxiosError: () => false,
    },
}));

// Use the actual WizardShell, portal, leave guard and confirmation dialog.
const options = {
    identities: [],
    agents: [{ id: 7, name: 'QA technician' }],
    sites: [{ id: 2, name: 'QA North Site' }],
    viewerUserId: 7,
    canManage: true,
};
const requestUuid = '11111111-1111-4111-8111-111111111111';
const savedIdentity: ItApiIdentity = {
    id: 41,
    public_id: 'qa-identity',
    name: 'QA recovery identity',
    description: null,
    actor: options.agents[0],
    creator: options.agents[0],
    abilities: ['work:create'],
    allowed_work_types: ['incident'],
    allowed_site_ids: [],
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
    created_at: null,
    configuration_version: 1,
    is_active: true,
};
const response = (path: string, data: unknown) => ({
    data,
    headers: { 'content-type': 'application/json' },
    request: { responseURL: `${window.location.origin}${path}` },
});
async function openReview() {
    render(<ItApiIdentities {...options} />);
    fireEvent.click(
        screen.getAllByRole('button', { name: 'New API identity' })[0],
    );
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/Identity name/), {
        target: { value: 'QA recovery identity' },
    });
    for (let step = 0; step < 3; step++)
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
    return dialog;
}

describe('API identity real wizard recovery', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(requestUuid);
    });

    it('retries the unchanged command inside the modal and keeps refresh recovery reachable after confirmation', async () => {
        vi.mocked(axios.request)
            .mockRejectedValueOnce(new Error('Synthetic lost response'))
            .mockResolvedValueOnce(
                response('/it/setup/api-identities', {
                    viewer_user_id: 7,
                    request_uuid: requestUuid,
                    operation: 'issue',
                    state: 'confirmed',
                    identity_id: 41,
                    configuration_version: 1,
                    replayed: true,
                    credential: null,
                    credential_unavailable: true,
                }),
            );
        vi.mocked(axios.get)
            .mockRejectedValueOnce(new Error('Synthetic metadata failure'))
            .mockResolvedValueOnce(
                response('/it/setup/api-identities', {
                    viewer_user_id: 7,
                    can_manage: true,
                    identities: [savedIdentity],
                    agents: options.agents,
                    sites: options.sites,
                }),
            );
        const dialog = await openReview();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Issue identity' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'Retry same request',
            }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toEqual(
            vi.mocked(axios.request).mock.calls[0][0].data,
        );
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toMatchObject({
            request_uuid: requestUuid,
        });
        const refresh = await within(dialog).findByRole('button', {
            name: 'Refresh current identities',
        });
        expect(
            within(dialog).getByText(/one-time credential is unavailable/),
        ).toBeInTheDocument();
        fireEvent.click(refresh);
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Done' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('cancels an unconfirmed command from inside the modal and preserves the unsaved draft', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(
            new Error('Synthetic lost response'),
        );
        vi.mocked(axios.post).mockResolvedValueOnce(
            response('/it/setup/api-identities/commands/cancel', {
                viewer_user_id: 7,
                request_uuid: requestUuid,
                state: 'cancelled',
                operation: null,
            }),
        );
        const dialog = await openReview();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Issue identity' }),
        );
        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'Cancel unconfirmed request',
            }),
        );
        expect(
            await within(dialog).findByText(/No saved identity was cancelled/),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: 'Issue identity' }),
        ).toBeEnabled();
        expect(
            within(dialog).getByText('QA recovery identity'),
        ).toBeInTheDocument();
        expect(axios.request).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith(
            '/it/setup/api-identities/commands/cancel',
            {
                request_uuid: requestUuid,
                viewer_user_id: 7,
            },
            expect.anything(),
        );
    });

    it.each(['hidden', 'changed'])(
        'clears a credential when retrying metadata review finds the identity %s',
        async (change) => {
            vi.mocked(axios.request).mockResolvedValueOnce(
                response('/it/setup/api-identities', {
                    viewer_user_id: 7,
                    request_uuid: requestUuid,
                    operation: 'issue',
                    state: 'confirmed',
                    identity_id: 41,
                    configuration_version: 1,
                    replayed: false,
                    credential: {
                        identity_id: 41,
                        name: savedIdentity.name,
                        token: 'synthetic-dialog-secret',
                    },
                    credential_unavailable: false,
                }),
            );
            vi.mocked(axios.get)
                .mockRejectedValueOnce(new Error('Synthetic metadata failure'))
                .mockResolvedValueOnce(
                    response('/it/setup/api-identities', {
                        viewer_user_id: 7,
                        can_manage: true,
                        identities:
                            change === 'hidden'
                                ? []
                                : [
                                      {
                                          ...savedIdentity,
                                          configuration_version: 2,
                                      },
                                  ],
                        agents: options.agents,
                        sites: options.sites,
                    }),
                );
            const dialog = await openReview();
            fireEvent.click(
                within(dialog).getByRole('button', { name: 'Issue identity' }),
            );
            const refresh = await within(dialog).findByRole('button', {
                name: 'Refresh current identities',
            });
            expect(
                within(dialog).getByDisplayValue('synthetic-dialog-secret'),
            ).toBeInTheDocument();
            fireEvent.click(refresh);
            await waitFor(() =>
                expect(
                    screen.queryByDisplayValue('synthetic-dialog-secret'),
                ).not.toBeInTheDocument(),
            );
            if (change === 'hidden') {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
                expect(
                    screen.getByText('API identity details are unavailable'),
                ).toBeInTheDocument();
            } else {
                expect(
                    within(dialog).getByText(
                        /one-time credential is unavailable/,
                    ),
                ).toBeInTheDocument();
            }
        },
    );
});
