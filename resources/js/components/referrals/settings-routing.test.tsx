import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { SettingsRouting } from './settings-routing';
import type { ReferralConfiguration } from './settings-types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({ default: { post, isAxiosError: () => false } }));
const configuration = {
    pathways: [
        {
            id: 'dss_nasc',
            label: 'DSS / NASC',
            service_ids: ['supported_living'],
            personal_income: false,
            enabled: true,
        },
    ],
    intake: { team: 'Central intake' },
    services: [
        { id: 'supported_living', label: 'Supported living', enabled: true },
    ],
} as ReferralConfiguration;
beforeEach(() => {
    vi.clearAllMocks();
});

it('includes reported funding context in a simulation without treating it as approval', async () => {
    post.mockResolvedValue({
        data: {
            result: {
                reason: 'Fictional navigator matched.',
                team: 'Navigation',
                owner_id: 4,
            },
        },
    });
    render(
        <SettingsRouting
            value={configuration}
            onChange={vi.fn()}
            disabled
            staff={[{ id: 4, name: 'Fictional owner' }]}
            sites={[]}
        />,
    );
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Example funding situation' }),
    );
    fireEvent.click(
        await screen.findByRole('option', {
            name: 'Need help getting funding',
        }),
    );
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Example reported pathway' }),
    );
    fireEvent.click(await screen.findByRole('option', { name: 'DSS / NASC' }));
    fireEvent.click(screen.getByRole('button', { name: 'Preview routing' }));
    await screen.findByText('Fictional navigator matched.');
    expect(post).toHaveBeenCalledWith(
        '/settings/referrals/routing-preview',
        expect.objectContaining({
            funding_situation: 'need_help',
            funding_pathway_id: 'dss_nasc',
        }),
    );
});

it('edits fallback ownership and submits a read-only simulation without saving settings', async () => {
    post.mockResolvedValue({
        data: {
            result: {
                reason: 'Used the central-intake fallback.',
                team: 'Central intake',
                owner_id: 4,
            },
        },
    });
    function Harness() {
        const [value, setValue] = useState(configuration);
        return (
            <SettingsRouting
                value={value}
                onChange={setValue}
                disabled={false}
                staff={[{ id: 4, name: 'Fictional owner' }]}
                sites={[]}
            />
        );
    }
    render(<Harness />);
    fireEvent.click(
        screen.getByRole('switch', {
            name: 'Automatically assign website enquiries',
        }),
    );
    fireEvent.click(screen.getByRole('checkbox', { name: 'Fictional owner' }));
    fireEvent.click(screen.getByRole('button', { name: 'Preview routing' }));
    await screen.findByText('Used the central-intake fallback.');
    expect(post).toHaveBeenCalledWith(
        '/settings/referrals/routing-preview',
        expect.objectContaining({
            configuration: expect.objectContaining({
                routing: expect.objectContaining({
                    enabled: true,
                    fallback_owner_ids: [4],
                }),
            }),
        }),
    );
    expect(post).toHaveBeenCalledTimes(1);
});

it('keeps saved settings read-only and reports a failed simulation without an invented result', async () => {
    post.mockRejectedValue(new Error('offline'));
    const change = vi.fn();
    render(
        <SettingsRouting
            value={configuration}
            onChange={change}
            disabled
            staff={[]}
            sites={[]}
        />,
    );
    expect(
        screen.getByRole('switch', {
            name: 'Automatically assign website enquiries',
        }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Preview routing' }));
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Routing could not be checked',
        ),
    );
    expect(change).not.toHaveBeenCalled();
    expect(
        screen.queryByText(/Manual assignment needed/),
    ).not.toBeInTheDocument();
});
