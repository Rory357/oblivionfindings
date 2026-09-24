import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    isNotRequired,
    NotRequiredDialog,
    NotRequiredToggle,
} from './compliance-not-required';
import type {
    ComplianceRecord,
    ComplianceVersion,
    VehicleProfile,
} from './types';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const vehicle = {
    id: 14,
    name: 'Kōwhai van',
    registration_number: 'KWH014',
} as unknown as VehicleProfile;

const version = (overrides: Partial<ComplianceVersion>): ComplianceVersion => ({
    id: 31,
    version: 2,
    applicability: 'unknown',
    applicability_basis: null,
    outcome: 'needs_assessment',
    evidence_reference: null,
    source_reference: null,
    effective_on: null,
    expires_on: null,
    ruc_start_km: null,
    ruc_end_km: null,
    legacy: false,
    reason: null,
    recorded_by: 'Alex Morgan',
    created_at: '2026-09-24T09:30:00+12:00',
    files: [],
    ...overrides,
});

const record = (current: ComplianceVersion | null): ComplianceRecord => ({
    kind: 'ruc',
    label: 'RUC',
    record_id: current ? 5 : null,
    current,
    history: current ? [current] : [],
    history_count: current ? 1 : 0,
});

const ok = (body: unknown) => ({
    ok: true,
    status: 200,
    json: async () => body,
});

describe('Not required for this vehicle', () => {
    it('shows the tick box ticked only for a not-applicable decision and opens the dialog either way', () => {
        const onToggle = vi.fn();
        const { rerender } = render(
            <NotRequiredToggle record={record(null)} onToggle={onToggle} />,
        );
        const box = screen.getByRole('checkbox', {
            name: 'Not required for this vehicle (RUC)',
        });
        expect(box.getAttribute('aria-checked')).toBe('false');
        fireEvent.click(box);
        expect(onToggle).toHaveBeenCalledTimes(1);

        const notRequired = record(
            version({
                applicability: 'not_applicable',
                applicability_basis: 'Petrol car.',
                outcome: 'recorded',
            }),
        );
        expect(isNotRequired(notRequired)).toBe(true);
        rerender(
            <NotRequiredToggle record={notRequired} onToggle={onToggle} />,
        );
        expect(
            screen
                .getByRole('checkbox', {
                    name: 'Not required for this vehicle (RUC)',
                })
                .getAttribute('aria-checked'),
        ).toBe('true');
    });

    it('needs a reason, then records a new not-applicable version against the current one', async () => {
        const request = vi
            .fn()
            .mockResolvedValue(
                ok({ version: { id: 32, version: 3, record_id: 5 } }),
            );
        vi.stubGlobal('fetch', request);
        const onSaved = vi.fn();
        const onClose = vi.fn();
        render(
            <NotRequiredDialog
                vehicle={vehicle}
                record={record(version({}))}
                onClose={onClose}
                onSaved={onSaved}
            />,
        );
        expect(
            screen.getByText('RUC not required for this vehicle'),
        ).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Mark not required' }),
        );
        expect(
            await screen.findByText(
                'Record why RUC isn’t required for this vehicle.',
            ),
        ).toBeTruthy();
        expect(request).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Reason'), {
            target: { value: '  Petrol car: charges are paid at the pump.  ' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Mark not required' }),
        );
        await vi.waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
        expect(onClose).toHaveBeenCalledTimes(1);
        const [url, init] = request.mock.calls[0];
        expect(url).toBe('/fleet-assets/vehicles/14/compliance/ruc');
        expect(JSON.parse(init.body)).toEqual({
            applicability: 'not_applicable',
            applicability_basis: 'Petrol car: charges are paid at the pump.',
            outcome: 'recorded',
            expected_current_version_id: 31,
        });
        expect(init.headers['Idempotency-Key']).toBeTruthy();
    });

    it('marks it required again as an unresolved assessment, or hands over to the evidence wizard', async () => {
        const request = vi
            .fn()
            .mockResolvedValue(
                ok({ version: { id: 33, version: 3, record_id: 5 } }),
            );
        vi.stubGlobal('fetch', request);
        const onRecordEvidence = vi.fn();
        const current = version({
            applicability: 'not_applicable',
            applicability_basis: 'Petrol car.',
            outcome: 'recorded',
        });
        render(
            <NotRequiredDialog
                vehicle={vehicle}
                record={record(current)}
                onClose={vi.fn()}
                onSaved={vi.fn()}
                onRecordEvidence={onRecordEvidence}
            />,
        );
        expect(
            screen.getByText('RUC is required for this vehicle'),
        ).toBeTruthy();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record evidence instead' }),
        );
        expect(onRecordEvidence).toHaveBeenCalledTimes(1);

        fireEvent.change(screen.getByLabelText('Reason'), {
            target: { value: 'Converted to diesel.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Mark as required' }),
        );
        await vi.waitFor(() => expect(request).toHaveBeenCalledTimes(1));
        expect(JSON.parse(request.mock.calls[0][1].body)).toEqual({
            applicability: 'applicable',
            applicability_basis: 'Converted to diesel.',
            outcome: 'needs_assessment',
            expected_current_version_id: 31,
        });
    });
});
