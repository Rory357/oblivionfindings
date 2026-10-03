import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, Home, Pill } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { ChartSection } from '@/components/emar/record/chart';
import { ClinicalSection } from '@/components/emar/record/clinical';
import { HistorySection } from '@/components/emar/record/history';
import {
    MedicinesSection,
    RecordMedicineDialog,
} from '@/components/emar/record/reading';
import { CanonicalSupportSection } from '@/components/emar/record/support';
import { RecordDoseLaunch } from '@/components/emar/record/record-dose-launch';
import { SafetySection } from '@/components/emar/record/safety';
import {
    locationSearch,
    readLocation,
    RECORD_SECTIONS,
    type RecordLocation,
} from '@/components/emar/record/sections';
import type { RecordPageProps } from '@/components/emar/record/types';
import {
    TabSearchPalette,
    TierTwoTabs,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { useEmarRecordBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';

export default function PersonMedicationRecord(props: RecordPageProps) {
    if (props.unavailable)
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    { title: 'Medication', href: '/emar' },
                ]}
            >
                <Head title="Medication record" />
                <Card className="p-2">
                    <EmptyState
                        icon={
                            props.unavailable === 'no_access'
                                ? AlertTriangle
                                : Pill
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
    return <AvailableRecord key={props.person.id} {...props} />;
}

function AvailableRecord({
    person,
    meters,
    as_at,
    can,
}: Extract<RecordPageProps, { person: unknown }>) {
    const page = usePage();
    const query = new URLSearchParams(page.url.split('?')[1]);
    const date = query.get('date');
    const week = query.get('mode') === 'week';
    const historyPage = Math.max(1, Number(query.get('page')) || 1);
    const sections = RECORD_SECTIONS.map((item) => ({
        ...item,
        views: item.views.filter(
            (view) => item.key !== 'history' || view.key !== 'changes' || can.view_audit,
        ),
    }));
    const [location, setLocation] = useState(() =>
        readLocation(page.url.split('?')[1] ?? ''),
    );
    const [search, setSearch] = useState('');
    const [find, setFind] = useState(false);
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
        href: `/emar/mar?client_id=${person.id}`,
    });
    const go = useCallback(
        (next: RecordLocation) => {
            setLocation(next);
            setSearch('');
            router.push({
                url: `/emar/mar${locationSearch(person.id, next)}`,
                preserveState: true,
                preserveScroll: true,
            });
        },
        [person.id],
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
            url: `/emar/mar${locationSearch(person.id, location, extra)}`,
            preserveState: true,
            preserveScroll: true,
        });
    const jump = (tab: RecordLocation['tab'], view?: string) => {
        const target = RECORD_SECTIONS.find((item) => item.key === tab);
        if (target) go({ tab, view: view ?? target.views[0].key });
    };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${person.preferred} · Medication record`} />
            <div className="flex min-w-0 max-w-full flex-col gap-5">
                <PageHeader
                    variant="profile"
                    wrapTitle
                    backHref="/emar/mar"
                    mark={
                        <span className="eh-mark-ring text-sm font-semibold">
                            {person.initials}
                        </span>
                    }
                    title={person.name}
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            {person.status ?? 'Record'}
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
                            <RecordDoseLaunch
                                clientId={person.id}
                                personName={person.preferred}
                            />
                        </>
                    }
                    meters={
                        <>
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
                                href={`/clients/${person.id}?tab=medical`}
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
                                    {meters.driver && !meters.driver.concealed
                                        ? meters.driver.last_check
                                            ? `Last checked ${formatDateTime(meters.driver.last_check)}`
                                            : 'No check recorded'
                                        : 'Open clinical record'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterButton icon={Clock} disabled>
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
                <div
                    id="medication-record-panel"
                    role="tabpanel"
                    aria-labelledby={`medication-record-tab-${activeView}`}
                    className="min-w-0 max-w-full"
                >
                    {location.tab === 'medicines' ? (
                        <MedicinesSection
                            clientId={person.id}
                            stopped={location.view === 'stopped'}
                            search={search}
                            onMedicine={setMedicine}
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
                        <SafetySection clientId={person.id} view={activeView} />
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
                            initialDoseId={Number(query.get('dose_id')) > 0 ? Number(query.get('dose_id')) : null}
                            onDetailClose={() => navigateQuery({ dose_id: null })}
                            page={historyPage}
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
        </AppLayout>
    );
}
