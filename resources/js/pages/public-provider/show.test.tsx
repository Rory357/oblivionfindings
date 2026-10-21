import type { PublicProvider } from '@/components/public-provider';
import '@testing-library/jest-dom/vitest';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import PublicProviderPage from './show';

const provider: PublicProvider = {
    name: 'Example Support',
    phone: '0800 000 000',
    response_hours: 'Monday to Friday',
    intake_enabled: true,
    notice: 'The published notice',
    notice_version: 'v1',
    services: [{ id: 'supported_living', label: 'Supported living' }],
};
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/', props: { provider } }),
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    Head: () => null,
}));
vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'light', updateAppearance: vi.fn() }),
}));
afterEach(cleanup);

it('opens and closes the mobile navigation with keyboard focus restored', () => {
    render(<PublicProviderPage provider={provider} page="home" />);
    const toggle = screen.getByRole('button', { name: 'Open navigation' });
    fireEvent.click(toggle);
    const menu = screen.getByRole('navigation', { name: 'Mobile navigation' });
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(
        within(menu).getByRole('link', { name: 'Funding help' }),
    ).toHaveAttribute('href', '/funding');
    fireEvent.keyDown(menu, { key: 'Escape' });
    expect(
        screen.queryByRole('navigation', { name: 'Mobile navigation' }),
    ).not.toBeInTheDocument();
    expect(toggle).toHaveFocus();
});

it('connects every contact choice to its specific durable enquiry purpose', () => {
    render(
        <PublicProviderPage
            provider={provider}
            page="contact"
            previousFormRetired
        />,
    );
    expect(
        screen.getByText('Your earlier message was not sent'),
    ).toBeInTheDocument();
    for (const [label, purpose] of [
        ['Start an enquiry', 'referral'],
        ['Ask for funding help', 'funding'],
        ['Request contact', 'callback'],
        ['General enquiry', 'business'],
    ]) {
        expect(screen.getByRole('link', { name: label })).toHaveAttribute(
            'href',
            `/referrals/new?purpose=${purpose}`,
        );
    }
    expect(
        screen.getAllByRole('link', { name: '0800 000 000' })[0],
    ).toHaveAttribute('href', 'tel:0800000000');
});

it('explains unavailable intake and keeps funding information accessible', () => {
    render(
        <PublicProviderPage
            provider={{ ...provider, intake_enabled: false, phone: '' }}
            page="funding"
        />,
    );
    expect(
        screen.getByText('Online enquiries are currently unavailable'),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'DSS: needs assessment services' }),
    ).toHaveAttribute(
        'href',
        'https://www.disabilitysupport.govt.nz/disabled-people/assessment-and-funding/needs-assessment-services',
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Can I enquire without funding?' }),
    );
    expect(
        screen.getByText(
            /making an enquiry does not guarantee funding or a place/,
        ),
    ).toBeVisible();
});

it('retains the selected service when moving from its page into funding help', () => {
    render(
        <PublicProviderPage
            provider={provider}
            page="service"
            service={provider.services[0]}
        />,
    );
    expect(
        screen.getByRole('link', { name: 'Talk about funding' }),
    ).toHaveAttribute(
        'href',
        '/referrals/new?purpose=funding&service=supported_living',
    );
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
        'Supported living',
    );
});
