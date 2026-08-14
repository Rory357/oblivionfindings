import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('eMAR oversight responsive containment', () => {
    it('lets the action centre and right rail shrink inside the mobile grid', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/pages/emar/Index.tsx'),
            'utf8',
        );
        const workspace = source.slice(
            source.indexOf('Main grid: Action centre + right rail'),
            source.indexOf('Client board'),
        );

        expect(workspace).toContain(
            'grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_360px]',
        );
        expect(workspace).toContain(
            '<Card className="min-w-0 rounded-[18px]">',
        );
        expect(workspace).toContain(
            '<div className="flex min-w-0 flex-col gap-4">',
        );
        expect(workspace).toContain(
            'flex flex-wrap items-start justify-between gap-3',
        );
        expect(workspace).toContain('Report error');
        expect(workspace).toMatch(
            /initialDimension=\{\{\s+width: 1,\s+height: 140,\s+\}\}/,
        );
    });

    it('wraps fixed card headers without removing their actions', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/pages/emar/Index.tsx'),
            'utf8',
        );
        const regions = [
            source.slice(
                source.indexOf('Client board'),
                source.indexOf('Clinical watch'),
            ),
            source.slice(
                source.indexOf('Reviews due'),
                source.indexOf('Ops row'),
            ),
            source.slice(
                source.indexOf('Stock & pharmacy'),
                source.indexOf('Medication errors'),
            ),
        ];

        for (const region of regions) {
            expect(region).toMatch(
                /CardHeader className="[^"]*flex-row[^"]*flex-wrap/,
            );
            expect(region).toContain('flex min-w-0 items-center');
        }

        expect(regions[0]).toContain('Add medication');
        expect(regions[0]).toContain('All clients →');
        expect(regions[0]).toContain('<CardContent className="min-w-0">');
        expect(regions[0]).toContain(
            'grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-3',
        );
        expect(regions[0]).toContain('flex min-w-0 flex-col gap-2');
        expect(regions[1]).toContain('Schedule');
        expect(regions[2]).toContain('Record stock');
    });
});
