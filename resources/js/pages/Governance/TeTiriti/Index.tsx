import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { Eye, Landmark, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';

import {
    IMPLEMENTATION_STATUSES,
    implementationStatusLabel,
    implementationStatusVariant,
    principleIcon,
    TeTiritiObligationDetailDialog,
    TeTiritiObligationWizardDialog,
    type Principle,
    type TeTiritiObligation,
} from './_dialogs';

interface Props extends PageProps {
    obligationsByPrinciple: Record<string, TeTiritiObligation[]>;
    principles: Principle[];
}

const STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    ...IMPLEMENTATION_STATUSES.map((s) => ({ value: s.key, label: s.label })),
];

function isDelivered(status: string): boolean {
    return status === 'implemented' || status === 'embedded';
}

export default function TeTiritiIndex({
    auth,
    obligationsByPrinciple,
    principles,
}: Props) {
    const canManage = Boolean(auth?.can?.governance?.['te-tiriti']?.manage);
    const [search, setSearch] = useState('');
    const [principleFilter, setPrincipleFilter] = useState('all');
    const [statusFilter, setStatusFilter] = useState('all');
    const [wizardOpen, setWizardOpen] = useState(false);
    const [editing, setEditing] = useState<TeTiritiObligation | null>(null);
    const [viewing, setViewing] = useState<TeTiritiObligation | null>(null);
    const ctxMenu = useEntityContextMenu<TeTiritiObligation>();

    const all = Object.values(obligationsByPrinciple).flat();
    const count = (status: string) =>
        all.filter((o) => o.implementation_status === status).length;
    const delivered = all.filter((o) => isDelivered(o.implementation_status))
        .length;
    const deliveredPct = all.length > 0 ? (delivered / all.length) * 100 : 0;

    const principleLabel = (value: string) =>
        principles.find((p) => p.value === value)?.label ?? value;

    const term = search.trim().toLowerCase();
    const matches = (o: TeTiritiObligation) =>
        (statusFilter === 'all' || o.implementation_status === statusFilter) &&
        (term === '' ||
            o.title.toLowerCase().includes(term) ||
            o.description.toLowerCase().includes(term) ||
            (o.evidence_notes ?? '').toLowerCase().includes(term));
    const hasFilters =
        principleFilter !== 'all' || statusFilter !== 'all' || term !== '';
    const visiblePrinciples = principles.filter(
        (p) => principleFilter === 'all' || p.value === principleFilter,
    );
    const visibleCount = visiblePrinciples.reduce(
        (sum, p) => sum + (obligationsByPrinciple[p.value] ?? []).filter(matches).length,
        0,
    );

    const clearFilters = () => {
        setSearch('');
        setPrincipleFilter('all');
        setStatusFilter('all');
    };

    const openEdit = (obligation: TeTiritiObligation) => {
        setViewing(null);
        setEditing(obligation);
    };

    const actionsFor = (obligation: TeTiritiObligation): MenuItem[] =>
        compactMenu([
            {
                label: 'View details',
                icon: Eye,
                onClick: () => setViewing(obligation),
            },
            canManage && {
                label: 'Edit obligation',
                icon: Pencil,
                onClick: () => openEdit(obligation),
            },
        ]);

    const header = (
        <PageHeader
            icon={Landmark}
            title="Te Tiriti o Waitangi"
            titleChip={
                <PageHeaderStatusChip
                    variant={
                        all.length === 0
                            ? 'neutral'
                            : deliveredPct >= 75
                              ? 'success'
                              : 'warning'
                    }
                >
                    {Math.round(deliveredPct)}% implemented
                </PageHeaderStatusChip>
            }
            subline={`Obligations and implementation tracking · ${principles.length} principles · ${all.length} obligations`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search obligations, evidence…"
                    />
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            Add obligation
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Obligations"
                        ariaLabel="View all obligations"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBig>{all.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {principles.length} principles
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Not started"
                        ariaLabel="View obligations not started"
                        onClick={() => setStatusFilter('not_started')}
                        tone={count('not_started') > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {count('not_started')}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            awaiting action
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="In progress"
                        ariaLabel="View obligations in progress"
                        onClick={() => setStatusFilter('in_progress')}
                    >
                        <PageHeaderMeterBig>
                            {count('in_progress')}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            actions under way
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Embedded"
                        ariaLabel="View embedded obligations"
                        onClick={() => setStatusFilter('embedded')}
                        tone={count('embedded') > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>{count('embedded')}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            in everyday practice
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Implemented"
                        value={`${delivered}/${all.length}`}
                        ariaLabel="View implemented obligations"
                        onClick={() => setStatusFilter('implemented')}
                    >
                        <PageHeaderMeterDonut
                            percent={deliveredPct}
                            caption="implemented or embedded"
                        />
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Principle"
                        value={principleFilter}
                        options={[
                            { value: 'all', label: 'All principles' },
                            ...principles,
                        ]}
                        onChange={setPrincipleFilter}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={statusFilter}
                        options={STATUS_FILTERS}
                        onChange={setStatusFilter}
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Te Tiriti o Waitangi', href: '/governance/te-tiriti' },
            ]}
        >
            <Head title="Te Tiriti o Waitangi" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {hasFilters && visibleCount === 0 ? (
                        <EmptyState
                            icon={Landmark}
                            title="No obligations match your filters"
                            description="Try clearing a filter or search term."
                            action={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            }
                        />
                    ) : (
                        visiblePrinciples.map((principle) => {
                            const obligations =
                                obligationsByPrinciple[principle.value] ?? [];
                            const rows = obligations.filter(matches);
                            if (hasFilters && rows.length === 0) return null;
                            const done = obligations.filter((o) =>
                                isDelivered(o.implementation_status),
                            ).length;
                            const progress =
                                obligations.length > 0
                                    ? Math.round(
                                          (done / obligations.length) * 100,
                                      )
                                    : 0;
                            return (
                                <section
                                    key={principle.value}
                                    className="flex flex-col gap-3"
                                    aria-label={principle.label}
                                >
                                    <ListCaption
                                        title={principle.label}
                                        caption={`${rows.length} of ${obligations.length} shown · ${progress}% implemented`}
                                    />
                                    {rows.length === 0 ? (
                                        <EmptyState
                                            variant="compact"
                                            icon={principleIcon(principle.value)}
                                            title="No obligations recorded for this principle"
                                        />
                                    ) : (
                                        <EntityTable<TeTiritiObligation>
                                            rows={rows}
                                            rowKey={(o) => o.id}
                                            identityLabel="Obligation"
                                            identity={(o) => ({
                                                icon: principleIcon(o.principle),
                                                name: o.title,
                                                subline: o.description,
                                            })}
                                            onOpen={(o) => setViewing(o)}
                                            minWidth={760}
                                            identityWidth="2.4fr"
                                            columns={[
                                                {
                                                    key: 'status',
                                                    label: 'Status',
                                                    width: '1fr',
                                                    cell: (o) => (
                                                        <EntityStatusChip
                                                            variant={implementationStatusVariant(
                                                                o.implementation_status,
                                                            )}
                                                        >
                                                            {implementationStatusLabel(
                                                                o.implementation_status,
                                                            )}
                                                        </EntityStatusChip>
                                                    ),
                                                },
                                                {
                                                    key: 'evidence',
                                                    label: 'Evidence',
                                                    width: '1.6fr',
                                                    cell: (o) =>
                                                        o.evidence_notes ? (
                                                            <span className="truncate">
                                                                {o.evidence_notes}
                                                            </span>
                                                        ) : (
                                                            <EmptyValue />
                                                        ),
                                                },
                                                {
                                                    key: 'target',
                                                    label: 'Target',
                                                    width: '0.9fr',
                                                    cell: (o) =>
                                                        o.target_date ? (
                                                            formatDateOnly(
                                                                o.target_date.slice(
                                                                    0,
                                                                    10,
                                                                ),
                                                            )
                                                        ) : (
                                                            <EmptyValue />
                                                        ),
                                                },
                                            ]}
                                            actionsFor={actionsFor}
                                            onRowContextMenu={(e, o) =>
                                                ctxMenu.open(e, o)
                                            }
                                        />
                                    )}
                                </section>
                            );
                        })
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={principleIcon(ctxMenu.ctx.record.principle)}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <TeTiritiObligationDetailDialog
                obligation={viewing}
                principleLabel={viewing ? principleLabel(viewing.principle) : ''}
                onClose={() => setViewing(null)}
                onEdit={canManage && viewing ? () => openEdit(viewing) : undefined}
            />

            {canManage ? (
                <>
                    <TeTiritiObligationWizardDialog
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        principles={principles}
                    />
                    <TeTiritiObligationWizardDialog
                        open={editing != null}
                        onClose={() => setEditing(null)}
                        principles={principles}
                        obligation={editing}
                    />
                </>
            ) : null}
        </AppLayout>
    );
}
