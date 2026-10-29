/**
 * First aid (approved H&S drawings pg-hsn-83, 83b — Stephan 23 Oct 2026): one
 * header with the register filters and the five views on the rail, then the
 * treatments as a table. Each row leads with the person treated; the outcome
 * is a coloured state with "Ambulance called" under it; the date and first
 * aider share one cell. The Needs incident review line stays word for word.
 */
import { FirstAidTreatmentWizard } from '@/components/health-safety/first-aid/treatment-wizard';
import type {
    FirstAidPickers,
    FirstAidRecord,
} from '@/components/health-safety/first-aid/types';
import {
    dateWithYear,
    plural,
} from '@/components/incidents/incident-stages';
import {
    RecordIdentity,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Note, Ring } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatTime } from '@/lib/datetime';
import {
    FIRST_AID_REGISTER,
    firstAidHref,
    firstAidReturn,
} from '@/lib/health-safety/first-aid-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardList,
    Download,
    Eye,
    FileText,
    HeartPulse,
    Link2,
    Plus,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import {
    OUTCOMES,
    PERSON_TYPES,
    injuryLabel,
    outcomeLabel,
    outcomeTone,
    personTypeLabel,
} from './options';

type FirstAidRow = Pick<
    FirstAidRecord,
    | 'id'
    | 'reference'
    | 'href'
    | 'treatment_date'
    | 'treated_person_name'
    | 'treated_person_type'
    | 'injury_illness_type'
    | 'treatment_outcome'
    | 'ambulance_called'
    | 'site'
    | 'incident_reported'
    | 'can'
> & {
    first_aider_name: string | null;
    attachments_count: number;
    open_followups_count: number;
};
type Filters = {
    site_id: number | null;
    treated_person_type: string | null;
    treatment_outcome: string | null;
    first_aider_id: number | null;
    period: string;
    q: string | null;
};
type Props = {
    records: {
        data: FirstAidRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    tab: string;
    tabCounts: Record<string, number>;
    filters: Filters;
    pickers: FirstAidPickers;
    filterOptions: { first_aiders: { id: number; name: string }[] };
    hero: {
        live: {
            treated: number;
            ambulance: number;
            hospital: number;
            linked: number;
        };
        attention: {
            reportable_unlinked: number;
            followups_open: number;
            no_aider: number;
            today: number;
        };
    };
    can: { view: boolean; create: boolean; manage: boolean };
    report?: boolean;
};
const TABS = [
    { key: 'all', label: 'All treatments' },
    { key: 'ambulance', label: 'Ambulance called' },
    { key: 'linked', label: 'Linked incidents' },
    { key: 'reportable', label: 'Needs incident review' },
    { key: 'followup', label: 'Open follow-ups' },
];
const PERIODS = [
    { value: 'week', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'quarter', label: 'Last 3 months' },
    { value: 'all', label: 'All time' },
];
const titleLink =
    'rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export default function FirstAidIndex({
    records,
    tab,
    tabCounts,
    filters,
    pickers,
    filterOptions,
    can,
    report = false,
}: Props) {
    const { url } = usePage();
    const returnTo = firstAidReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(filters.q ?? '');
    const [reporting, setReporting] = useState(report && can.create);
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    useEffect(() => setReporting(report && can.create), [report, can.create]);
    const params = (next: Partial<Filters> & { tab?: string } = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, tab, ...next }).filter(
                ([, value]) => value != null && value !== '',
            ),
        );
    const query = (next: Partial<Filters> & { tab?: string } = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [
                key,
                String(value),
            ]),
        );
    const go = (next: Partial<Filters> & { tab?: string }) =>
        router.get(FIRST_AID_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const closeReport = () =>
        drafts.request(() => {
            setReporting(false);
            router.replace({
                url: returnTo,
                preserveState: true,
                preserveScroll: true,
            });
        });
    const moreActive = [
        !!filters.treatment_outcome,
        !!filters.first_aider_id,
    ].filter(Boolean).length;
    const filtered = !!(
        filters.q ||
        filters.site_id ||
        filters.treated_person_type ||
        moreActive ||
        (filters.period && filters.period !== '30d')
    );
    const open = (record: FirstAidRow) =>
        router.visit(firstAidHref(record.id, { returnTo }));
    const actionsFor = (record: FirstAidRow): MenuItem[] =>
        compactMenu([
            {
                label: 'Open treatment record',
                icon: Eye,
                onClick: () => open(record),
            },
            {
                label: 'Follow-ups',
                icon: ClipboardList,
                onClick: () =>
                    router.visit(
                        firstAidHref(record.id, {
                            section: 'followups',
                            returnTo,
                        }),
                    ),
            },
            {
                label: 'Incident and review',
                icon: Link2,
                onClick: () =>
                    router.visit(
                        firstAidHref(record.id, {
                            section: 'incident',
                            returnTo,
                        }),
                    ),
            },
            {
                label: 'Evidence',
                icon: FileText,
                onClick: () =>
                    router.visit(
                        firstAidHref(record.id, {
                            section: 'evidence',
                            returnTo,
                        }),
                    ),
            },
        ]);
    const reference = (record: FirstAidRow) =>
        record.reference ?? `FA-${record.id}`;
    const columns: RecordTableColumn<FirstAidRow>[] = [
        {
            key: 'person',
            label: 'Person treated',
            className: 'min-w-[15rem]',
            cell: (record) => (
                <RecordIdentity
                    title={
                        <Link
                            href={firstAidHref(record.id, { returnTo })}
                            onClick={(event) => event.stopPropagation()}
                            aria-label={`Open first aid for ${record.treated_person_name} · ${reference(record)}`}
                            className={titleLink}
                        >
                            {record.treated_person_name}
                        </Link>
                    }
                    sub={[
                        personTypeLabel(record.treated_person_type),
                        record.site?.name ?? 'Site not recorded',
                        reference(record),
                    ].join(' · ')}
                />
            ),
        },
        {
            key: 'injury',
            label: 'Injury',
            nowrap: true,
            cell: (record) => injuryLabel(record.injury_illness_type),
        },
        {
            key: 'outcome',
            label: 'Outcome',
            nowrap: true,
            cell: (record) => (
                <div className="grid justify-items-start gap-1">
                    <StatusBadge
                        variant={outcomeTone(record.treatment_outcome)}
                    >
                        {outcomeLabel(record.treatment_outcome)}
                    </StatusBadge>
                    {record.ambulance_called ? (
                        <span className="text-subtle">Ambulance called</span>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'treated',
            label: 'Treated',
            nowrap: true,
            cell: (record) =>
                record.treatment_date ? (
                    <RecordIdentity
                        title={dateWithYear(record.treatment_date)}
                        sub={`${formatTime(record.treatment_date)} · ${
                            record.first_aider_name ?? 'First aider not recorded'
                        }`}
                    />
                ) : (
                    <RecordIdentity
                        title="Treatment time not recorded"
                        sub={
                            record.first_aider_name ??
                            'First aider not recorded'
                        }
                    />
                ),
        },
        {
            key: 'followups',
            label: 'Follow-ups',
            nowrap: true,
            cell: (record) => (
                <RecordIdentity
                    title={
                        record.open_followups_count
                            ? plural(
                                  record.open_followups_count,
                                  'open follow-up',
                              )
                            : 'No open follow-ups'
                    }
                    sub={[
                        record.attachments_count
                            ? plural(record.attachments_count, 'file')
                            : 'No files',
                        record.incident_reported ? 'Incident reported' : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
            ),
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Safety registers', href: '/health-safety/registers' },
                { title: 'First aid', href: FIRST_AID_REGISTER },
            ]}
        >
            <Head title="First aid" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            wrapTitle
                            icon={HeartPulse}
                            title="First aid"
                            subline="Treatments, follow-up work and linked incident reviews"
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                go({
                                                    q: search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search treatment records"
                                        ariaLabel="Search first-aid records"
                                    />
                                    {can.view && (
                                        <PageHeaderGlassButton
                                            asChild
                                            icon={Download}
                                        >
                                            <a
                                                href={`${FIRST_AID_REGISTER}/export?${query()}`}
                                                aria-label="Export filtered records"
                                            >
                                                Export
                                            </a>
                                        </PageHeaderGlassButton>
                                    )}
                                    {can.create && (
                                        <PageHeaderPrimaryButton
                                            asChild
                                            icon={Plus}
                                        >
                                            <Link
                                                href={`${FIRST_AID_REGISTER}/report?return_to=${encodeURIComponent(returnTo)}`}
                                            >
                                                Record first aid
                                            </Link>
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="All sites"
                                        value={String(filters.site_id ?? '')}
                                        options={[
                                            { value: '', label: 'All sites' },
                                            ...pickers.sites.map((item) => ({
                                                value: String(item.id),
                                                label: item.name,
                                            })),
                                        ]}
                                        onChange={(value) =>
                                            go({
                                                site_id: value
                                                    ? Number(value)
                                                    : null,
                                            })
                                        }
                                        allValue=""
                                    />
                                    <PageHeaderFilterSelect
                                        label="Last 30 days"
                                        value={filters.period}
                                        allValue="30d"
                                        options={PERIODS}
                                        onChange={(value) =>
                                            go({ period: value || '30d' })
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="All person types"
                                        value={
                                            filters.treated_person_type ?? ''
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All person types',
                                            },
                                            ...PERSON_TYPES,
                                        ]}
                                        onChange={(value) =>
                                            go({
                                                treated_person_type:
                                                    value || null,
                                            })
                                        }
                                        allValue=""
                                    />
                                    <MoreFilters
                                        active={moreActive}
                                        filters={filters}
                                        firstAiders={filterOptions.first_aiders}
                                        onChange={go}
                                    />
                                    {filtered && (
                                        <PageHeaderFilterButton
                                            icon={X}
                                            onClick={() =>
                                                router.get(
                                                    FIRST_AID_REGISTER,
                                                    { tab },
                                                    {
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                        replace: true,
                                                    },
                                                )
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderFilterButton>
                                    )}
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={TABS.map((item) => ({
                                        ...item,
                                        count: tabCounts[item.key] ?? 0,
                                    }))}
                                    value={tab}
                                    onSelect={(next) => go({ tab: next })}
                                    ariaLabel="First-aid register views"
                                />
                            }
                        />
                    }
                >
                    <div className="grid min-w-0 gap-5">
                        {tab === 'reportable' ? (
                            <Note tone="warning" icon={AlertTriangle}>
                                Check these: an incident was reported, an
                                ambulance was called, or the person went to
                                hospital. This list doesn’t tell you whether
                                WorkSafe must be notified — check that on the
                                incident.
                            </Note>
                        ) : null}
                        <RecordTable
                            title="Treatments"
                            sub="Newest first · click a row to open it, right-click for more"
                            ariaLabel="First-aid treatment records"
                            rows={records.data}
                            rowKey={(record) => record.id}
                            onOpen={open}
                            actionsFor={actionsFor}
                            menuTitle={(record) =>
                                `${record.treated_person_name} · ${reference(record)}`
                            }
                            menuIcon={HeartPulse}
                            columns={columns}
                            empty={
                                <p className="sg-quiet">
                                    <Ring tone="none" />
                                    <span>
                                        No treatments match this view. Change
                                        the filters to find a record.
                                    </span>
                                </p>
                            }
                            footer={
                                records.data.length ? (
                                    <>
                                        <p className="text-subtle">
                                            Showing {records.data.length} of{' '}
                                            {records.total}
                                        </p>
                                        {records.last_page > 1 ? (
                                            <LaravelPagination
                                                links={records.links}
                                            />
                                        ) : null}
                                    </>
                                ) : undefined
                            }
                        />
                    </div>
                    {reporting && (
                        <FirstAidTreatmentWizard
                            pickers={pickers}
                            returnTo={returnTo}
                            onClose={closeReport}
                        />
                    )}
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}

/** Outcome and first aider — the two quieter filters, in one place. */
function MoreFilters({
    active,
    filters,
    firstAiders,
    onChange,
}: {
    active: number;
    filters: Filters;
    firstAiders: { id: number; name: string }[];
    onChange: (next: Partial<Filters>) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton
                    icon={SlidersHorizontal}
                    active={active > 0}
                >
                    {active > 0 ? `More filters · ${active}` : 'More filters'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="grid w-80 max-w-[calc(100vw-2rem)] gap-3"
            >
                <FilterField label="Outcome">
                    <SelectInput
                        ariaLabel="Outcome"
                        placeholder="All outcomes"
                        value={filters.treatment_outcome ?? 'all'}
                        onChange={(value) =>
                            onChange({
                                treatment_outcome:
                                    value === 'all' ? null : value,
                            })
                        }
                        options={[
                            { value: 'all', label: 'All outcomes' },
                            ...OUTCOMES,
                        ]}
                    />
                </FilterField>
                <FilterField label="First aider">
                    <SelectInput
                        ariaLabel="First aider"
                        placeholder="All first aiders"
                        value={String(filters.first_aider_id ?? 'all')}
                        onChange={(value) =>
                            onChange({
                                first_aider_id:
                                    value === 'all' ? null : Number(value),
                            })
                        }
                        options={[
                            { value: 'all', label: 'All first aiders' },
                            ...firstAiders.map((item) => ({
                                value: String(item.id),
                                label: item.name,
                            })),
                        ]}
                    />
                </FilterField>
            </PopoverContent>
        </Popover>
    );
}

function FilterField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <span className="text-caption font-semibold">{label}</span>
            {children}
        </div>
    );
}
