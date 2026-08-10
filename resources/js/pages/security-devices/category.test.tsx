import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { CategoryRegisterAction, CategorySearchInput } from './category';

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

describe('CategoryRegisterAction', () => {
    it('does not offer a registration route to view-only operators', () => {
        render(
            <CategoryRegisterAction
                canRegister={false}
                label="Register device"
                onRegister={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).toBeNull();
    });

    it('opens the governed registration dialog with explicit create capability', () => {
        const onRegister = vi.fn();

        render(
            <CategoryRegisterAction
                canRegister
                label="Register device"
                onRegister={onRegister}
            />,
        );

        const action = screen.getByRole('button', {
            name: 'Register device',
        });

        expect(
            screen.queryByRole('link', { name: 'Register device' }),
        ).toBeNull();
        fireEvent.click(action);
        expect(onRegister).toHaveBeenCalledOnce();
    });
});
