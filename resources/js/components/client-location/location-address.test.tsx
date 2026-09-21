import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import LocationAddress from './location-address';

it('never promotes legacy coordinate strings to an address or hides the measured position', () => {
    const point = {
        lat: -36.8497,
        lng: 174.76,
        display_location: '-36.849700, 174.760000',
    };
    const view = render(<LocationAddress point={point} />);
    expect(screen.getByText('Address unavailable')).toBeVisible();
    expect(screen.getByLabelText('Coordinates')).toHaveTextContent(
        point.display_location,
    );
    view.rerender(
        <LocationAddress
            point={{
                ...point,
                address: '12 Example Street',
                address_source: 'nearest',
            }}
        />,
    );
    expect(screen.getByText('12 Example Street')).toBeVisible();
    expect(
        screen.getByText('Nearest mapped address · approximate'),
    ).toBeVisible();
    expect(screen.getByLabelText('Coordinates')).toHaveTextContent(
        point.display_location,
    );
});
