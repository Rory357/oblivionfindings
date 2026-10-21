import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { CallSummary } from './call-summary';

vi.mock('./api', () => ({ chatRequest: vi.fn() }));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
const call = {
    id: 'call-1',
    client_id: 12,
    client_name: 'Aroha',
    can_record_note: true,
};

describe('reviewed call summary', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(chatRequest).mockResolvedValue({ already_saved: false });
    });
    it('ignores a late save acknowledgement after the account-owned review closes', async () => {
        let resolve!: (value: { already_saved: boolean }) => void;
        vi.mocked(chatRequest).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const close = vi.fn();
        const view = render(
            <CallSummary actorId={11} call={call} onClose={close} />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Staff summary' }),
            { target: { value: 'A reviewed update.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save staff summary' }),
        );
        view.unmount();
        await act(async () => resolve({ already_saved: false }));
        expect(toast.success).not.toHaveBeenCalled();
        expect(close).not.toHaveBeenCalled();
    });
    it('saves nothing automatically and requires separately reviewed wording for Family Notes', async () => {
        render(<CallSummary actorId={11} call={call} onClose={vi.fn()} />);
        expect(chatRequest).not.toHaveBeenCalled();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Staff summary' }),
            { target: { value: 'Agreed a follow-up visit.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        expect(chatRequest).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Also publish a separate Family Note',
            }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Family-friendly update' }),
        ).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'Save both records' }),
        ).toBeDisabled();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Family-friendly update' }),
            { target: { value: 'We will arrange a visit with you.' } },
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'I have reviewed this wording for the Family Notes audience.',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save both records' }),
        );
        await waitFor(() =>
            expect(chatRequest).toHaveBeenCalledWith(
                '/chat/api/calls/call-1/record',
                expect.objectContaining({
                    headers: { 'X-Chat-Actor': '11' },
                    body: JSON.stringify({
                        summary: 'Agreed a follow-up visit.',
                        publish_family_note: true,
                        family_summary: 'We will arrange a visit with you.',
                        audience_confirmed: true,
                    }),
                }),
            ),
        );
    });
    it('retains the review when saving fails', async () => {
        vi.mocked(chatRequest).mockRejectedValue(
            new Error('Connection interrupted'),
        );
        const close = vi.fn();
        render(<CallSummary actorId={11} call={call} onClose={close} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Staff summary' }),
            { target: { value: 'Follow up tomorrow.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save staff summary' }),
        );
        await screen.findByRole('alert');
        expect(screen.getByText('Follow up tomorrow.')).toBeInTheDocument();
        expect(close).not.toHaveBeenCalled();
    });
});
