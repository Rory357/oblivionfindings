import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import WorkforceSettings from './workforce-settings';
const { patch, reload, visit, on } = vi.hoisted(() => ({
    patch: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    on: vi.fn(() => () => {}),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 7 }, can: {} } } }),
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { patch, reload, visit, on },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
const props = {
    preferences: {
        default_tab: 'shifts' as const,
        roster_view: 'grid' as const,
        revision: 'a'.repeat(64),
    },
    workforceSettings: {
        worker_timezone: 'Pacific/Auckland',
        week_starts_on: 'Monday',
        fatigue: {
            max_hours_per_day: 12,
            max_hours_per_week: 60,
            warning_threshold_weekly: 40,
            min_rest_between_shifts_hours: 11,
            max_consecutive_days: 6,
        },
        features: { publish: true, auto_schedule: true },
    },
};
beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/operations/workforce-settings');
});
function reviewCalendar() {
    fireEvent.click(screen.getByLabelText('Starting view'));
    fireEvent.click(screen.getByRole('option', { name: 'Month calendar' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save preferences' }));
}
it('uses its matching preference receipt instead of a newer unrelated projection', () => {
    render(<WorkforceSettings {...props} />);
    reviewCalendar();
    expect(patch.mock.calls[0][1]).toEqual({
        default_tab: 'calendar',
        roster_view: 'grid',
        expected_revision: props.preferences.revision,
    });
    act(() => {
        patch.mock.calls[0][2].onSuccess({
            props: {
                auth: { user: { id: 7 } },
                preferences: { ...props.preferences, roster_view: 'list' },
                flash: {
                    workforce_settings_result: {
                        action: 'preferences',
                        actor_id: 7,
                        expected_revision: props.preferences.revision,
                        prior_revision: props.preferences.revision,
                        revision: 'b'.repeat(64),
                        values: {
                            default_tab: 'calendar',
                            roster_view: 'grid',
                        },
                        changed: true,
                    },
                },
            },
        });
        patch.mock.calls[0][2].onFinish();
    });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Starting view')).toHaveTextContent(
        'Month calendar',
    );
    expect(screen.getByLabelText('Roster layout')).toHaveTextContent(
        'Week grid',
    );
    expect(screen.getByText(/Your roster preferences are saved/)).toBeVisible();
});
it('keeps choices after an unknown save and failed then successful current-state reads', () => {
    render(<WorkforceSettings {...props} />);
    reviewCalendar();
    act(() => {
        patch.mock.calls[0][2].onFinish();
    });
    const dialog = screen.getByRole('dialog');
    expect(
        within(dialog).getByRole('button', { name: 'Save preferences' }),
    ).toBeDisabled();
    fireEvent.click(
        within(dialog).getByRole('button', {
            name: 'Check current preferences',
        }),
    );
    act(() => {
        reload.mock.calls[0][0].onError({});
        reload.mock.calls[0][0].onFinish();
    });
    expect(
        screen.getByRole('button', { name: 'Save preferences' }),
    ).toBeDisabled();
    fireEvent.click(
        within(dialog).getByRole('button', {
            name: 'Check current preferences',
        }),
    );
    act(() => {
        reload.mock.calls[1][0].onSuccess({
            props: {
                auth: { user: { id: 7 } },
                preferences: { ...props.preferences, revision: 'c'.repeat(64) },
            },
        });
        reload.mock.calls[1][0].onFinish();
    });
    expect(screen.getByLabelText('Starting view')).toHaveTextContent(
        'Month calendar',
    );
    expect(screen.getByText(/does not confirm the earlier save/)).toBeVisible();
    expect(patch).toHaveBeenCalledOnce();
});

it('reviews and submits both personal choices together', () => {
    render(<WorkforceSettings {...props} />);
    fireEvent.click(screen.getByLabelText('Starting view'));
    fireEvent.click(screen.getByRole('option', { name: 'Month calendar' }));
    fireEvent.click(screen.getByLabelText('Roster layout'));
    fireEvent.click(screen.getByRole('option', { name: 'List' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
    const review = screen.getByRole('dialog');
    expect(within(review).getByText('Roster → Month calendar')).toBeVisible();
    expect(within(review).getByText('Week grid → List')).toBeVisible();
    fireEvent.click(
        within(review).getByRole('button', { name: 'Save preferences' }),
    );
    expect(patch).toHaveBeenCalledExactlyOnceWith(
        '/operations/workforce-settings',
        {
            default_tab: 'calendar',
            roster_view: 'list',
            expected_revision: props.preferences.revision,
        },
        expect.any(Object),
    );
});
