import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    post: vi.fn(),
    visit: vi.fn(),
    errors: {} as Record<string, string>,
    initial: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Head: () => null,
        Link: ({ children, href }: { children: ReactNode; href: string }) => (
            <a href={href}>{children}</a>
        ),
        router: { on: () => () => undefined, visit: state.visit },
        usePage: () => ({ props: { flash: {} } }),
        useForm: (defaults: Record<string, unknown>) => {
            const [data, setData] = React.useState({
                ...defaults,
                ...state.initial,
            });
            const [dirty, setDirty] = React.useState(false);
            const transform = React.useRef(
                (value: Record<string, unknown>) => value,
            );
            return {
                data,
                isDirty: dirty,
                errors: state.errors,
                processing: false,
                setData: (key: string, value: unknown) => {
                    setData((previous) => ({ ...previous, [key]: value }));
                    setDirty(true);
                },
                reset: () => {
                    setData(defaults);
                    setDirty(false);
                },
                clearErrors: vi.fn(),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                post: (url: string, options: unknown) =>
                    state.post(url, transform.current(data), options),
            };
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

import SafeguardingCreate from '@/pages/safeguarding/create';
const props = {
    clients: [{ id: 4, name: 'Permitted client' }],
    staff: [],
    sites: [{ id: 1, name: 'Permitted site' }],
    returnUrl: '/my-day',
};

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    state.errors = {};
    state.initial = {};
});

it('opens a real reporting page without a concern register or capture dialog', () => {
    render(<SafeguardingCreate {...props} />);
    expect(document.querySelectorAll('header.eh-header')).toHaveLength(1);
    expect(
        screen.getByRole('heading', {
            name: 'Raise a safeguarding concern',
            level: 1,
        }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('tablist', { name: 'Reporting steps' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(
        screen.getByRole('combobox', { name: 'Select client' }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Close reporting' }));
    expect(state.visit).toHaveBeenCalledWith('/my-day');
});

it('keeps entered details when leaving is cancelled and requires explicit discard', () => {
    render(<SafeguardingCreate {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Other person' }));
    fireEvent.change(
        screen.getByPlaceholderText('Name of the person at risk'),
        { target: { value: 'Synthetic subject' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Close reporting' }));
    expect(state.visit).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(
        screen.getByPlaceholderText('Name of the person at risk'),
    ).toHaveValue('Synthetic subject');
    fireEvent.click(screen.getByRole('button', { name: 'Close reporting' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Discard and continue' }),
    );
    expect(state.visit).toHaveBeenCalledWith('/my-day');
});

it('submits the existing canonical intake payload from the page review step', () => {
    state.initial = {
        subject_type: 'client',
        subject_id: '4',
        concern_type: 'neglect',
        description: 'Synthetic concern',
        severity: 'high',
        site_id: '1',
        is_sensitive: true,
        subject_informed: 'yes',
        requires_external_referral: 'no',
    };
    render(<SafeguardingCreate {...props} />);
    fireEvent.click(screen.getByRole('tab', { name: 'Review' }));
    expect(screen.getByRole('region', { name: 'Review' })).toHaveFocus();
    expect(screen.getByText('Step 6 of 6 · Review')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Raise concern' }));
    expect(state.post).toHaveBeenCalledWith(
        '/safeguarding',
        expect.objectContaining({
            subject_type: 'client',
            subject_id: 4,
            site_id: 1,
            description: 'Synthetic concern',
            severity: 'high',
            is_sensitive: true,
            subject_informed: true,
            requires_external_referral: false,
        }),
        expect.objectContaining({ preserveState: true }),
    );
});

it('shows server errors even when they belong to another reporting step', () => {
    state.errors = { site_id: 'The selected site is no longer available.' };
    render(<SafeguardingCreate {...props} />);
    expect(screen.getByRole('alert')).toHaveTextContent(
        'The selected site is no longer available.',
    );
});
