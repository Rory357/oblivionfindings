import { render } from '@testing-library/react';
import { Gavel } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { EmptyState } from './empty-state';

describe('EmptyState', () => {
    it('renders a lucide icon component (forwardRef object)', () => {
        const { container } = render(
            <EmptyState icon={Gavel} title="No resolutions yet" />,
        );
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('renders an icon passed as an element', () => {
        const { container } = render(
            <EmptyState icon={<Gavel data-testid="icon" />} title="Empty" />,
        );
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('renders without an icon', () => {
        const { getByText, container } = render(
            <EmptyState title="Nothing here" />,
        );
        expect(getByText('Nothing here')).toBeTruthy();
        expect(container.querySelector('svg')).toBeNull();
    });
});
