import type { PpeCommand } from '@/lib/health-safety/ppe-navigation';
import type { PpeRecord, PpeReceipt } from './_workspace-types';

export const PPE_COMMAND_LABELS: Record<PpeCommand, string> = {
    edit: 'Edit item', allocate: 'Issue PPE', inspect: 'Record inspection', condemn: 'Take out of use (condemn)', dispose: 'Record disposal',
    acknowledge: 'Confirm it’s been received', return: 'Record return', upload_evidence: 'Add evidence', remove_evidence: 'Remove evidence',
    hold: 'Place on hold', release: 'Return to use', relocate_evidence: 'Move file to secure storage',
};
export const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) > 0;
export function ppeCommandAllowed(record: PpeRecord, command: PpeCommand, attachment?: number | null): boolean {
    const { can } = record.detail;
    if (command === 'edit') return record.kind === 'inventory' && can.manage === true;
    if (['allocate', 'inspect', 'condemn', 'dispose', 'hold', 'release'].includes(command)) return record.kind === 'inventory' && can[command as 'allocate'] === true;
    if (command === 'return') return record.kind === 'allocations' && can.return === true;
    if (command === 'acknowledge') return record.kind === 'allocations' && (can.acknowledge === true || can.acknowledge_own === true);
    if (record.kind === 'allocations' && record.detail.read_scope === 'own_allocation') return false;
    if (command === 'relocate_evidence') return can.manage === true && positive(attachment) &&
        'attachments' in record.detail && record.detail.attachments.some((item) => item.id === attachment && item.can_relocate === true);
    if (command === 'upload_evidence') return can.evidence === true || (record.kind !== 'inventory' && can.manage === true);
    return (can.evidence === true || (record.kind !== 'inventory' && can.manage === true)) && positive(attachment) &&
        'attachments' in record.detail && record.detail.attachments.some((item) => item.id === attachment);
}
export function ppeReceiptCommand(record: PpeRecord, command: PpeCommand): string {
    if (['edit', 'hold', 'release'].includes(command)) return 'update_inventory';
    if (command === 'upload_evidence') return 'upload_attachment';
    if (command === 'remove_evidence') return 'remove_attachment';
    if (command === 'relocate_evidence') return 'relocate_attachment';
    if (command === 'acknowledge' && record.detail.can.acknowledge_own === true) return 'acknowledge_own';
    return command;
}
export function ppeCheckedRecord(value: unknown, expected: PpeRecord): PpeRecord | null {
    if (!object(value) || value.kind !== expected.kind || !object(value.detail)) return null;
    const d = value.detail; const c = d.command_context;
    if (d.id !== expected.detail.id || !object(c) || !object(d.can) || !positive(c.root_id) || !positive(c.site_id) ||
        c.root_kind !== 'inventory' || c.source_kind !== expected.kind || c.source_id !== d.id ||
        !Number.isSafeInteger(c.expected_version) || Number(c.expected_version) < 0 || Number(c.expected_version) > 4294967294 ||
        !Number.isSafeInteger(c.source_schema_version) || Number(c.source_schema_version) < 0 || typeof d.can.manage !== 'boolean' ||
        !(c.user_id === null || positive(c.user_id)) || !Object.values(d.can).every((flag) => typeof flag === 'boolean') ||
        c.root_id !== expected.detail.command_context.root_id || c.site_id !== expected.detail.command_context.site_id ||
        c.user_id !== expected.detail.command_context.user_id) return null;
    const evidence = (files: unknown) => Array.isArray(files) && files.every((file) => object(file) && positive(file.id));
    if (expected.kind === 'inventory' && (c.root_id !== d.id || !['available', 'allocated', 'maintenance', 'condemned', 'disposed'].includes(String(d.status)) || !evidence(d.attachments))) return null;
    if (expected.kind === 'allocations' && (!['own_allocation', 'site_operational'].includes(String(d.read_scope)) || typeof d.acknowledged !== 'boolean')) return null;
    if (expected.kind === 'allocations' && d.read_scope === 'site_operational' && !evidence(d.attachments)) return null;
    if (expected.kind === 'inspections' && (!positive(d.ppe_inventory_id) || d.ppe_inventory_id !== c.root_id || !evidence(d.attachments))) return null;
    return { kind: value.kind, detail: d } as unknown as PpeRecord;
}
export function confirmsPpeReceipt(value: unknown, expected: { actorId: number; rootId: number; sourceKind: string; sourceId?: number; command: string; version: number; key: string }): value is PpeReceipt {
    if (!object(value) || Object.keys(value).length !== 11) return false;
    return value.accepted === true && value.actor_id === expected.actorId && value.root_type === 'inventory' && value.root_id === expected.rootId &&
        value.command === expected.command && value.idempotency_key === expected.key && value.expected_version === expected.version &&
        ((value.version === expected.version + 1 && positive(value.source_version_id)) ||
            (value.version === expected.version && value.source_version_id === null && ['update_inventory', 'acknowledge', 'acknowledge_own'].includes(expected.command))) && positive(value.source_id) &&
        value.source_type === expected.sourceKind && (expected.sourceId == null || value.source_id === expected.sourceId);
}
