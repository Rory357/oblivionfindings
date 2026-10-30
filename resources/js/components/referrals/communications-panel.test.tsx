import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { ReactNode } from 'react';
import { expect, it, vi } from 'vitest';
import { CommunicationsPanel } from './communications-panel';
import type { ReferralDetail } from './types';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    Link: ({ children, ...props }: { children?: ReactNode }) => (
        <a {...props}>{children}</a>
    ),
}));

vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), isAxiosError: () => true },
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({ confirmation: null }),
}));
const referral = { reference: 'REF-FICTIONAL', version: 1 } as ReferralDetail;
/** Record actions live in the card's kebab and right-click menu (one MenuItem[]). */
const openAction = async (name: string) => {
    fireEvent.contextMenu(await screen.findByText('Enquiry acknowledgement'));
    fireEvent.click(screen.getByRole('menuitem', { name }));
};
const response = (
    blocker: string | null = null,
    state = 'delivery_unknown',
) => ({
    data: {
        version: 1,
        can_manage: true,
        acknowledgement: {
            subject: 'Your enquiry has been received',
            body: 'Reference REF-FICTIONAL. Receipt only.',
            sender: 'preview@example.test',
        },
        communications: {
            total: 1,
            current_page: 1,
            last_page: 1,
            links: [],
            data: [
                {
                    id: 4,
                    kind: 'public_acknowledgement',
                    state,
                    attempts: 1,
                    recipient: 'fictional@example.test',
                    created_at: '2026-10-16T00:00:00Z',
                    available_at: '2026-10-16T00:00:00Z',
                    sent_at: null,
                    delivered_at: null,
                    reason: 'transport_result_unknown',
                    retry_blocker: blocker,
                },
            ],
        },
    },
});

it('requires duplicate-risk acknowledgement and retains the exact recovery request after uncertainty', async () => {
    vi.mocked(axios.get).mockResolvedValue(response());
    vi.mocked(axios.post)
        .mockRejectedValueOnce({})
        .mockResolvedValueOnce({ data: { saved: true } });
    render(<CommunicationsPanel referral={referral} />);
    await openAction('Review retry');
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for this decision/ }),
        {
            target: {
                value: 'Checked the transport logs and reviewed this retry',
            },
        },
    );
    expect(
        screen.getByRole('button', { name: 'Queue reviewed retry' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Queue reviewed retry' }),
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Retry exact request' }),
    );
    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
    expect(vi.mocked(axios.post).mock.calls[1]).toEqual(
        vi.mocked(axios.post).mock.calls[0],
    );
});

it('explains a current safe-contact blocker and prevents queueing the old acknowledgement', async () => {
    vi.mocked(axios.get).mockResolvedValue(
        response('safe_contact_changed', 'failed'),
    );
    render(<CommunicationsPanel referral={referral} />);
    await openAction('Review retry');
    expect(screen.getByRole('dialog')).toHaveTextContent(
        'Do not send the old acknowledgement',
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for this decision/ }),
        {
            target: {
                value: 'A long enough reason cannot override the safe contact check',
            },
        },
    );
    expect(
        screen.getByRole('button', { name: 'Queue reviewed retry' }),
    ).toBeDisabled();
});

it('withdraws recipient and message details after an access denial', async () => {
    vi.mocked(axios.get).mockResolvedValue(response(null, 'failed'));
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 403 } });
    render(<CommunicationsPanel referral={referral} />);
    await openAction('Cancel queued communication');
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for this decision/ }),
        { target: { value: 'Cancel the unsent acknowledgement after review' } },
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Cancel communication',
        }),
    );
    await screen.findByText(/Private details have been hidden/);
    expect(
        screen.queryByText('fictional@example.test'),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
