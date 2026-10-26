import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    AccessControlWorkspace,
    type AccessControlWorkspaceData,
} from './access-control-workspace';

const data: AccessControlWorkspaceData = {
    restricted: false,
    canManage: false,
    summary: { activeCredentials: 0, activeSchedules: 1, coveredDoors: 0 },
    sites: [{ id: 9, name: 'Harbour House' }],
    deviceOptions: [],
    holderOptions: [],
    providerActions: {
        issue: { available: false, reason: 'Not connected.' },
        revoke: { available: false, reason: 'Not connected.' },
    },
    schedules: [
        {
            id: 2,
            siteId: 9,
            siteName: 'Harbour House',
            name: 'Weekday staff access',
            days: ['monday', 'tuesday', 'wednesday'],
            startsAt: '08:00',
            endsAt: '18:00:00',
            timezone: 'Pacific/Auckland',
            isActive: true,
            version: 3,
            activeCredentials: 0,
            impact: {
                activeCredentials: 0,
                requiresExactConfirmation: false,
                updateConfirmation: 'UPDATE 0',
                deactivateConfirmation: 'DEACTIVATE 0',
            },
            providerReconciliation: {
                status: 'required',
                label: 'Provider reconciliation required',
                tone: 'warning',
                requiredAt: null,
                message: 'Saved in Oblivion Findings only.',
                failureReason: null,
                providerConfirmed: false,
            },
            deactivatedAt: null,
            deactivationReason: null,
            revisionHistory: [],
        },
    ],
    credentials: [],
    history: [],
};

describe('Access schedules', () => {
    it('shows days and times the way the rest of the app writes them', () => {
        render(<AccessControlWorkspace data={data} />);
        expect(
            screen.getByText('Mon, Tue, Wed • 8:00 am – 6:00 pm'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/mon, tue/)).not.toBeInTheDocument();
        expect(screen.queryByText(/08:00/)).not.toBeInTheDocument();
    });

    it('filters schedules and credentials by search query and lifecycle status', () => {
        render(
            <AccessControlWorkspace
                data={{
                    ...data,
                    schedules: [
                        ...data.schedules,
                        {
                            ...data.schedules[0],
                            id: 3,
                            name: 'After-hours contractor window',
                            isActive: false,
                        },
                    ],
                    credentials: [
                        {
                            id: 101,
                            siteId: 9,
                            siteName: 'Harbour House',
                            label: 'Reception keyfob',
                            holderType: 'staff',
                            holderLabel: 'Aroha Support',
                            scheduleName: 'Weekday staff access',
                            referenceKey: 'unifi:cred/alpha-1',
                            status: 'active',
                            devices: [],
                            validFrom: null,
                            validUntil: null,
                            revokedAt: null,
                            revocationReason: null,
                            providerLifecycle: {
                                state: 'active',
                                label: 'Provider-confirmed active',
                                tone: 'positive',
                                message: 'Active in provider.',
                                requestedAt: null,
                                confirmedAt: '2026-08-05T08:00:00.000Z',
                                failureReason: null,
                                accessStillConfirmed: true,
                            },
                        },
                        {
                            id: 102,
                            siteId: 12,
                            siteName: 'Kauri Lodge',
                            label: 'Contractor temporary card',
                            holderType: 'staff',
                            holderLabel: 'Tane Technician',
                            scheduleName: 'After-hours contractor window',
                            referenceKey: 'unifi:cred/beta-9',
                            status: 'revoked',
                            devices: [],
                            validFrom: null,
                            validUntil: null,
                            revokedAt: '2026-08-06T08:00:00.000Z',
                            revocationReason: 'Work complete',
                            providerLifecycle: {
                                state: 'revoked',
                                label: 'Provider-confirmed revoked',
                                tone: 'neutral',
                                message: 'Revoked in provider.',
                                requestedAt: null,
                                confirmedAt: '2026-08-06T08:00:00.000Z',
                                failureReason: null,
                                accessStillConfirmed: false,
                            },
                        },
                    ],
                }}
            />,
        );

        fireEvent.change(screen.getByLabelText('Search access schedules'), {
            target: { value: 'contractor' },
        });
        expect(
            screen.getAllByText('After-hours contractor window').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByText('Weekday staff access'),
        ).toHaveLength(1);

        fireEvent.change(screen.getByLabelText('Search access schedules'), {
            target: { value: '' },
        });
        fireEvent.change(screen.getByLabelText('Filter schedules by status'), {
            target: { value: 'inactive' },
        });
        expect(
            screen.getAllByText('After-hours contractor window').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByText('Weekday staff access'),
        ).toHaveLength(1);

        fireEvent.change(
            screen.getByLabelText('Filter credentials by lifecycle status'),
            {
                target: { value: 'revoked' },
            },
        );
        expect(
            screen.getByText('Contractor temporary card'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Reception keyfob')).not.toBeInTheDocument();
    });
});
