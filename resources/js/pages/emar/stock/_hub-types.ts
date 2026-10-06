export type ControlledRegisterRow = {
    id: number;
    medication_id: number;
    medication_name: string | null;
    client_id: number | null;
    client_name: string;
    cd_class: string | null;
    register_balance: number | null;
    on_hand: number | null;
    unit: string;
    last_check_at: string | null;
    last_check_witness: string | null;
    discrepancy: number | null;
};

export type PharmacyOrderRow = {
    id: number;
    medication_id: number | null;
    client_name: string;
    medication_name: string | null;
    controlled: boolean;
    pharmacy_name: string | null;
    order_type: string | null;
    status: string;
    quantity_ordered: number | null;
    quantity_received: number | string | null;
    ordered_at: string | null;
    submitted_at: string | null;
    confirmed_at: string | null;
    dispensed_at: string | null;
    delivered_at: string | null;
    batch_number: string | null;
    batch_expiry: string | null;
};

export const PHARMACY_NEXT_LABEL: Record<string, string> = {
    draft: 'Submit to pharmacy',
    submitted: 'Mark confirmed',
    confirmed: 'Mark dispensed',
    dispensed: 'Receive stock',
};

export type StockFilters = {
    q: string;
    view: string;
    chip: 'all' | 'controlled' | 'cold_chain';
    per_page: number;
    page: number;
    site_id: number | null;
    client_id: number | null;
};

export type StockPagination = {
    current_page: number;
    per_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type StockSummary = {
    total_stock: number;
    low_stock: number;
    expiring: number;
    expired: number;
    controlled: number;
    controlled_discrepancies: number;
    total_orders: number;
    open_orders: number;
    overdue_orders: number;
};
