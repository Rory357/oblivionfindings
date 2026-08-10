import { fireEvent, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';

import { SiteHardwareRegisterAction } from './index';

describe('SiteHardwareRegisterAction', () => {
    it('passes the originating Site into the canonical registration dialog', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/pages/sites/hardware/index.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('prefillSiteId={site.id}');
        expect(source).toContain('prefillSiteName={site.name}');
    });

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
