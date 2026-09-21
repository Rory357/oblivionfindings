import type { Coordinate } from './types';

type AddressPoint = Coordinate & {
    address?: string | null;
    display_location?: string | null;
    address_source?: 'recorded' | 'nearest' | null;
};

// Older responses put coordinates in display_location. Keep that fallback out
// of the address heading, without mistaking street numbers for coordinates.
export function recordedAddress(point: AddressPoint): string | null {
    const value = point.address?.trim() || point.display_location?.trim();
    return value && !/^[-+]?\d+(?:\.\d+)?\s*,\s*[-+]?\d+(?:\.\d+)?$/.test(value)
        ? value
        : null;
}

export default function LocationAddress({
    point,
}: {
    point: AddressPoint | null;
}) {
    if (!point) return <strong>No observation available</strong>;
    const address = recordedAddress(point);
    return (
        <div className="location-address">
            <strong className="location-address-label">
                {address || 'Address unavailable'}
            </strong>
            <span
                className="location-address-coordinates"
                aria-label="Coordinates"
            >
                {point.lat.toFixed(6)}, {point.lng.toFixed(6)}
            </span>
            {point.address_source === 'nearest' && address && (
                <span className="location-address-note">
                    Nearest mapped address · approximate
                </span>
            )}
        </div>
    );
}
