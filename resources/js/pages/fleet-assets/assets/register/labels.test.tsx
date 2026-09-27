import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { Labels } from './labels';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
vi.mock('./api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./api')>()),
    api: apiMock,
    download: vi.fn().mockResolvedValue(undefined),
}));

beforeEach(() => {
    apiMock.mockReset();
    apiMock.mockResolvedValue({
        data: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    });
});

function renderLabels() {
    render(
        <Labels
            status=""
            selected={[7, 8]}
            onInventory={vi.fn()}
            onClear={vi.fn()}
        />,
    );
}

it('sends the shared A4 minimum and blocks undersized new labels', async () => {
    renderLabels();
    fireEvent.change(
        screen.getByRole('spinbutton', { name: 'Label height (mm)' }),
        { target: { value: '45' } },
    );
    expect(
        screen.getByRole('button', { name: 'Prepare label batch' }),
    ).toBeDisabled();
    fireEvent.change(
        screen.getByRole('spinbutton', { name: 'Label height (mm)' }),
        { target: { value: '50' } },
    );
    apiMock.mockResolvedValueOnce({
        id: 4,
        asset_ids: [7, 8],
        expires_at: '2030-01-01T00:00:00Z',
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Prepare label batch' }),
    );
    await waitFor(() =>
        expect(apiMock).toHaveBeenCalledWith(
            '/labels',
            'POST',
            expect.objectContaining({
                asset_ids: [7, 8],
                layout: {
                    paper: 'a4',
                    logo: true,
                    width: 60,
                    height: 50,
                    margin: 10,
                    gap: 3,
                    copies: 1,
                    start: 1,
                },
            }),
        ),
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Download label PDF' }),
    );
    const fallback = await screen.findByRole('link', {
        name: 'Save file directly',
    });
    expect(fallback).toHaveAttribute(
        'href',
        '/fleet-assets/asset-register/labels/4/pdf',
    );
    expect(fallback).toHaveAttribute('download', 'asset-labels-4.pdf');
});

it('sends one custom page per label with the chosen branding setting', async () => {
    renderLabels();
    fireEvent.change(screen.getByRole('combobox', { name: 'Print preset' }), {
        target: { value: 'label' },
    });
    expect(
        screen.getByRole('spinbutton', { name: 'Page margin (mm)' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('spinbutton', { name: 'First label position' }),
    ).toHaveValue(1);
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'Include company logo from Branding settings',
        }),
    );
    apiMock.mockResolvedValueOnce({
        id: 5,
        asset_ids: [7, 8],
        expires_at: '2030-01-01T00:00:00Z',
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Prepare label batch' }),
    );
    await waitFor(() =>
        expect(apiMock).toHaveBeenCalledWith(
            '/labels',
            'POST',
            expect.objectContaining({
                layout: {
                    paper: 'label',
                    logo: false,
                    width: 60,
                    height: 50,
                    margin: 0,
                    gap: 0,
                    copies: 1,
                    start: 1,
                },
            }),
        ),
    );
});
