import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { brandContrastReport } from '@/lib/derive-palette';

import { BrandContrastNotice } from '../brand-contrast-notice';

afterEach(cleanup);

describe('BrandContrastNotice', () => {
    it('stays hidden for a brand whose header text is readable', () => {
        render(<BrandContrastNotice hex="#111827" onUseShade={vi.fn()} />);
        expect(screen.queryByTestId('brand-contrast-notice')).toBeNull();
    });

    it('stays hidden while the hex is still being typed', () => {
        render(<BrandContrastNotice hex="#facc1" onUseShade={vi.fn()} />);
        expect(screen.queryByTestId('brand-contrast-notice')).toBeNull();
    });

    it('lists the measured contrast and offers a darker shade for a very light brand', () => {
        const onUseShade = vi.fn();
        render(<BrandContrastNotice hex="#facc15" onUseShade={onUseShade} />);

        const notice = screen.getByTestId('brand-contrast-notice');
        expect(notice).toHaveTextContent('Page header titles');
        expect(notice).toHaveTextContent('needs 4.5:1');
        expect(notice).toHaveTextContent(
            'Lowest measured: 1.3:1 (page header descriptions)',
        );
        expect(notice).toHaveTextContent('You can still save this colour.');

        const { suggestion } = brandContrastReport('#facc15');
        expect(suggestion).not.toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use suggested shade' }),
        );
        expect(onUseShade).toHaveBeenCalledWith(suggestion);
    });
});
