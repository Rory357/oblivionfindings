import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ClinicalRail, { type InrRecord } from './clinical-rail';

function renderRail(inrRecords: InrRecord[]) {
    return render(
        <ClinicalRail
            inrRecords={inrRecords}
            syringeDrivers={[]}
            awaitingVerification={0}
            pendingCorrections={0}
            chartReviewDate={null}
            allergies={[]}
            conditions={[]}
            emergencyContacts={[]}
            can={{
                manageInr: false,
                manageSyringeDrivers: false,
                verifyOrders: false,
                reviewCorrections: false,
            }}
            onRecordInr={() => {}}
            onStartDriver={() => {}}
            onVerifyOrders={() => {}}
            onReviewCorrections={() => {}}
        />,
    );
}

// Shape sent by EmarController::getClientInrRecords.
const reading: InrRecord = {
    id: 1,
    client_medication_id: null,
    medication_name: null,
    inr_value: '4.8',
    tested_on: '2026-09-30',
    next_test_date: null,
    target_range_low: '2.0',
    target_range_high: '3.0',
    dose_mg: '3.00',
    disabled_at: null,
};

describe('ClinicalRail INR card', () => {
    it('labels a reading with no medicine linked (NF-23)', () => {
        renderRail([reading]);

        expect(screen.getByText('4.8')).toBeInTheDocument();
        expect(screen.getByText('No medicine linked')).toBeInTheDocument();
    });

    it('does not label a reading linked to a medicine', () => {
        renderRail([
            {
                ...reading,
                client_medication_id: 7,
                medication_name: 'Warfarin',
            },
        ]);

        expect(
            screen.queryByText('No medicine linked'),
        ).not.toBeInTheDocument();
    });

    it('shows the target range and dose from the payload field names', () => {
        renderRail([reading]);

        expect(screen.getByText('Target 2–3')).toBeInTheDocument();
        expect(screen.getByText('Dose 3 mg')).toBeInTheDocument();
    });
});
