import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Toggle } from './toggle';
import { ToggleGroup, ToggleGroupItem } from './toggle-group';

// bg-accent + accent-foreground measured 4.03:1 (axe, role Overview). The on
// state is the brand-fill tint with mode-aware brand text, ≥ 4.5:1 for any
// brand in light and dark (DESIGN.md "A light panel painted bg-sidebar").
const ON_PAIR = ['data-[state=on]:bg-primary-fill/10', 'data-[state=on]:text-primary'];
const OLD_PAIR = ['data-[state=on]:bg-accent', 'data-[state=on]:text-accent-foreground'];

describe('Toggle on state', () => {
    it('uses the brand-fill tint and brand text for a pressed toggle', () => {
        render(
            <Toggle defaultPressed aria-label="Bold">
                B
            </Toggle>,
        );
        const toggle = screen.getByRole('button', { name: 'Bold' });
        expect(toggle).toHaveAttribute('data-state', 'on');
        for (const cls of ON_PAIR) expect(toggle).toHaveClass(cls);
        for (const cls of OLD_PAIR) expect(toggle).not.toHaveClass(cls);
    });

    it.each(['default', 'outline'] as const)(
        'gives every %s toggle-group item the same verified on pair',
        (variant) => {
            render(
                <ToggleGroup
                    type="single"
                    variant={variant}
                    aria-label="Category"
                    defaultValue="hr"
                >
                    <ToggleGroupItem value="all">All</ToggleGroupItem>
                    <ToggleGroupItem value="hr">HR</ToggleGroupItem>
                </ToggleGroup>,
            );
            const on = screen.getByRole('radio', { name: 'HR' });
            const off = screen.getByRole('radio', { name: 'All' });
            expect(on).toHaveAttribute('data-state', 'on');
            expect(off).toHaveAttribute('data-state', 'off');
            for (const item of [on, off]) {
                for (const cls of ON_PAIR) expect(item).toHaveClass(cls);
                for (const cls of OLD_PAIR) expect(item).not.toHaveClass(cls);
            }
        },
    );
});
