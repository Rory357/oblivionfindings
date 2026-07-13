import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

describe('Fleet bounded option selectors', () => {
    it.each([
        [
            'maintenance/work-orders/create-wizard.tsx',
            '/fleet-assets/maintenance/work-orders/options/search',
        ],
        [
            '../../components/fleet/fleet-incident-report-dialog.tsx',
            '/fleet-assets/incidents/options/search',
        ],
        ['devices/index.tsx', '/fleet-assets/devices/options/search'],
    ])('%s searches its bounded backend options', (relativePath, endpoint) => {
        const source = readFileSync(
            `resources/js/pages/fleet-assets/${relativePath}`,
            'utf8',
        );

        expect(source).toContain(endpoint);
        expect(source).toContain('fetch(');
    });

    it.each([
        ['maintenance/work-orders/create-wizard.tsx', 'visibleAssetOptions', 'visibleUserOptions'],
        ['../../components/fleet/fleet-incident-report-dialog.tsx', 'visibleAssetOptions', 'visibleDriverOptions'],
        ['devices/index.tsx', 'visibleDeviceOptions', 'visibleAssetOptions'],
    ])('%s retains selected values outside the latest result page', (relativePath, firstOptions, secondOptions) => {
        const source = readFileSync(
            `resources/js/pages/fleet-assets/${relativePath}`,
            'utf8',
        );

        expect(source).toContain(firstOptions);
        expect(source).toContain(secondOptions);
    });
});
