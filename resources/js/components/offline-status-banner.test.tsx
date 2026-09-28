import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { dismissRejectedOfflineSubmissions } from '@/lib/offline-queue';

import OfflineStatusBanner from './offline-status-banner';

vi.mock('@/hooks/use-offline-queue', () => ({
    useOfflineQueueState: vi.fn(),
}));
vi.mock('@/lib/offline-queue', () => ({
    dismissRejectedOfflineSubmissions: vi.fn(),
    retryOfflineSubmissionsNeedingAttention: vi.fn(),
}));

const mockedUseOfflineQueueState = vi.mocked(useOfflineQueueState);

describe('OfflineStatusBanner', () => {
    beforeEach(() => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 0,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            syncing: false,
        });
    });

    it('hides when online and the queue is clear', () => {
        const { container } = render(<OfflineStatusBanner />);

        expect(container).toBeEmptyDOMElement();
    });

    it('shows an offline message even before anything is queued', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: false,
            pendingCount: 0,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /offline.*send anything you save/i,
        );
    });

    it('shows pending count while offline', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: false,
            pendingCount: 3,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /offline.*3 items will send/i,
        );
    });

    it('shows syncing state when replay is active', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 2,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            syncing: true,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Sending 2 queued items…',
        );
    });

    it('offers a manual retry without discarding the original request identity', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 1,
            needsAttentionCount: 1,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /need.*attention.*Trying again won’t create a duplicate/i,
        );
        expect(
            screen.getByRole('button', { name: 'Retry safely' }),
        ).toBeInTheDocument();
    });

    it('keeps a server-refused saved action visible as not recorded until dismissed', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 0,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 1,
            rejectedSubmissions: [
                {
                    id: 'a92be861-e38f-4cb0-8daf-87bd65dfcae7',
                    actorId: '101',
                    action: 'prn',
                    method: 'post',
                    url: '/meds/today/prn',
                    payload: {},
                    createdAt: '2026-04-30T09:00:00.000Z',
                    lastAttemptAt: '2026-04-30T09:05:00.000Z',
                    attempts: 1,
                    lastError: 'PRN limit reached (4 of 4 in 24 hours).',
                    needsAttention: true,
                    rejected: true,
                },
            ],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('alert')).toHaveTextContent(
            'A saved medication action was not recorded: PRN limit reached (4 of 4 in 24 hours).',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(dismissRejectedOfflineSubmissions).toHaveBeenCalledTimes(1);
    });
});
