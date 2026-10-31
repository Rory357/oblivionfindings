import type { JobListing, PublicJob } from '@/components/careers/careers-parts';
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
import ApplicationStatusPage from './application-status';
import CareersJob from './apply';
import CareersIndex from './index';

const page = vi.hoisted(() => ({
    url: '/careers',
    props: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    Head: () => null,
    router: { get: vi.fn(), post: vi.fn() },
}));

const listing: JobListing = {
    id: 1,
    title: 'Support Worker',
    slug: 'support-worker-kapiti-coast',
    position_role: 'support_worker',
    employment_type: 'part_time',
    summary: 'Support three adults in their shared home in Paraparaumu.',
    site: { id: 3, name: 'Kāpiti Lodge' },
    published_at: '2026-10-23',
    closing_at: null,
};

const job: PublicJob = {
    ...listing,
    description:
        'You’ll join a small, steady team.\n\nThe role is 20 to 30 hours a week.',
    requirements:
        '- A full or restricted New Zealand driver licence\n- The right to work in New Zealand',
    responsibilities:
        'Support people with everyday living\nWrite clear daily notes',
    screening_questions: [],
    pay: '$28.50 to $31.00 an hour',
};

const options = { position_roles: [], employment_types: [], sites: [] };
const noFilters = {
    search: null,
    position_role: null,
    employment_type: null,
    site: null,
};

beforeEach(() => {
    page.url = '/careers';
    page.props = {
        branding: { name: 'Oblivion Living' },
        provider: { name: 'Oblivion Living', phone: '0800 000 000' },
        demoSite: true,
    };
    Element.prototype.scrollTo = vi.fn();
});
afterEach(cleanup);

it('lists open roles inside the public site, with Careers marked current', () => {
    render(
        <CareersIndex jobs={[listing]} options={options} filters={noFilters} />,
    );

    const nav = screen.getAllByRole('navigation', { name: 'Main' })[0];
    expect(within(nav).getByRole('link', { name: 'Careers' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(
        screen.getByRole('heading', {
            level: 1,
            name: 'Work that makes a real difference',
        }),
    ).toBeInTheDocument();
    const card = screen.getByRole('article', { name: /Support Worker/ });
    expect(
        within(card).getByRole('heading', {
            level: 3,
            name: 'Support Worker · Kāpiti Lodge',
        }),
    ).toBeInTheDocument();
    expect(within(card).getByText('Part time')).toBeInTheDocument();
    expect(within(card).getByText('Open until filled')).toBeInTheDocument();
    expect(
        within(card).getByRole('link', { name: 'Apply for Support Worker' }),
    ).toHaveAttribute(
        'href',
        '/careers/jobs/support-worker-kapiti-coast/apply',
    );
    expect(
        within(card).getByRole('link', {
            name: 'Read more about Support Worker',
        }),
    ).toHaveAttribute('href', '/careers/jobs/support-worker-kapiti-coast');
    // A short list needs no filters.
    expect(
        screen.queryByRole('search', { name: 'Filter open roles' }),
    ).not.toBeInTheDocument();
    // The demo photo shows only on the demonstration server.
    expect(
        screen.getByAltText(/members of our team talking/),
    ).toBeInTheDocument();
    expect(screen.getByText(/or call 0800 000 000/)).toBeInTheDocument();
});

it('says plainly when there are no open roles, and shows no demo photo on a client site', () => {
    page.props = { provider: { name: 'Kōwhai Living' }, demoSite: false };
    render(<CareersIndex jobs={[]} options={options} filters={noFilters} />);

    expect(screen.getByText('No open roles right now')).toBeInTheDocument();
    // The empty state's action, and the same link under "How applying works".
    const questions = screen.getAllByRole('link', {
        name: 'Send us a careers question',
    });
    expect(questions).toHaveLength(2);
    questions.forEach((link) =>
        expect(link).toHaveAttribute('href', '/contact'),
    );
    expect(
        screen.queryByAltText(/members of our team talking/),
    ).not.toBeInTheDocument();
    expect(screen.getByText(/Kōwhai Living trains you/)).toBeInTheDocument();
});

it('shows a role’s own page and opens the apply wizard from it', () => {
    page.url = '/careers/jobs/support-worker-kapiti-coast';
    render(<CareersJob job={job} />);

    expect(
        screen.getByRole('heading', {
            level: 1,
            name: 'Support Worker · Kāpiti Lodge',
        }),
    ).toBeInTheDocument();
    expect(
        screen.getByText('The role is 20 to 30 hours a week.'),
    ).toBeInTheDocument();
    expect(screen.getByText('Write clear daily notes')).toBeInTheDocument();
    expect(
        screen.getByText('The right to work in New Zealand'),
    ).toBeInTheDocument();
    expect(screen.getByText('$28.50 to $31.00 an hour')).toBeInTheDocument();
    expect(screen.getByText('When the role is filled')).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    fireEvent.click(
        screen.getAllByRole('button', { name: /Apply for this role/ })[0],
    );
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/Step 1 of 4/)).toBeInTheDocument();
    expect(within(dialog).getByLabelText('First name')).toBeInTheDocument();
});

it('opens the wizard straight away from the apply link', () => {
    page.url = '/careers/jobs/support-worker-kapiti-coast/apply';
    render(<CareersJob job={{ ...job, pay: null }} openApply />);

    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.queryByText('Pay')).not.toBeInTheDocument();
});

it('shows the applicant where their application is up to', () => {
    page.url = '/careers/application/token';
    render(
        <ApplicationStatusPage
            application={{
                position_title: 'Support Worker',
                applied_at: '2026-10-20',
                status: 'screening',
                status_label: 'Under review',
                posting: {
                    title: 'Support Worker',
                    department: null,
                    location: 'Kāpiti Lodge',
                },
            }}
        />,
    );

    expect(
        screen.getByRole('heading', {
            level: 1,
            name: 'Support Worker · Kāpiti Lodge',
        }),
    ).toBeInTheDocument();
    expect(
        screen.getByText('20 October 2026. Thanks for applying.'),
    ).toBeInTheDocument();
    const now = screen
        .getAllByRole('listitem')
        .find((item) => item.getAttribute('aria-current') === 'step');
    expect(now).toHaveTextContent(/Under review/);
    expect(
        screen.getByText('This link is just for you. Please don’t share it.'),
    ).toBeInTheDocument();
});

it('tells an unsuccessful applicant kindly, without the pathway', () => {
    page.url = '/careers/application/token';
    render(
        <ApplicationStatusPage
            application={{
                position_title: 'Support Worker',
                applied_at: '2026-10-20',
                status: 'rejected',
                status_label: 'Not successful this time',
                posting: null,
            }}
        />,
    );

    expect(screen.getByText(/wasn’t successful this time/)).toBeInTheDocument();
    expect(
        screen
            .queryAllByRole('listitem')
            .some((item) => item.getAttribute('aria-current') === 'step'),
    ).toBe(false);
});
