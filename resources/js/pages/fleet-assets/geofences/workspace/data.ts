import type {
    Geometry,
    ZoneSchedule,
} from '@/components/client-location/types';
import type { RulePolicy } from './rule-policy';

export type BoundaryRecord = {
    id: number;
    name: string;
    site_id: number | null;
    site: string | null;
    address: string | null;
    geometry: Geometry | null;
    geometry_version: number;
    revision: number;
    uses: string[];
    personal_eligible: boolean;
    retired_at: string | null;
    legacy_monitoring: boolean;
    copy_source: {
        id: number;
        revision: number | null;
        geometry_version: number | null;
        geometry_hash?: string;
    } | null;
    updated_at: string | null;
};
export type Boundary = {
    id: string;
    name: string;
    site: string;
    address: string;
    geometry: Geometry;
    version: number;
    revision: number;
    status: 'Available' | 'Review needed' | 'Retired';
    uses: string[];
    updated: string;
    author: string;
    history: string[];
    raw: BoundaryRecord;
};
export type Rule = {
    id: string;
    resource: string;
    resourceId?: string;
    boundary: string;
};
export type RuleRecord = {
    id: number;
    asset_id: number;
    resource: string;
    boundary_id: number;
    label: string;
    purpose: string;
    response_proposal: string | null;
    schedule: ZoneSchedule | null;
    policy: (RulePolicy & { direction: string; owner: string }) | null;
    revision: number;
    geometry: Geometry;
    monitoring: string;
    source_changed: boolean;
};
export type ResourceRecord = {
    id: number;
    name: string;
    tag: string | null;
    kind: 'Vehicle' | 'Asset';
    site: string | null;
    position: { lat: number; lng: number } | null;
    observed_at: string | null;
    received_at: string | null;
    fresh: boolean;
    accuracy_m: number | null;
    boundary_ids: number[];
    href: string;
};
export type Page<T> = {
    data: T[];
    total: number;
    page: number;
    last_page: number;
};
export type Impact = {
    protected_dependency: boolean;
    geometry_blocked: boolean;
    retirement_blocked: boolean;
    linked_assignments: boolean;
    legacy_monitoring: boolean;
};
export type SiteOption = {
    id: number;
    name: string;
    address_line_1: string | null;
    suburb: string | null;
    city: string | null;
    latitude: number | string | null;
    longitude: number | string | null;
};
export type AddressCapabilities = {
    enabled: boolean;
    autocomplete: boolean;
    attribution: string;
    attribution_url?: string | null;
};
export type HistoryEntry = {
    id: number;
    revision: number;
    geometry_version: number;
    category: string;
    reason: string;
    recorded_at: string;
    actor: { id: number; name: string } | null;
    snapshot: Omit<
        BoundaryRecord,
        'site' | 'personal_eligible' | 'legacy_monitoring' | 'updated_at'
    >;
};
export type EventRecord = {
    id: number;
    resource: string;
    boundary_id: number;
    kind: string;
    observed_at: string | null;
    received_at: string | null;
    delivery: string;
    boundary_version: number | null;
    geometry: Geometry | null;
    follow_up_href: string | null;
};
export function mapBoundary(b: BoundaryRecord): Boundary | null {
    return b.geometry
        ? {
              id: String(b.id),
              name: b.name,
              site: b.site ?? 'Owning resource',
              address: b.address ?? 'Address not recorded',
              geometry: b.geometry,
              version: b.geometry_version,
              revision: b.revision,
              status: b.retired_at ? 'Retired' : 'Available',
              uses: b.uses,
              updated: b.updated_at ?? '',
              author: '',
              history: [],
              raw: b,
          }
        : null;
}
