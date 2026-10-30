import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatAvailabilityControl } from './chat-availability-control';

const state = vi.hoisted(() => ({
    enabled: true,
    availability: 'available',
    soundEnabled: true,
    canSetOffDuty: true,
    setAvailability: vi.fn(),
    setSoundEnabled: vi.fn(),
}));
vi.mock('./chat-calling', () => ({ useChatCalling: () => state }));

function openAvailability() {
    fireEvent.keyDown(screen.getByRole('combobox'), { key: 'Enter' });
}
function choose(option: string) {
    openAvailability();
    fireEvent.click(screen.getByRole('option', { name: option }));
}

describe('Chat availability choices', () => {
    beforeEach(() => {
        state.enabled = true;
        state.availability = 'available';
        state.canSetOffDuty = true;
        state.setAvailability.mockReset().mockResolvedValue(undefined);
        state.setSoundEnabled.mockReset().mockResolvedValue(undefined);
    });
    it('separates Busy and Do not disturb and limits Off duty to eligible staff', () => {
        const view = render(
            <ChatAvailabilityControl actorId={1} family={false} />,
        );
        openAvailability();
        expect(screen.getByRole('option', { name: 'Busy' })).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Do not disturb' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Off duty' })).toBeInTheDocument();
        view.unmount();
        const family = render(<ChatAvailabilityControl actorId={2} family />);
        openAvailability();
        expect(screen.getByRole('option', { name: 'Busy' })).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Off duty' }),
        ).not.toBeInTheDocument();
        family.unmount();
        state.canSetOffDuty = false;
        render(<ChatAvailabilityControl actorId={3} family={false} />);
        openAvailability();
        expect(screen.getByRole('option', { name: 'Busy' })).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Off duty' }),
        ).not.toBeInTheDocument();
    });
    it('prevents overlapping changes and leaves failed writes visibly unconfirmed', async () => {
        let reject!: (error: Error) => void;
        state.setAvailability.mockImplementation(
            () =>
                new Promise((_, fail) => {
                    reject = fail;
                }),
        );
        render(<ChatAvailabilityControl actorId={1} family={false} />);
        choose('Off duty');
        expect(state.setAvailability).toHaveBeenCalledWith('off_duty');
        expect(screen.getByRole('combobox')).toBeDisabled();
        expect(screen.getByRole('switch')).toBeDisabled();
        await act(async () => reject(new Error('Connection lost. Try again.')));
        expect(screen.getByRole('alert')).toHaveTextContent('Connection lost');
        expect(screen.getByRole('combobox')).toHaveTextContent('Available');
        expect(screen.getByRole('combobox')).toBeEnabled();
        expect(
            screen.queryByText('You are off duty. No response is expected.'),
        ).not.toBeInTheDocument();
    });
    it('does not show a previous account’s late failure in the new session', async () => {
        let reject!: (error: Error) => void;
        state.setAvailability.mockImplementation(
            () =>
                new Promise((_, fail) => {
                    reject = fail;
                }),
        );
        const view = render(
            <ChatAvailabilityControl actorId={1} family={false} />,
        );
        choose('Busy');
        view.rerender(<ChatAvailabilityControl actorId={2} family={false} />);
        await act(async () => reject(new Error('Old account failure')));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByRole('combobox')).toBeEnabled();
    });
    it('saves ringtone separately and explains a confirmed off-duty preference', async () => {
        state.availability = 'off_duty';
        render(<ChatAvailabilityControl actorId={1} family={false} />);
        expect(
            screen.getByText('You are off duty. No response is expected.'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('switch'));
        await waitFor(() => expect(screen.getByRole('switch')).toBeEnabled());
        expect(state.setSoundEnabled).toHaveBeenCalledWith(false);
        expect(state.setAvailability).not.toHaveBeenCalled();
    });
});
