import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import QualificationCheckShift from './CheckShift';
import QualificationsIndex from './Index';

const state = vi.hoisted(() => ({ mobile: true, get: vi.fn() }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => state.mobile }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => (
        <a {...props}>{children}</a>
    ),
    router: { get: state.get, reload: vi.fn() },
    usePage: () => ({
        props: {
            auth: { user: { id: 5 } },
            workerTimezone: 'Pacific/Auckland',
        },
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: { title: string; href: string }[];
    }) => (
        <div>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((item) => (
                    <a href={item.href} key={item.title}>
                        {item.title}
                    </a>
                ))}
            </nav>
            {children}
        </div>
    ),
}));
vi.mock('./requirement-editor', () => ({
    QualificationRequirementEditor: () => <div>Requirement editor opened</div>,
    QualificationRemoveDialog: () => null,
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.clearAllMocks();
    state.mobile = true;
});

const indexProps = {
    requirements: {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters: { mandatory: 'mandatory', client_id: 7 },
    stats: { total: 4, mandatory: 3, clients: 1 },
    clients: [{ id: 7, first_name: 'Test', last_name: 'Person' }],
    can: { create: true, edit: true, delete: true },
};

it('keeps phone create authority and one retained search that preserves the current requirement filters', () => {
    vi.useFakeTimers();
    const { container } = render(<QualificationsIndex {...indexProps} />);
    const toggle = screen.getByRole('button', {
        name: /Requirements.*Details & filters/,
    });
    const details = document.getElementById(
        toggle.getAttribute('aria-controls')!,
    )!;
    expect(details).toHaveClass('hidden');
    expect(
        container.querySelector('[data-slot="workforce-primary-actions"]'),
    ).toHaveTextContent('Add requirement');
    expect(container.querySelectorAll('input[type="search"]')).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Add requirement' }));
    expect(screen.getByText('Requirement editor opened')).toBeInTheDocument();
    fireEvent.click(toggle);
    expect(details).not.toHaveClass('hidden');
    const input = screen.getByRole('searchbox', {
        name: 'Search qualifications and clients…',
    });
    fireEvent.change(input, { target: { value: 'First aid' } });
    fireEvent.click(toggle);
    fireEvent.click(toggle);
    expect(input).toHaveValue('First aid');
    act(() => vi.advanceTimersByTime(400));
    expect(state.get).toHaveBeenCalledWith(
        '/operations/qualifications',
        { mandatory: 'mandatory', q: 'First aid', client_id: '7' },
        expect.objectContaining({ preserveState: true, replace: true }),
    );
    expect(screen.getByRole('link', { name: 'Workforce' })).toHaveAttribute(
        'href',
        '/operations/rostering',
    );
    expect(
        screen.queryByRole('link', { name: 'Operations' }),
    ).not.toBeInTheDocument();
});

it('does not expose create when permission is absent and keeps the existing desktop search and status', () => {
    state.mobile = false;
    const { container } = render(
        <QualificationsIndex
            {...indexProps}
            can={{ create: false, edit: false, delete: false }}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Add requirement' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('searchbox')).toBeInTheDocument();
    expect(
        container.querySelector('[data-slot="workforce-primary-actions"]'),
    ).toBeNull();
    expect(
        container.querySelector('[data-slot="page-header-subline"]'),
    ).toHaveTextContent('Worker qualification requirements by client');
    expect(
        container.querySelector('[data-slot="page-header-top"]'),
    ).toHaveTextContent('3 mandatory');
});

it('preserves the qualification check warning, shift return and body search behind phone details', () => {
    const { container } = render(
        <QualificationCheckShift
            shift={{
                id: 11,
                starts_at: '2026-10-10T21:00:00Z',
                ends_at: '2026-10-11T05:00:00Z',
                staff: { id: 9, name: 'Test worker' },
            }}
            allMandatoryMet={false}
            results={[
                {
                    requirement: { id: 1, qualification_name: 'First aid' },
                    met: false,
                    is_mandatory: true,
                    severity: 'warning',
                    status: 'unmapped',
                },
                {
                    requirement: {
                        id: 2,
                        qualification_name: 'Manual handling',
                    },
                    met: true,
                    is_mandatory: true,
                    severity: 'info',
                },
            ]}
        />,
    );
    expect(
        container.querySelector('[data-slot="workforce-primary-actions"]'),
    ).toHaveTextContent('Qualification warnings');
    expect(
        container.querySelector('header a[href="/operations/shifts/11"]'),
    ).not.toBeNull();
    fireEvent.click(
        screen.getByRole('button', {
            name: /Qualification check.*Details & filters/,
        }),
    );
    fireEvent.change(
        screen.getByRole('searchbox', { name: 'Search requirements…' }),
        { target: { value: 'First aid' } },
    );
    expect(screen.getByText('First aid')).toBeInTheDocument();
    expect(screen.queryByText('Manual handling')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Workforce' })).toHaveAttribute(
        'href',
        '/operations/rostering',
    );
});
