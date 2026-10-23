import { alertDetail } from '@/components/control-room/alert-record/test-fixtures';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ControlRoomAlertShow from './show';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
    breadcrumbs: [] as Array<{ title: string; href?: string }>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: '/control-room/alerts/418',
        props: { auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } } },
    }),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        setData: vi.fn(),
        post: vi.fn(),
        transform: vi.fn(),
        processing: false,
        isDirty: false,
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: Array<{ title: string; href?: string }>;
    }) => {
        inertia.breadcrumbs = breadcrumbs;
        return <div>{children}</div>;
    },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Control Room alert record page', () => {
    it('is a real page: title, state, targets and the record tabs', () => {
        render(<ControlRoomAlertShow {...alertDetail()} return_to="/control-room" />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Reported outside the safe zone' }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Open').length).toBeGreaterThan(0);
        // The missed acknowledgement target shows in the header and the side card.
        expect(screen.getAllByText('Missed').length).toBeGreaterThan(0);
        for (const tab of [
            'Overview',
            'Response & timeline',
            'Procedure',
            'Evidence',
            'Tasks',
            'Notes & activity',
            'Linked records',
        ]) {
            expect(screen.getAllByText(tab).length).toBeGreaterThan(0);
        }
        expect(inertia.breadcrumbs.map((c) => c.title)).toEqual([
            'Home',
            'Control Room',
            'Live response',
            'Alert CR-2026-00418',
        ]);
    });

    it('opens the next step as a pop-up from the kit', () => {
        render(<ControlRoomAlertShow {...alertDetail()} return_to="/control-room" />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Acknowledge' })[0]);
        expect(screen.getByRole('dialog', { name: 'Acknowledge alert' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Acknowledge alert' })).toBeInTheDocument();
    });

    it('keeps the way back to where the person came from', () => {
        const returnTo = '/tasks?q=CR-2026-00418&sources=alert&bucket=in_progress';
        render(
            <ControlRoomAlertShow
                {...alertDetail({ can: { manage: false, assign: false, escalate: false, create_incident: false, watch: false } })}
                return_to={returnTo}
            />,
        );

        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', returnTo);
        expect(inertia.breadcrumbs.map((c) => c.title)).toEqual([
            'Home',
            'Tasks',
            'CR-2026-00418',
        ]);
        // A reader sees the record but no action buttons.
        expect(screen.queryByRole('button', { name: 'Acknowledge' })).not.toBeInTheDocument();
    });
});
