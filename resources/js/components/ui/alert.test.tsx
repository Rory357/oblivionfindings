import { render, screen } from '@testing-library/react';
import { AlertCircle } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { Alert, AlertDescription, AlertTitle } from './alert';

describe('Alert destructive variant', () => {
    it('uses the verified critical status pair, not the fill foreground', () => {
        render(
            <Alert variant="destructive" className="mb-4">
                <AlertCircle />
                <AlertTitle>Report could not be run</AlertTitle>
                <AlertDescription>The server returned an error.</AlertDescription>
            </Alert>,
        );

        const classes = screen.getByRole('alert').className.split(/\s+/);

        expect(classes).toEqual(
            expect.arrayContaining([
                'border-status-critical/40',
                'bg-status-critical-bg',
                'text-status-critical-foreground',
                '[&>svg]:text-status-critical',
                '*:data-[slot=alert-description]:text-status-critical-foreground',
                'text-sm',
                'mb-4',
            ]),
        );
        // White in light mode: only readable on a solid bg-destructive fill.
        expect(classes.some((c) => c.includes('destructive-foreground'))).toBe(
            false,
        );
        // The icon colour replaces the base text-current rather than racing it.
        expect(classes).not.toContain('[&>svg]:text-current');
        // Description at full strength: an opacity modifier can drop it below AA.
        expect(
            classes.some((c) =>
                /alert-description\]:text-[\w-]+\/\d+$/.test(c),
            ),
        ).toBe(false);
    });
});
