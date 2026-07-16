import { Button } from '@/components/ui/button';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createRef, useState, type RefObject } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskDetailDialog } from './task-detail-dialog';
import type { TaskDetail, TaskItem } from './types';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

const item: TaskItem = {
    id: 'alert-41',
    source: 'alert',
    sourceLabel: 'Control Room Alerts',
    ref: 'CR-2026-2135',
    title: 'Resident safety response',
    status: 'triaging',
    bucket: 'in_progress',
    severity: 'high',
    assignee: { id: 9, name: 'Current Control Room Owner' },
    client: { id: 7, name: 'Aroha Rangi' },
    site: { id: 3, name: 'North House' },
    dueAt: null,
    createdAt: '2026-07-16T08:00:00+12:00',
    link: '/control-room/alerts/41',
    type: 'Alert',
    description: 'Safety response',
    actionLabel: 'Continue Control Room response',
    displayState: 'Triage in progress',
    actionHelp: null,
    overdue: false,
};

function detail(overrides: Partial<TaskDetail> = {}): TaskDetail {
    return {
        item,
        timeline: [],
        canOpen: true,
        canWatch: true,
        canAssign: false,
        watchers: [],
        watchersHidden: false,
        isWatching: false,
        canSplit: false,
        ...overrides,
    };
}

function Harness({
    returnTo,
    selectedItem = item,
}: {
    returnTo: string;
    selectedItem?: TaskItem;
}) {
    const [selected, setSelected] = useState<TaskItem | null>(null);
    const triggerRef = createRef<HTMLButtonElement>();

    return (
        <>
            <Button
                ref={triggerRef}
                type="button"
                onClick={() => setSelected(selectedItem)}
            >
                Open {selectedItem.ref ?? selectedItem.title}
            </Button>
            <TaskDetailDialog
                item={selected}
                currentUserId={12}
                onClose={() => setSelected(null)}
                returnTo={returnTo}
                triggerRef={triggerRef as RefObject<HTMLElement | null>}
            />
            <span data-testid="dialog-state">
                {selected ? 'open' : 'closed'}
            </span>
        </>
    );
}

describe('TaskDetailDialog permission recovery and focus', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        inertia.visit.mockReset();
        inertia.post.mockReset();
    });

    it('restores the invoking row focus on Escape and Close without changing the filtered URL', async () => {
        const returnTo =
            '/tasks?q=CR-2026-2135&sources=alert&bucket=in_progress';
        window.history.replaceState({}, '', returnTo);
        const payload = detail();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => payload,
            }),
        );
        render(<Harness returnTo={returnTo} />);
        const rowButton = screen.getByRole('button', {
            name: /Open CR-2026-2135/,
        });

        fireEvent.click(rowButton);
        await screen.findByText('Resident safety response');
        fireEvent.keyDown(document, { key: 'Escape' });

        await waitFor(() => expect(rowButton).toHaveFocus());
        expect(window.location.pathname + window.location.search).toBe(
            returnTo,
        );

        fireEvent.click(rowButton);
        await screen.findByText('Resident safety response');
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));

        await waitFor(() => expect(rowButton).toHaveFocus());
    });

    it('requests detail with the exact task return path and renders only authorized actions', async () => {
        const returnTo =
            '/tasks?q=CR-2026-2135&sources=alert&bucket=in_progress';
        window.history.replaceState({}, '', returnTo);
        inertia.visit.mockImplementation((href: string) => {
            window.history.pushState({}, '', href);
        });
        const payload = detail({
            item: {
                ...item,
                actionLabel: 'View alert',
                link: `/control-room/alerts/41?return_to=${encodeURIComponent(returnTo)}`,
            },
            canAssign: false,
            canOpen: true,
            canWatch: true,
        });
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => payload,
        });
        vi.stubGlobal('fetch', fetchMock);
        render(<Harness returnTo={returnTo} />);
        fireEvent.click(
            screen.getByRole('button', { name: /Open CR-2026-2135/ }),
        );

        expect(
            await screen.findByRole('button', { name: 'View alert' }),
        ).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Watch' })).toBeEnabled();
        expect(
            screen.queryByRole('button', {
                name: 'Continue Control Room response',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Assign to me|Unassign/ }),
        ).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining(
                `return_to=${encodeURIComponent(returnTo)}`,
            ),
            expect.any(Object),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Watch' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/tasks/alert/41/watch',
            {
                watching: true,
                return_to: returnTo,
            },
            expect.any(Object),
        );

        fireEvent.click(screen.getByRole('button', { name: 'View alert' }));
        expect(inertia.visit).toHaveBeenCalledWith(payload.item.link);
        expect(window.location.pathname + window.location.search).toBe(
            payload.item.link,
        );

        window.history.back();
        await waitFor(() =>
            expect(window.location.pathname + window.location.search).toBe(
                returnTo,
            ),
        );
    });

    it('removes watch assign and destination actions when no action is authorized', async () => {
        const payload = detail({
            item: {
                ...item,
                link: null,
                actionLabel: 'No action for you',
                actionHelp:
                    'This response is owned by Current Control Room Owner. Contact a Control Room manager if you need access.',
            },
            canOpen: false,
            canWatch: false,
            canAssign: false,
        });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => payload,
            }),
        );
        render(<Harness returnTo="/tasks?sources=alert" />);
        fireEvent.click(
            screen.getByRole('button', { name: /Open CR-2026-2135/ }),
        );

        expect(
            await screen.findByText(/This response is owned by/),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Watch' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Assign to me|Unassign/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'No action for you' }),
        ).not.toBeInTheDocument();
    });

    it('uses the full record prefix for composite provider detail and watch routes', async () => {
        const fleetItem: TaskItem = {
            ...item,
            id: 'fleet_service_schedule-41',
            source: 'fleet_maintenance',
            sourceLabel: 'Fleet Maintenance',
            ref: null,
            title: 'Service due — Hiace 2',
            link: '/fleet-assets/maintenance/schedules',
            type: 'Service schedule',
            actionLabel: 'Open record',
        };
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => detail({ item: fleetItem }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <Harness
                returnTo="/tasks?sources=fleet_maintenance"
                selectedItem={fleetItem}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Open Service due — Hiace 2',
            }),
        );

        expect(
            await screen.findByRole('button', { name: 'Watch' }),
        ).toBeEnabled();
        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('source=fleet_service_schedule'),
            expect.any(Object),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Watch' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/tasks/fleet_service_schedule/41/watch',
            {
                watching: true,
                return_to: '/tasks?sources=fleet_maintenance',
            },
            expect.any(Object),
        );
    });
});
