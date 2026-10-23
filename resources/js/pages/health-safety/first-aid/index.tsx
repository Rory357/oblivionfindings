import { FirstAidFact } from '@/components/health-safety/first-aid/record-panels';
import { FirstAidTreatmentWizard } from '@/components/health-safety/first-aid/treatment-wizard';
import type {
    FirstAidPickers,
    FirstAidRecord,
} from '@/components/health-safety/first-aid/types';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    FIRST_AID_REGISTER,
    firstAidHref,
    firstAidReturn,
} from '@/lib/health-safety/first-aid-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Ambulance,
    ClipboardList,
    Download,
    HeartPulse,
    LayoutList,
    Link2,
    Plus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    OUTCOMES,
    PERSON_TYPES,
    injuryLabel,
    outcomeLabel,
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
    { key: 'all', label: 'All treatments', icon: LayoutList },
    { key: 'ambulance', label: 'Ambulance called', icon: Ambulance },
    { key: 'linked', label: 'Linked incidents', icon: Link2 },
    { key: 'reportable', label: 'Needs incident review', icon: AlertTriangle },
    { key: 'followup', label: 'Open follow-ups', icon: ClipboardList },
];
const PERIODS = [
    { value: 'week', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'quarter', label: 'Last 3 months' },
    { value: 'all', label: 'All time' },
];

export default function FirstAidIndex({
    records,
    tab,
    tabCounts,
    filters,
    pickers,
    filterOptions,
    hero,
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
    const meterHref = (next: Partial<Filters> & { tab?: string }) =>
        `${FIRST_AID_REGISTER}?${query(next)}`;
    const closeReport = () =>
        drafts.request(() => {
            setReporting(false);
            router.replace({
                url: returnTo,
                preserveState: true,
                preserveScroll: true,
            });
        });
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'First aid', href: FIRST_AID_REGISTER },
            ]}
        >
            <Head title="First aid" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            frontline
                            wrapTitle
                            icon={HeartPulse}
                            title="First aid"
                            subline="Treatment records, follow-up work and connected incident reviews"
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
                                        <PageHeaderGlassButton asChild>
                                            <a
                                                href={`${FIRST_AID_REGISTER}/export?${query()}`}
                                            >
                                                <Download className="size-4" />
                                                Export filtered records
                                            </a>
                                        </PageHeaderGlassButton>
                                    )}
                                    {can.create && (
                                        <PageHeaderPrimaryButton asChild>
                                            <Link
                                                href={`${FIRST_AID_REGISTER}/report?return_to=${encodeURIComponent(returnTo)}`}
                                            >
                                                <Plus className="size-4" />
                                                Record first aid
                                            </Link>
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Treatments"
                                        href={meterHref({
                                            tab: 'all',
                                            period: '30d',
                                        })}
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.live.treated}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Last 30 days · current filters
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Ambulance called"
                                        href={meterHref({
                                            tab: 'ambulance',
                                            period: '30d',
                                        })}
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.live.ambulance}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Last 30 days · current filters
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Needs incident review"
                                        href={meterHref({
                                            tab: 'reportable',
                                            period: 'all',
                                        })}
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.attention.reportable_unlinked}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Reported incidents or significant
                                            treatment without a linked incident
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Treatments needing follow-up"
                                        href={meterHref({
                                            tab: 'followup',
                                            period: 'all',
                                        })}
                                    >
                                        <PageHeaderMeterBig>
                                            {hero.attention.followups_open}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            At least one open follow-up · all
                                            time
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="Site"
                                        value={String(filters.site_id ?? '')}
                                        options={pickers.sites.map((item) => ({
                                            value: String(item.id),
                                            label: item.name,
                                        }))}
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
                                        label="Person type"
                                        value={
                                            filters.treated_person_type ?? ''
                                        }
                                        options={PERSON_TYPES}
                                        onChange={(value) =>
                                            go({
                                                treated_person_type:
                                                    value || null,
                                            })
                                        }
                                        allValue=""
                                    />
                                    <PageHeaderFilterSelect
                                        label="Outcome"
                                        value={filters.treatment_outcome ?? ''}
                                        options={OUTCOMES}
                                        onChange={(value) =>
                                            go({
                                                treatment_outcome:
                                                    value || null,
                                            })
                                        }
                                        allValue=""
                                    />
                                    <PageHeaderFilterSelect
                                        label="First aider"
                                        value={String(
                                            filters.first_aider_id ?? '',
                                        )}
                                        options={filterOptions.first_aiders.map(
                                            (item) => ({
                                                value: String(item.id),
                                                label: item.name,
                                            }),
                                        )}
                                        onChange={(value) =>
                                            go({
                                                first_aider_id: value
                                                    ? Number(value)
                                                    : null,
                                            })
                                        }
                                        allValue=""
                                    />
                                    <PageHeaderFilterSelect
                                        label="Period"
                                        value={filters.period}
                                        allValue="30d"
                                        options={PERIODS}
                                        onChange={(value) =>
                                            go({ period: value || '30d' })
                                        }
                                    />
                                    <PageHeaderGlassButton
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
                                    </PageHeaderGlassButton>
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
                    <div className="space-y-5">
                        <p className="text-subtle">
                            {records.total} treatment records match this view.
                            {tab === 'reportable'
                                ? ' Reported incidents, ambulance calls and recorded hospital referrals need review; this list does not determine statutory notification requirements.'
                                : ''}
                        </p>
                        {!records.data.length ? (
                            <Card>
                                <CardContent className="space-y-2 p-6">
                                    <h2 className="text-section-title">
                                        No treatments match this view
                                    </h2>
                                    <p className="text-sm">
                                        Change the filters to find a record.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <>
                                <div className="hidden overflow-x-auto rounded-lg border lg:block">
                                    <table className="w-full text-left text-sm">
                                        <caption className="sr-only">
                                            First-aid treatment records
                                        </caption>
                                        <thead className="bg-muted/40">
                                            <tr>
                                                {[
                                                    'Treatment',
                                                    'Person and Site',
                                                    'Injury and outcome',
                                                    'Follow-up work',
                                                    'Open',
                                                ].map((label) => (
                                                    <th
                                                        key={label}
                                                        scope="col"
                                                        className="p-4 font-medium"
                                                    >
                                                        {label}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {records.data.map((record) => (
                                                <tr key={record.id}>
                                                    <td className="p-4">
                                                        <p className="font-medium">
                                                            {record.reference ??
                                                                `FA-${record.id}`}
                                                        </p>
                                                        <p className="text-subtle">
                                                            {formatDateTimeLong(
                                                                record.treatment_date,
                                                                'Treatment time not recorded',
                                                            )}
                                                        </p>
                                                        <p className="text-subtle">
                                                            {record.first_aider_name
                                                                ? `First aider: ${record.first_aider_name}`
                                                                : 'First aider not recorded'}
                                                        </p>
                                                    </td>
                                                    <td className="p-4">
                                                        <p className="font-medium">
                                                            {
                                                                record.treated_person_name
                                                            }
                                                        </p>
                                                        <p className="text-subtle">
                                                            {personTypeLabel(
                                                                record.treated_person_type,
                                                            )}{' '}
                                                            ·{' '}
                                                            {record.site
                                                                ?.name ??
                                                                'Site not recorded'}
                                                        </p>
                                                    </td>
                                                    <td className="space-y-2 p-4">
                                                        <p>
                                                            {injuryLabel(
                                                                record.injury_illness_type,
                                                            )}
                                                        </p>
                                                        <p>
                                                            {outcomeLabel(
                                                                record.treatment_outcome,
                                                            )}
                                                        </p>
                                                        {record.ambulance_called && (
                                                            <StatusBadge variant="warning">
                                                                Ambulance called
                                                            </StatusBadge>
                                                        )}
                                                    </td>
                                                    <td className="p-4">
                                                        <p>
                                                            {
                                                                record.open_followups_count
                                                            }{' '}
                                                            open follow-ups
                                                        </p>
                                                        <p className="text-subtle">
                                                            {
                                                                record.attachments_count
                                                            }{' '}
                                                            evidence records
                                                        </p>
                                                        {record.incident_reported && (
                                                            <p className="text-subtle">
                                                                Incident report
                                                                recorded
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td className="p-4">
                                                        <Button
                                                            className="frontline-tap"
                                                            asChild
                                                            variant="outline"
                                                        >
                                                            <Link
                                                                href={firstAidHref(
                                                                    record.id,
                                                                    {
                                                                        returnTo,
                                                                    },
                                                                )}
                                                            >
                                                                Open{' '}
                                                                {record.reference ??
                                                                    `FA-${record.id}`}
                                                            </Link>
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <ul className="space-y-4 lg:hidden">
                                    {records.data.map((record) => (
                                        <li key={record.id}>
                                            <Card>
                                                <CardContent className="space-y-4 p-5">
                                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                                        <div>
                                                            <h2 className="text-section-title">
                                                                {
                                                                    record.treated_person_name
                                                                }
                                                            </h2>
                                                            <p className="text-subtle">
                                                                {record.reference ??
                                                                    `FA-${record.id}`}{' '}
                                                                ·{' '}
                                                                {record.site
                                                                    ?.name ??
                                                                    'Site not recorded'}
                                                            </p>
                                                        </div>
                                                        {record.ambulance_called && (
                                                            <StatusBadge variant="warning">
                                                                Ambulance called
                                                            </StatusBadge>
                                                        )}
                                                    </div>
                                                    <dl className="grid gap-3 sm:grid-cols-2">
                                                        <FirstAidFact label="Treatment time">
                                                            {formatDateTimeLong(
                                                                record.treatment_date,
                                                                'Not recorded',
                                                            )}
                                                        </FirstAidFact>
                                                        <FirstAidFact label="Injury or illness">
                                                            {injuryLabel(
                                                                record.injury_illness_type,
                                                            )}
                                                        </FirstAidFact>
                                                        <FirstAidFact label="Recorded outcome">
                                                            {outcomeLabel(
                                                                record.treatment_outcome,
                                                            )}
                                                        </FirstAidFact>
                                                        <FirstAidFact label="First aider">
                                                            {
                                                                record.first_aider_name
                                                            }
                                                        </FirstAidFact>
                                                        <FirstAidFact label="Open follow-ups">
                                                            {
                                                                record.open_followups_count
                                                            }
                                                        </FirstAidFact>
                                                        <FirstAidFact label="Evidence records">
                                                            {
                                                                record.attachments_count
                                                            }
                                                        </FirstAidFact>
                                                    </dl>
                                                    <div className="flex flex-wrap gap-3">
                                                        <Button
                                                            className="frontline-tap"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={firstAidHref(
                                                                    record.id,
                                                                    {
                                                                        returnTo,
                                                                    },
                                                                )}
                                                            >
                                                                Open{' '}
                                                                {record.reference ??
                                                                    `FA-${record.id}`}
                                                            </Link>
                                                        </Button>
                                                        {record.open_followups_count >
                                                            0 && (
                                                            <Button
                                                                className="frontline-tap"
                                                                variant="outline"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={firstAidHref(
                                                                        record.id,
                                                                        {
                                                                            section:
                                                                                'followups',
                                                                            returnTo,
                                                                        },
                                                                    )}
                                                                >
                                                                    View
                                                                    follow-ups
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        <LaravelPagination links={records.links} />
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
