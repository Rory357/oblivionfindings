import type { GovernedAction } from '@/pages/security-devices/devices/_safety-kit-types';
import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EndSiteEmergency, SiteEmergency, type SiteEmergencySite } from './site-emergency-popup';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, post: vi.fn(), visit: vi.fn(), reload: vi.fn() } };
});

const critical: GovernedAction = { available: true, reason: null, step_up: false, approval: true, type_name: true, via: 'Synthetic House hub' };
const site: SiteEmergencySite = {
    deviceId: 90,
    site: 'Synthetic House',
    mode: 'normal',
    typeName: 'Synthetic House doors',
    lockdown: critical,
    evacuate: critical,
    end: { ...critical, type_name: false },
    confirmUrl: '/confirm',
};

describe('Lockdown or evacuation (sdr-86)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('says people inside can always leave and who is told, and needs a reason and the typed name', () => {
        render(<SiteEmergency open site={site} onClose={() => undefined} />);
        const popup = screen.getByRole('alertdialog', { name: 'Lockdown at Synthetic House?' });

        expect(within(popup).getByText(/every door opens from the inside, always/)).toBeInTheDocument();
        expect(within(popup).getByText('Control Room, and the house manager and staff on shift at Synthetic House')).toBeInTheDocument();
        expect(within(popup).getByText(/A second person who approves device commands says yes first/)).toBeInTheDocument();

        const start = within(popup).getByRole('button', { name: 'Start lockdown' });
        expect(start).toBeDisabled();
        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Police asked us to keep the doors shut' } });
        expect(start).toBeDisabled();
        fireEvent.change(within(popup).getByLabelText(/Type Synthetic House doors to confirm/), { target: { value: 'Synthetic House doors' } });
        expect(start).toBeEnabled();
        fireEvent.click(start);

        expect(router.post).toHaveBeenCalledWith(
            '/security-devices/devices/90/safety/site/lockdown',
            expect.objectContaining({ reason: 'Police asked us to keep the doors shut', confirmation_text: 'Synthetic House doors' }),
            expect.anything(),
        );
    });

    it('switches to evacuation, says to call 111 first, and sends an evacuation', () => {
        render(<SiteEmergency open site={site} onClose={() => undefined} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Evacuation' }));
        const popup = screen.getByRole('alertdialog', { name: 'Evacuate Synthetic House?' });

        expect(within(popup).getByText('If there’s a fire, call 111 first.')).toBeInTheDocument();
        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Smoke alarm going off in the kitchen' } });
        fireEvent.change(within(popup).getByLabelText(/Type Synthetic House doors to confirm/), { target: { value: 'Synthetic House doors' } });
        fireEvent.click(within(popup).getByRole('button', { name: 'Start evacuation' }));

        expect(router.post).toHaveBeenCalledWith('/security-devices/devices/90/safety/site/evacuate', expect.anything(), expect.anything());
    });

    it('asks the person to confirm it’s them first, and says when the hub can’t do it', () => {
        render(<SiteEmergency open site={{ ...site, lockdown: { ...critical, step_up: true } }} onClose={() => undefined} />);
        fireEvent.click(screen.getByRole('button', { name: 'Confirm it’s you' }));
        expect(router.visit).toHaveBeenCalledWith('/confirm');
        cleanup();

        render(<SiteEmergency open site={{ ...site, lockdown: { ...critical, available: false, reason: 'Synthetic House hub needs its software update.' } }} onClose={() => undefined} />);
        expect(screen.getByText('Synthetic House hub needs its software update.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Start lockdown' })).toBeDisabled();
    });

    it('ends an evacuation with a reason', () => {
        render(<EndSiteEmergency open site={{ ...site, mode: 'evacuation' }} onClose={() => undefined} />);
        const popup = screen.getByRole('alertdialog', { name: 'End the evacuation at Synthetic House?' });
        fireEvent.change(within(popup).getByLabelText(/Reason/), { target: { value: 'Fire service says it’s safe to go back in' } });
        fireEvent.click(within(popup).getByRole('button', { name: 'End evacuation' }));

        expect(router.post).toHaveBeenCalledWith('/security-devices/devices/90/safety/site/end', expect.objectContaining({ reason: 'Fire service says it’s safe to go back in' }), expect.anything());
    });
});
