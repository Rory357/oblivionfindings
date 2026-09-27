import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BoundaryWizard } from './boundary-wizard';
import type { BoundaryRecord, SiteOption } from './data';

const site: SiteOption = {
    id: 1,
    name: 'Test site',
    address_line_1: '1 Test Road',
    suburb: 'Central',
    city: 'Wellington',
    latitude: -41.28,
    longitude: 174.77,
};
vi.mock('./remote-picker', () => ({
    RemotePicker: ({ onSelect }: { onSelect: (s: SiteOption) => void }) => (
        <button onClick={() => onSelect(site)}>Select test site</button>
    ),
}));
vi.mock('./map', () => ({ BoundaryMap: () => null }));
const props = {
    boundaries: [],
    capabilities: { enabled: false, autocomplete: false, attribution: '' },
    onClose: vi.fn(),
    onSaved: vi.fn(),
    onView: vi.fn(),
};
afterEach(cleanup);

describe('new boundary location defaults', () => {
    it('does not treat a copied boundary address as loaded Site details', () => {
        const existing: BoundaryRecord = {
            id: 9,
            name: 'Remote pickup',
            site_id: site.id,
            site: site.name,
            address: 'Remote pickup entrance',
            geometry: {
                type: 'circle',
                center: { lat: -40.1, lng: 175.1 },
                radius_m: 90,
            },
            geometry_version: 1,
            revision: 1,
            uses: ['Vehicles'],
            personal_eligible: false,
            retired_at: null,
            legacy_monitoring: false,
            copy_source: null,
            updated_at: null,
        };
        render(<BoundaryWizard {...props} existing={existing} copy />);
        expect(
            screen.queryByRole('button', { name: 'Use site address' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('This site has no saved map position'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Select test site' }),
        );
        expect(
            screen.getByLabelText(/Address or place description/),
        ).toHaveValue('Remote pickup entrance');
        expect(screen.getByLabelText('Latitude')).toHaveValue(-40.1);
        expect(
            screen.getByRole('button', { name: 'Use site address' }),
        ).toBeInTheDocument();
    });
    it('fills the saved address and coordinates when choosing a site for an untouched location', async () => {
        render(<BoundaryWizard {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Select test site' }),
        );
        await waitFor(() =>
            expect(
                screen.getByLabelText(/Address or place description/),
            ).toHaveValue('1 Test Road, Central, Wellington'),
        );
        expect(screen.getByLabelText('Latitude')).toHaveValue(-41.28);
        expect(screen.getByLabelText('Longitude')).toHaveValue(174.77);
    });

    it('retains a manually entered location when choosing its owning site', () => {
        render(<BoundaryWizard {...props} />);
        fireEvent.change(
            screen.getByLabelText(/Address or place description/),
            { target: { value: 'Community garden entrance' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Select test site' }),
        );
        expect(
            screen.getByLabelText(/Address or place description/),
        ).toHaveValue('Community garden entrance');
        expect(screen.getByLabelText('Latitude')).toHaveValue(-41.29);
        fireEvent.click(
            screen.getByRole('button', { name: 'Use site address' }),
        );
        expect(
            screen.getByLabelText(/Address or place description/),
        ).toHaveValue('1 Test Road, Central, Wellington');
        expect(screen.getByLabelText('Latitude')).toHaveValue(-41.28);
    });

    it('retains a point chosen on the map when the owning site is selected', () => {
        render(
            <BoundaryWizard {...props} point={{ lat: -40.1, lng: 175.1 }} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Select test site' }),
        );
        expect(screen.getByLabelText('Latitude')).toHaveValue(-40.1);
        expect(screen.getByLabelText('Longitude')).toHaveValue(175.1);
    });

    it('prefills an incoming site address even if the site needs map verification', () => {
        render(
            <BoundaryWizard
                {...props}
                initialSite={{ ...site, latitude: null, longitude: null }}
            />,
        );
        expect(
            screen.getByLabelText(/Address or place description/),
        ).toHaveValue('1 Test Road, Central, Wellington');
        expect(
            screen.getByText('This site has no saved map position'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save boundary' }),
        ).not.toBeInTheDocument();
    });
});
