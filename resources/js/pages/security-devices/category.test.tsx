import { render, screen } from '@testing-library/react';
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
                href="/security-devices/devices/create?domain=security"
                label="Register device"
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Register device' }),
        ).toBeNull();
    });

    it('offers the governed registration route with explicit create capability', () => {
        render(
            <CategoryRegisterAction
                canRegister
                href="/security-devices/devices/create?domain=security"
                label="Register device"
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Register device' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/create?domain=security',
        );
    });
});
