import { statSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

describe('client profile bundle source hygiene', () => {
    it('keeps show.tsx below Babel code-generator deopt threshold', () => {
        const showPage = resolve(
            process.cwd(),
            'resources/js/pages/operations/clients/show.tsx',
        );

        expect(statSync(showPage).size).toBeLessThan(500_000);
    });
});
