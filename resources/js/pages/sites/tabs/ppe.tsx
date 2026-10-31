import { EntityChip } from '@/components/lists/entity-cells';
import type { MenuItem } from '@/components/lists/entity-menu';
import type { EntityTableColumn } from '@/components/lists/entity-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import { PpeRows } from '@/pages/health-safety/ppe/_register-list';
import { condLabel, statusLabel, statusTone } from '@/pages/health-safety/ppe/ppe-shared';
import { router } from '@inertiajs/react';
import { Package } from 'lucide-react';
import type { ReactNode } from 'react';
import { SafetyEmpty, SafetyRegisterHeader } from './safety-register';

type PpeRow = {
    id: number;
    name: string;
    href: string;
    condition: string | null;
    quantity: number | null;
    status: string | null;
    location: string | null;
    expiry_date: string | null;
    next_inspection_due: string | null;
    expired: boolean | null;
    inspection_due: boolean | null;
    source_integrity: 'intact' | 'legacy_unknown' | 'repair_required';
};

export type SitePpeData = {
    locked?: boolean;
    items: PpeRow[];
    can_manage: boolean;
    href: string;
    physical_record_count: number;
    displayed_count: number;
    truncated: boolean;
    repair_count: number;
    confirmed_units: number | null;
};

const damaged = (row: PpeRow) => row.source_integrity === 'repair_required';
const name = (row: PpeRow) => damaged(row) ? `Equipment ${row.id} · source needs review` : row.name || `Equipment ${row.id}`;
const fact = (row: PpeRow, value: ReactNode) => damaged(row) ? 'Not confirmed' : value;
const alerts = (row: PpeRow) => <>
    {damaged(row) ? <StatusBadge variant="warning">Source needs review</StatusBadge> : <>
        {row.source_integrity === 'legacy_unknown' && <StatusBadge variant="neutral">Earlier history not confirmed</StatusBadge>}
        {row.expired && <StatusBadge variant="warning">Recorded expiry has passed</StatusBadge>}
        {row.inspection_due && <StatusBadge variant="warning">Inspection due</StatusBadge>}
        <span className="text-caption whitespace-normal">Expiry: {formatDateOnly(row.expiry_date)} · Next inspection: {formatDateOnly(row.next_inspection_due)}</span>
    </>}
</>;
const columns: EntityTableColumn<PpeRow>[] = [
    { key: 'state', label: 'Service state', width: '1fr', cell: (row) => fact(row, <StatusBadge variant={statusTone(row.status)}>{statusLabel(row.status)}</StatusBadge>) },
    { key: 'condition', label: 'Condition', width: '1fr', cell: (row) => fact(row, condLabel(row.condition)) },
    { key: 'location', label: 'Storage', width: '1fr', cell: (row) => fact(row, row.location ?? 'Not recorded') },
    { key: 'quantity', label: 'Recorded quantity', width: '110px', cell: (row) => fact(row, row.quantity ?? 'Not recorded') },
    { key: 'expiry', label: 'Expiry', width: '1fr', cell: (row) => fact(row, formatDateOnly(row.expiry_date)) },
    { key: 'inspection', label: 'Next inspection', width: '1fr', cell: (row) => fact(row, formatDateOnly(row.next_inspection_due)) },
];

export function SiteProfilePpe({ data }: { data: SitePpeData }) {
    if (data.locked) return <SafetyEmpty label="PPE records require current safety and Site access." />;
    const actions = (row: PpeRow): MenuItem[] => [{ label: 'Open equipment', icon: Package, onClick: () => router.get(row.href) }];
    return <div className="space-y-5">
        <SafetyRegisterHeader title="PPE inventory" description="Recorded equipment facts for this Site. Open an item for issues, inspections, evidence and history." href={data.href} actionLabel="Open PPE register" />
        <p className="text-subtle">{data.confirmed_units == null ? 'Recorded quantity is not confirmed.' : `${data.confirmed_units} recorded quantity across matching equipment records.`} Quantity does not identify individual units or confirm suitability.</p>
        {data.repair_count > 0 && <div role="status" className="rounded-xl border border-status-warning bg-status-warning-bg p-4 text-status-warning-foreground">
            {data.repair_count} equipment {data.repair_count === 1 ? 'source needs' : 'sources need'} review. Unconfirmed facts are withheld; open the equipment record to review its source status.
        </div>}
        {data.truncated && <p role="status" className="text-subtle">This preview is limited. Open the PPE register to see the remaining matching records.</p>}
        {data.items.length ? <PpeRows rows={data.items} total={data.physical_record_count} title="Site equipment" caption={`${data.displayed_count} preview records · ${data.physical_record_count} saved Site records, including retained history`} icon={Package} view="table"
            name={name} subline={(row) => damaged(row) ? 'Saved facts withheld pending repair' : row.location ?? 'Storage not recorded'} href={(row) => row.href} actions={actions} columns={columns}
            chips={(row) => damaged(row) ? null : <><StatusBadge variant={statusTone(row.status)}>{statusLabel(row.status)}</StatusBadge><EntityChip>{condLabel(row.condition)}</EntityChip><EntityChip>{row.quantity ?? 'Unknown'} recorded quantity</EntityChip></>}
            alerts={alerts} footer={(row) => ({ primary: damaged(row) ? 'Facts not confirmed' : row.location ?? 'Storage not recorded', secondary: 'Open equipment and return to this Site' })}
            meridian={(row) => damaged(row) || row.expired || row.inspection_due ? 'warning' : 'neutral'} /> :
            <SafetyEmpty label="No matching equipment records are available in this Site preview. Open the register to review retained records." />}
    </div>;
}
