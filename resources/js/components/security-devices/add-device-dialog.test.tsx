import { Button } from '@/components/ui/button';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AddDeviceDialog,
    EditDeviceDialog,
    useAddDeviceDialogState,
} from './add-device-dialog';

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

function AddDeviceDialogLauncher() {
    const dialog = useAddDeviceDialogState();

    return (
        <>
            <Button type="button" onClick={dialog.openDialog}>
                Register device
            </Button>
            <AddDeviceDialog open={dialog.open} onClose={dialog.closeDialog} />
        </>
    );
}

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
        window.history.replaceState(null, '', '/');
    });

    it('opens the canonical in-place wizard without changing the workspace URL', async () => {
        window.history.replaceState(
            window.history.state,
            '',
            '/security-devices/devices?status=offline',
        );

        render(<AddDeviceDialogLauncher />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Register device' }),
        );

        expect(
            screen.getByRole('dialog', { name: 'Register device' }),
        ).toBeVisible();
        expect(
            await screen.findByText('What are we registering?'),
        ).toBeVisible();
        expect(window.location.pathname).toBe('/security-devices/devices');
        expect(window.location.search).toBe('?status=offline');
    });

    it('keeps legacy create links opening the same modal and cleans the query when closed', async () => {
        window.history.replaceState(
            null,
            '',
            '/security-devices/devices?dialog=add-device&domain=security',
        );

        render(<AddDeviceDialogLauncher />);

        expect(
            screen.getByRole('dialog', { name: 'Register device' }),
        ).toBeVisible();

        fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);

        expect(
            screen.queryByRole('dialog', { name: 'Register device' }),
        ).not.toBeInTheDocument();
        expect(window.location.pathname).toBe('/security-devices/devices');
        expect(window.location.search).toBe('?domain=security');
    });

    it('closes an in-place registration when browser history moves back', () => {
        window.history.replaceState(
            null,
            '',
            '/security-devices/devices?status=offline',
        );

        render(<AddDeviceDialogLauncher />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Register device' }),
        );

        window.history.replaceState(
            null,
            '',
            '/security-devices/devices?status=offline',
        );
        fireEvent.popState(window);

        expect(
            screen.queryByRole('dialog', { name: 'Register device' }),
        ).not.toBeInTheDocument();
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
