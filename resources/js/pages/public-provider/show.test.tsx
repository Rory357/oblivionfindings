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
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
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
const page = vi.hoisted(() => ({
    url: '/',
    props: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Link: ({
        href,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url} {...props} />,
    Head: () => null,
}));
beforeEach(() => {
    page.url = '/';
    page.props = { provider };
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(cleanup);

it('opens and closes the phone menu with keyboard focus restored', () => {
    render(<PublicProviderPage provider={provider} page="home" />);
    const toggle = screen.getByRole('button', { name: 'Open menu' });
    fireEvent.click(toggle);
    const menu = document.getElementById('site-menu')!;
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(
        within(menu).getByRole('link', { name: 'Funding help' }),
    ).toHaveAttribute('href', '/funding');
    expect(
        within(menu).getByRole('link', { name: /Make a referral/ }),
    ).toHaveAttribute('href', '/referrals/new');
    fireEvent.keyDown(menu, { key: 'Escape' });
    expect(document.getElementById('site-menu')).not.toBeInTheDocument();
    expect(toggle).toHaveFocus();
});

it('places the Contact us form beside the office details and says when an old form was not sent', () => {
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
    expect(
        screen.getByRole('heading', { name: 'Send us a message' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('heading', { name: 'Our office' }),
    ).toBeInTheDocument();
    expect(
        screen.getAllByRole('link', { name: '0800 000 000' })[0],
    ).toHaveAttribute('href', 'tel:0800000000');
});

it('shows the Contact us details from Settings › Referrals and hides the ones not set', () => {
    page.props = {
        provider: {
            ...provider,
            email: 'kiaora@example.test',
            address: '',
            opening_hours: 'Weekdays, 9am to 5pm',
        },
    };
    render(<PublicProviderPage provider={provider} page="contact" />);
    expect(
        screen.getByRole('link', { name: 'kiaora@example.test' }),
    ).toHaveAttribute('href', 'mailto:kiaora@example.test');
    expect(screen.getByText('Weekdays, 9am to 5pm')).toBeInTheDocument();
    expect(screen.queryByText('Visit us')).not.toBeInTheDocument();
});

it('explains unavailable intake and keeps the funding pathways readable', () => {
    const closed = { ...provider, intake_enabled: false, phone: '' };
    page.props = { provider: closed };
    render(<PublicProviderPage provider={closed} page="funding" />);
    expect(
        screen.getByText('Online enquiries are currently unavailable.'),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('heading', { name: 'Disability Support Services' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: /Get help with funding/ }),
    ).toHaveAttribute('href', '/referrals/new?purpose=funding');
    expect(
        screen.queryByRole('link', { name: /0800/ }),
    ).not.toBeInTheDocument();
});

it('retains the selected service when moving from its page into funding help', () => {
    page.url = '/services/supported_living';
    render(
        <PublicProviderPage
            provider={provider}
            page="service"
            service={provider.services[0]}
        />,
    );
    expect(
        screen.getByRole('link', { name: /Talk about funding/ }),
    ).toHaveAttribute(
        'href',
        '/referrals/new?purpose=funding&service=supported_living',
    );
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
        'Supported living',
    );
    // The three kinds of support, each reachable from the footer and cards.
    for (const id of ['home', 'community', 'respite'])
        expect(document.getElementById(id)).toBeInTheDocument();
    // A section preselects its service only when the provider offers it.
    expect(
        screen.getByRole('link', { name: /Ask about support at home/ }),
    ).toHaveAttribute(
        'href',
        '/referrals/new?purpose=support&service=supported_living',
    );
    expect(
        screen.getByRole('link', { name: /Ask about in the community/ }),
    ).toHaveAttribute('href', '/referrals/new?purpose=support');
});

it('shows the client photo in the home hero, a plain brand panel without one, and the islands only with no client branding', () => {
    page.props = {
        provider,
        branding: {
            name: 'Kōwhai Living',
            heroUrl: '/storage/branding/hero.jpg',
            heroAlt: 'Two people cooking',
        },
    };
    const { unmount } = render(
        <PublicProviderPage provider={provider} page="home" />,
    );
    expect(
        screen.getByRole('img', { name: 'Two people cooking' }),
    ).toHaveAttribute('src', '/storage/branding/hero.jpg');
    expect(screen.queryByTestId('home-islands')).not.toBeInTheDocument();
    unmount();

    page.props = { provider, branding: { name: 'Kōwhai Living' } };
    const second = render(
        <PublicProviderPage provider={provider} page="home" />,
    );
    expect(screen.queryByTestId('home-islands')).not.toBeInTheDocument();
    expect(screen.getAllByTestId('site-photo-panel').length).toBeGreaterThan(0);
    second.unmount();

    const stock = { ...provider, name: 'Oblivion Care' };
    page.props = { provider: stock, branding: { name: 'Oblivion Care' } };
    render(<PublicProviderPage provider={stock} page="home" />);
    expect(screen.getByTestId('home-islands')).toBeInTheDocument();
});

it('shows our demo photos and people only on the demonstration server', () => {
    page.props = { provider, branding: { name: 'Example Support' } };
    const { unmount } = render(
        <PublicProviderPage provider={provider} page="about" />,
    );
    expect(document.querySelector('img[src^="/demo-site/"]')).toBeNull();
    expect(screen.queryByText('Mere Tānirau')).not.toBeInTheDocument();
    unmount();

    page.props = {
        provider,
        branding: { name: 'Oblivion Living' },
        demoSite: true,
    };
    render(<PublicProviderPage provider={provider} page="about" />);
    expect(
        document.querySelector('img[src="/demo-site/about-whanau.jpg"]'),
    ).not.toBeNull();
    expect(screen.getByText('Mere Tānirau')).toBeInTheDocument();
});

it('marks the footer pages as placeholder text for the provider to replace', () => {
    page.url = '/privacy-policy';
    render(<PublicProviderPage provider={provider} page="privacy" />);
    expect(screen.getByText('Placeholder text')).toBeInTheDocument();
    expect(
        screen.getByText(/Example Support replaces this with its own/),
    ).toBeInTheDocument();
    // The published enquiry notice is real, not a placeholder.
    expect(screen.getByText('The published notice')).toBeInTheDocument();
});
