import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useOfflineQueueState } from '@/hooks/use-offline-queue';

import OfflineStatusBanner from './offline-status-banner';

vi.mock('@/hooks/use-offline-queue', () => ({
    useOfflineQueueState: vi.fn(),
}));

const mockedUseOfflineQueueState = vi.mocked(useOfflineQueueState);

describe('OfflineStatusBanner', () => {
    beforeEach(() => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 0,
            pendingSubmissions: [],
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
            pendingSubmissions: [],
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
            pendingSubmissions: [],
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
            pendingSubmissions: [],
            syncing: true,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Sending 2 queued items…',
        );
    });
});
