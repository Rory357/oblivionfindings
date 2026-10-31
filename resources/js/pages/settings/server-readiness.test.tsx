import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ServerReadinessPage, {
    type ServerReadinessPayload,
} from './server-readiness';

const { routerReload } = vi.hoisted(() => ({
    routerReload: vi.fn(),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <section>{children}</section>
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        reload: routerReload,
    },
}));

const sampleReadiness: ServerReadinessPayload = {
    overall_status: 'fail',
    passed_count: 5,
    failed_count: 2,
    total_count: 7,
    checked_at: '2026-10-27T05:30:00Z',
    checks: [
        {
            key: 'redis',
            title: 'Redis',
            status: 'pass',
            summary: 'Connected and responding',
            detail: 'Redis replied to a live ping.',
            fix: null,
        },
        {
            key: 'scheduler',
            title: 'Scheduler',
            status: 'fail',
            summary: 'Not running',
            detail: 'No scheduler tick has been recorded yet on this server.',
            fix: 'Add a system cron entry (Linux) or Task Scheduler job (Windows) that runs the Oblivion task scheduler every minute.',
        },
        {
            key: 'worker_pools',
            title: 'Worker pools',
            status: 'fail',
            summary: '1 of 2 pools running',
            detail: 'Pools needing attention: Health checks.',
            fix: 'Start or restart the background queue worker processes in Supervisor.',
            subitems: [
                {
                    key: 'default',
                    label: 'General background jobs',
                    queue: 'default',
                    status: 'pass',
                    detail: 'Running · heartbeat 12 s ago',
                },
                {
                    key: 'checks',
                    label: 'Health checks',
                    queue: 'monitoring-checks',
                    status: 'fail',
                    detail: 'No recent worker heartbeat recorded',
                },
            ],
        },
    ],
};

describe('ServerReadinessPage', () => {
    beforeEach(() => {
        routerReload.mockReset();
    });

    it('renders ready and needs-fix statuses, plain fixes, and worker pool rows', () => {
        render(<ServerReadinessPage readiness={sampleReadiness} />);

        expect(
            screen.getByRole('heading', { name: 'Server readiness' }),
        ).toBeTruthy();
        expect(screen.getByText('Redis')).toBeTruthy();
        expect(screen.getByText('Scheduler')).toBeTruthy();
        expect(
            screen.getByText(
                /Add a system cron entry \(Linux\) or Task Scheduler job \(Windows\)/,
            ),
        ).toBeTruthy();
        expect(screen.getByText('General background jobs')).toBeTruthy();
        expect(screen.getByText('Health checks')).toBeTruthy();
        expect(screen.getByText('Running')).toBeTruthy();
        expect(screen.getByText('Stopped')).toBeTruthy();
    });

    it('filters checks via the header rail and reloads on Check again', () => {
        render(<ServerReadinessPage readiness={sampleReadiness} />);

        fireEvent.click(screen.getByRole('tab', { name: /Needs fix/ }));
        expect(screen.queryByTestId('readiness-check-redis')).toBeNull();
        expect(screen.getByTestId('readiness-check-scheduler')).toBeTruthy();
        expect(screen.getByTestId('readiness-check-worker_pools')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Check again/ }));
        expect(routerReload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['readiness'] }),
        );
    });
});
