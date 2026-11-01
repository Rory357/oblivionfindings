import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { doorWords, KeepDoorUnlocked, type DoorPopupDoor } from './door-popups';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, post: vi.fn(), visit: vi.fn(), reload: vi.fn() } };
});

const door: DoorPopupDoor = { id: 61, name: 'Front door lock', model: 'UniFi Access G2 reader', site: 'Synthetic House', lockWords: 'locked now' };

describe('Door pop-ups (sdr-70)', () => {
    afterEach(cleanup);

    it('names a door the way people say it', () => {
        expect(doorWords('Front door lock')).toBe('the front door');
        expect(doorWords('Synthetic House back door')).toBe('Synthetic House back door');
    });

    it('keeps a door unlocked only until a time at most 4 hours ahead, with a reason, and says automations never unlock', () => {
        const onSubmit = vi.fn();
        render(<KeepDoorUnlocked open door={door} onSubmit={onSubmit} onClose={() => undefined} />);

        const popup = screen.getByRole('dialog', { name: 'Keep the door unlocked' });
        expect(within(popup).getByText(/stays unlocked until/)).toBeInTheDocument();
        expect(within(popup).getByText(/Anyone can walk in until then/)).toBeInTheDocument();
        expect(within(popup).getByText(/automations never unlock/)).toBeInTheDocument();
        expect(within(popup).getByText('The house manager and the staff on shift at Synthetic House')).toBeInTheDocument();

        const send = within(popup).getByRole('button', { name: 'Keep unlocked' });
        expect(send).toBeDisabled();
        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Whānau barbecue in the front garden' } });
        expect(send).toBeEnabled();
        fireEvent.click(send);
        expect(onSubmit).toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:00$/), 'Whānau barbecue in the front garden');
    });
});
