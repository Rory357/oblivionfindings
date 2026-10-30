import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

// GOOD-CATCHES §3 row 35: these detail viewers used to delete an attachment
// the moment the trash button was clicked. Each now asks first through the
// shared ConfirmDialog and only deletes from the confirmed handler. The injury, first aid, drill, restraint and
// risk assessment viewers were removed with the unreachable screens (b3090e6bc); their record pages remove a file
// through their own "Remove evidence" pop-up, which asks for a reason first.
const VIEWERS = [
    'resources/js/components/health-safety/procedure-detail-dialog.tsx',
    'resources/js/components/safeguarding/concern-dialog.tsx',
    'resources/js/components/safeguarding/concern-record.tsx',
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

        // The trash button (or row-menu item) only picks the file; it never deletes directly.
        expect(source).not.toMatch(/onClick(?:=\{|:\s*)\(\) =>\s*router\.delete\(/);
        expect(source).toMatch(/onClick(?:=\{|:\s*)\(\) =>\s*setFileToRemove\(/);

        // The delete request is sent only from the confirmed handler.
        expect(source).toMatch(
            /if \(!fileToRemove\) return;\s*router\.delete\(\s*`[^`]*\$\{fileToRemove\.id\}`/,
        );
    });
});
