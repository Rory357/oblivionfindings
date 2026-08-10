import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    AssetFinanceTechnologyProjectionPanel,
    type AssetFinanceTechnologyProjection,
} from './asset-finance-technology-projection';

const projection: AssetFinanceTechnologyProjection = {
    boundary: {
        title: 'One asset, three clear owners',
        description:
            'Fleet & Assets owns operational status. Finance owns disposal. Security & Devices owns installed technology.',
        management: 'Resolve each mismatch in its owning module.',
    },
    reconciliation: {
        state: 'operational_retirement_required',
        title: 'Financial disposal recorded; operational follow-up remains',
        description:
            'Finance has disposed the value, but the Asset is still operational.',
        tone: 'critical',
        attention: true,
        actions: [
            {
                owner: 'Fleet & Assets',
                label: 'Open operational Asset',
                href: '/fleet-assets/assets/44',
            },
        ],
    },
    operational_asset: {
        id: 44,
        name: 'Community gateway cabinet',
        asset_tag: 'AST-44',
        category: 'Equipment',
        status: 'active',
        site: 'Harbour House',
        active_assignments: 1,
        href: '/fleet-assets/assets/44',
    },
    finance: {
        id: 12,
        name: 'Gateway cabinet capital asset',
        asset_tag: 'FA-12',
        category: 'equipment',
        status: 'disposed',
        purchase_date: '2025-04-01',
        purchase_cost: 2000,
        accumulated_depreciation: 500,
        book_value: 1500,
        disposed_date: '2026-07-24',
        disposal_proceeds: 900,
        capitalised: true,
        href: '/finance/fixed-assets/12',
    },
    technology: {
        devices: [
            {
                id: 7,
                device_uid: 'DEV-007',
                name: 'Aggregation switch',
                domain: 'network_it',
                category: 'switch',
                provider: 'unifi',
                status: 'active',
                health: 'healthy',
                battery: null,
                last_seen_at: '2026-07-24T01:00:00Z',
                link_type: 'installed_in',
                linked_at: '2025-04-01T00:00:00Z',
                href: '/security-devices/devices/7',
            },
        ],
        truncated: false,
    },
    permissions: {
        operational_asset: true,
        finance: true,
        technology: true,
    },
    links: {
        assets: '/fleet-assets/assets/44',
        finance: '/finance/fixed-assets',
        devices: '/security-devices/devices',
    },
};

describe('AssetFinanceTechnologyProjectionPanel', () => {
    it('keeps operational, financial, and technology ownership distinct', () => {
        render(
            <AssetFinanceTechnologyProjectionPanel projection={projection} />,
        );

        expect(screen.getByText('One asset, three clear owners')).toBeVisible();
        expect(
            screen.getByText(
                'Financial disposal recorded; operational follow-up remains',
            ),
        ).toBeVisible();
        expect(screen.getByText('Fleet & Assets')).toBeVisible();
        expect(screen.getByText('Finance')).toBeVisible();
        expect(screen.getByText('Security & Devices')).toBeVisible();
        expect(screen.getByText('Aggregation switch')).toBeVisible();
        expect(
            screen.getByRole('link', { name: /Open operational Asset/i }),
        ).toHaveAttribute('href', '/fleet-assets/assets/44');
    });

    it('explains source restrictions without inventing hidden records', () => {
        render(
            <AssetFinanceTechnologyProjectionPanel
                projection={{
                    ...projection,
                    reconciliation: {
                        state: 'operational_source_restricted',
                        title: 'Operational reconciliation is access restricted',
                        description:
                            'The linked Site and Asset are not available to this user.',
                        tone: 'neutral',
                        attention: false,
                        actions: [],
                    },
                    operational_asset: null,
                    technology: null,
                    permissions: {
                        operational_asset: false,
                        finance: true,
                        technology: false,
                    },
                    links: {
                        assets: null,
                        finance: '/finance/fixed-assets',
                        devices: null,
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Operational reconciliation is access restricted'),
        ).toBeVisible();
        expect(
            screen.getByText('Fleet & Assets access required.'),
        ).toBeVisible();
        expect(
            screen.getByText('Security & Devices access required.'),
        ).toBeVisible();
        expect(
            screen.queryByText('Community gateway cabinet'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Aggregation switch'),
        ).not.toBeInTheDocument();
    });
});
