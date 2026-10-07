import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConnectedCare from '../ConnectedCare';
import type { ConnectedProps } from './_types';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => (
        <a {...props}>{children}</a>
    ),
    usePage: () => ({ props: { auth: { user: { id: 1 }, can: {} } } }),
    router: { reload: vi.fn(), get: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('axios', () => ({
    default: { request: vi.fn(), isAxiosError: () => false },
}));

function props(revokeIdentity?: boolean): ConnectedProps {
    return {
        clients: [{ id: 7, name: 'Ada Synthetic' }],
        selected_client: {
            id: 7,
            name: 'Ada Synthetic',
            date_of_birth: '1980-01-01',
        },
        clinicians: [
            {
                id: 3,
                user_id: 30,
                name: 'Dr Example',
                email: 'prescriber@example.test',
                provider_name: 'Example practice',
                registration_authority: 'Clinical register',
                registration_number: 'EXAMPLE-3',
                identity_verified_at: '2026-10-01T00:00:00Z',
                expires_at: '2026-12-01T00:00:00Z',
                revoked_at: null,
            },
        ],
        grants: [
            {
                id: 9,
                clinician_id: 3,
                clinician_name: 'Dr Example',
                client_id: 7,
                site_id: 2,
                purpose: 'Person-only access',
                can_propose: true,
                include_controlled: false,
                expires_at: '2026-11-01T00:00:00Z',
                revoked_at: null,
                active: true,
                availability: 'ready',
            },
        ],
        proposals: [],
        transfers: [],
        witnesses: [],
        can: {
            manage_access: true,
            revoke_identity: revokeIdentity,
            manage_orders: false,
            transfer: false,
            export: false,
        },
    };
}

beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(
        {},
        '',
        '/emar/connected-care?client_id=7#access',
    );
    vi.mocked(axios.request).mockResolvedValue({ data: { id: 3 } });
});

describe('prescriber identity and person access authority', () => {
    it('returns focus to the same record action after discarding a menu-opened review', async () => {
        render(<ConnectedCare {...props(false)} />);
        const trigger = screen.getByRole('button', {
            name: 'Actions for Dr Example',
        });
        trigger.focus();
        fireEvent.keyDown(trigger, { key: 'ArrowDown' });
        const menuItem = await screen.findByRole('menuitem', { name: 'Open' });
        menuItem.focus();
        fireEvent.click(menuItem);
        const dialog = await screen.findByRole('dialog');
        fireEvent.change(
            within(dialog).getByRole('textbox', { name: /Reason/ }),
            {
                target: { value: 'Keep access unchanged' },
            },
        );
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        const confirmation = screen.getByRole('alertdialog');
        fireEvent.click(
            within(confirmation).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(
            within(dialog).getByRole('textbox', { name: /Reason/ }),
        ).toHaveValue('Keep access unchanged');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(axios.request).not.toHaveBeenCalled();
    });

    it.each([false, undefined])(
        'keeps person access available but prevents global withdrawal when authority is %s',
        async (authority) => {
            render(<ConnectedCare {...props(authority)} />);
            const profileRow = screen
                .getByText('prescriber@example.test')
                .closest('[role="row"]')!;
            expect(profileRow).not.toHaveAttribute('tabindex');
            fireEvent.click(profileRow);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.getByText(/requires organisation-wide authority/),
            ).toBeInTheDocument();

            fireEvent.click(
                screen.getByText('Person-only access').closest('[role="row"]')!,
            );
            const dialog = screen.getByRole('dialog');
            fireEvent.change(
                within(dialog).getByRole('textbox', { name: /Reason/ }),
                { target: { value: 'Named review has ended' } },
            );
            fireEvent.click(
                within(dialog).getByRole('button', { name: 'Continue' }),
            );
            expect(
                within(dialog).getByText('End this named-person grant'),
            ).toBeInTheDocument();
            expect(
                within(dialog).queryByText(/every granted person/),
            ).not.toBeInTheDocument();
            fireEvent.click(
                within(dialog).getByRole('button', { name: 'Revoke access' }),
            );
            await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(1));
            expect(axios.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: '/emar/connected-care/grants/9/revoke',
                    data: { client_id: 7, reason: 'Named review has ended' },
                }),
            );
        },
    );

    it('makes global identity withdrawal explicit and reviews its complete effect before submitting', async () => {
        render(<ConnectedCare {...props(true)} />);
        fireEvent.click(
            screen
                .getByText('prescriber@example.test')
                .closest('[role="row"]')!,
        );
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Withdraw prescriber identity');
        expect(dialog).toHaveTextContent('Dr Example · All houses');
        expect(
            within(dialog).getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
        fireEvent.change(
            within(dialog).getByRole('textbox', { name: /Reason/ }),
            { target: { value: 'Identity verification withdrawn' } },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        expect(
            within(dialog).getByText(
                'End this clinician’s access to every granted person',
            ),
        ).toBeInTheDocument();
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Withdraw identity' }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(1));
        expect(axios.request).toHaveBeenCalledWith(
            expect.objectContaining({
                url: '/emar/connected-care/clinicians/3/revoke',
                data: {
                    client_id: 7,
                    reason: 'Identity verification withdrawn',
                },
            }),
        );
    });
});
