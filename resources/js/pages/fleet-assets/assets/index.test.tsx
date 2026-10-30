import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AssetTechnologySummary } from './index';

describe('AssetTechnologySummary', () => {
    it('distinguishes restricted access from zero and linked canonical Devices', () => {
        const { rerender } = render(<AssetTechnologySummary count={null} />);

        expect(screen.getByText('Device details restricted')).toBeVisible();
        expect(screen.queryByText('0 linked devices')).not.toBeInTheDocument();

        rerender(<AssetTechnologySummary count={0} />);
        expect(screen.getByText('0 linked devices')).toBeVisible();

        rerender(<AssetTechnologySummary count={2} />);
        expect(screen.getByText('2 linked devices')).toBeVisible();
    });
});
