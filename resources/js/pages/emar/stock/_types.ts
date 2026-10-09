export type Pager<T> = {
    data: T[];
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
};
export type StockItem = {
    id: number;
    client_id: number;
    site_id: number | null;
    name: string;
    client_name: string;
    site_name: string | null;
    controlled: boolean;
    active: boolean;
    /** EA-073: active or held (a held medicine can still be received and reordered). */
    receivable?: boolean;
    held?: boolean;
    stock_id: number | null;
    on_hand: number | null;
    physical_on_hand?: number | string | null;
    usable_on_hand?: number | string | null;
    unit: string | null;
    reorder_level: number | null;
    last_counted_at: string | null;
    lots_started: boolean;
    pack_count: number;
    next_batch: string | null;
    next_expiry: string | null;
    expired_packs: number;
    state: string;
    days_supply: number | null;
};
export type PackPhoto = {
    id: number;
    original_name: string;
    mime: string;
    bytes: number;
    taken_at: string;
    description: string | null;
    /** C11: taken because the pack or brand looked different. */
    pack_changed?: boolean;
    taken_by_name: string | null;
    preview_url: string;
    download_url: string;
};
export type Pack = {
    id: number;
    batch_number: string | null;
    batch_not_printed: boolean;
    expiry_date: string | null;
    expiry_not_printed: boolean;
    quantity_received: string;
    quantity_remaining: string;
    state: string;
    source: string;
    source_reference: string | null;
    received_at: string;
    short_expiry_reason: string | null;
    notes: string | null;
    revision: number;
    /** C11: "Batch AB12 · Expires 05/2027" and who supplied it. */
    label?: string | null;
    supplier?: string | null;
    photos: PackPhoto[];
};
export type ItemDetail = StockItem & {
    witnesses?: import('@/components/emar/controlled/product-types').ControlledWitness[];
    current_user_id?: number;
    movements?: Movement[];
    orders?: SupplyOrder[];
    history_truncated?: boolean;
    /** C11 (P06 Q6, P11 Medicine photos). */
    photo_policy?: PhotoPolicy;
    latest_photo?: LatestPackPhoto | null;
    packs: Pack[];
    outward: {
        id: number;
        quantity: string;
        medication_stock_lot_id: number;
        recorded_at: string;
        reason: string;
    }[];
};
export type SupplyOrder = {
    id: number;
    client_medication_id: number;
    medication_name: string;
    client_name: string;
    pharmacy_name: string;
    status: string;
    controlled: boolean;
    quantity_ordered: number;
    quantity_received: string | null;
    quantity_dispensed: string | null;
    needed_by: string | null;
    expected_delivery: string | null;
    order_notes: string | null;
    communication_method: string | null;
    communication_reference: string | null;
    communication_recorded_at: string | null;
    closure_reason: string | null;
    batch_number: string | null;
    batch_expiry: string | null;
};
export type StockCount = {
    id: number;
    medication_id: number;
    medication_name: string;
    client_name: string;
    state: string;
    counted_at: string;
    counted_by_name: string | null;
    reason: string | null;
    review_reason: string | null;
    lines: {
        lot_id: number;
        expected: string;
        counted: string;
        revision: number;
    }[];
};
export type Movement = {
    id: number;
    medication_id: number;
    medication_name: string;
    client_name: string;
    kind: string;
    quantity: string;
    balance_before: string;
    balance_after: string;
    reason: string;
    notes: string | null;
    recorded_at: string;
    recorded_by_name: string | null;
};
export type Capabilities = {
    /** C11: the Medicine photos "who" setting. */
    photograph?: boolean;
    receive: boolean;
    receive_controlled?: boolean;
    manage: boolean;
    controlled: boolean;
};
export type StockResponse = {
    success: true;
    lot_id?: number;
    lot_ids?: number[];
    quantity_received?: string;
    order_id?: number;
    count_id?: number;
    movement_id?: number;
    stock_id?: number;
    photo_id?: number;
};
/** C11: whether this receipt asks for a photo, and who may take one. */
export type PhotoPolicy = {
    can_photograph: boolean;
    mode: 'off' | 'needed' | 'always';
    prompt: boolean;
    required: boolean;
    has_photo: boolean;
};
/** C11: the picture staff see for a medicine (staff photo first, else a reference image). */
export type LatestPackPhoto = {
    kind: 'staff' | 'reference';
    url: string;
    taken_at: string | null;
    taken_by: string | null;
    pack: string | null;
    pharmacy: string | null;
    pack_changed?: boolean;
    now_pack?: string | null;
    source?: string | null;
};
