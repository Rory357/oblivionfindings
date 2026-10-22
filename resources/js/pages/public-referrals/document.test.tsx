import '@testing-library/jest-dom/vitest';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReferralDocumentPage from './document';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (e: { isAxiosError?: boolean }) => !!e.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({
        url: '/',
        props: { provider: { phone: '0800 000 000' } },
    }),
    router: { on: () => () => {} },
}));
vi.mock('@/layouts/marketing-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({ confirmation: null }),
}));
beforeEach(() => post.mockReset());
afterEach(cleanup);
const details = {
    reference: 'DOCUMENT-REQUEST',
    title: 'Decision letter',
    instructions: 'Supply the current letter.',
    state: 'pending',
    expires_at: '2026-10-25T08:00:00Z',
    received_at: null,
};
async function openRequest() {
    post.mockResolvedValueOnce({ data: details });
    render(<ReferralDocumentPage token={'a'.repeat(64)} />);
    fireEvent.change(screen.getByLabelText(/^10-digit code/), {
        target: { value: '0123456789' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Open request' }));
    await screen.findByText('Decision letter');
}

it('shows no case details before code verification and rejects a short code locally', async () => {
    render(<ReferralDocumentPage token={'a'.repeat(64)} />);
    expect(screen.queryByText('Decision letter')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open request' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('10-digit code');
    expect(post).not.toHaveBeenCalled();
});

it('requires a deliberate file confirmation and displays a receipt without promising approval', async () => {
    await openRequest();
    fireEvent.click(screen.getByRole('button', { name: 'Send document' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Choose your document',
    );
    const file = new File(['%PDF-1.4\nFictional\n%%EOF'], 'letter.pdf', {
        type: 'application/pdf',
    });
    fireEvent.change(
        screen.getByLabelText(/^Choose a document/, { selector: 'input' }),
        {
            target: { files: [file] },
        },
    );
    fireEvent.click(screen.getByRole('checkbox'));
    post.mockResolvedValueOnce({
        data: {
            ...details,
            state: 'received',
            received_at: '2026-10-18T08:00:00Z',
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send document' }));
    await screen.findByRole('heading', { name: 'Document received' });
    expect(screen.getByText(/does not confirm funding/)).toBeInTheDocument();
    const payload = post.mock.calls[1][1] as FormData;
    expect(payload.get('file')).toBe(file);
    expect(payload.get('passcode')).toBe('0123456789');
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it('keeps the same file available for retry after an uncertain upload and clears confirmation on replacement', async () => {
    await openRequest();
    const file = new File(['first'], 'first.pdf', { type: 'application/pdf' });
    fireEvent.change(
        screen.getByLabelText(/^Choose a document/, { selector: 'input' }),
        {
            target: { files: [file] },
        },
    );
    fireEvent.click(screen.getByRole('checkbox'));
    post.mockRejectedValueOnce(new Error('Network lost'));
    fireEvent.click(screen.getByRole('button', { name: 'Send document' }));
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent('same file'),
    );
    expect(screen.getByText(/first.pdf/)).toBeInTheDocument();
    fireEvent.change(
        screen.getByLabelText(/^Choose a document/, { selector: 'input' }),
        {
            target: {
                files: [
                    new File(['second'], 'second.pdf', {
                        type: 'application/pdf',
                    }),
                ],
            },
        },
    );
    expect(screen.getByRole('checkbox')).not.toBeChecked();
});

it('clears private request details and the selected file when an opened request becomes unavailable', async () => {
    await openRequest();
    fireEvent.change(
        screen.getByLabelText(/^Choose a document/, { selector: 'input' }),
        {
            target: {
                files: [
                    new File(['first'], 'first.pdf', {
                        type: 'application/pdf',
                    }),
                ],
            },
        },
    );
    fireEvent.click(screen.getByRole('checkbox'));
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 404 },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send document' }));
    await screen.findByText(/contact the team for a new request/);
    expect(screen.queryByText('Decision letter')).not.toBeInTheDocument();
    expect(
        screen.queryByLabelText(/^Choose a document/, { selector: 'input' }),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText(/^10-digit code/)).toHaveValue('');
});
