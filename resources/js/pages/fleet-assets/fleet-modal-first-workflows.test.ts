import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const read = (path: string) => readFileSync(path, 'utf8');

describe('Fleet modal-first create workflows', () => {
    it.each([
        {
            name: 'geofence',
            index: 'resources/js/pages/fleet-assets/geofences/index.tsx',
            form: 'resources/js/pages/fleet-assets/geofences/create.tsx',
            component: 'GeofenceWizard',
            steps: ['Scope & name', 'Draw area', 'Alerts & schedule', 'Review'],
        },
        {
            name: 'outing',
            index: 'resources/js/pages/fleet-assets/outings/index.tsx',
            form: 'resources/js/pages/fleet-assets/outings/create.tsx',
            component: 'OutingWizard',
            steps: ['People & purpose', 'Transport & timing', 'Safety checks', 'Review'],
        },
        {
            name: 'transport',
            index: 'resources/js/pages/fleet-assets/transports/index.tsx',
            form: 'resources/js/pages/fleet-assets/transports/create.tsx',
            component: 'TransportWizard',
            steps: [
                'Resident & destination',
                'Vehicle & staff',
                'Medication & accessibility',
                'Review',
            ],
        },
    ])('opens the $name canonical WizardShell from its index', ({ index, form, component, steps }) => {
        const indexSource = read(index);
        const formSource = read(form);

        expect(indexSource).toContain(component);
        expect(indexSource).toContain("searchParams.get('new') === '1'");
        expect(formSource).toContain(`export function ${component}`);
        expect(formSource).toContain('<WizardShell');
        expect(formSource).not.toContain('<AppLayout');

        for (const step of steps) {
            expect(formSource).toContain(`label: '${step}'`);
        }
    });

    it('keeps incident detail in one modal experience', () => {
        const source = read(
            'resources/js/components/fleet/fleet-incident-dialog.tsx',
        );

        expect(source).not.toContain('Open full page');
        expect(source).toContain('?incident=');
    });
});
