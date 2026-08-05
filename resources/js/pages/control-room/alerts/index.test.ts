import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('Control Room active-alert pagination', () => {
    it('contains the wide page links instead of widening the mobile page', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/pages/control-room/alerts/index.tsx',
            ),
            'utf8',
        );
        const pagination = source.slice(source.indexOf('{/* Pagination */'));

        expect(pagination).toContain('min-w-0');
        expect(pagination).toContain('max-w-full overflow-x-auto');
        expect(pagination).toContain('w-max');
    });
});
