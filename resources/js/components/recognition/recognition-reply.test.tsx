import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { RecognitionReply } from './recognition-reply';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('@/pages/company-hub/_api', () => ({ hubRequest: request }));
const reply = {
    id: 4,
    name: 'Jamie',
    body: 'Thank you for making everyone feel welcome.',
    version: 2,
    can_remove: true,
};
beforeEach(() => {
    request.mockReset();
});

it('offers removal only with current author permission and identity', () => {
    const { rerender } = render(
        <RecognitionReply
            actorId={11}
            kudosId={7}
            reply={{ ...reply, can_remove: false }}
            onRemoved={vi.fn()}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Remove my reply' }),
    ).not.toBeInTheDocument();
    rerender(
        <RecognitionReply kudosId={7} reply={reply} onRemoved={vi.fn()} />,
    );
    expect(
        screen.queryByRole('button', { name: 'Remove my reply' }),
    ).not.toBeInTheDocument();
    expect(screen.getByText(reply.body)).toBeVisible();
    expect(request).not.toHaveBeenCalled();
});

it('requires confirmation, prevents duplicate removal and updates only after acknowledgement', async () => {
    let finish!: (result: unknown) => void;
    request.mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const onRemoved = vi.fn();
    render(
        <RecognitionReply
            actorId={11}
            kudosId={7}
            reply={reply}
            onRemoved={onRemoved}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Remove my reply' }));
    expect(request).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Keep reply' }));
    expect(request).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Remove my reply' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm removal' }));
    fireEvent.click(screen.getByRole('button', { name: 'Removing…' }));
    expect(request).toHaveBeenCalledTimes(1);
    expect(request).toHaveBeenCalledWith(
        '/hr/feed/kudos/7/replies/4',
        'DELETE',
        { actor_id: 11, version: 2 },
    );
    expect(screen.getByRole('button', { name: 'Keep reply' })).toBeDisabled();
    expect(onRemoved).not.toHaveBeenCalled();
    await act(async () => finish({ saved: true, kudos_id: 7, reply_id: 4 }));
    expect(screen.getByRole('status')).toHaveTextContent(
        'Your reply was removed.',
    );
    expect(screen.queryByText(reply.body)).not.toBeInTheDocument();
    expect(onRemoved).toHaveBeenCalledTimes(1);
});

it('retains a reply after failure or an acknowledgement for another reply', async () => {
    request
        .mockRejectedValueOnce(new Error('This conversation changed.'))
        .mockResolvedValueOnce({ saved: true, kudos_id: 7, reply_id: 99 });
    const onRemoved = vi.fn();
    render(
        <RecognitionReply
            actorId={11}
            kudosId={7}
            reply={reply}
            onRemoved={onRemoved}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Remove my reply' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm removal' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'This conversation changed.',
    );
    expect(screen.getByText(reply.body)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm removal' }));
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The removal could not be confirmed.',
        ),
    );
    expect(screen.getByText(reply.body)).toBeVisible();
    expect(onRemoved).not.toHaveBeenCalled();
});

it('ignores a previous account response and resets its confirmation', async () => {
    let finish!: (result: unknown) => void;
    request.mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const onRemoved = vi.fn();
    const { rerender } = render(
        <RecognitionReply
            actorId={11}
            kudosId={7}
            reply={reply}
            onRemoved={onRemoved}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Remove my reply' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm removal' }));
    rerender(
        <RecognitionReply
            actorId={12}
            kudosId={7}
            reply={{ ...reply, can_remove: false }}
            onRemoved={onRemoved}
        />,
    );
    await act(async () => finish({ saved: true, kudos_id: 7, reply_id: 4 }));
    expect(screen.getByText(reply.body)).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Keep reply' }),
    ).not.toBeInTheDocument();
    expect(onRemoved).not.toHaveBeenCalled();
});
