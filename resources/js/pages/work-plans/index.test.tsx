import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import WorkPlans, { PlanEditor } from './index';

type VisitEvent = {
    detail: { visit: { url: URL; method: string } };
    preventDefault: () => void;
};

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    get: vi.fn(),
    visit: vi.fn(),
    before: null as ((event: VisitEvent) => void) | null,
    errors: {} as Record<string, string>,
    breadcrumbs: [] as { title: string; href?: string }[],
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: React.ReactNode;
        breadcrumbs: { title: string; href?: string }[];
    }) => {
        mocks.breadcrumbs = breadcrumbs;
        return children;
    },
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Head: () => null,
        Link: ({
            children,
            href,
        }: {
            children: React.ReactNode;
            href: string;
        }) => <a href={href}>{children}</a>,
        router: {
            get: mocks.get,
            visit: mocks.visit,
            reload: vi.fn(),
            on: (_: string, callback: (event: VisitEvent) => void) => {
                mocks.before = callback;
                return () => {
                    if (mocks.before === callback) mocks.before = null;
                };
            },
        },
        useForm: (initial: Record<string, unknown>) => {
            const [data, set] = React.useState(initial);
            return {
                data,
                setData: (key: string, value: unknown) =>
                    set((previous) => ({ ...previous, [key]: value })),
                errors: mocks.errors,
                processing: false,
                isDirty: JSON.stringify(initial) !== JSON.stringify(data),
                post: (url: string) => mocks.post(url, data),
                put: (url: string) => mocks.put(url, data),
            };
        },
    };
});

const props = () => ({
    definition: {
        kind: 'worker-reminders',
        title: 'My reminders',
        role: 'worker',
        scope: 'personal' as const,
        guide: 'Keep private care details in the care record.',
        fields: { next_step: 'Next step' },
    },
    plan: null,
    sites: [],
    owners: [{ id: 1, name: 'Alex', site_ids: [] }],
    currentUserId: 1,
    canWrite: true,
    history: [],
    onClose: vi.fn(),
});

const sitePlan = {
    id: 7,
    title: 'Order gloves',
    due_date: '2026-10-20',
    status: 'planned' as const,
    progress: 0,
    site_id: 1,
    owner_id: 1,
    owner: { name: 'Alex' },
    site: { name: 'Aurora House' },
    details: { items: 'Gloves, size M' },
    completion_note: null,
    completed_at: null,
    updated_at: '2026-10-12T00:00:00Z',
    version: 1,
};
const sitePage = () => ({
    definition: {
        kind: 'lead-supplies',
        title: 'Supply requests',
        role: 'lead',
        scope: 'site' as const,
        guide: 'Track a site supply request through to receipt.',
        fields: { items: 'Items and quantities' },
    },
    sites: [{ id: 1, name: 'Aurora House' }],
    owners: [
        { id: 1, name: 'Alex', site_ids: [1] },
        { id: 2, name: 'Bea', site_ids: [1] },
        { id: 3, name: 'Cam', site_ids: [9] },
    ],
    currentUserId: 1,
    canWrite: true,
    selected: null,
    history: [],
    counts: { planned: 1 },
    filters: {},
    records: {
        data: [sitePlan],
        current_page: 1,
        last_page: 1,
        total: 1,
        links: [],
    },
});

beforeEach(() => {
    vi.clearAllMocks();
    mocks.errors = {};
});
afterEach(() => {
    vi.useRealTimers();
});

describe('work planning', () => {
    it('warns before refreshing a dirty draft and removes the warning when the editor closes', () => {
        const view = render(<PlanEditor {...props()} />);
        const clean = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Keep this work' },
        });
        const dirty = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(dirty);
        expect(dirty.defaultPrevented).toBe(true);
        view.unmount();
        const closed = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(closed);
        expect(closed.defaultPrevented).toBe(false);
    });

    it('keeps a draft on cancelled navigation and visits only after explicit discard', () => {
        render(<PlanEditor {...props()} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Keep this work' },
        });
        const event = {
            detail: {
                visit: {
                    url: new URL('https://oblivionfindings.test/dashboard'),
                    method: 'get',
                },
            },
            preventDefault: vi.fn(),
        };
        act(() => mocks.before?.(event));
        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(mocks.visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText('Plan title')).toHaveValue(
            'Keep this work',
        );
        expect(mocks.visit).not.toHaveBeenCalled();
        act(() => mocks.before?.(event));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            event.detail.visit.url,
            event.detail.visit,
        );
    });

    it('allows saving without treating the write as navigation away', () => {
        render(<PlanEditor {...props()} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Save this plan' },
        });
        const event = {
            detail: {
                visit: {
                    url: new URL(
                        'https://oblivionfindings.test/work-plans/worker-reminders',
                    ),
                    method: 'post',
                },
            },
            preventDefault: vi.fn(),
        };
        act(() => mocks.before?.(event));
        expect(event.preventDefault).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it.each(['In progress', 'Blocked', 'Cancelled'])(
        'reopens a completed plan as %s with valid progress',
        (status) => {
            const plan = {
                id: 5,
                title: 'Completed check',
                due_date: '2026-10-20',
                status: 'completed' as const,
                progress: 100,
                site_id: null,
                owner_id: 1,
                owner: { name: 'Alex' },
                site: null,
                details: { next_step: 'Repeat the check' },
                completion_note: 'Done',
                completed_at: '2026-10-12T00:00:00Z',
                updated_at: '2026-10-12T00:00:00Z',
                version: 1,
            };
            render(<PlanEditor {...props()} plan={plan} />);
            fireEvent.keyDown(
                screen.getByRole('combobox', { name: 'Status' }),
                { key: 'ArrowDown' },
            );
            fireEvent.click(screen.getByRole('option', { name: status }));
            const progress = screen.getByLabelText(
                /Progress/,
            ) as HTMLInputElement;
            expect(progress.disabled).toBe(false);
            expect(progress.validity.valid).toBe(true);
            expect(progress).toHaveValue(99);
            fireEvent.click(
                screen.getByRole('button', { name: 'Save changes' }),
            );
            expect(mocks.put).toHaveBeenCalledWith(
                '/work-plans/worker-reminders/5',
                expect.objectContaining({ progress: 99 }),
            );
        },
    );

    it('preserves the mounted draft when a validation redirect carries a newer saved record', () => {
        const plan = {
            id: 5,
            title: 'Original plan',
            due_date: '2026-10-20',
            status: 'planned' as const,
            progress: 0,
            site_id: null,
            owner_id: 1,
            owner: { name: 'Alex' },
            site: null,
            details: { next_step: 'Original step' },
            completion_note: null,
            completed_at: null,
            updated_at: '2026-10-12T00:00:00Z',
            version: 1,
        };
        const page = {
            ...props(),
            selected: plan,
            counts: { planned: 1 },
            filters: {},
            records: {
                data: [plan],
                current_page: 1,
                last_page: 1,
                total: 1,
                links: [],
            },
        };
        const view = render(<WorkPlans {...page} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'My unsaved draft' },
        });
        mocks.errors = {
            version: 'A newer version exists. Your draft is still here.',
        };
        view.rerender(
            <WorkPlans
                {...page}
                selected={{
                    ...plan,
                    title: 'Other window’s saved plan',
                    version: 2,
                }}
                errors={mocks.errors}
            />,
        );
        expect(screen.getByLabelText('Plan title')).toHaveValue(
            'My unsaved draft',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'A newer version exists',
        );
        // The stale-version notice stays inline; discarding the draft is a
        // separate confirmation.
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved version' }),
        );
        expect(mocks.get).not.toHaveBeenCalled();
        const reload = screen.getByRole('alertdialog');
        expect(reload).toHaveTextContent('Reload the saved version?');
        fireEvent.click(
            within(reload).getByRole('button', { name: 'Keep my draft' }),
        );
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Plan title')).toHaveValue(
            'My unsaved draft',
        );
        expect(mocks.get).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved version' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Discard draft and reload',
            }),
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/work-plans/worker-reminders',
            { record: 5 },
            { preserveState: false, preserveScroll: true },
        );
    });

    it('submits the entered title, calendar due date and next step to the correct workspace', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-13T12:00:00'));
        render(<PlanEditor {...props()} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Read the shift handover' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Due date: Choose date' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: /20 October 2026/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Use date' }));
        expect(
            screen.getByRole('button', { name: 'Due date: 20 Oct 2026' }),
        ).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Next step'), {
            target: { value: 'Open My Day before the shift' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add plan' }));
        expect(mocks.post).toHaveBeenCalledWith(
            '/work-plans/worker-reminders',
            expect.objectContaining({
                title: 'Read the shift handover',
                due_date: '2026-10-20',
                owner_id: 1,
                site_id: null,
                details: { next_step: 'Open My Day before the shift' },
            }),
        );
    });

    it('asks for a due date instead of sending a plan without one', () => {
        render(<PlanEditor {...props()} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'No date yet' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add plan' }));
        expect(mocks.post).not.toHaveBeenCalled();
        const trigger = screen.getByRole('button', {
            name: 'Due date: Choose date',
        });
        expect(trigger).toHaveAttribute('aria-invalid', 'true');
        expect(trigger).toHaveAccessibleDescription('Choose a due date.');
        expect(trigger).toHaveFocus();
    });

    it('keeps an unsaved plan until the user confirms the discard', () => {
        const p = props();
        render(<PlanEditor {...p} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Keep this draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(p.onClose).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog');
        expect(confirm).toHaveTextContent('Discard unsaved plan changes?');
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Keep editing' }),
        );
        expect(p.onClose).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Plan title')).toHaveValue(
            'Keep this draft',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Discard changes',
            }),
        );
        expect(p.onClose).toHaveBeenCalledOnce();
    });

    it('announces a rejected stale edit without clearing the entered fields', () => {
        mocks.errors = {
            version:
                'This plan changed in another window. Reopen it before saving. Your draft is still here.',
        };
        render(<PlanEditor {...props()} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'My retained draft' },
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This plan changed in another window',
        );
        expect(screen.getByLabelText('Plan title')).toHaveValue(
            'My retained draft',
        );
    });

    it('offers only owners who can work at the chosen site through a searchable picker', () => {
        const page = sitePage();
        render(<PlanEditor {...page} plan={null} onClose={vi.fn()} />);
        const owner = screen.getByRole('combobox', { name: 'Owner' });
        expect(owner).toHaveTextContent('Alex');
        fireEvent.click(owner);
        expect(
            screen.getByPlaceholderText('Search owner…'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Cam' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('option', { name: 'Bea' }));
        expect(owner).toHaveTextContent('Bea');
    });
});

describe('work planning workspace', () => {
    it('roots the breadcrumbs at Home and keeps search and filters in the header', () => {
        render(<WorkPlans {...sitePage()} />);
        expect(mocks.breadcrumbs).toEqual([
            { title: 'Home', href: '/dashboard' },
            { title: 'Overview', href: '/dashboard?role=lead' },
            { title: 'Supply requests' },
        ]);
        const header = screen.getByRole('banner');
        const search = within(header).getByRole('searchbox', {
            name: 'Search plans',
        });
        fireEvent.change(search, { target: { value: ' gloves ' } });
        fireEvent.keyDown(search, { key: 'Enter' });
        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/work-plans/lead-supplies',
            expect.objectContaining({ search: 'gloves' }),
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }),
        );

        fireEvent.click(
            within(header).getByRole('button', { name: 'Open plans' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Completed' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/work-plans/lead-supplies',
            expect.objectContaining({ status: 'completed' }),
            expect.objectContaining({ preserveScroll: true }),
        );

        fireEvent.click(
            within(header).getByRole('button', {
                name: 'All your sites',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Aurora House' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/work-plans/lead-supplies',
            expect.objectContaining({ site: '1' }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('opens a plan from the card kebab and the right-click menu, without the raw record id', () => {
        render(<WorkPlans {...sitePage()} />);
        expect(screen.queryByText('#7')).not.toBeInTheDocument();
        expect(screen.getByText('1 of 1 shown')).toBeInTheDocument();

        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Actions for Order gloves' }),
            { key: 'Enter' },
        );
        fireEvent.click(
            within(screen.getByRole('menu')).getByRole('menuitem', {
                name: 'Open plan',
            }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/work-plans/lead-supplies',
            expect.objectContaining({ record: 7 }),
            expect.objectContaining({ preserveScroll: true }),
        );

        mocks.get.mockClear();
        fireEvent.contextMenu(
            screen.getByText('Order gloves').closest('[role="button"]')!,
        );
        const menu = screen.getByRole('menu', { name: 'Order gloves' });
        fireEvent.click(
            within(menu).getByRole('menuitem', { name: 'Open plan' }),
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/work-plans/lead-supplies',
            expect.objectContaining({ record: 7 }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows the shared empty state with an add action', () => {
        const page = sitePage();
        render(
            <WorkPlans
                {...page}
                records={{ ...page.records, data: [], total: 0 }}
            />,
        );
        expect(screen.getByText('No plans in this view')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Add a plan' }));
        expect(
            screen.getByRole('dialog', { name: 'Add a plan' }),
        ).toBeInTheDocument();
    });
});
