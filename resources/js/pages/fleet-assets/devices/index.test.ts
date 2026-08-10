import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    'resources/js/pages/fleet-assets/devices/index.tsx',
    'utf8',
);

describe('Fleet device workflow dialogs', () => {
    it('uses the wizard family for pairing with truthful Asset copy and review', () => {
        expect(source).toContain('title="Pair tracking device"');
        expect(source).toContain("label: 'Device & asset'");
        expect(source).toContain("label: 'Review'");
        expect(source).toContain(
            'Link an existing tracking device to an active Fleet & Assets record.',
        );
        expect(source).toContain('Asset *');
        expect(source).not.toContain('Vehicle Asset *');
    });

    it('uses a sectioned wizard detail shell and leaves confirmations compact', () => {
        expect(source).toContain("label: 'Device overview'");
        expect(source).toContain("label: 'Recent telemetry'");
        expect(source).toContain(
            'headerLabel={deviceDetailSteps[detailStepIndex]?.label}',
        );
        expect(source).not.toMatch(/<DialogContent(?:\s|>)/);
        expect(source).toContain('<ConfirmDialog');
    });
});
