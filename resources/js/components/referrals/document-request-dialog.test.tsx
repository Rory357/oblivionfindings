import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { DocumentRequestDialog } from './document-request-dialog';
import { DocumentRequestsPanel } from './document-requests-panel';
import type { ReferralDetail } from './types';

const { post, get, reload } = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        post,
        get,
        isAxiosError: (e: { isAxiosError?: boolean }) => !!e.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
beforeEach(() => {
    post.mockReset();
    get.mockReset();
    reload.mockReset();
    Element.prototype.scrollTo = vi.fn();
});
afterEach(cleanup);
const referral = {
    name: 'Fictional person',
    reference: 'REF-DOCUMENT',
    version: 4,
} as ReferralDetail;
function fill() {
    fireEvent.change(screen.getByLabelText('Document title'), {
        target: { value: 'Decision letter' },
    });
    fireEvent.change(screen.getByLabelText(/^Instructions for the recipient/), {
        target: { value: 'Supply the current letter.' },
    });
    fireEvent.change(screen.getByLabelText('Verified recipient'), {
        target: { value: 'Verified person' },
    });
    fireEvent.change(
        screen.getByLabelText(/^Recipient and safe contact checks/),
        {
            target: {
                value: 'Verified identity by the agreed callback and separate safe channel.',
            },
        },
    );
    fireEvent.change(
        screen.getByLabelText('Reason for requesting this document'),
        { target: { value: 'Prepare the funding application.' } },
    );
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
it('requires recipient checks before creating and says delivery is still for staff to arrange', async () => {
    post.mockResolvedValue({
        data: {
            saved: true,
            request: {
                token: 'a'.repeat(64),
                passcode: '0123456789',
                expires_at: '2026-10-25T08:00:00Z',
            },
        },
    });
    render(
        <DocumentRequestDialog
            referral={referral}
            audience="funding"
            maximumDays={7}
            onClose={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByRole('alert')).toHaveTextContent('recipient');
    expect(post).not.toHaveBeenCalled();
    fill();
    fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
    await screen.findByText('Request ready to share');
    expect(
        screen.getByText(/Nothing has been sent automatically/),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Code — share separately')).toHaveValue(
        '0123456789',
    );
    expect(post.mock.calls[0][1]).toMatchObject({
        version: 4,
        audience: 'funding',
        recipient: 'Verified person',
        separate_channel_confirmed: true,
        days: 7,
    });
});
it('retries the exact issue payload after a lost response', async () => {
    post.mockRejectedValueOnce(
        new Error('Lost response'),
    ).mockResolvedValueOnce({
        data: {
            saved: true,
            request: {
                token: 'b'.repeat(64),
                passcode: '1234567890',
                expires_at: '2026-10-25T08:00:00Z',
            },
        },
    });
    render(
        <DocumentRequestDialog
            referral={referral}
            audience="funding"
            maximumDays={3}
            onClose={vi.fn()}
        />,
    );
    fill();
    fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
    await screen.findByText('Request ready to share');
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
});

it('keeps the issued credentials visible while the surrounding request history reloads', async () => {
    get.mockResolvedValueOnce({
        data: {
            requests: {
                data: [],
                current_page: 1,
                last_page: 1,
                total: 0,
                links: [],
            },
            can_issue: true,
            configuration: { enabled: true, maximum_days: 7 },
        },
    }).mockImplementation(() => new Promise(() => {}));
    post.mockResolvedValue({
        data: {
            saved: true,
            request: {
                token: 'c'.repeat(64),
                passcode: '0123456789',
                expires_at: '2026-10-25T08:00:00Z',
            },
        },
    });
    const props = {
        referral,
        audience: 'funding' as const,
        permissions: {},
        onReceivedChange: vi.fn(),
    };
    const { rerender } = render(<DocumentRequestsPanel {...props} />);
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Request a document',
        }),
    );
    fill();
    fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
    await screen.findByText('Request ready to share');
    rerender(
        <DocumentRequestsPanel
            {...props}
            referral={{ ...referral, version: 5 }}
        />,
    );
    expect(screen.getByLabelText('Code — share separately')).toHaveValue(
        '0123456789',
    );
    expect(screen.getByText('Request ready to share')).toBeInTheDocument();
});
