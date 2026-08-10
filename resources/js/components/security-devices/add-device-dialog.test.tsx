import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddDeviceDialog, EditDeviceDialog } from './add-device-dialog';

const options = {
    taxonomy: {
        security: {
            cctv: {
                dome_camera: 'Dome camera',
            },
        },
    },
    domains: [{ value: 'security', label: 'Security' }],
    statuses: [{ value: 'active', label: 'Active' }],
};

describe('AddDeviceDialog', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => options,
            }),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('uses the shared wizard shell without navigating to a standalone form page', async () => {
        const onClose = vi.fn();
        render(<AddDeviceDialog open onClose={onClose} />);

        expect(
            screen.getByRole('dialog', { name: 'Register device' }),
        ).toBeVisible();
        expect(
            await screen.findByText('What are we registering?'),
        ).toBeVisible();
        expect(screen.getByText('Hardware identity')).toBeVisible();
        expect(screen.getByText('Connection')).toBeVisible();
        expect(screen.getByText('Review & register')).toBeVisible();
        expect(screen.getByRole('button', { name: /Continue/ })).toBeDisabled();
        expect(fetch).toHaveBeenCalledWith(
            '/security-devices/devices/create',
            expect.objectContaining({
                credentials: 'same-origin',
            }),
        );
    });

    it('loads an existing device into the same modal journey', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                ...options,
                device: {
                    id: 42,
                    name: 'Main entrance camera',
                    domain: 'security',
                    category: 'cctv',
                    subcategory: 'dome_camera',
                    status: 'active',
                },
            }),
        } as Response);

        render(<EditDeviceDialog open onClose={vi.fn()} deviceId={42} />);

        expect(
            screen.getByRole('dialog', { name: 'Edit device' }),
        ).toBeVisible();
        expect(
            await screen.findByRole('textbox', { name: /Device name/ }),
        ).toHaveValue('Main entrance camera');
        expect(screen.getByText('Review & save')).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/security-devices/devices/42/edit',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
    });
});
