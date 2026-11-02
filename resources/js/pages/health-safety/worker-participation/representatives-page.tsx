/**
 * Health and safety representatives (board G4-13, approved by Stephan 1 Nov 2026): one name for the page — the title,
 * breadcrumb and active rail item all say "Health and safety representatives". The header is the G2 shape: four boxes
 * about representatives only (all "No trend yet"; none needs action, so none takes the alert tone; active
 * representatives drawn as faces, because people are never just numbers), then one line with the hub's three pages,
 * the site filter and Find. The representatives table is the lead block; Committees and Consultations show as one quiet
 * row each, linking to their own pages.
 */
import {
    QuietRows,
    RegisterBoxes,
    RegisterRailLine,
} from '@/components/health-safety/register-kit';
import {
    RecordIdentity,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterAvatars,
    PageHeaderSearch,
} from '@/components/page';
import type { GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import { Ring } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import type { RepRow } from '@/components/worker-participation/shared';
import { formatDateOnly } from '@/lib/datetime';
import { CalendarCheck, GraduationCap, Home, List, MessageSquare, UserCheck, UserMinus, Users, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { participationMenu, type ParticipationActions } from './participation-cards';
import { participationHref, type ParticipationFilters, type ParticipationHero } from './participation-header';

/** G4-13: the representatives page's own numbers (WorkerParticipationRegisterReadService). */
export type RepresentativesSummary = {
    active: number;
    former: number;
    elected_12m: number;
    training_days: number;
    faces: string[];
};

export const REPRESENTATIVES_TITLE = 'Health and safety representatives';

/** The three status views under the header: All · Active · No longer a representative. */
export function representativeViews(total: number, summary: RepresentativesSummary): (GroupedProfileNavTab & { status: string | null })[] {
    return [
        { key: 'all', label: 'All', icon: List, count: total, status: null },
        { key: 'active', label: 'Active', icon: UserCheck, count: summary.active, status: 'active' },
        { key: 'former', label: 'No longer a representative', icon: UserMinus, count: summary.former, status: 'former' },
    ];
}

export function RepresentativesHeader({
    filters,
    hero,
    summary,
    sites,
    actions,
    rail,
    onFilters,
    onClear,
}: {
    filters: ParticipationFilters;
    hero: ParticipationHero;
    summary: RepresentativesSummary;
    sites: { id: number; name: string }[];
    actions?: ReactNode;
    rail: ReactNode;
    onFilters: (next: Partial<ParticipationFilters>) => void;
    onClear: () => void;
}) {
    const [query, setQuery] = useState(filters.q ?? '');
    useEffect(() => setQuery(filters.q ?? ''), [filters.q]);
    const href = (next: Partial<ParticipationFilters>) => participationHref(filters, { tab: 'representatives', period: 'all', ...next });
    const more = Math.max(0, summary.active - summary.faces.length);
    const narrowed = !!(filters.site_id || filters.q || filters.focus);
    return (
        <PageHeader
            title={REPRESENTATIVES_TITLE}
            icon={Users}
            wrapTitle
            subline="The people elected to speak for staff on health and safety"
            actions={
                <>
                    <PageHeaderSearch
                        value={query}
                        onChange={setQuery}
                        placeholder="Search representatives"
                        ariaLabel="Search representatives"
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                onFilters({ q: query || null });
                            }
                        }}
                    />
                    {actions}
                </>
            }
            strip={
                <RegisterBoxes
                    boxes={[
                        {
                            key: 'active_representatives',
                            label: 'Active representatives',
                            value: summary.active,
                            icon: UserCheck,
                            href: href({ status: 'active' }),
                            extra: summary.faces.length ? (
                                <PageHeaderMeterAvatars people={summary.faces.map((name, index) => ({ id: index, name }))} overflow={more} />
                            ) : undefined,
                        },
                        {
                            key: 'sites_without',
                            label: 'Sites without a representative',
                            value: hero.clusters.participation.sites_without_rep,
                            icon: Home,
                            href: href({ status: 'active' }),
                        },
                        { key: 'elected_12m', label: 'Elected · last 12 months', value: summary.elected_12m, icon: CalendarCheck, href: href({ status: null }) },
                        // Training days are kept as one running total per representative, so this counts every day recorded, not only this year.
                        { key: 'training_days', label: 'Training days recorded', value: summary.training_days, icon: GraduationCap, href: href({ status: 'active' }) },
                    ]}
                />
            }
            rail={
                <RegisterRailLine
                    rail={rail}
                    filters={
                        <>
                            {sites.length > 0 && (
                                <PageHeaderFilterSelect
                                    label={`All sites · ${sites.length}`}
                                    value={filters.site_id === null ? 'all' : String(filters.site_id)}
                                    onChange={(site) => onFilters({ site_id: site === 'all' ? null : Number(site) })}
                                    options={[{ value: 'all', label: 'All sites' }, ...sites.map((site) => ({ value: String(site.id), label: site.name }))]}
                                />
                            )}
                            {narrowed && (
                                <PageHeaderFilterButton
                                    icon={X}
                                    onClick={() => {
                                        setQuery('');
                                        onClear();
                                    }}
                                >
                                    Clear filters
                                </PageHeaderFilterButton>
                            )}
                        </>
                    }
                />
            }
        />
    );
}

/** "Feb 2026" — the month a representative was elected. */
function electedMonth(day: string | null): string {
    const value = formatDateOnly(day, '');
    return value ? value.replace(/^\d+\s/, '') : 'Not recorded';
}

/** The lead block: every representative in this view, most recent first, each opening their record. */
export function RepresentativesTable({
    rows,
    total,
    from,
    to,
    actionsFor,
    onOpen,
    footer,
}: {
    rows: RepRow[];
    total: number;
    from: number | null;
    to: number | null;
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    footer?: ReactNode;
}) {
    const columns: RecordTableColumn<RepRow>[] = [
        {
            key: 'name',
            label: 'Representative',
            className: 'min-w-[13rem]',
            cell: (row) => <RecordIdentity title={row.user?.name ?? 'Representative not recorded'} sub={row.work_group ?? 'Work group not recorded'} />,
        },
        {
            key: 'site',
            label: 'Site',
            cell: (row) =>
                row.site_ownership === 'unconfirmed' ? (
                    <StatusBadge variant="warning">Site not checked</StatusBadge>
                ) : (
                    (row.site?.name ?? 'Site not recorded')
                ),
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (row) =>
                row.status === 'active' ? <StatusBadge variant="success">Active</StatusBadge> : <StatusBadge variant="neutral">No longer a representative</StatusBadge>,
        },
        {
            key: 'training',
            label: 'Training days',
            nowrap: true,
            // A representative's entitlement, not a requirement: a neutral fact (G4-13).
            cell: (row) => (Number.isFinite(row.training_days_completed) ? `${row.training_days_completed} recorded` : 'Not recorded'),
        },
        { key: 'elected', label: 'Elected', nowrap: true, cell: (row) => electedMonth(row.elected_at) },
    ];
    return (
        <RecordTable
            title="Representatives"
            sub={total ? `Most recent first · showing ${from ?? 1}–${to ?? rows.length} of ${total}` : 'Most recent first'}
            ariaLabel="Health and safety representatives"
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            onOpen={(row) => onOpen(row.id)}
            actionsFor={(row) => participationMenu(actionsFor('representative', row))}
            menuTitle={(row) => row.user?.name ?? 'Representative'}
            menuIcon={Users}
            footer={footer}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No representatives match this view and these filters.</span>
                </p>
            }
        />
    );
}

/** "Elsewhere in People and participation": Committees and Consultations, one quiet row each. */
export function RepresentativesElsewhere({ filters, hero }: { filters: ParticipationFilters; hero: ParticipationHero }) {
    const minutes = hero.clusters.consultation.minutes_outstanding;
    const open = hero.clusters.consultation.open;
    const link = (tab: string) => participationHref({ ...filters, status: null, focus: null }, { tab, period: 'all' });
    return (
        <QuietRows
            title="Elsewhere in People and participation"
            sub={null}
            rows={[
                {
                    key: 'committees',
                    icon: Users,
                    title: 'Committees',
                    sub: minutes > 0 ? `${minutes} held ${minutes === 1 ? 'meeting has' : 'meetings have'} no minutes yet` : 'Meetings, minutes and agreed actions',
                    href: link('meetings'),
                },
                {
                    key: 'consultations',
                    icon: MessageSquare,
                    title: 'Consultations',
                    sub: open > 0 ? `${open} open ${open === 1 ? 'consultation' : 'consultations'}` : 'Involving staff in decisions that affect their safety',
                    href: link('consultations'),
                },
            ]}
        />
    );
}
