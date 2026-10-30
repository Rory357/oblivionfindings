import { expect, it } from 'vitest';
import { confirmsPpeReceipt, ppeCheckedRecord, ppeCommandAllowed, ppeReceiptCommand } from './_command-state';
import { checkedPpeCatalogue, checkedPpeOptions, confirmsPpeCatalogueReceipt } from './_catalogue-state';
import type { PpeRecord } from './_workspace-types';

const expected = { actorId: 12, rootId: 9, sourceKind: 'inventory', sourceId: 9, command: 'update_inventory', version: 4,
    key: '17fd345d-a0f4-4aa1-8f24-6f992ff3d455' };
const receipt = { accepted: true, actor_id: 12, command: 'update_inventory', idempotency_key: expected.key, root_type: 'inventory', root_id: 9,
    source_type: 'inventory', source_id: 9, expected_version: 4, version: 5, source_version_id: 21 };
const record = { kind: 'inventory', detail: { id: 9, status: 'available', attachments: [{ id: 8 }],
    can: { manage: true, inspect: true, evidence: true, allocate: false }, command_context: { root_kind: 'inventory', root_id: 9,
        source_kind: 'inventory', source_id: 9, site_id: 4, user_id: null, expected_version: 4, source_schema_version: 1 } } } as unknown as PpeRecord;

it('matches the exact command and permits only the explicitly accepted unchanged actions', () => {
    expect(confirmsPpeReceipt(receipt, expected)).toBe(true);
    expect(confirmsPpeReceipt({ ...receipt, version: 4, source_version_id: null }, expected)).toBe(true);
    expect(confirmsPpeReceipt({ ...receipt, command: 'dispose', version: 4, source_version_id: null }, { ...expected, command: 'dispose' })).toBe(false);
    expect(confirmsPpeReceipt({ ...receipt, version: 4 }, expected)).toBe(false);
    expect(confirmsPpeReceipt({ ...receipt, source_version_id: null }, expected)).toBe(false);
});
it('rejects another actor, root, exact child, source, key or any additional receipt claim', () => {
    for (const patch of [{ accepted: 'true' }, { actor_id: 13 }, { root_id: 10 }, { root_type: 'type' }, { source_type: 'inspection' },
        { source_id: 8 }, { expected_version: 3 }, { idempotency_key: 'another-key' }, { version: 6 }, { source_version_id: '21' },
        { replayed: true }, { approved: true }, { file_path: 'private-path' }]) expect(confirmsPpeReceipt({ ...receipt, ...patch }, expected)).toBe(false);
});
it('checks fresh source identity without allowing reparenting or malformed current read capabilities', () => {
    expect(ppeCheckedRecord(record, record)).toEqual(record);
    const detail = record.detail;
    for (const patch of [{ id: 10 }, { can: { manage: 'true' } }, { attachments: [null] }, { status: 'assumed_safe' },
        { command_context: { ...detail.command_context, root_id: 10 } }, { command_context: { ...detail.command_context, site_id: 5 } },
        { command_context: { ...detail.command_context, user_id: 14 } }, { command_context: { ...detail.command_context, expected_version: '4' } },
        { command_context: null }]) expect(ppeCheckedRecord({ kind: record.kind, detail: { ...detail, ...patch } }, record)).toBeNull();
});
it('uses source capabilities and exact evidence selection rather than an action in the URL', () => {
    expect(ppeCommandAllowed(record, 'allocate')).toBe(false);
    expect(ppeCommandAllowed(record, 'inspect')).toBe(true);
    expect(ppeCommandAllowed(record, 'remove_evidence', 8)).toBe(true);
    expect(ppeCommandAllowed(record, 'remove_evidence', 9)).toBe(false);
    const own = { kind: 'allocations', detail: { ...record.detail, read_scope: 'own_allocation', can: { manage: false, acknowledge_own: true } } } as unknown as PpeRecord;
    expect(ppeCommandAllowed(own, 'acknowledge')).toBe(true);
    expect(ppeReceiptCommand(own, 'acknowledge')).toBe('acknowledge_own');
    expect(ppeCommandAllowed(own, 'upload_evidence')).toBe(false);
    expect(ppeCommandAllowed(own, 'return')).toBe(false);
});

it('requires explicit canonical eligibility for an exact legacy evidence transfer and its persisted receipt', () => {
    const eligible = { ...record, detail: { ...record.detail, attachments: [{ id: 8, can_relocate: true }, { id: 10, can_relocate: false }] } } as unknown as PpeRecord;
    expect(ppeCommandAllowed(eligible, 'relocate_evidence', 8)).toBe(true);
    expect(ppeCommandAllowed(eligible, 'relocate_evidence', 10)).toBe(false);
    expect(ppeCommandAllowed(eligible, 'relocate_evidence', 9)).toBe(false);
    expect(ppeCommandAllowed(record, 'relocate_evidence', 8)).toBe(false);
    expect(ppeCommandAllowed({ ...eligible, detail: { ...eligible.detail, can: { manage: false, evidence: true } } } as PpeRecord, 'relocate_evidence', 8)).toBe(false);
    expect(ppeReceiptCommand(eligible, 'relocate_evidence')).toBe('relocate_attachment');
    const expectedTransfer = { ...expected, sourceKind: 'inventory_attachment', sourceId: 8, command: 'relocate_attachment' };
    const saved = { ...receipt, command: 'relocate_attachment', source_type: 'inventory_attachment', source_id: 8 };
    expect(confirmsPpeReceipt(saved, expectedTransfer)).toBe(true);
    expect(confirmsPpeReceipt({ ...saved, version: 4, source_version_id: null }, expectedTransfer)).toBe(false);
    expect(confirmsPpeReceipt({ ...saved, source_id: 10 }, expectedTransfer)).toBe(false);
});
it('requires canonical create/type receipt identity and prevents creation from masquerading as a no-op', () => {
    const create = { ...receipt, command: 'create_inventory', expected_version: null, version: 1 };
    const match = { actorId: 12, command: 'create_inventory' as const, key: expected.key, version: null };
    expect(confirmsPpeCatalogueReceipt(create, match)).toBe(true);
    expect(confirmsPpeCatalogueReceipt({ ...create, version: 0, source_version_id: null }, match)).toBe(false);
    expect(confirmsPpeCatalogueReceipt({ ...create, root_id: 10 }, match)).toBe(false);
    const update = { ...receipt, root_type: 'type', source_type: 'type', command: 'update_type', version: 4, source_version_id: null };
    const typeMatch = { ...match, command: 'update_type' as const, version: 4, id: 9 };
    expect(confirmsPpeCatalogueReceipt(update, typeMatch)).toBe(true);
    expect(confirmsPpeCatalogueReceipt({ ...update, root_id: 10, source_id: 10 }, typeMatch)).toBe(false);
});
it('rejects unproved choices and catalogue ownership while retaining explicit empty current lists', () => {
    const options = { sites: [{ id: 4, name: 'Approved Site' }], types: [{ id: 9, name: 'Type' }], can: { create: true, catalogue_manage: true } };
    expect(checkedPpeOptions(options)).toEqual(options);
    expect(checkedPpeOptions({ ...options, sites: [...options.sites, ...options.sites] })).toBeNull();
    expect(checkedPpeOptions({ ...options, can: { create: 'true', catalogue_manage: true } })).toBeNull();
    expect(checkedPpeOptions({ ...options, sites: [], types: [] })).not.toBeNull();
    const type = { id: 9, name: 'Type', category: 'head', is_active: true, source_integrity: 'legacy_unknown', can: { manage: true },
        command_context: { root_kind: 'type', root_id: 9, source_kind: 'type', source_id: 9, expected_version: 0, source_schema_version: 0, site_id: null, user_id: null } };
    const page = { can: { manage: true }, types: [type] };
    expect(checkedPpeCatalogue(page, 9)).toEqual(type);
    expect(checkedPpeCatalogue({ ...page, types: [type, type] }, 9)).toBeNull();
    expect(checkedPpeCatalogue({ ...page, types: [{ ...type, source_integrity: 'repair_required', command_context: null }] }, 9)).toBeNull();
    expect(checkedPpeCatalogue({ ...page, can: { manage: false } }, 9)).toBeNull();
});
