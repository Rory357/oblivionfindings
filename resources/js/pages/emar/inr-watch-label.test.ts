import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('eMAR Warfarin / INR watch card', () => {
    it('labels readings with no medicine linked instead of hiding them (NF-23)', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/pages/emar/Index.tsx'),
            'utf8',
        );
        const card = source.slice(
            source.indexOf('Warfarin / INR watch'),
            source.indexOf('{/* Syringe drivers */}'),
        );

        expect(source).toContain('client_medication_id: number | null;');
        expect(card).toMatch(
            /r\.client_medication_id\s*===\s*null\s*&&\s*'No medicine linked · '/,
        );
    });
});
