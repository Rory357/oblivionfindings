import type { PpeCommand, PpeRecordKind } from '@/lib/health-safety/ppe-navigation';
import type { AllocationDetail, AllocationRow, ItemDetail, PpeAttachment, Ref } from './ppe-shared';

export type PpePage = { current_page: number; last_page: number; total: number };
export type PpeCommandContext = {
    expected_version: number; source_schema_version: number; root_kind: 'inventory'; root_id: number;
    source_kind: PpeRecordKind; source_id: number; site_id: number; user_id: number | null;
};
export type PpeRecordControls = {
    command_context: PpeCommandContext;
    can: Partial<Record<'manage' | 'allocate' | 'inspect' | 'condemn' | 'dispose' | 'return' | 'acknowledge' | 'acknowledge_own' | 'evidence' | 'release' | 'hold', boolean>>;
    blocked_reasons?: Partial<Record<PpeCommand | 'release' | 'hold', string>>;
    pages?: Partial<Record<'allocations' | 'inspections' | 'attachments' | 'history', PpePage>>;
    source_integrity?: 'intact' | 'legacy_unknown' | 'repair_required';
    child_integrity?: { allocations: 'readable' | 'repair_required'; inspections: 'readable' | 'repair_required' };
    history_scope?: string;
    command_history_integrity?: 'readable' | 'repair_required';
    command_history?: { id: number; command: string; version: number; recorded_at: string; actor: Ref | null }[];
    href: string;
};
export type PpeItem = ItemDetail & PpeRecordControls & {
    active_allocation_state: 'recorded' | 'none' | 'ambiguous';
    currency: { expiry_state: 'unknown' | 'expired' | 'in_date'; inspection_state: 'unknown' | 'overdue' | 'scheduled';
        inventory_complete: null; fit_test_current: null; training_current: null; fitness_for_work: null; release_authorized: null };
};
export type PpeAllocation = (AllocationDetail | (AllocationRow & { returned_at: string | null })) & PpeRecordControls & {
    read_scope: 'own_allocation' | 'site_operational'; fit_test_current: null; training_current: null; catalogue_integrity?: 'recorded' | 'legacy_unknown' | 'repair_required';
};
export type PpeInspection = PpeRecordControls & {
    id: number; ppe_inventory_id: number; result: string; condition_after: string | null; findings: string | null;
    action_taken: string | null; inspected_at: string | null; next_inspection_due: string | null;
    inspector: Ref | null; attachments: PpeAttachment[]; inventory_href: string; release_authorized: null;
};
export type PpeRecord = { kind: 'inventory'; detail: PpeItem } | { kind: 'allocations'; detail: PpeAllocation } | { kind: 'inspections'; detail: PpeInspection };
export type PpeRepairRecord = { kind: PpeRecordKind; detail: { id: number; href: string; read_scope: 'source_repair_required';
    attribution_state: 'repair_required'; source_integrity: 'repair_required'; command_context: null; can: Record<string, false>;
    allocations: null; inspections: null; attachments: null; history: null; command_history: null; pages: null } };
export type PpeReceipt = {
    accepted: true; actor_id: number; command: string; idempotency_key: string; root_type: 'inventory' | 'type'; root_id: number;
    source_type: string; source_id: number; expected_version: number | null; version: number; source_version_id: number | null;
};
