import type {
    ControlledAction,
    ControlledActionValues,
    ControlledMedicine,
} from './product-types';
import type { WitnessValue } from './product-ui';

/** Build only canonical fields for the selected server action, never stale fields from another wizard step. */
export function buildControlledActionValues(input: {
    action: ControlledAction;
    medicine?: ControlledMedicine;
    targetId?: number;
    values: Record<string, string>;
    flags: {
        police: boolean;
        regulator: boolean;
        ready: boolean;
        checked: boolean;
        correct: boolean;
    };
    medicineIds: number[];
    witness: WitnessValue;
    secondWitness: WitnessValue;
    needsWitness: boolean;
    photo: File | null;
}): ControlledActionValues {
    const { action, medicine, targetId, values, flags } = input;
    const result: ControlledActionValues = {
        client_medication_id: medicine?.id ?? null,
        target_id: targetId ?? null,
        expected_entry_id: medicine?.entry_version ?? null,
        expected_balance: medicine?.balance ?? null,
        timezone: 'Pacific/Auckland',
    };
    const copy = (...keys: string[]) =>
        keys.forEach((key) => {
            if (values[key]?.trim()) result[key] = values[key].trim();
        });
    const number = (...keys: string[]) =>
        keys.forEach((key) => {
            if (values[key]?.trim()) result[key] = Number(values[key]);
        });
    const correction = () => {
        result.correction_quantity = flags.correct
            ? Number(values.correction_quantity)
            : null;
        result.correction_direction = flags.correct
            ? values.correction_direction
            : null;
    };
    const notifications = () => {
        result.reported_to_police = flags.police;
        result.reported_to_regulator = flags.regulator;
        if (flags.police) copy('police_reference');
        if (flags.regulator) copy('regulator_reference');
    };
    switch (action) {
        case 'movement':
            copy(
                'movement_type',
                'direction',
                'notes',
                'immediate_action_taken',
            );
            number('quantity', 'actual_balance');
            break;
        case 'void':
            result.notes = [values.reason, values.notes]
                .filter(Boolean)
                .join(' — ');
            correction();
            break;
        case 'resolve':
            copy('outcome', 'notes', 'immediate_action_taken');
            number('quantity', 'entry_id');
            if (values.outcome === 'recount')
                result.actual_balance = Number(values.recount_balance);
            if (values.outcome === 'recording') correction();
            if (values.outcome === 'loss') {
                result.notes = [values.circumstances, values.notes]
                    .filter(Boolean)
                    .join('\n');
                notifications();
            }
            break;
        case 'loss_report':
            result.notes = values.circumstances?.trim() ?? '';
            copy('immediate_action_taken', 'discovered_at');
            number('quantity');
            notifications();
            break;
        case 'loss_note':
            copy('notes');
            result.ready_to_close = flags.ready;
            break;
        case 'loss_notify':
            copy('authority', 'reference', 'notified_at', 'notes');
            break;
        case 'loss_close':
            result.notes = values.resolution_notes?.trim() ?? '';
            result.resolution_outcome = values.outcome;
            result.notifications_checked = flags.checked;
            result.suspected_theft = values.outcome === 'theft';
            break;
        case 'destruction':
            copy('reason', 'method', 'notes');
            number('quantity');
            if (input.photo) result.photo = input.photo;
            break;
        case 'destruction_receipt':
            copy(
                'pharmacist_name',
                'pharmacist_registration',
                'received_at',
                'notes',
            );
            break;
        case 'destruction_void':
            result.notes = [values.reason, values.notes]
                .filter(Boolean)
                .join(' — ');
            break;
        case 'class_review':
            copy('nz_class', 'source');
            result.notes = values.review_notes?.trim() ?? '';
            break;
        case 'witness_request':
            result.witness_id = Number(values.witnessed_by);
            result.notes = values.reason?.trim() ?? '';
            result.purpose = values.purpose || 'count';
            break;
        case 'witness_answer':
            copy('response');
            result.notes =
                values.response === 'cant_come'
                    ? (values.reason?.trim() ?? '')
                    : 'On my way';
            break;
        case 'override_request':
            result.notes = [values.reason, values.notes]
                .filter(Boolean)
                .join('\n');
            result.medicine_ids = input.medicineIds;
            break;
        case 'override_decide':
            copy('decision', 'notes');
            if (values.decision === 'approved') {
                copy('starts_at', 'expires_at');
                result.medicine_ids = input.medicineIds;
            }
            break;
        default:
            break;
    }
    if (input.needsWitness) {
        result.witnessed_by = Number(input.witness.id);
        result.witness_credential = input.witness.pin;
    }
    if (action === 'destruction' && values.method === 'denaturing') {
        result.second_witness_id = Number(input.secondWitness.id);
        result.second_witness_credential = input.secondWitness.pin;
    }
    return result;
}
