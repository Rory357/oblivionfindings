import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    CalendarRange,
    Clock,
    Home,
    Pill,
    Printer,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { MarReportDialog } from '@/components/clients/profile/mar-day/report-dialog';
import { ChartSection } from '@/components/emar/record/chart';
import { ClinicalSection } from '@/components/emar/record/clinical';
import { DoseMeters } from '@/components/emar/record/dose-meters';
import { HistorySection } from '@/components/emar/record/history';
import { PhotosSection } from '@/components/emar/record/photos';
import {
    MedicinesSection,
    RecordMedicineDialog,
} from '@/components/emar/record/reading';
import { RecordDoseLaunch } from '@/components/emar/record/record-dose-launch';
import { SafetySection } from '@/components/emar/record/safety';
import {
    locationSearch,
    readLocation,
    RECORD_SECTIONS,
    type RecordLocation,
} from '@/components/emar/record/sections';
import { CanonicalSupportSection } from '@/components/emar/record/support';
import type { RecordPageProps } from '@/components/emar/record/types';
import {
    TabSearchPalette,
    TierTwoTabs,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { useEmarRecordBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';
import {
    emarScopedHref,
    isFrontlineMedication,
    type EmarNavigationPermissions,
} from '@/lib/emar-navigation';
import { medicationReturnTo } from '@/lib/medication-navigation';

export default function PersonMedicationRecord(props: RecordPageProps) {
    if (props.unavailable)
        return <UnavailableRecord unavailable={props.unavailable} />;
    return <AvailableRecord key={props.person.id} {...props} />;
}

/**
 * EA-079 (Lane C8): the same Home-rooted record trail as an open record —
 * frontline staff go back to Meds today, never to the lead Overview.
 */
function UnavailableRecord({
    unavailable,
}: {
    unavailable: NonNullable<RecordPageProps['unavailable']>;
}) {
    const page = usePage();
    const breadcrumbs = useEmarRecordBreadcrumbs({
        title: 'Medication record',
        href: page.url,
    });
    const props = { unavailable };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Medication record" />
            <Card className="p-2">
                <EmptyState
                    icon={
                        props.unavailable === 'no_access' ? AlertTriangle : Pill
                    }
                    title={
                        props.unavailable === 'no_access'
                            ? 'You don’t have medication-record access'
                            : 'Medication record not found'
                    }
                    description="Check your medication permissions and the person’s current house."
                    action={
                        <Button variant="outline" asChild>
                            <Link href="/dashboard">
                                <Home className="size-4" /> Home
                            </Link>
                        </Button>
                    }
                />
            </Card>
        </AppLayout>
    );
}

function AvailableRecord({
    person,
    meters,
    as_at,
    can,
    today,
}: Extract<RecordPageProps, { person: unknown }>) {
    const page = usePage<{ auth?: { can?: EmarNavigationPermissions } }>();
    const query = new URLSearchParams(page.url.split('?')[1]);
    const date = query.get('date');
    const hubUrl = new URL(
        emarScopedHref(
            isFrontlineMedication(page.props.auth?.can)
                ? '/meds/today'
                : '/emar/medications',
            page.url,
        ),
        'https://emar.invalid',
    );
    // EA-108 (Lane C8): back to everyone in the house, not a one-person list.
    hubUrl.searchParams.delete('client_id');
    const backHref =
        medicationReturnTo(query.get('return_to')) ??
        hubUrl.pathname + hubUrl.search;
    const week = query.get('mode') === 'week';
    const historyPage = Math.max(1, Number(query.get('page')) || 1);
    // EA-109 (Lane C9): each section's approved filters, kept in the URL.
    const pick = <T extends string>(
        key: string,
        allowed: readonly T[],
        fallback: T,
    ): T =>
        (allowed as readonly string[]).includes(query.get(key) ?? '')
            ? (query.get(key) as T)
            : fallback;
    const medType = pick('type', ['all', 'scheduled', 'prn'] as const, 'all');
    const attention = query.get('attn') === '1';
    const showResolved = query.get('resolved') === '1';
    const range = pick('range', ['7d', '30d', 'all'] as const, '7d');
    const outcome = pick(
        'out',
        ['all', 'given', 'notgiven', 'corrected'] as const,
        'all',
    );
    const sectionFilters: Record<string, string | null> = {
        type: medType === 'all' ? null : medType,
        attn: attention ? '1' : null,
        resolved: showResolved ? '1' : null,
        range: range === '7d' ? null : range,
        out: outcome === 'all' ? null : outcome,
    };
    // "As at" refreshes the header and reloads the open section.
    const [refreshKey, setRefreshKey] = useState(0);
    const refresh = () =>
        router.reload({
            onSuccess: () => {
                setRefreshKey((key) => key + 1);
                toast.success(
                    `Up to date · as at ${formatTime(new Date().toISOString())} · Pacific/Auckland`,
                );
            },
        });
    const sections = RECORD_SECTIONS.map((item) => ({
        ...item,
        views: item.views.filter(
            (view) =>
                item.key !== 'history' ||
                view.key !== 'changes' ||
                can.view_audit,
        ),
    }));
    const [location, setLocation] = useState(() =>
        readLocation(page.url.split('?')[1] ?? ''),
    );
    const [search, setSearch] = useState('');
    const [find, setFind] = useState(false);
    const [printing, setPrinting] = useState(false);
    const [medicine, setMedicine] = useState<number | null>(
        () =>
            Number(
                new URLSearchParams(page.url.split('?')[1]).get(
                    'medication_id',
                ),
            ) || null,
    );
    const breadcrumbs = useEmarRecordBreadcrumbs({
        title: person.preferred,
        href: emarScopedHref(`/emar/mar?client_id=${person.id}`, page.url),
    });
    const go = useCallback(
        (next: RecordLocation) => {
            setLocation(next);
            setSearch('');
            router.push({
                url: `/emar/mar${locationSearch(person.id, next, {}, page.url.split('?')[1])}`,
                preserveState: true,
                preserveScroll: true,
            });
        },
        [person.id, page.url],
    );
    useEffect(() => {
        setLocation(readLocation(page.url.split('?')[1] ?? ''));
        setMedicine(
            Number(
                new URLSearchParams(page.url.split('?')[1]).get(
                    'medication_id',
                ),
            ) || null,
        );
    }, [page.url]);
    const section = sections.find((item) => item.key === location.tab)!;
    const activeView = section.views.some((view) => view.key === location.view)
        ? location.view
        : section.views[0].key;
    const navigateQuery = (extra: Record<string, string | null>) =>
        router.push({
            url: `/emar/mar${locationSearch(person.id, location, { ...sectionFilters, ...extra }, page.url.split('?')[1])}`,
            preserveState: true,
            preserveScroll: true,
        });
    // C11: Medicines › Photos for one medicine (approved P02 "View pack photos").
    const openPhotos = (medicationId: number) => {
        const next: RecordLocation = { tab: 'medicines', view: 'photos' };
        setLocation(next);
        setMedicine(null);
        router.push({
            url: `/emar/mar${locationSearch(person.id, next, { med: String(medicationId) }, page.url.split('?')[1])}`,
            preserveState: true,
            preserveScroll: true,
        });
    };
    const jump = (tab: RecordLocation['tab'], view?: string) => {
        const target = RECORD_SECTIONS.find((item) => item.key === tab);
        if (target) go({ tab, view: view ?? target.views[0].key });
    };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${person.preferred} · Medication record`} />
            <div className="flex max-w-full min-w-0 flex-col gap-5">
                <PageHeader
                    variant="profile"
                    wrapTitle
                    mobileSummary={`${meters.medicines.count} medicines · ${person.house ?? 'Medication record'}`}
                    backHref={backHref}
                    mark={
                        <span className="eh-mark-ring text-sm font-semibold">
                            {person.initials}
                        </span>
                    }
                    title={person.name}
                    titleChip={
                        // EA-216 (Lane C9): the person's status in words and
                        // tone, as on their profile — never the raw value.
                        <PageHeaderStatusChip
                            variant={
                                person.status === 'active'
                                    ? 'success'
                                    : person.status === 'onboarding' ||
                                        person.status === 'respite'
                                      ? 'warning'
                                      : 'neutral'
                            }
                        >
                            {person.status
                                ? person.status.charAt(0).toUpperCase() +
                                  person.status.slice(1).replaceAll('_', ' ')
                                : 'Record'}
                        </PageHeaderStatusChip>
                    }
                    subline={
                        <>
                            {person.preferred} ·{' '}
                            {person.age !== null
                                ? `${person.age} years · `
                                : ''}
                            NHI {person.nhi ?? 'Not recorded'}
                            <br />
                            Medication record ·{' '}
                            {person.house ?? 'No house recorded'} ·{' '}
                            {person.service ?? 'No service recorded'}
                        </>
                    }
                    actions={
                        <>
                            <PageHeaderSearchTrigger
                                placeholder="Find in this record…"
                                onOpen={() => setFind(true)}
                            />
                            {can.print && today ? (
                                <PageHeaderGlassButton
                                    icon={Printer}
                                    onClick={() => setPrinting(true)}
                                >
                                    Print MAR
                                </PageHeaderGlassButton>
                            ) : null}
                            <RecordDoseLaunch
                                clientId={person.id}
                                personName={person.preferred}
                                asNeeded={
                                    location.tab === 'chart' &&
                                    activeView === 'asneeded'
                                }
                            />
                        </>
                    }
                    meters={
                        <>
                            <DoseMeters
                                doses={meters.doses ?? null}
                                onChart={() => jump('chart')}
                                onHistory={() => jump('history', 'doses')}
                            />
                            <PageHeaderMeterBlock
                                label="Medicines"
                                onClick={() => jump('medicines')}
                                ariaLabel="View current medicines"
                            >
                                <PageHeaderMeterBig>
                                    {meters.medicines.count}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {meters.medicines.as_needed} as needed ·{' '}
                                    {meters.medicines.to_check} waiting to be
                                    checked
                                    {meters.medicines.hidden
                                        ? ` · ${meters.medicines.hidden} controlled hidden`
                                        : ''}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Allergies"
                                onClick={() => jump('allergies', 'allergies')}
                                ariaLabel="View allergies"
                                tone={
                                    meters.allergies.status === 'recorded'
                                        ? 'critical'
                                        : meters.allergies.status === 'no_known'
                                          ? 'brand'
                                          : 'warning'
                                }
                            >
                                <PageHeaderMeterBig>
                                    {meters.allergies.status === 'unavailable'
                                        ? 'Couldn’t load'
                                        : meters.allergies.status === 'no_known'
                                          ? 'No known allergies'
                                          : meters.allergies.count ||
                                            'None recorded'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {meters.allergies.reviewed
                                        ? `Reviewed by ${meters.allergies.reviewed.by ?? 'recorded reviewer'}`
                                        : 'Not reviewed'}{' '}
                                    · health profile
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            {meters.inr ? (
                                <PageHeaderMeterBlock
                                    label="INR"
                                    onClick={() => jump('clinical', 'inr')}
                                    ariaLabel="View INR results"
                                >
                                    <PageHeaderMeterBig>
                                        {meters.inr?.value ?? 'None recorded'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meters.inr
                                            ? `${formatDateOnly(meters.inr.tested)} · ${meters.inr.target ? `target ${meters.inr.target.join('–')}` : 'target not recorded'}`
                                            : 'Open clinical results'}
                                        {meters.inr?.next
                                            ? ` · next ${formatDateOnly(meters.inr.next)}`
                                            : ''}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ) : null}
                            {meters.driver ? (
                                <PageHeaderMeterBlock
                                    label="Syringe driver"
                                    onClick={() => jump('clinical', 'driver')}
                                    ariaLabel="View syringe driver"
                                >
                                    <PageHeaderMeterBig>
                                        {meters.driver?.concealed
                                            ? 'Controlled access needed'
                                            : meters.driver
                                              ? 'Running'
                                              : 'None running'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meters.driver &&
                                        !meters.driver.concealed
                                            ? meters.driver.last_check
                                                ? `Last checked ${formatDateTime(meters.driver.last_check)}`
                                                : 'No check recorded'
                                            : 'Open clinical record'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ) : null}
                        </>
                    }
                    filters={
                        <>
                            {location.tab === 'chart' &&
                            activeView !== 'asneeded' ? (
                                <PageHeaderViewToggle
                                    ariaLabel="Chart layout"
                                    value={week ? 'week' : 'day'}
                                    onChange={(next) =>
                                        navigateQuery({
                                            mode:
                                                next === 'week' ? 'week' : null,
                                        })
                                    }
                                    options={[
                                        {
                                            value: 'day',
                                            label: 'Day',
                                            icon: CalendarDays,
                                        },
                                        {
                                            value: 'week',
                                            label: 'Week',
                                            icon: CalendarRange,
                                        },
                                    ]}
                                />
                            ) : null}
                            {location.tab === 'medicines' &&
                            location.view !== 'photos' ? (
                                <>
                                    <PageHeaderFilterSelect
                                        label="Type"
                                        value={medType}
                                        allValue="all"
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All types',
                                            },
                                            {
                                                value: 'scheduled',
                                                label: 'Scheduled',
                                            },
                                            {
                                                value: 'prn',
                                                label: 'As needed',
                                            },
                                        ]}
                                        onChange={(next) =>
                                            navigateQuery({
                                                type:
                                                    next === 'all'
                                                        ? null
                                                        : next,
                                            })
                                        }
                                    />
                                    <PageHeaderFilterCheck
                                        label="Needs attention"
                                        checked={attention}
                                        onChange={(next) =>
                                            navigateQuery({
                                                attn: next ? '1' : null,
                                            })
                                        }
                                    />
                                </>
                            ) : null}
                            {location.tab === 'allergies' &&
                            activeView === 'alerts' ? (
                                <PageHeaderFilterCheck
                                    label="Show resolved alerts"
                                    checked={showResolved}
                                    onChange={(next) =>
                                        navigateQuery({
                                            resolved: next ? '1' : null,
                                        })
                                    }
                                />
                            ) : null}
                            {location.tab === 'history' &&
                            activeView !== 'changes' ? (
                                <>
                                    <PageHeaderFilterSelect
                                        label="Range"
                                        value={range}
                                        options={[
                                            {
                                                value: '7d',
                                                label: 'Last 7 days',
                                            },
                                            {
                                                value: '30d',
                                                label: 'Last 30 days',
                                            },
                                            {
                                                value: 'all',
                                                label: 'All dates',
                                            },
                                        ]}
                                        onChange={(next) =>
                                            navigateQuery({
                                                range:
                                                    next === '7d' ? null : next,
                                                page: null,
                                            })
                                        }
                                    />
                                    <PageHeaderFilterSelect
                                        label="Outcome"
                                        value={outcome}
                                        allValue="all"
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All outcomes',
                                            },
                                            {
                                                value: 'given',
                                                label: 'Given or taken',
                                            },
                                            {
                                                value: 'notgiven',
                                                label: 'Not given',
                                            },
                                            {
                                                value: 'corrected',
                                                label: 'Has a correction',
                                            },
                                        ]}
                                        onChange={(next) =>
                                            navigateQuery({
                                                out:
                                                    next === 'all'
                                                        ? null
                                                        : next,
                                                page: null,
                                            })
                                        }
                                    />
                                </>
                            ) : null}
                            <PageHeaderFilterButton
                                icon={Clock}
                                onClick={refresh}
                            >
                                As at {formatTime(as_at)} · Pacific/Auckland
                            </PageHeaderFilterButton>
                            {location.tab === 'medicines' ? (
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Find a medicine…"
                                />
                            ) : null}
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={sections}
                            value={location.tab}
                            onSelect={(tab) => jump(tab)}
                            onFind={() => setFind(true)}
                            ariaLabel="Medication record sections"
                        />
                    }
                />
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <TierTwoTabs
                        tabs={section.views}
                        activeTab={activeView}
                        onTab={(view) => go({ tab: location.tab, view })}
                        testIdPrefix="medication-record"
                        panelId="medication-record-panel"
                        renderLink={(tab, className, inner, accessibility) => (
                            <Button
                                unstyled
                                className={className}
                                onClick={() =>
                                    go({ tab: location.tab, view: tab.key })
                                }
                                {...accessibility}
                            >
                                {inner}
                            </Button>
                        )}
                    />
                </div>
                <div
                    key={refreshKey}
                    id="medication-record-panel"
                    role="tabpanel"
                    aria-labelledby={`medication-record-tab-${activeView}`}
                    className="max-w-full min-w-0"
                >
                    {location.tab === 'medicines' &&
                    location.view === 'photos' ? (
                        <PhotosSection
                            clientId={person.id}
                            medicationId={Number(query.get('med')) || null}
                            onMedicine={setMedicine}
                            onShowAll={() => navigateQuery({ med: null })}
                        />
                    ) : location.tab === 'medicines' ? (
                        <MedicinesSection
                            clientId={person.id}
                            stopped={location.view === 'stopped'}
                            search={search}
                            type={medType}
                            attention={attention}
                            onMedicine={setMedicine}
                            onPhotos={openPhotos}
                        />
                    ) : location.tab === 'support' ? (
                        <CanonicalSupportSection
                            clientId={person.id}
                            view={activeView}
                        />
                    ) : location.tab === 'chart' ? (
                        <ChartSection
                            clientId={person.id}
                            personName={person.preferred}
                            canViewControlled={can.view_controlled}
                            view={activeView}
                            date={date}
                            week={week}
                            onChange={(nextDate, nextWeek) =>
                                navigateQuery({
                                    date: nextDate,
                                    mode: nextWeek ? 'week' : null,
                                })
                            }
                        />
                    ) : location.tab === 'allergies' ? (
                        <SafetySection
                            clientId={person.id}
                            view={activeView}
                            showResolved={showResolved}
                        />
                    ) : location.tab === 'clinical' ? (
                        <ClinicalSection
                            clientId={person.id}
                            view={activeView}
                        />
                    ) : (
                        <HistorySection
                            key={`${person.id}:${query.get('dose_id') ?? ''}:${activeView}`}
                            clientId={person.id}
                            view={activeView}
                            initialDoseId={
                                Number(query.get('dose_id')) > 0
                                    ? Number(query.get('dose_id'))
                                    : null
                            }
                            onDetailClose={() =>
                                navigateQuery({ dose_id: null })
                            }
                            page={historyPage}
                            range={range}
                            outcome={outcome}
                            onPage={(next) =>
                                navigateQuery({
                                    page: next > 1 ? String(next) : null,
                                })
                            }
                        />
                    )}
                </div>
            </div>
            {medicine !== null ? (
                <RecordMedicineDialog
                    key={medicine}
                    clientId={person.id}
                    medicationId={medicine}
                    onPhotos={openPhotos}
                    onClose={() => {
                        setMedicine(null);
                        if (query.has('medication_id')) {
                            query.delete('medication_id');
                            router.replace({
                                url: `/emar/mar?${query}`,
                                preserveState: true,
                                preserveScroll: true,
                            });
                        }
                    }}
                />
            ) : null}
            <TabSearchPalette
                open={find}
                onClose={() => setFind(false)}
                groups={sections.map((item) => ({
                    ...item,
                    tabs: item.views.map((view) => ({
                        ...view,
                        key: `${item.key}.${view.key}`,
                    })),
                }))}
                onTab={(key) => {
                    const [tab, view] = key.split('.');
                    setFind(false);
                    jump(tab as RecordLocation['tab'], view);
                }}
                testIdPrefix="medication-record"
                searchLabel="Find a section of this record"
            />
            {can.print && today ? (
                <MarReportDialog
                    open={printing}
                    onClose={() => setPrinting(false)}
                    clientId={person.id}
                    personName={person.preferred}
                    today={today}
                    controlledLeftOut={!can.view_controlled}
                />
            ) : null}
        </AppLayout>
    );
}
