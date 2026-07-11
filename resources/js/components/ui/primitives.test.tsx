import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { Button } from './button';
import { Card } from './card';

describe('unstyled UI primitives', () => {
    it('preserves a custom button surface without applying styled defaults', async () => {
        const onClick = vi.fn();

        render(
            <Button unstyled className="custom-selector-surface" onClick={onClick}>
                Choose
            </Button>,
        );

        const button = screen.getByRole('button', { name: 'Choose' });
        expect(button.className).toContain('custom-selector-surface');
        expect(button.className).toContain('focus-visible:ring');
        expect(button.className).not.toContain('h-9');
        expect(button.className).not.toContain('px-4');

        button.click();
        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('preserves a custom card layout without applying styled defaults', () => {
        render(
            <Card unstyled className="custom-panel-layout">
                Panel
            </Card>,
        );

        const card = screen.getByText('Panel');
        expect(card).toHaveAttribute('data-slot', 'card');
        expect(card.className).toBe('custom-panel-layout');
    });
});
