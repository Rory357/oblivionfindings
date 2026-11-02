import type { GovernedAction } from '@/pages/security-devices/devices/_safety-kit-types';
import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { doorWords, KeepDoorUnlocked, LockDoorAgain, type DoorPopupDoor } from './door-popups';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, post: vi.fn(), visit: vi.fn(), reload: vi.fn() } };
});

const door: DoorPopupDoor = { id: 61, name: 'Front door lock', model: 'UniFi Access G2 reader', site: 'Synthetic House', lockWords: 'locked now' };
const ready: GovernedAction = { available: true, reason: null, step_up: false, approval: true, type_name: false, via: 'Synthetic House hub' };

describe('Door pop-ups (sdr-70)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('names a door the way people say it', () => {
        expect(doorWords('Front door lock')).toBe('the front door');
        expect(doorWords('Synthetic House back door')).toBe('Synthetic House back door');
    });

    it('keeps a door unlocked only until a time at most 4 hours ahead, with a reason, through the governed request', () => {
        render(<KeepDoorUnlocked open door={door} action={ready} confirmUrl="/confirm" onClose={() => undefined} />);

        const popup = screen.getByRole('dialog', { name: 'Keep the door unlocked' });
        expect(within(popup).getByText(/stays unlocked until/)).toBeInTheDocument();
        expect(within(popup).getByText(/Anyone can walk in until then/)).toBeInTheDocument();
        expect(within(popup).getByText(/automations never unlock/)).toBeInTheDocument();
        expect(within(popup).getByText(/A second person who approves device commands says yes first/)).toBeInTheDocument();
        expect(within(popup).getByText('The house manager and the staff on shift at Synthetic House')).toBeInTheDocument();

        const send = within(popup).getByRole('button', { name: 'Keep unlocked' });
        expect(send).toBeDisabled();
        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Whānau barbecue in the front garden' } });
        expect(send).toBeEnabled();
        fireEvent.click(send);
        expect(router.post).toHaveBeenCalledWith(
            '/security-devices/devices/61/safety/keep-unlocked',
            expect.objectContaining({ until: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:00$/), reason: 'Whānau barbecue in the front garden' }),
            expect.anything(),
        );
    });

    it('asks the person to confirm it’s them first when the framework wants it', () => {
        render(<KeepDoorUnlocked open door={door} action={{ ...ready, step_up: true }} confirmUrl="/confirm" onClose={() => undefined} />);
        const popup = screen.getByRole('dialog', { name: 'Keep the door unlocked' });
        fireEvent.click(within(popup).getByRole('button', { name: 'Confirm it’s you' }));

        expect(router.visit).toHaveBeenCalledWith('/confirm');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('says why it can’t be done, and sends nothing', () => {
        render(<KeepDoorUnlocked open door={door} action={{ ...ready, available: false, reason: 'Synthetic House hub needs its software update.' }} confirmUrl="/confirm" onClose={() => undefined} />);
        const popup = screen.getByRole('dialog', { name: 'Keep the door unlocked' });

        expect(within(popup).getByText('Synthetic House hub needs its software update.')).toBeInTheDocument();
        expect(within(popup).getByRole('button', { name: 'Keep unlocked' })).toBeDisabled();
    });

    it('locks the door again early', () => {
        render(<LockDoorAgain open door={door} action={ready} confirmUrl="/confirm" onClose={() => undefined} />);
        const popup = screen.getByRole('alertdialog', { name: 'Lock the front door again now?' });
        fireEvent.click(within(popup).getByRole('button', { name: 'Lock again' }));

        expect(router.post).toHaveBeenCalledWith('/security-devices/devices/61/safety/keep-unlocked/end', expect.anything(), expect.anything());
    });
});
