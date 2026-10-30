import type { PreviewFile } from '@/components/files/file-preview-dialog';

export type AssetFile = PreviewFile & {
    id: number | string;
    sourceOwned?: boolean;
    sourceUrl?: string;
    category: string;
    set_id: number | null;
    set_version: number;
    current: boolean;
    state: string;
    added_at: string;
    added_by: string | null;
    expiry_date: string | null;
    reason: string | null;
};
export type KitItem = {
    id: number;
    name: string;
    component_id: number | null;
    added_at: string;
    removed_at: string | null;
    reason: string | null;
};
export type Movement = {
    id: number;
    kind: string;
    state: string;
    origin: string;
    origin_site_id: number | null;
    destination: string;
    destination_site_id: number | null;
    room: string | null;
    recipient: string | null;
    received_by?: string | null;
    dispatched_at: string;
    received_at: string | null;
    returned_at: string | null;
    return_due_on: string | null;
    reason: string | null;
    receipt_note: string | null;
    kit: { id: number; name: string }[];
    received_kit: number[];
    can_receive: boolean;
};
export type ProfileWorkspace = {
    sources?: {
        ownership: { name: string; type: string | null; since: string | null };
        observations: {
            id: number;
            site: string | null;
            observed_at: string | null;
            received_at: string | null;
            by: string | null;
            source: string;
        }[];
        original_checks: {
            id: number;
            name: string;
            at: string | null;
            by: string | null;
            outcome: string;
            kind: string | null;
            rule_version: number | null;
            corrects_id: number | null;
            url: string;
        }[];
        source_files: AssetFile[];
        costs: {
            allowed: boolean;
            from: string;
            to: string;
            totals: { currency: string; amount: string }[];
            entries: {
                id: number;
                date: string;
                amount: string;
                currency: string;
                reference: string;
                url: string;
            }[];
            bills: {
                id: number;
                reference: string;
                supplier: string | null;
                status: string;
                amount: string;
                date: string;
                url: string;
            }[];
        };
    };
    photo_url: string | null;
    history_next: number | null;
    ready: boolean;
    version: number;
    condition: string | null;
    permissions: Record<
        | 'update'
        | 'manageDocuments'
        | 'manageAssignments'
        | 'recordInspection'
        | 'recordScan'
        | 'delete'
        | 'report'
        | 'maintenance',
        boolean
    > & { assess: boolean; finance_review: boolean; manageOwnership?: boolean };
    finance_reviews: {
        id: number;
        reference: string;
        type: string;
        status: string;
        note: string;
        amount: string | null;
        by: string | null;
        at: string;
        decision: string | null;
        url: string;
    }[];
    finance_sources: { value: string; label: string }[];
    finance_types: { value: string; label: string }[];
    documents: AssetFile[];
    movements: Movement[];
    kit: KitItem[];
    events: {
        id: number;
        action: string;
        actor: string | null;
        at: string;
        message: string;
        reason: string | null;
    }[];
    manual_location: { location: string; observed_at: string } | null;
    retirement_blockers: string[];
    work: {
        id: number;
        reference: string;
        title: string;
        status: string;
        priority: string;
        owner: string | null;
        next_action: string | null;
        due_at: string | null;
        url: string;
    }[];
    checks: {
        id: number;
        at: string;
        by: string | null;
        result: string;
        notes: string | null;
        due: string | null;
    }[];
    qr: { image: string; svg: string; download: string; label: string } | null;
    room: string | null;
};
export type Option = { id: number | string; name: string };
export type ProfileAction =
    | 'ownership'
    | 'dispatch'
    | 'receive'
    | 'return'
    | 'cancel_movement'
    | 'exception'
    | 'verify_location'
    | 'kit_add'
    | 'kit_remove'
    | 'retire'
    | 'assign'
    | 'confirm_assignment_receipt'
    | 'set_photo'
    | 'remove_photo'
    | 'generate_qr'
    | 'release';
