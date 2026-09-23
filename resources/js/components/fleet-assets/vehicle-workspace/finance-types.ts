/** Server DTOs for the vehicle Overview › Finance view (VehicleFinancePresenter). */

import type { StatusVariant } from '@/components/ui/status-badge';

export type FinanceRecordType = 'fixed_asset' | 'purchase_order' | 'bill';

export type FinanceRequestType =
    | 'supplier_invoice_review'
    | 'purchase_approval'
    | 'fixed_asset_update'
    | 'cost_allocation_correction';

export type FinanceRequestStatus = 'submitted' | 'resolved' | 'declined';

export type FinanceFile = {
    id: number;
    name: string;
    state: string | null;
    /** Only present when the viewer may open the file. */
    url: string | null;
    /** Stored privately and still waiting for its virus check. */
    waiting: boolean;
};

export type FinanceLinkedRecord = {
    key: string;
    type: FinanceRecordType;
    id: number;
    kind: string;
    name: string;
    reference: string | null;
    status: string | null;
    status_label: string;
    tone: StatusVariant;
    /** NZD including GST; null when restricted or unavailable. */
    amount: number | null;
    /** YYYY-MM-DD */
    date: string | null;
    owner: string;
    workspace: string;
    detail: string | null;
    basis_note: string | null;
    /** Only present when the viewer may open the Finance page. */
    href: string | null;
    /** Accounts payable details need finance.ap.view. */
    restricted: boolean;
    /** False when the linked record is no longer in Finance. */
    available: boolean;
    /** Who connected it: Finance itself, this vehicle, or both. */
    basis: 'finance' | 'vehicle' | 'both';
    link_id: number | null;
    can_unlink: boolean;
    files: FinanceFile[];
};

export type FinanceRequestEvent = {
    id: number;
    action: string;
    label: string;
    actor: string | null;
    note: string | null;
    occurred_at: string | null;
};

export type FinanceReviewRequest = {
    id: number;
    reference: string | null;
    type: FinanceRequestType;
    type_label: string;
    source: { type: string; id: number | null; label: string };
    amount: number | null;
    note: string;
    status: FinanceRequestStatus;
    status_label: string;
    tone: StatusVariant;
    requested_by: string | null;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    lock_version: number;
    history: FinanceRequestEvent[];
    files: FinanceFile[];
    can_decide: boolean;
    can_add_files: boolean;
};

export type FinanceChoice = { value: string; label: string; detail: string };

export type FinanceFixedAssetLink = {
    id: number;
    label: string;
    link_id: number | null;
};

export type VehicleFinanceWorkspace = {
    can: {
        view: boolean;
        view_spend: boolean;
        link: boolean;
        link_fixed_asset: boolean;
        link_spend: boolean;
        request_review: boolean;
        decide: boolean;
        attach_files: boolean;
        open_documents: boolean;
    };
    fixed_asset: { id: number; label: string; count: number } | null;
    cost_centre: {
        id: number;
        code: string;
        name: string;
        active: boolean;
    } | null;
    pending_requests: number;
    records: FinanceLinkedRecord[];
    records_total: number;
    requests: FinanceReviewRequest[];
    request_types: Array<{ value: FinanceRequestType; label: string }>;
    sources: FinanceChoice[];
    documents: Array<{ id: number; name: string; detail: string }>;
    link_state: {
        /** Linked by Finance itself; locked on the vehicle. */
        finance_fixed_asset: FinanceFixedAssetLink | null;
        /** Linked from the vehicle; can be replaced or removed. */
        vehicle_fixed_asset: FinanceFixedAssetLink | null;
    };
    as_of: string;
};

/** A linkable Finance record returned by the scoped search. */
export type LinkableFinanceRecord = {
    type: FinanceRecordType;
    id: number;
    name: string;
    reference: string | null;
    status_label: string;
    detail: string;
};

export type LinkableFinancePage = {
    results: LinkableFinanceRecord[];
    next_before: number | null;
    scope: 'recent' | 'search' | 'site_cost_centre' | 'none';
};
