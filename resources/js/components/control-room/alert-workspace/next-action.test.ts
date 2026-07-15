import { describe, expect, it } from 'vitest';

import { nextAlertAction } from './next-action';

const base = {
    alertStatus: 'triaging',
    sensorConfirmationRequired: false,
    incident: null,
    healthSafety: null,
    can: {
        manage: true,
        createIncident: true,
        viewIncident: true,
        viewHealthSafety: true,
    },
};

describe('alert workspace next action', () => {
    it('prioritises sensor confirmation before creating any incident', () => {
        expect(
            nextAlertAction({ ...base, sensorConfirmationRequired: true }),
        ).toEqual(
            expect.objectContaining({
                key: 'confirm_sensor',
                label: 'Confirm detection',
            }),
        );
    });

    it('offers one create-and-handover action when the alert has no incident', () => {
        expect(nextAlertAction(base)).toEqual(
            expect.objectContaining({
                key: 'create_incident',
                label: 'Create incident and hand over',
            }),
        );
    });

    it('opens the official incident while H&S is waiting for acceptance', () => {
        expect(
            nextAlertAction({
                ...base,
                incident: {
                    referenceNumber: 'INC-2026-0010',
                    href: '/incidents?incident=10',
                },
                healthSafety: {
                    referenceNumber: 'HS-2026-0010',
                    handoverStatus: 'awaiting_acceptance',
                    href: '/health-safety/events/10',
                },
            }),
        ).toEqual(
            expect.objectContaining({
                key: 'open_incident',
                label: 'Open incident',
                statusText: 'Waiting for H&S acceptance',
            }),
        );
    });

    it('continues accepted governance in H&S when the viewer can open it', () => {
        expect(
            nextAlertAction({
                ...base,
                incident: {
                    referenceNumber: 'INC-2026-0010',
                    href: '/incidents?incident=10',
                },
                healthSafety: {
                    referenceNumber: 'HS-2026-0010',
                    handoverStatus: 'accepted',
                    href: '/health-safety/events/10',
                },
            }),
        ).toEqual(
            expect.objectContaining({
                key: 'continue_health_safety',
                label: 'Continue in H&S',
            }),
        );
    });
});
