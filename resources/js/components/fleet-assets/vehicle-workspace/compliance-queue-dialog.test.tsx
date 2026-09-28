import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ComplianceDialog } from './compliance-dialog';
import type { ComplianceRecord, VehicleProfile } from './types';

const vehicle = {
    id: 14,
    name: 'Queue vehicle',
    registration_number: 'QA14',
} as VehicleProfile;
const record: ComplianceRecord = {
    kind: 'ruc',
    label: 'RUC',
    record_id: 5,
    history: [],
    history_count: 1,
    current: {
        id: 31,
        version: 2,
        applicability: 'applicable',
        applicability_basis: null,
        outcome: 'passed',
        evidence_reference: 'OLD-REF',
        source_reference: null,
        effective_on: null,
        expires_on: null,
        ruc_start_km: 100,
        ruc_end_km: 200,
        legacy: false,
        reason: null,
        recorded_by: 'Fleet manager',
        created_at: null,
        files: [],
    },
};
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Compliance queue evidence dialog', () => {
    it('keeps a draft through closing and resuming, with RUC validation on direct review submission', async () => {
        const keep = vi.fn();
        const props = {
            vehicle,
            record,
            initialStep: 1,
            onClose: vi.fn(),
            onSaved: vi.fn(),
            onKeepDraft: keep,
        };
        const { rerender } = render(<ComplianceDialog {...props} />);
        fireEvent.change(screen.getByLabelText('Evidence reference'), {
            target: { value: 'DRAFT-REF' },
        });
        fireEvent.change(screen.getByLabelText('Licence end odometer (km)'), {
            target: { value: '50' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep draft & close' }),
        );
        expect(keep).toHaveBeenCalledOnce();
        rerender(<ComplianceDialog {...props} open={false} />);
        expect(screen.queryByRole('dialog')).toBeNull();
        rerender(<ComplianceDialog {...props} open />);
        expect(screen.getByLabelText('Evidence reference')).toHaveValue(
            'DRAFT-REF',
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review.*Confirm the new record/,
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Record evidence' }),
        );
        await waitFor(() =>
            expect(
                screen.getByLabelText('Licence end odometer (km)'),
            ).toHaveFocus(),
        );
        expect(
            screen.getByLabelText('Licence end odometer (km)'),
        ).toHaveAttribute('aria-invalid', 'true');
    });

    it('retains draft fields across a version conflict and submits against the explicitly refreshed source', async () => {
        const request = vi
            .fn()
            .mockResolvedValueOnce({
                ok: false,
                status: 409,
                json: async () => ({ message: 'Evidence changed.' }),
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    can: { manage: true },
                    compliance: [
                        {
                            ...record,
                            current: {
                                ...record.current,
                                id: 32,
                                version: 3,
                                evidence_reference: 'NEW-SOURCE',
                            },
                        },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ version: { id: 33, version: 4 } }),
            });
        vi.stubGlobal('fetch', request);
        render(
            <ComplianceDialog
                vehicle={vehicle}
                record={record}
                initialStep={1}
                onClose={vi.fn()}
                onSaved={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByLabelText('Evidence reference'), {
            target: { value: 'DRAFT-REF' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Record evidence' }),
        );
        await screen.findByText('Evidence changed.');
        fireEvent.click(
            screen.getByRole('button', { name: 'Review latest record' }),
        );
        await screen.findByText('3 · NEW-SOURCE');
        expect(screen.getByText('DRAFT-REF')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record evidence' }),
        );
        await screen.findByText('RUC evidence recorded');
        expect(JSON.parse(request.mock.calls[2][1].body)).toMatchObject({
            expected_current_version_id: 32,
            evidence_reference: 'DRAFT-REF',
            ruc_start_km: 100,
            ruc_end_km: 200,
        });
    });
});
