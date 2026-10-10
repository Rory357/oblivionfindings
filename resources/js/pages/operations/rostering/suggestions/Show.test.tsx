import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { suggestionHash } from './use-suggestion-command';

import { Button } from '@/components/ui/button';
import Show from './Show';

const { post, reload } = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
    router: { post, reload, on: () => () => {} },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/lib/i18n', () => ({
    useI18n: () => ({ t: (key: string, fallback?: string) => fallback ?? key }),
}));
vi.mock('@/components/page', () => {
    const Content = ({ children }: { children?: ReactNode }) => children;
    return {
        PageLayout: ({
            hero,
            children,
        }: {
            hero: ReactNode;
            children: ReactNode;
        }) => (
            <>
                {hero}
                {children}
            </>
        ),
        PageHeader: ({
            actions,
            subline,
            meters,
            filters,
        }: {
            actions: ReactNode;
            subline: string;
            meters: ReactNode;
            filters: ReactNode;
        }) => (
            <>
                <p>{subline}</p>
                {actions}
                {meters}
                {filters}
            </>
        ),
        PageHeaderPrimaryButton: ({
            children,
            onClick,
            disabled,
        }: ComponentProps<'button'>) => (
            <Button onClick={onClick} disabled={disabled}>
                {children}
            </Button>
        ),
        PageHeaderSearch: ({
            value,
            onChange,
            placeholder,
        }: {
            value: string;
            onChange: (value: string) => void;
            placeholder: string;
        }) => (
            <input
                aria-label="Search suggestions"
                placeholder={placeholder}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        ),
        PageHeaderFilterSelect: ({
            value,
            onChange,
            options,
        }: {
            value: string;
            onChange: (value: string) => void;
            options: { value: string; label: string }[];
        }) => (
            <select
                aria-label="Suggestion status"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        ),
        PageHeaderMeterBig: Content,
        PageHeaderMeterBlock: Content,
        PageHeaderMeterCaption: Content,
        PageHeaderMeterDonut: () => null,
        PageHeaderStatusChip: Content,
    };
});

const fixture: ComponentProps<typeof Show> = {
    run: {
        id: 17,
        status: 'completed',
        strategy: 'balanced',
        week_start: '2026-10-05',
        week_end: '2026-10-11',
        site: { id: 2, name: 'Test house' },
        requested_by: 'Fixture manager',
        totals: { open_shifts: 1, suggested_shifts: 1, suggestion_count: 1 },
        parameters: {},
        expires_at: null,
        failure_message: null,
        is_expired: false,
        can: { apply_accepted: true },
        urls: {
            apply_accepted:
                '/operations/rostering/suggestions/17/apply-accepted',
        },
    },
    suggestions: [
        {
            id: 23,
            shift_id: 42,
            candidate_user_id: 3,
            source_revision: 'a'.repeat(64),
            rank: 1,
            score: 80,
            status: 'suggested',
            current_source: { status: 'available', reason: null },
            can: { accept: true, dismiss: true, apply: true },
            urls: {
                accept: '/operations/rostering/suggestions/23/accept',
                dismiss: '/operations/rostering/suggestions/23/dismiss',
                apply: '/operations/rostering/suggestions/23/apply',
            },
            reasons: {},
            eligibility_snapshot: {},
            candidate: { id: 3, name: 'Fixture worker' },
            shift: {
                id: 42,
                starts_at: null,
                ends_at: null,
                status: 'draft',
                client: 'Fixture person',
                site: 'Test house',
                service_context: null,
                current_staff: null,
            },
        },
    ],
};

function acceptedProps(): ComponentProps<typeof Show> {
    return {
        ...fixture,
        suggestions: fixture.suggestions.map((item) => ({
            ...item,
            status: 'accepted',
        })),
    };
}

beforeEach(() => {
    post.mockClear();
    reload.mockClear();
    vi.stubGlobal('crypto', webcrypto);
});
afterEach(() => vi.unstubAllGlobals());
function currentPage(props = fixture, result?: unknown) {
    return {
        props: {
            ...props,
            auth: { user: { id: 1 } },
            worker_timezone: props.worker_timezone ?? 'Pacific/Auckland',
            suggestion_visibility: props.suggestion_visibility ?? {
                basis: 'current_canonical_run_site',
                recorded_count: props.suggestions.length,
                visible_count: props.suggestions.length,
                withheld_count: 0,
            },
            flash: { roster_suggestion_result: result },
            errors: {},
        },
    };
}
async function sent(count = 1) {
    await waitFor(() => expect(post).toHaveBeenCalledTimes(count));
}
async function confirmChoice(
    action: 'accept' | 'dismiss',
    props = acceptedProps(),
    index = 0,
) {
    const payload = post.mock.calls[index][1];
    const status = action === 'accept' ? 'accepted' : 'dismissed';
    const result = {
        action,
        actor_id: 1,
        request_id: payload.request_id,
        scope: 'single',
        run_id: 17,
        site_id: 2,
        suggestion_id: 23,
        expected_source: payload.expected_source,
        values_hash: await suggestionHash({
            action,
            source: payload.expected_source,
        }),
        outcome: status,
        changed: true,
        disposition: 'single',
        counts: { selected: 1, applied: 0, stale: 0, failed: 0 },
        assignments: [],
        suggestion: {
            id: 23,
            status,
            accepted_by: null,
            accepted_at: null,
            dismissed_by: null,
            dismissed_at: null,
            applied_by: null,
            applied_at: null,
            [`${status}_by`]: 1,
            [`${status}_at`]: '2026-10-08T01:00:00.000Z',
        },
    };
    act(() => {
        post.mock.calls[index][2].onSuccess(currentPage(props, result));
        post.mock.calls[index][2].onFinish();
    });
}
describe('roster suggestion save sequencing', () => {
    beforeEach(() => post.mockClear());

    it('waits for a saved acceptance before enabling the bulk apply action', async () => {
        const view = render(<Show {...fixture} />);
        const apply = screen.getByRole('button', {
            name: 'Apply accepted',
        });
        expect(apply).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
        await sent();
        expect(post).toHaveBeenCalledWith(
            '/operations/rostering/suggestions/23/accept',
            expect.objectContaining({
                request_id: expect.any(String),
                expected_source: expect.objectContaining({
                    source_revision: 'a'.repeat(64),
                }),
            }),
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled();
        view.rerender(<Show {...acceptedProps()} />);
        expect(apply).toBeDisabled();
        await confirmChoice('accept');
        expect(screen.getByRole('status')).toHaveTextContent('Choice accepted');
        expect(apply).toBeEnabled();
        fireEvent.click(apply);
        fireEvent.click(apply);
        await sent(2);
        expect(post.mock.calls[1][0]).toBe(
            '/operations/rostering/suggestions/17/apply-accepted',
        );
        expect(apply).toBeDisabled();
    });

    it('holds an unconfirmed save until an explicit current read without replaying it', async () => {
        render(<Show {...fixture} />);
        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
        await sent();
        act(() => post.mock.calls[0][2].onFinish());
        expect(screen.getByRole('button', { name: 'Accept' })).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'may already have saved',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload suggestions' }),
        );
        act(() => {
            reload.mock.calls[0][0].onSuccess(currentPage());
            reload.mock.calls[0][0].onFinish();
        });
        expect(screen.getByRole('button', { name: 'Accept' })).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
        expect(post).toHaveBeenCalledTimes(1);
    });
    it('keeps expired assignments blocked while retaining authorised dismissal', async () => {
        render(
            <Show
                {...acceptedProps()}
                run={{ ...fixture.run, is_expired: true }}
                suggestions={acceptedProps().suggestions.map((item) => ({
                    ...item,
                    can: { accept: false, dismiss: true, apply: false },
                    urls: {
                        accept: null,
                        dismiss: item.urls!.dismiss,
                        apply: null,
                    },
                }))}
            />,
        );
        for (const name of ['Accept', 'Apply', 'Apply accepted']) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
        expect(post).not.toHaveBeenCalled();
        const dismiss = screen.getByRole('button', { name: 'Dismiss' });
        expect(dismiss).toBeEnabled();
        fireEvent.click(dismiss);
        await sent();
        expect(post.mock.calls[0][0]).toBe(
            '/operations/rostering/suggestions/23/dismiss',
        );
    });
});

describe('suggestion duty times', () => {
    beforeEach(() => post.mockClear());

    const timedProps = (
        workerTimezone: string,
    ): ComponentProps<typeof Show> => ({
        ...fixture,
        worker_timezone: workerTimezone,
        run: {
            ...fixture.run,
            week_start: '2026-12-28',
            week_end: '2027-01-03',
        },
        suggestions: fixture.suggestions.map((item) => ({
            ...item,
            shift: {
                ...item.shift!,
                starts_at: '2026-12-31T10:17:00Z',
                ends_at: '2026-12-31T14:43:00Z',
            },
        })),
    });

    it('shows the entire overnight duty in the roster zone and keeps calendar week dates intact', () => {
        render(<Show {...timedProps('Pacific/Auckland')} />);

        expect(screen.getByText(/31 Dec 2026, 11:17 pm/)).toHaveTextContent(
            '31 Dec 2026, 11:17 pm → 1 Jan 2027, 3:43 am',
        );
        expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
            '28 Dec 2026 → 3 Jan 2027 · Pacific/Auckland',
        );
        expect(screen.getByText('Fixture person')).toBeVisible();
        expect(post).not.toHaveBeenCalled();
    });

    it('uses the server roster zone instead of the browser zone or a fixed Auckland conversion', () => {
        render(<Show {...timedProps('UTC')} />);

        expect(screen.getByText(/31 Dec 2026, 10:17 am/)).toHaveTextContent(
            '31 Dec 2026, 10:17 am → 31 Dec 2026, 2:43 pm',
        );
        expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
            '28 Dec 2026 → 3 Jan 2027 · UTC',
        );
    });

    it('refreshes the roster zone with a generating run and displays the new zone when the run completes', () => {
        vi.useFakeTimers();
        reload.mockClear();
        const props = timedProps('Pacific/Auckland');
        const view = render(
            <Show {...props} run={{ ...props.run, status: 'running' }} />,
        );
        try {
            act(() => vi.advanceTimersByTime(5000));
            expect(reload).toHaveBeenCalledWith(
                expect.objectContaining({
                    only: [
                        'run',
                        'suggestions',
                        'worker_timezone',
                        'suggestion_visibility',
                        'auth',
                    ],
                }),
            );
            view.rerender(<Show {...timedProps('UTC')} />);
            expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
                '28 Dec 2026 → 3 Jan 2027 · UTC',
            );
            expect(screen.getByText(/31 Dec 2026, 10:17 am/)).toHaveTextContent(
                '31 Dec 2026, 10:17 am → 31 Dec 2026, 2:43 pm',
            );
            act(() => vi.advanceTimersByTime(5000));
            expect(reload).toHaveBeenCalledOnce();
            expect(post).not.toHaveBeenCalled();
        } finally {
            view.unmount();
            vi.useRealTimers();
        }
    });

    it('identifies missing or unusable endpoints without crashing the existing suggestion controls', () => {
        render(
            <Show
                {...fixture}
                suggestions={fixture.suggestions.map((item) => ({
                    ...item,
                    shift: {
                        ...item.shift!,
                        starts_at: null,
                        ends_at: 'not-an-instant',
                    },
                }))}
            />,
        );

        expect(screen.getByText(/Start time unavailable/)).toHaveTextContent(
            'Start time unavailable → End time unavailable',
        );
        expect(screen.getByText(/Roster suggestions ·/)).toHaveTextContent(
            'Pacific/Auckland',
        );
        expect(screen.getByRole('button', { name: 'Accept' })).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });
});

describe('suggestion source visibility and current actions', () => {
    beforeEach(() => {
        post.mockReset();
        reload.mockReset();
    });
    const visibleProps: ComponentProps<typeof Show> = {
        ...fixture,
        run: {
            ...fixture.run,
            totals: {
                open_shifts: 4,
                suggested_shifts: 2,
                suggestion_count: 3,
            },
        },
        suggestion_visibility: {
            basis: 'current_canonical_run_site',
            recorded_count: 3,
            visible_count: 1,
            withheld_count: 2,
        },
        suggestions: fixture.suggestions.map((item) => ({
            ...item,
            candidate: {
                ...item.candidate!,
                email: 'fixture.worker@example.test',
            },
            reasons: { weekly_hours: 12, site_familiarity: 2 },
            shift: {
                ...item.shift!,
                service_context: 'Supported living',
                current_staff: 'Current worker',
            },
        })),
    };

    it('distinguishes filtered visible and recorded counts and searches an authorised email', () => {
        render(<Show {...visibleProps} />);
        expect(screen.getByText(/1 shown by this filter/)).toHaveTextContent(
            '1 currently visible of 3 recorded suggestions. 2 no longer have a visible duty',
        );
        expect(screen.getByText(/recorded candidates across 2/)).toBeVisible();
        const input = screen.getByRole('textbox', {
            name: 'Search suggestions',
        });
        fireEvent.change(input, {
            target: { value: 'fixture.worker@example.test' },
        });
        expect(screen.getByText(/1 shown by this filter/)).toBeVisible();
        fireEvent.change(input, { target: { value: 'No match' } });
        expect(screen.getByText(/0 shown by this filter/)).toHaveTextContent(
            '1 currently visible of 3',
        );
        expect(
            screen.getByText('No suggestions match your search or filter.'),
        ).toBeVisible();
        expect(screen.getByText(/recorded candidates across 2/)).toBeVisible();
    });

    it('explains withheld choices instead of claiming nothing was generated', () => {
        render(
            <Show
                {...visibleProps}
                suggestions={[]}
                suggestion_visibility={{
                    ...visibleProps.suggestion_visibility!,
                    visible_count: 0,
                    withheld_count: 3,
                }}
            />,
        );
        expect(
            screen.getByText(/No recorded suggestions are currently visible/),
        ).toBeVisible();
        expect(
            screen.queryByText('No suggestions were generated for this run.'),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    it('preserves readable source facts and recorded hours without promising current availability', () => {
        render(
            <Show
                {...visibleProps}
                suggestions={visibleProps.suggestions.map((item) => ({
                    ...item,
                    candidate: null,
                    current_source: {
                        status: 'unavailable',
                        reason: 'The suggested worker is no longer available. Reload or generate new suggestions.',
                    },
                    can: { accept: true, dismiss: true, apply: false },
                    urls: { ...item.urls!, apply: null },
                }))}
            />,
        );
        expect(screen.getByText('Worker unavailable')).toBeVisible();
        expect(screen.getByText('Supported living')).toBeVisible();
        expect(screen.getByText(/Assigned to Current worker/)).toBeVisible();
        expect(
            screen.getByText(/Weekly hours when generated/),
        ).toHaveTextContent('12');
        expect(
            screen.getByText(/The suggested worker is no longer available/),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Apply' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Dismiss' })).toBeEnabled();
    });

    it('does not invent action permissions or endpoints from an older payload', () => {
        render(
            <Show
                {...acceptedProps()}
                run={{ ...fixture.run, can: undefined, urls: undefined }}
                suggestions={acceptedProps().suggestions.map((item) => ({
                    ...item,
                    can: undefined,
                    urls: undefined,
                }))}
            />,
        );
        for (const name of ['Accept', 'Dismiss', 'Apply', 'Apply accepted']) {
            const button = screen.getByRole('button', { name });
            expect(button).toBeDisabled();
            fireEvent.click(button);
        }
        expect(post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Reload suggestions' }),
        ).toBeEnabled();
    });

    it('uses the current action URL and disables only the denied row action', async () => {
        render(
            <Show
                {...fixture}
                suggestions={fixture.suggestions.map((item) => ({
                    ...item,
                    can: { accept: false, dismiss: true, apply: true },
                    urls: {
                        ...item.urls!,
                        accept: null,
                        apply: '/operations/rostering/suggestions/23/apply?from=run',
                    },
                }))}
            />,
        );
        expect(screen.getByRole('button', { name: 'Accept' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        await sent();
        expect(post.mock.calls[0][0]).toBe(
            '/operations/rostering/suggestions/23/apply?from=run',
        );
    });

    it('reloads all visibility context together and offers retry after a read failure', () => {
        render(<Show {...acceptedProps()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload suggestions' }),
        );
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: [
                    'run',
                    'suggestions',
                    'worker_timezone',
                    'suggestion_visibility',
                    'auth',
                ],
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
        act(() => {
            reload.mock.calls[0][0].onError({ message: 'Read failed' });
            reload.mock.calls[0][0].onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be refreshed',
        );
        expect(
            screen.getByRole('button', { name: 'Apply accepted' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload suggestions' }),
        );
        act(() => {
            reload.mock.calls[1][0].onSuccess(currentPage(acceptedProps()));
            reload.mock.calls[1][0].onFinish();
        });
        expect(screen.queryByRole('alert')).toBeNull();
        expect(reload).toHaveBeenCalledTimes(2);
        expect(post).not.toHaveBeenCalled();
    });

    it('ignores an old command finishing after navigation to another run', async () => {
        const view = render(<Show {...fixture} />);
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        await sent();
        view.rerender(
            <Show
                {...acceptedProps()}
                run={{
                    ...fixture.run,
                    id: 18,
                    urls: {
                        apply_accepted:
                            '/operations/rostering/suggestions/18/apply-accepted',
                    },
                }}
            />,
        );
        const apply = screen.getByRole('button', { name: 'Apply accepted' });
        expect(apply).toBeEnabled();
        fireEvent.click(apply);
        await sent(2);
        expect(post.mock.calls[1][0]).toBe(
            '/operations/rostering/suggestions/18/apply-accepted',
        );
        act(() => post.mock.calls[0][2].onFinish());
        expect(apply).toBeDisabled();
        expect(screen.queryByRole('alert')).toBeNull();
    });
    it('uses a safe failure explanation without rendering stored technical detail', () => {
        render(
            <Show
                {...fixture}
                run={{
                    ...fixture.run,
                    status: 'failed',
                    failure_message: 'SQLSTATE private-source-sentinel',
                }}
            />,
        );
        expect(
            screen.getByText(
                'Generate a fresh run before applying assignments.',
            ),
        ).toBeVisible();
        expect(screen.queryByText(/private-source-sentinel/)).toBeNull();
    });
});

it('keeps the selected status clear when a refresh changes every matching row', () => {
    const props = acceptedProps();
    const view = render(<Show {...props} />);
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Suggestion status' }),
        { target: { value: 'accepted' } },
    );
    view.rerender(
        <Show
            {...props}
            suggestions={props.suggestions.map((item) => ({
                ...item,
                status: 'applied',
            }))}
        />,
    );
    expect(
        screen.getByRole('combobox', { name: 'Suggestion status' }),
    ).toHaveValue('accepted');
    expect(
        screen.getByRole('option', { name: 'accepted' }),
    ).toBeInTheDocument();
    expect(
        screen.getByText('No suggestions match your search or filter.'),
    ).toBeVisible();
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Suggestion status' }),
        { target: { value: 'all' } },
    );
    expect(screen.getByText('Fixture worker')).toBeVisible();
});
