import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
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
beforeEach(() => {
    vi.clearAllMocks();
    mocks.errors = {};
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
                prev_page_url: null,
                next_page_url: null,
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
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved version' }),
        );
        expect(mocks.get).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep my draft' }));
        expect(screen.getByLabelText('Plan title')).toHaveValue(
            'My unsaved draft',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved version' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard draft and reload' }),
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/work-plans/worker-reminders',
            { record: 5 },
            { preserveState: false, preserveScroll: true },
        );
    });
    it('submits the entered title, date and next step to the correct workspace', () => {
        render(<PlanEditor {...props()} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Read the shift handover' },
        });
        fireEvent.change(screen.getByLabelText('Due date'), {
            target: { value: '2026-10-20' },
        });
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

    it('keeps an unsaved plan until the user explicitly discards it', () => {
        const p = props();
        render(<PlanEditor {...p} />);
        fireEvent.change(screen.getByLabelText('Plan title'), {
            target: { value: 'Keep this draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(p.onClose).not.toHaveBeenCalled();
        expect(screen.getByText('You have unsaved changes.')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
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
});
