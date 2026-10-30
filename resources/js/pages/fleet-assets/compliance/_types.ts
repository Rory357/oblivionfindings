import type {
    PageLinks,
    QueueFilters,
} from '@/components/fleet-assets/queue-kit';
import type {
    ComplianceRecord,
    VehicleWorkspace,
} from '@/components/fleet-assets/vehicle-workspace/types';
export type QueueRow = {
    can_plan: boolean;
    id: string;
    kind: string;
    label: string;
    state: string;
    action: string;
    vehicle: {
        id: number;
        name: string;
        asset_tag: string;
        registration_number: string | null;
        site: { id: number; name: string } | null;
        responsible: string | null;
    };
    applicability: string;
    basis: string | null;
    reason: string;
    reference: string | null;
    version: number | null;
    version_id: number | null;
    record_id: number | null;
    recorded_by: string | null;
    recorded_at: string | null;
    assessed_at: string;
    expires_on: string | null;
    effective_on: string | null;
    ruc_start_km: number | null;
    ruc_end_km: number | null;
    odometer_km: number | null;
};
export type EvidenceContext = Pick<
    VehicleWorkspace,
    'vehicle' | 'compliance' | 'can' | 'catalogues'
>;
export type OpenEvidence = {
    key: string;
    mode: 'evidence' | 'plan' | 'source';
    context: EvidenceContext;
    record: ComplianceRecord;
    hidden: boolean;
};
export type Props = {
    queue: {
        data: QueueRow[];
        total: number;
        from: number | null;
        to: number | null;
        links: PageLinks;
    };
    summary: {
        vehicles: number;
        attention: number;
        not_recorded: number;
        due_soon: number;
        failed_restricted: number;
    };
    filters: QueueFilters;
    sites: Array<{ id: number; name: string }>;
    can: { manage: boolean };
};
export const stateLabels: Record<string, string> = {
    not_recorded: 'Not recorded',
    needs_assessment: 'Needs assessment',
    not_applicable: 'Not applicable',
    current: 'Current evidence',
    recorded: 'Date recorded',
    due_soon: 'Due soon',
    expired: 'Expired / exhausted',
    failed: 'Failed',
    restricted: 'Restricted',
    failed_restricted: 'Failed / restricted',
};
export const kindLabels: Record<string, string> = {
    wof: 'WoF',
    registration: 'Registration',
    cof: 'CoF',
    ruc: 'RUC',
    insurance: 'Insurance context',
    restriction: 'Vehicle restrictions',
};
export const stateTone = (state: string) =>
    ['failed', 'expired', 'restricted'].includes(state)
        ? ('critical' as const)
        : ['due_soon', 'not_recorded', 'needs_assessment'].includes(state)
          ? ('warning' as const)
          : state === 'current'
            ? ('success' as const)
            : ('neutral' as const);
