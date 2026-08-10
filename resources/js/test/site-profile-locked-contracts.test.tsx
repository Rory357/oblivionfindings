import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SiteProfileAssets } from '@/pages/sites/tabs/assets';
import { SiteProfileChecklists } from '@/pages/sites/tabs/checklists';
import { SiteProfileInspections } from '@/pages/sites/tabs/inspections';

describe('Site Profile locked deferred payloads', () => {
    it('renders the Checklists locked state without requiring workspace data', () => {
        render(
            <SiteProfileChecklists
                data={{
                    locked: true,
                    site: { id: 17, name: 'Restricted Site', type: 'house' },
                    backHref: '/sites/17',
                }}
            />,
        );

        expect(screen.getByText('Checklists is restricted')).toBeTruthy();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('renders the Inspections locked state before reading protected registers', () => {
        render(
            <SiteProfileInspections
                data={{
                    locked: true,
                    items: [],
                    summary: null,
                    href: null,
                }}
            />,
        );

        expect(screen.getByText('Site inspections is restricted')).toBeTruthy();
        expect(screen.queryByText('Inspection schedules')).toBeNull();
    });

    it('renders the Assets locked state without creating a null destination link', () => {
        render(
            <SiteProfileAssets
                data={{
                    locked: true,
                    items: [],
                    can_create: false,
                    href: null,
                }}
            />,
        );

        expect(screen.getByText('Site assets is restricted')).toBeTruthy();
        expect(screen.queryByRole('link')).toBeNull();
    });
});
