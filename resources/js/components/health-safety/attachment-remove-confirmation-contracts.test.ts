import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

// GOOD-CATCHES §3 row 35: these detail viewers used to delete an attachment
// the moment the trash button was clicked. Each now asks first through the
// shared ConfirmDialog and only deletes from the confirmed handler.
const VIEWERS = [
    'resources/js/components/health-safety/injury-detail-dialog.tsx',
    'resources/js/components/health-safety/first-aid-detail-dialog.tsx',
    'resources/js/components/health-safety/drill-detail-dialog.tsx',
    'resources/js/components/health-safety/restraint-event-detail-dialog.tsx',
    'resources/js/components/health-safety/procedure-detail-dialog.tsx',
    'resources/js/components/health-safety/risk-assessments/ra-detail-dialog.tsx',
    'resources/js/components/safeguarding/concern-dialog.tsx',
    'resources/js/components/privacy/privacy-attachments-pane.tsx',
];

const read = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

describe('attachment removal asks first', () => {
    it.each(VIEWERS)('%s confirms before deleting a file', (path) => {
        const source = read(path);

        expect(source).toContain(
            "import { ConfirmDialog } from '@/components/confirm-dialog';",
        );
        expect(source).toContain('title="Remove this file?"');
        expect(source).toContain('confirmText="Remove file"');
        expect(source).toContain('variant="destructive"');
        expect(source).toContain('processing={removingFile}');

        // The trash button only picks the file; it never deletes directly.
        expect(source).not.toMatch(/onClick=\{\(\) =>\s*router\.delete\(/);
        expect(source).toMatch(/onClick=\{\(\) =>\s*setFileToRemove\(/);

        // The delete request is sent only from the confirmed handler.
        expect(source).toMatch(
            /if \(!fileToRemove\) return;\s*router\.delete\(\s*`[^`]*\$\{fileToRemove\.id\}`/,
        );
    });
});
