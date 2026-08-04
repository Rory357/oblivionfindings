import { ItWizard } from '@/components/it/it-wizards';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        transform: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

describe('IT ticket Device linking', () => {
    it('lets an agent review a canonical Security and Devices record before logging', () => {
        render(
            <ItWizard
                modal={{
                    type: 'ticket',
                    provisioning: { id: 7, item: 'Replacement laptop' },
                }}
                assignees={[]}
                assetOptions={[]}
                siteOptions={[{ id: 9, name: 'Sunnyside Lodge' }]}
                deviceOptions={[
                    {
                        id: 42,
                        name: 'Sunnyside core switch',
                        uid: 'DEV-NET-0042',
                        site_id: 9,
                    },
                    {
                        id: 43,
                        name: 'Harbour core switch',
                        uid: 'DEV-NET-0043',
                        site_id: 10,
                    },
                ]}
                serviceOptions={[{ id: 12, name: 'Site connectivity' }]}
                onClose={vi.fn()}
            />,
        );

        expect(screen.getByText('Ticket Site')).toBeVisible();
        expect(screen.getByText('Sunnyside Lodge')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Work type')).toBeVisible();
        expect(screen.getByText('Incident')).toBeVisible();
        expect(screen.getByText('Affected service')).toBeVisible();
        expect(screen.getByText('No service selected')).toBeVisible();
        expect(
            screen.getByText(
                'optional — helps route the ticket to the right queue',
            ),
        ).toBeVisible();
        expect(screen.getByText('Affected Device')).toBeVisible();
        expect(screen.getByText('No Device')).toBeVisible();
        expect(
            screen.getByText('optional — canonical Security & Devices record'),
        ).toBeVisible();
    });
});
