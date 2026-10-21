import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { useCallOutcome } from './call-outcome';

vi.mock('./api', () => ({ chatRequest: vi.fn() }));
const data = {
    call: {
        id: 'call-a',
        my_invitation_id: 'invitation-a',
        conversation_id: 17,
        title: 'Private family call',
        outcome: 'missed',
    },
    followup: { conversation: { id: 17 }, family: true, callbacks: true },
};
function View({ actor = 11 }: { actor?: number }) {
    const outcome = useCallOutcome(actor, true);
    return (
        <>
            <Button
                onClick={() =>
                    void outcome.show({
                        id: 'call-a',
                        conversation_id: 17,
                        my_invitation_id: 'invitation-a',
                        device: 'device-a',
                    })
                }
            >
                Check outcome
            </Button>
            <p>{outcome.state?.data?.call.title}</p>
            <p>{outcome.state?.data?.call.outcome}</p>
            <p>{outcome.state?.error}</p>
            <p>{outcome.state?.denied ? 'Denied' : ''}</p>
        </>
    );
}
beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(chatRequest).mockResolvedValue(data);
});
afterEach(cleanup);

it('binds an outcome read to the actor device and exact observed invitation', async () => {
    render(<View />);
    fireEvent.click(screen.getByText('Check outcome'));
    await screen.findByText('missed');
    expect(chatRequest).toHaveBeenCalledWith(
        '/chat/api/calls/call-a/outcome?device_id=device-a&invitation_id=invitation-a',
        expect.objectContaining({ headers: { 'X-Chat-Actor': '11' } }),
    );
});

it('ignores a late outcome from an earlier account', async () => {
    let finish!: (value: unknown) => void;
    vi.mocked(chatRequest).mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const view = render(<View />);
    fireEvent.click(screen.getByText('Check outcome'));
    view.rerender(<View actor={33} />);
    await act(async () => finish(data));
    expect(screen.queryByText('Private family call')).not.toBeInTheDocument();
});

it('rejects a successful response for a different invitation', async () => {
    vi.mocked(chatRequest).mockResolvedValueOnce({
        ...data,
        call: { ...data.call, my_invitation_id: 'replacement' },
    });
    render(<View />);
    fireEvent.click(screen.getByText('Check outcome'));
    await screen.findByText(/The call result could not be confirmed/);
    expect(screen.queryByText('Private family call')).not.toBeInTheDocument();
});

it('clears result identity on access denial and does not guess the reason a call disappeared', async () => {
    vi.mocked(chatRequest).mockRejectedValueOnce(
        Object.assign(new Error('Denied'), { status: 403 }),
    );
    render(<View />);
    fireEvent.click(screen.getByText('Check outcome'));
    await screen.findByText('Denied');
    expect(screen.queryByText('Private family call')).not.toBeInTheDocument();
    expect(screen.queryByText('missed')).not.toBeInTheDocument();
});

it('discards an obsolete invitation result and rejects a mismatched conversation', async () => {
    vi.mocked(chatRequest).mockRejectedValueOnce(
        Object.assign(new Error('Changed'), { status: 409 }),
    );
    render(<View />);
    fireEvent.click(screen.getByText('Check outcome'));
    await act(async () => undefined);
    expect(screen.queryByText('Private family call')).not.toBeInTheDocument();
    vi.mocked(chatRequest).mockResolvedValueOnce({
        ...data,
        call: { ...data.call, conversation_id: 99 },
    });
    fireEvent.click(screen.getByText('Check outcome'));
    await waitFor(() =>
        expect(
            screen.getByText(/The call result could not be confirmed/),
        ).toBeInTheDocument(),
    );
    expect(screen.queryByText('Private family call')).not.toBeInTheDocument();
});
