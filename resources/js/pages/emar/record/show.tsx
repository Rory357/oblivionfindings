import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, Home, Pill } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import {
    MedicinesSection,
    RecordMedicineDialog,
    SupportSection,
} from '@/components/emar/record/reading';
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
import { formatTime } from '@/lib/datetime';

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
    return <AvailableRecord {...props} />;
}

function AvailableRecord({
    person,
    meters,
    as_at,
}: Extract<RecordPageProps, { person: unknown }>) {
    const page = usePage();
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
            window.history.pushState(
                null,
                '',
                `/emar/mar${locationSearch(person.id, next)}`,
            );
        },
        [person.id],
    );
    useEffect(() => {
        const onBack = () => setLocation(readLocation(window.location.search));
        window.addEventListener('popstate', onBack);
        return () => window.removeEventListener('popstate', onBack);
    }, []);
    const section = RECORD_SECTIONS.find((item) => item.key === location.tab)!;
    const jump = (tab: RecordLocation['tab'], view?: string) => {
        const target = RECORD_SECTIONS.find((item) => item.key === tab);
        if (target) go({ tab, view: view ?? target.views[0].key });
    };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${person.preferred} · Medication record`} />
            <div className="flex min-w-0 flex-col gap-5">
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
                        <PageHeaderSearchTrigger
                            placeholder="Find in this record…"
                            onOpen={() => setFind(true)}
                        />
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
                                        : 'warning'
                                }
                            >
                                <PageHeaderMeterBig>
                                    {meters.allergies.status === 'unavailable'
                                        ? 'Couldn’t load'
                                        : meters.allergies.count ||
                                          'None recorded'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Not reviewed · health profile
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
                            items={RECORD_SECTIONS}
                            value={location.tab}
                            onSelect={(tab) => jump(tab)}
                            onFind={() => setFind(true)}
                            ariaLabel="Medication record sections"
                        />
                    }
                />
                <TierTwoTabs
                    tabs={section.views}
                    activeTab={location.view}
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
                    aria-labelledby={`medication-record-tab-${location.view}`}
                    className="min-w-0"
                >
                    {location.tab === 'medicines' ? (
                        <MedicinesSection
                            clientId={person.id}
                            stopped={location.view === 'stopped'}
                            search={search}
                            onMedicine={setMedicine}
                        />
                    ) : (
                        <SupportSection
                            clientId={person.id}
                            assessment={location.view === 'assessment'}
                            onMedicine={setMedicine}
                        />
                    )}
                </div>
            </div>
            {medicine !== null ? (
                <RecordMedicineDialog
                    key={medicine}
                    clientId={person.id}
                    medicationId={medicine}
                    onClose={() => setMedicine(null)}
                />
            ) : null}
            <TabSearchPalette
                open={find}
                onClose={() => setFind(false)}
                groups={RECORD_SECTIONS.map((item) => ({
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
