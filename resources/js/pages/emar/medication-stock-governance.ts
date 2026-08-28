export type ControlledMedicationLike = {
    controlled?: boolean;
};

export type PharmacyOrderActionLike = {
    controlled: boolean;
    status: string;
};

export function genericStockMedications<T extends ControlledMedicationLike>(
    medications: T[],
): T[] {
    return medications.filter((medication) => !medication.controlled);
}

export function pharmacyOrderAdvanceAction(
    order: PharmacyOrderActionLike,
): 'advance' | 'controlled-delivery' {
    return order.controlled && order.status === 'dispensed'
        ? 'controlled-delivery'
        : 'advance';
}

export function stockItemQuantityDestination(
    item: ControlledMedicationLike,
): 'controlled-balance-check' | 'generic-adjust' {
    return item.controlled ? 'controlled-balance-check' : 'generic-adjust';
}

export function controlledPharmacyDeliveryPath(orderId: number): string {
    return `/emar/stock/pharmacy-orders/${orderId}/controlled-delivery`;
}

function hundredths(value: string): number | null {
    const match = value.trim().match(/^(\d+)(?:\.(\d{1,2}))?$/);
    if (!match) return null;

    const whole = Number(match[1]);
    const fraction = Number((match[2] ?? '').padEnd(2, '0'));
    if (!Number.isSafeInteger(whole) || !Number.isSafeInteger(fraction))
        return null;

    const scaled = whole * 100 + fraction;
    return Number.isSafeInteger(scaled) ? scaled : null;
}

function formatHundredths(value: number): string {
    return `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`;
}

export function normalizeMedicationStockQuantityInput(
    value: string,
): string | null {
    const scaled = hundredths(value);

    return scaled === null ? null : formatHundredths(scaled);
}

export function addMedicationStockQuantities(
    left: string,
    right: string,
): string {
    const leftHundredths = hundredths(left);
    const rightHundredths = hundredths(right);
    if (leftHundredths === null || rightHundredths === null) return '';

    const total = leftHundredths + rightHundredths;
    return Number.isSafeInteger(total) ? formatHundredths(total) : '';
}

export function subtractMedicationStockQuantities(
    left: string,
    right: string,
): string {
    const leftHundredths = hundredths(left);
    const rightHundredths = hundredths(right);
    if (leftHundredths === null || rightHundredths === null) return '';

    const difference = leftHundredths - rightHundredths;
    return difference >= 0 && Number.isSafeInteger(difference)
        ? formatHundredths(difference)
        : '';
}

export function medicationStockQuantitiesEqual(
    left: string,
    right: string,
): boolean {
    const leftHundredths = hundredths(left);
    const rightHundredths = hundredths(right);

    return (
        leftHundredths !== null &&
        rightHundredths !== null &&
        leftHundredths === rightHundredths
    );
}

export function buildControlledPharmacyDeliveryRequest(input: {
    clientMedicationId: number;
    quantityReceived: string;
    onHandBefore: string;
    onHandAfter: string;
    witnessedBy: string;
    witnessCredential: string;
    deliveryNotes: string;
    uuid: string;
}) {
    return {
        client_medication_id: input.clientMedicationId,
        quantity_received: input.quantityReceived,
        on_hand_before: input.onHandBefore,
        on_hand_after: input.onHandAfter,
        witnessed_by: Number(input.witnessedBy),
        witness_credential: input.witnessCredential,
        delivery_notes: input.deliveryNotes || null,
        client_request_uuid: input.uuid,
    };
}
