import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { CategorySearchInput } from './category';

describe('CategorySearchInput', () => {
    it('gives the workspace search field an accessible name', () => {
        render(
            <CategorySearchInput
                title="Security"
                value=""
                onChange={vi.fn()}
                onSubmit={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('textbox', { name: 'Search security' }),
        ).toHaveAttribute('placeholder', 'Search security...');
    });
});
