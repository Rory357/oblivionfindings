import type { Props } from '@/pages/fleet-assets/assets/show';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AssetCustodyView } from './custody-view';
import type { ProfileWorkspace } from './types';

describe('canonical assignment custody', () => {
    it('uses the current assignment beyond bounded history and keeps unverified receipt explicit', () => {
        const onAction = vi.fn();
        const asset = {
            status: 'active',
            assignments: [],
            site: { id: 1, name: 'Kōwhai' },
            current_assignment: {
                id: 23,
                assignee: { id: 5, name: 'Mara' },
                assigned_at: '2026-01-01T00:00:00Z',
                receipt_confirmed_at: null,
                recipient_visible: true,
            },
        } as unknown as Props['asset'];
        const data = {
            ready: true,
            permissions: { manageAssignments: true },
            movements: [],
        } as unknown as ProfileWorkspace;
        render(
            <AssetCustodyView
                asset={asset}
                data={data}
                section="current"
                onAction={onAction}
                onKit={vi.fn()}
            />,
        );
        expect(screen.getByText('Mara')).toBeVisible();
        expect(screen.getByText('No confirmed custodian')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify assignment receipt' }),
        );
        expect(onAction).toHaveBeenCalledWith(
            'confirm_assignment_receipt',
            undefined,
            23,
        );
    });
});
