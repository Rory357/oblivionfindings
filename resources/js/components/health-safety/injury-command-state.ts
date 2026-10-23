import type { InjuryCommand, InjuryRecord } from './injury-workspace-types';

export const INJURY_COMMAND_LABELS: Record<InjuryCommand, string> = {
    start_treatment: 'Record treatment started',
    begin_rtw: 'Start recovery planning',
    mark_recovered: 'Record recovery',
    close: 'Close injury record',
    acc: 'Update claim facts',
    add_rtw: 'Add recovery plan',
    add_capacity: 'Record capacity assessment',
    edit: 'Edit injury facts',
    assign: 'Change incident owner',
    review: 'Record recovery review',
    edit_rtw: 'Edit recovery plan',
    add_duty: 'Add modified duties',
    upload_evidence: 'Add evidence',
    remove_evidence: 'Remove evidence',
};
export const INJURY_RECEIPT_COMMAND: Record<InjuryCommand | 'create', string> = {
    create: 'create', edit: 'update', acc: 'update',
    start_treatment: 'transition', begin_rtw: 'transition',
    mark_recovered: 'transition', close: 'transition',
    assign: 'assign', review: 'review', add_capacity: 'record_capacity',
    add_rtw: 'create_rtw_plan', edit_rtw: 'update_rtw_plan',
    add_duty: 'add_modified_duty', upload_evidence: 'upload_evidence',
    remove_evidence: 'remove_evidence',
};
export function injuryCommandAllowed(record: InjuryRecord, command: InjuryCommand,
    selected?: { plan?: number | null; attachment?: number | null }): boolean {
    if (record.source_integrity === 'repair_required') return false;
    if (command === 'assign') return record.can.assign;
    if (record.status === 'closed') return false;
    const transition = {
        start_treatment: 'under_treatment', begin_rtw: 'return_to_work',
        mark_recovered: 'recovered', close: 'closed',
    }[command as 'start_treatment' | 'begin_rtw' | 'mark_recovered' | 'close'];
    if (transition)
        return record.can.transition && record.commands.transitions.includes(transition);
    switch (command) {
        case 'edit': return record.can.update;
        case 'acc': return record.can.update && record.medical_access === 'readable';
        case 'review': return record.can.review && record.medical_access === 'readable';
        case 'add_capacity':
        case 'add_rtw':
            return record.can.recovery && record.medical_access === 'readable' && record.recovery_integrity === 'valid';
        case 'edit_rtw':
        case 'add_duty':
            return record.can.recovery && record.medical_access === 'readable' && record.recovery_integrity === 'valid' &&
                !!record.rtw_plans?.some((plan) => plan.id === selected?.plan);
        case 'upload_evidence': return record.can.upload_evidence && record.medical_access === 'readable';
        case 'remove_evidence':
            return record.can.remove_evidence && record.medical_access === 'readable' &&
                !!record.attachments?.some((file) => file.id === selected?.attachment);
        default: return false;
    }
}
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const revision = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0 && Number(value) <= 4294967294;
const receiptKeys = [
    'accepted', 'injury_id', 'source_type', 'source_id', 'actor_id', 'command',
    'idempotency_key', 'expected_version', 'version', 'source_version_id',
    'changed', 'receipt_id', 'replayed',
] as const;
export type InjuryCommandReceipt = Record<string, unknown> & {
    accepted: true;
    injury_id: number;
    source_type: 'injury' | 'rtw_plan' | 'capacity_assessment' | 'modified_duty' | 'attachment';
    source_id: number;
    actor_id: number;
    command: string;
    idempotency_key: string;
    expected_version: number | null;
    version: number;
    source_version_id: number;
    changed: boolean;
    receipt_id: number;
    replayed: boolean;
};
/** UI success needs the reconstructed actor/source/command receipt. */
export function confirmsInjuryReceipt(value: unknown, expected: {
    injuryId?: number; version?: number; actorId: number; key: string;
    command: InjuryCommand | 'create'; childId?: number;
}): value is InjuryCommandReceipt {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    const receipt = value as Record<string, unknown>;
    if (Object.keys(receipt).length !== receiptKeys.length ||
        !receiptKeys.every((key) => Object.hasOwn(receipt, key))) return false;
    const type = ['add_rtw', 'edit_rtw'].includes(expected.command) ? 'rtw_plan'
        : expected.command === 'add_capacity' ? 'capacity_assessment'
        : expected.command === 'add_duty' ? 'modified_duty'
        : ['upload_evidence', 'remove_evidence'].includes(expected.command) ? 'attachment'
        : 'injury';
    return receipt.accepted === true && positive(receipt.injury_id) &&
        (expected.injuryId == null || receipt.injury_id === expected.injuryId) &&
        positive(receipt.actor_id) && receipt.actor_id === expected.actorId &&
        receipt.command === INJURY_RECEIPT_COMMAND[expected.command] &&
        receipt.idempotency_key === expected.key.toLowerCase() &&
        receipt.expected_version === (expected.version ?? null) &&
        revision(receipt.version) && typeof receipt.changed === 'boolean' &&
        receipt.version === (expected.version == null ? 1 : expected.version + (receipt.changed ? 1 : 0)) &&
        (expected.version != null || receipt.changed === true) &&
        receipt.source_type === type && positive(receipt.source_id) &&
        (type !== 'injury' || receipt.source_id === receipt.injury_id) &&
        (expected.childId == null || receipt.source_id === expected.childId) &&
        positive(receipt.source_version_id) && positive(receipt.receipt_id) &&
        typeof receipt.replayed === 'boolean';
}
