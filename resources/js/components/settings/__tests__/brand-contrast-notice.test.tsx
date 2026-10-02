import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { BrandContrastReport } from '@/lib/derive-palette';

import { BrandContrastNotice } from '../brand-contrast-notice';

// Lets one test force a failing report; the rest use the real checks.
const forced = vi.hoisted(() => ({
    report: null as BrandContrastReport | null,
}));
vi.mock('@/lib/derive-palette', async (importOriginal) => {
    const actual =
        await importOriginal<typeof import('@/lib/derive-palette')>();
    return {
        ...actual,
        brandContrastReport: (hex: string) =>
            forced.report ?? actual.brandContrastReport(hex),
    };
});

afterEach(() => {
    cleanup();
    forced.report = null;
});

describe('BrandContrastNotice', () => {
    it('stays hidden for a brand whose header text is readable', () => {
        render(<BrandContrastNotice hex="#111827" onUseShade={vi.fn()} />);
        expect(screen.queryByTestId('brand-contrast-notice')).toBeNull();
        expect(screen.queryByTestId('brand-contrast-ok')).toBeNull();
    });

    it('stays hidden while the hex is still being typed', () => {
        render(<BrandContrastNotice hex="#facc1" onUseShade={vi.fn()} />);
        expect(screen.queryByTestId('brand-contrast-notice')).toBeNull();
        expect(screen.queryByTestId('brand-contrast-ok')).toBeNull();
    });

    it.each(['#facc15', '#fde68a', '#ffffff'])(
        'tells a very light brand (%s) that headers stay readable, with no warning',
        (hex) => {
            render(<BrandContrastNotice hex={hex} onUseShade={vi.fn()} />);
            expect(screen.queryByTestId('brand-contrast-notice')).toBeNull();
            expect(screen.getByTestId('brand-contrast-ok')).toHaveTextContent(
                'Headers stay readable',
            );
        },
    );

    it('still lists the measured contrast and offers a darker shade when a check fails', () => {
        forced.report = {
            checks: [
                { id: 'title', label: 'Page header titles', ratio: 9.1 },
                {
                    id: 'heroButton',
                    label: 'White header button text',
                    ratio: 3.2,
                },
            ],
            passes: false,
            suggestion: '#806000',
        };
        const onUseShade = vi.fn();
        render(<BrandContrastNotice hex="#ffd000" onUseShade={onUseShade} />);

        const notice = screen.getByTestId('brand-contrast-notice');
        expect(notice).toHaveTextContent('Page header titles');
        expect(notice).toHaveTextContent('needs 4.5:1');
        expect(notice).toHaveTextContent(
            'Lowest measured: 3.2:1 (white header button text)',
        );
        expect(notice).toHaveTextContent('You can still save this colour.');

        fireEvent.click(
            screen.getByRole('button', { name: 'Use suggested shade' }),
        );
        expect(onUseShade).toHaveBeenCalledWith('#806000');
    });
});
