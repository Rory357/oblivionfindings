import type { Coordinate } from '@/components/client-location/types';
import type { ResourceRecord } from './data';
export type MapResource = {
    id: string;
    name: string;
    tag: string;
    kind: 'Vehicle' | 'Asset';
    site: string;
    position: Coordinate | null;
    observed: string | null;
    received: string | null;
    accuracy: number | null;
    fresh: boolean;
    source: string;
    unavailable?: string;
    boundaries: string[];
    href: string;
};
export function mapResource(r: ResourceRecord): MapResource {
    return {
        id: String(r.id),
        name: r.name,
        tag: r.tag ?? 'No tag',
        kind: r.kind,
        site: r.site ?? 'Site not recorded',
        position: r.position,
        observed: r.observed_at,
        received: r.received_at,
        accuracy: r.accuracy_m,
        fresh: r.fresh,
        source: 'Permitted recorded source',
        boundaries: r.boundary_ids.map(String),
        href: r.href,
    };
}
