import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { dismissRejectedOfflineSubmission } from '@/lib/offline-queue';

import OfflineStatusBanner from './offline-status-banner';

vi.mock('@/hooks/use-offline-queue', () => ({
    useOfflineQueueState: vi.fn(),
}));
vi.mock('@/lib/offline-queue', () => ({
    dismissRejectedOfflineSubmission: vi.fn(),
    purgeMedicationDeviceData: vi.fn(async () => undefined),
    retryOfflineSubmissionsNeedingAttention: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: { flushAll: vi.fn(), post: vi.fn() },
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

    const refusedDose = {
        id: 'a92be861-e38f-4cb0-8daf-87bd65dfcae7',
        actorId: '101',
        action: 'prn' as const,
        method: 'post' as const,
        url: '/meds/today/prn',
        payload: {},
        createdAt: '2026-04-30T09:00:00.000Z',
        lastAttemptAt: '2026-04-30T09:05:00.000Z',
        attempts: 1,
        lastError: 'PRN limit reached (4 of 4 in 24 hours).',
        needsAttention: true,
        rejected: true,
        display: {
            person: 'Mary',
            medicine: 'Paracetamol 500mg',
            outcome: 'Given',
            at: '2026-04-29T21:00:00.000Z',
        },
    };

    it('keeps a refused saved dose visible beside the waiting line, and names it (EA-046)', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: false,
            pendingCount: 2,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 1,
            rejectedSubmissions: [refusedDose],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('alert')).toHaveTextContent(
            'A saved medication record was not recorded. Check it and tell your lead.',
        );
        // The offline / waiting line is still there (it used to be hidden).
        expect(screen.getByRole('status')).toHaveTextContent(
            /Offline — 2 items will send/,
        );
        expect(
            screen.queryByRole('button', { name: 'Dismiss' }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        expect(
            screen.getByText('Mary · Paracetamol 500mg'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('PRN limit reached (4 of 4 in 24 hours).'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Lead not told yet — tell your lead'),
        ).toBeInTheDocument();
    });

    it('removes a refused dose only after the worker says it is dealt with (EA-049)', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 0,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 1,
            rejectedSubmissions: [
                { ...refusedDose, reportedAt: '2026-04-30T09:06:00.000Z' },
            ],
            syncing: false,
        });
        vi.mocked(dismissRejectedOfflineSubmission).mockResolvedValue(true);

        render(<OfflineStatusBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        expect(screen.getByText('Lead follow-up created')).toBeInTheDocument();
        const remove = screen.getByRole('button', {
            name: 'Remove from this device',
        });
        expect(remove).toBeDisabled();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'I’ve recorded this on paper or told my lead',
            }),
        );
        fireEvent.click(remove);
        expect(dismissRejectedOfflineSubmission).toHaveBeenCalledWith(
            refusedDose.id,
            'told_lead',
        );
    });

    it('shows another worker’s saved doses so nobody gives them again (EA-048)', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 0,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            strandedSubmissions: [
                {
                    id: 'theirs',
                    createdAt: '2026-04-30T09:00:00.000Z',
                    display: refusedDose.display,
                },
            ],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /saved by another worker and isn’t on the chart/,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        expect(
            screen.getByText('Mary · Paracetamol 500mg'),
        ).toBeInTheDocument();
    });

    it('says to sign in again instead of retrying forever (EA-050)', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 1,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            signInRequired: true,
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Sign in again to send the medication records saved on this device.',
        );
    });

    it('says the server didn’t answer, not offline, while online (EA-010)', () => {
        mockedUseOfflineQueueState.mockReturnValue({
            online: true,
            pendingCount: 1,
            needsAttentionCount: 0,
            pendingSubmissions: [],
            rejectedCount: 0,
            rejectedSubmissions: [],
            syncing: false,
        });

        render(<OfflineStatusBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            /server didn’t answer; we’ll keep trying/,
        );
    });

    it('asks before signing out while doses are saved only on this device (EA-048)', () => {
        render(<OfflineStatusBanner />);
        act(() => {
            window.dispatchEvent(
                new CustomEvent('emar:sign-out-blocked', {
                    detail: { pending: 2 },
                }),
            );
        });
        expect(
            screen.getByText(
                'Medication records are saved only on this device',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Stay signed in' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Sign out anyway' }),
        ).toBeInTheDocument();
    });
});
