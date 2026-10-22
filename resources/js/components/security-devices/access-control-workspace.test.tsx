import { render, screen } from '@testing-library/react';
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
});
