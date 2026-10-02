import { PageHero } from '@/components/page';
import type { EmarNavigationPermissions } from '@/lib/emar-navigation';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EmarHubRail } from './emar-hub-rail';

const fixture = vi.hoisted(() => ({
    url: '/emar/medications',
    can: {} as EmarNavigationPermissions,
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: { visit: fixture.visit },
    usePage: () => ({
        url: fixture.url,
        props: { auth: { can: fixture.can } },
    }),
}));

const coordinator: EmarNavigationPermissions = {
    medications: {
        view: true,
        administerRecord: true,
        ordersManage: true,
        ordersVerify: true,
        settingsManage: true,
        stockUpdate: true,
        controlledView: true,
        auditView: true,
        reportsExport: true,
    },
};
const auditor: EmarNavigationPermissions = {
    medications: { view: true, auditView: true },
};

function at(url: string, can: EmarNavigationPermissions) {
    fixture.url = url;
    fixture.can = can;
}
const tabs = () => screen.queryAllByRole('tab').map((tab) => tab.textContent);

afterEach(() => {
    cleanup();
    fixture.visit.mockReset();
});

describe('EmarHubRail', () => {
    it('shows the hub views for the URL, with the page as the active tab', () => {
        at('/emar/medications', coordinator);
        render(<EmarHubRail />);

        expect(
            screen.getByRole('tablist', { name: 'MAR & medicines pages' }),
        ).toBeTruthy();
        expect(tabs()).toEqual([
            'MAR charts',
            'Medicines',
            'As-needed history',
            'Support & self-administration',
        ]);
        expect(
            screen
                .getByRole('tab', { name: 'Medicines' })
                .getAttribute('aria-selected'),
        ).toBe('true');
    });

    it('keeps the view lit on its deeper pages', () => {
        at('/emar/controlled/12?tab=loss', coordinator);
        render(<EmarHubRail />);

        expect(
            screen
                .getByRole('tab', { name: 'Controlled register' })
                .getAttribute('aria-selected'),
        ).toBe('true');
    });

    it('opens another view by its existing URL', () => {
        at('/emar/medications', coordinator);
        render(<EmarHubRail />);

        fireEvent.click(screen.getByRole('tab', { name: 'As-needed history' }));
        expect(fixture.visit).toHaveBeenCalledWith('/emar/prn');

        fireEvent.click(screen.getByRole('tab', { name: 'Medicines' }));
        expect(fixture.visit).toHaveBeenCalledTimes(1);
    });

    it('lists only the views the viewer may open, plus the page itself', () => {
        at('/emar/errors', auditor);
        render(<EmarHubRail />);
        expect(tabs()).toEqual(['Medication errors']);
        cleanup();

        // Overview is lead-only, but the server let this person open it.
        at('/emar', auditor);
        render(<EmarHubRail />);
        expect(tabs()).toEqual(['Overview', 'Medication errors']);
    });

    it('renders nothing for hubs with their own rail or outside the module', () => {
        for (const url of [
            '/meds/today',
            '/emar/rounds',
            '/emar/settings',
            '/fleet-assets',
        ]) {
            at(url, coordinator);
            const { container } = render(<EmarHubRail />);
            expect(container.innerHTML).toBe('');
            cleanup();
        }
    });

    it('shows alert counters on their view', () => {
        at('/emar/safety/eligibility', coordinator);
        render(<EmarHubRail alerts={{ eligibility: 3 }} />);

        expect(
            screen.getByRole('tab', { name: /Staff eligibility/ }).textContent,
        ).toBe('Staff eligibility3');
    });

    it('sits in the PageHero rail row, inside the banner', () => {
        at('/emar/stock', coordinator);
        const { container } = render(
            <PageHero title="Stock" rail={<EmarHubRail />} />,
        );

        const hero = container.querySelector('[data-page-hero]');
        const tablist = screen.getByRole('tablist', {
            name: 'Stock & controlled drugs pages',
        });
        expect(hero?.contains(tablist)).toBe(true);
    });
});
