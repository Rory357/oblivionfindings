import type { EmarNavigationPermissions } from '@/lib/emar-navigation';
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    EmarRecordDialog,
    scheduledDoseRecordLink,
    type EmarMedication,
} from './emar-dialog';

const fixture = vi.hoisted(() => ({ can: {} as EmarNavigationPermissions }));
vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { can: fixture.can } } }),
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const lead: EmarNavigationPermissions = {
    medications: { view: true, administerRecord: true, ordersVerify: true },
};
const supportWorker: EmarNavigationPermissions = {
    medications: { view: true, administerRecord: true },
};

const scheduled: EmarMedication = {
    id: 1,
    name: 'Metformin',
    is_prn: false,
    record_on_profile: false,
};
const asNeeded: EmarMedication = {
    id: 2,
    name: 'Paracetamol',
    is_prn: true,
    record_on_profile: true,
};

function open(medications: EmarMedication[], initialMedicationId?: number) {
    render(
        <EmarRecordDialog
            open
            onClose={() => {}}
            clientId={7}
            clientLabel="Aroha"
            medications={medications}
            canRecord
            canRecordControlled={false}
            witnessOptions={[]}
            initialMedicationId={initialMedicationId}
        />,
    );
}

afterEach(() => cleanup());

describe('EmarRecordDialog — as-needed only (interim, until P02-6)', () => {
    it('offers only the link for a scheduled medicine, never the form', () => {
        fixture.can = lead;
        open([scheduled, asNeeded], scheduled.id);

        expect(
            screen.getByText(/Scheduled doses are recorded on the MAR chart/),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: /Record scheduled doses/ })
                .getAttribute('href'),
        ).toBe('/emar/mar?client_id=7');
        expect(screen.queryByRole('button', { name: /Sign & record/ })).toBe(
            null,
        );
    });

    it('records as-needed medicines and links the scheduled ones', () => {
        fixture.can = lead;
        open([scheduled, asNeeded]);

        expect(screen.getByText(/As-needed medicine/)).toBeTruthy();
        expect(
            screen.getByRole('button', { name: /Sign & record/ }),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: /Record scheduled doses/ }),
        ).toBeTruthy();
    });

    it('sends frontline staff to Meds today', () => {
        fixture.can = supportWorker;
        open([scheduled]);

        expect(
            screen
                .getByRole('link', {
                    name: /Record scheduled doses on Meds today/,
                })
                .getAttribute('href'),
        ).toBe('/meds/today?client_id=7');
        expect(scheduledDoseRecordLink(7, lead).href).toBe(
            '/emar/mar?client_id=7',
        );
    });
});
