import { ItWizard } from '@/components/it/it-wizards';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 230 } } } }),
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

function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
}

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

        choose('Affected Site', 'Sunnyside Lodge');
        fireEvent.change(
            screen.getByPlaceholderText(
                'e.g. Printer offline — Sunnyside Lodge',
            ),
            { target: { value: 'Core switch dropping links' } },
        );
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

        // Only Devices at the ticket's Site are offered as canonical links.
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Affected Device' }),
            { key: 'ArrowDown' },
        );
        expect(
            screen.getByRole('option', {
                name: 'Sunnyside core switch · DEV-NET-0042',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', {
                name: 'Harbour core switch · DEV-NET-0043',
            }),
        ).not.toBeInTheDocument();
    });
});
