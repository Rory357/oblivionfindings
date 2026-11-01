import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { PageHeaderStatusChip } from './page-header';
import { PageHeaderStages } from './page-header-strip';

describe('S3: the header status chip stays readable on the brand band (H&S plan B1.8)', () => {
    it('draws an info chip as an opaque card pill with brand text, not the brand tint', () => {
        render(<PageHeaderStatusChip variant="info">Open</PageHeaderStatusChip>);
        const chip = screen.getByText('Open');
        expect(chip.className).toContain('bg-card');
        expect(chip.className).toContain('text-primary');
        expect(chip.className).not.toContain('bg-status-info-bg');
    });

    it('leaves the other status pairs alone', () => {
        render(<PageHeaderStatusChip variant="warning">Being investigated</PageHeaderStatusChip>);
        expect(screen.getByText('Being investigated').className).not.toContain('bg-card');
    });
});

describe('S1: the next step can carry its own button (H&S plan B1.4)', () => {
    const steps = [
        { label: 'To do', state: 'done' as const },
        { label: 'In progress', state: 'now' as const },
    ];

    it('looks as before without an action', () => {
        const { container } = render(<PageHeaderStages steps={steps} next={{ text: 'Start the action' }} />);
        expect(container.querySelector('.eh-next-action')).toBeNull();
    });

    it('puts the action inside the next-step line', () => {
        const { container } = render(
            <PageHeaderStages steps={steps} next={{ text: 'Choose a new owner', action: <button type="button">Choose a new owner</button> }} />,
        );
        const slot = container.querySelector('.eh-next .eh-next-action');
        expect(slot).not.toBeNull();
        expect(slot?.querySelector('button')?.textContent).toBe('Choose a new owner');
    });
});
