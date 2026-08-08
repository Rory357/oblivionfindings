import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    DeviceDocumentHistory,
    type DeviceDocumentHistoryItem,
} from './device-document-history';

const base: DeviceDocumentHistoryItem = {
    id: 7,
    title: 'Commissioning manual',
    category: 'manual',
    version: '2',
    original_name: 'commissioning.pdf',
    size_bytes: 2048,
    uploaded_at: '2026-08-06T01:00:00Z',
    uploaded_by: 'Aroha Smith',
    state: 'removed',
    status_label: 'Removed',
    needs_attention: false,
    storage_verified_at: '2026-08-06T01:01:00Z',
    integrity_sha256:
        'a4fd387a4620e90c5c4ff12c8ca1bb823ad5d6299dfb046d2746d88d11f44b91',
    removal_requested_at: '2026-08-06T02:00:00Z',
    removed_at: '2026-08-06T02:01:00Z',
    removed_by: 'Moana Jones',
    removal_reason: 'Superseded by the verified current commissioning manual.',
    storage_deleted_at: '2026-08-06T02:02:00Z',
};

describe('DeviceDocumentHistory', () => {
    it('shows retained reason, actor, integrity and an icon-backed removed state', () => {
        render(<DeviceDocumentHistory items={[base]} />);

        expect(
            screen.getByRole('heading', {
                name: 'Document lifecycle history',
            }),
        ).toBeVisible();
        expect(screen.getAllByText('Removed')[0]).toBeVisible();
        expect(screen.getByText(/Moana Jones/)).toBeVisible();
        expect(screen.getByText(/Superseded by the verified/)).toBeVisible();
        expect(screen.getByText(/SHA-256 a4fd387a4620/)).toBeVisible();
    });

    it('uses plain-language recovery status without exposing internal error codes', () => {
        render(
            <DeviceDocumentHistory
                items={[
                    {
                        ...base,
                        id: 8,
                        state: 'removal_pending',
                        status_label: 'Removal needs storage recovery',
                        needs_attention: true,
                        removed_at: null,
                        storage_deleted_at: null,
                    },
                ]}
            />,
        );

        expect(
            screen.getByText('Removal needs storage recovery'),
        ).toBeVisible();
        expect(screen.queryByText(/quarantine_move_pending/)).toBeNull();
    });
});
