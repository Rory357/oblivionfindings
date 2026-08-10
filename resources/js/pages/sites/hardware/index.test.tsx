import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { SiteHardwareRegisterAction } from './index';

describe('SiteHardwareRegisterAction', () => {
    it('opens the canonical device dialog without rendering a create-page link', () => {
        const onRegister = vi.fn();

        render(
            <SiteHardwareRegisterAction canRegister onRegister={onRegister} />,
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

    it('does not expose registration without the canonical create capability', () => {
        render(
            <SiteHardwareRegisterAction
                canRegister={false}
                onRegister={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).toBeNull();
    });
});
