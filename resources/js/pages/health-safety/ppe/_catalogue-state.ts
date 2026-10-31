import { object } from './_command-state';
import type { PpeReceipt } from './_workspace-types';
import type { TypeRow } from './ppe-shared';

export type CatalogueCommand = 'create_inventory' | 'create_type' | 'update_type' | 'activate_type' | 'deactivate_type';
export type CatalogueRow = Omit<TypeRow, 'inventory_count'> & { inventory_count: number | null; physical_inventory_count: number; inventory_integrity: 'readable' | 'repair_required'; source_integrity: 'intact' | 'legacy_unknown'; can: { manage: boolean };
    command_context: { root_kind: 'type'; root_id: number; source_kind: 'type'; source_id: number;
        expected_version: number; source_schema_version: number; site_id: null; user_id: null } };
export type CatalogueRepairRow = { id: number; source_integrity: 'repair_required'; command_context: null; can: { manage: false };
    inventory_count: number | null; physical_inventory_count: number; inventory_integrity: 'readable' | 'repair_required'; name: null; category: null; is_active: null };
export type CatalogueDisplayRow = CatalogueRow | CatalogueRepairRow;
export type PpeCreateOptions = { sites: { id: number; name: string }[];
    types: { id: number; name: string; category: string; standards_reference: string | null; inspection_frequency: string | null }[];
    can: { create: boolean; catalogue_manage: boolean } };
const positive = (value: unknown) => Number.isSafeInteger(value) && Number(value) > 0;
export function checkedPpeOptions(value: unknown): PpeCreateOptions | null {
    if (!object(value) || !object(value.can) || typeof value.can.create !== 'boolean' || typeof value.can.catalogue_manage !== 'boolean' ||
        !Array.isArray(value.sites) || !Array.isArray(value.types) ||
        ![...value.sites, ...value.types].every((row) => object(row) && positive(row.id) && typeof row.name === 'string')) return null;
    if (new Set(value.sites.map((site) => site.id)).size !== value.sites.length || new Set(value.types.map((type) => type.id)).size !== value.types.length) return null;
    return value as unknown as PpeCreateOptions;
}
export function checkedPpeCatalogue(value: unknown, id: number): CatalogueRow | null {
    if (!object(value) || !object(value.can) || value.can.manage !== true || !Array.isArray(value.types)) return null;
    const found = value.types.filter((row) => object(row) && row.id === id);
    if (found.length !== 1) return null;
    const row = found[0]; const c = row.command_context;
    if (!object(c) || !object(row.can) || row.can.manage !== true || !['intact', 'legacy_unknown'].includes(String(row.source_integrity)) ||
        c.root_kind !== 'type' || c.root_id !== id || c.source_kind !== 'type' || c.source_id !== id || c.site_id !== null || c.user_id !== null ||
        !Number.isSafeInteger(c.expected_version) || Number(c.expected_version) < 0 || Number(c.expected_version) > 4294967294 ||
        !Number.isSafeInteger(c.source_schema_version) || Number(c.source_schema_version) < 0 || typeof row.name !== 'string' ||
        typeof row.category !== 'string' || typeof row.is_active !== 'boolean') return null;
    return row as unknown as CatalogueRow;
}
export function confirmsPpeCatalogueReceipt(value: unknown, expected: {
    command: CatalogueCommand; actorId: number; key: string; version: number | null; id?: number;
}): value is PpeReceipt {
    if (!object(value) || Object.keys(value).length !== 11) return false;
    const root = expected.command === 'create_inventory' ? 'inventory' : 'type';
    const changed = value.version === (expected.version ?? 0) + 1 && positive(value.source_version_id);
    const noop = expected.version !== null && value.version === expected.version && value.source_version_id === null &&
        ['update_type', 'activate_type', 'deactivate_type'].includes(expected.command);
    return value.accepted === true && value.actor_id === expected.actorId && value.command === expected.command &&
        value.idempotency_key === expected.key && value.expected_version === expected.version && value.root_type === root &&
        value.source_type === root && positive(value.root_id) && value.source_id === value.root_id &&
        (expected.id == null || value.root_id === expected.id) && (changed || noop);
}
