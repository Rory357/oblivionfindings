import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { WorkOrderCreateWizard } from './create-wizard';

vi.mock('@inertiajs/react', () => ({
    useForm: function useTestForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);
        return { data, setData, errors: {}, processing: false };
    },
}));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Maintenance resource selection', () => {
    it('retains a chosen search result when a subsequent search returns different assets', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce({
                    ok: true,
                    json: async () => ({
                        results: [
                            {
                                id: 7,
                                name: 'Harbour van',
                                asset_tag: 'VAN-7',
                                category: 'Vehicle',
                            },
                        ],
                    }),
                })
                .mockResolvedValueOnce({
                    ok: true,
                    json: async () => ({
                        results: [
                            {
                                id: 8,
                                name: 'Kauri car',
                                asset_tag: 'CAR-8',
                                category: 'Vehicle',
                            },
                        ],
                    }),
                }),
        );
        const assets: [] = [];
        render(
            <WorkOrderCreateWizard
                open
                onClose={vi.fn()}
                assets={assets}
                checklistRuns={[]}
            />,
        );
        fireEvent.change(screen.getByLabelText('Affected asset *'), {
            target: { value: 'Harbour' },
        });
        fireEvent.click(
            await screen.findByRole('button', { name: /Harbour van/ }),
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Change' }));
        fireEvent.change(screen.getByLabelText('Affected asset *'), {
            target: { value: 'Kauri' },
        });
        expect(
            await screen.findByRole('button', { name: /Kauri car/ }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Harbour van/ }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep selected asset' }),
        );
        expect(screen.getByText('Harbour van · VAN-7')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
    });
});
