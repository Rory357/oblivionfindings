import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardList, History, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import { MedicationFollowupDialog } from '@/components/emar/followups/followup-dialog';
import { MedicationFollowupList } from '@/components/emar/followups/followup-list';
import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import type { DoseTarget } from '@/components/emar/record-dose/types';
import type { MedicationFollowup } from '@/components/emar/followups/types';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import AppLayout from '@/layouts/app-layout';

type Props = {
    followups: {
        data: MedicationFollowup[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    meters: {
        open: number;
        overdue: number;
        lead: number;
        unscheduled: number;
    };
    filters: {
        q?: string;
        type?: string;
        state?: string;
        client_id?: number;
        site_id?: number;
    };
    types: Record<string, string>;
    hidden_controlled?: number;
};

export default function Followups({
    followups,
    meters,
    filters,
    types,
    hidden_controlled = 0,
}: Props) {
    const page = usePage();
    const breadcrumbs = useEmarBreadcrumbs();
    const queue = useOfflineQueueState();
    const previousPending = useRef(queue.pendingCount);
    const [q, setQ] = useState(filters.q ?? '');
    const [dialog, setDialog] = useState<{
        id: number;
        mode: 'action' | 'history' | 'reassign';
    } | null>(null);
    const [reoffer, setReoffer] = useState<DoseTarget | null>(null);
    const actor = (page.props.auth as { user?: { name?: string } } | undefined)
        ?.user;
    const state = filters.state ?? 'open';
    const go = (changes: Record<string, string | number | undefined>) =>
        router.get(
            '/medication-followups',
            { ...filters, q, ...changes },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    useEffect(() => {
        const timer = window.setTimeout(() => {
            if (q !== (filters.q ?? ''))
                router.get(
                    '/medication-followups',
                    { ...filters, q, page: 1 },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        replace: true,
                    },
                );
        }, 350);
        return () => window.clearTimeout(timer);
    }, [q, filters]);
    useEffect(() => {
        const id = new URLSearchParams(page.url.split('?')[1] ?? '').get(
            'open',
        );
        if (id && /^\d+$/.test(id))
            setDialog({ id: Number(id), mode: 'action' });
    }, [page.url]);
    useEffect(() => {
        if (previousPending.current > queue.pendingCount)
            router.reload({
                only: ['followups', 'meters', 'hidden_controlled'],
            });
        previousPending.current = queue.pendingCount;
    }, [queue.pendingCount]);
    const open = (
        row: MedicationFollowup,
        mode: 'action' | 'history' | 'reassign' = 'action',
    ) => setDialog({ id: row.id, mode });
    const close = () => {
        setDialog(null);
        // Removing the deep-link does not reload or discard the list's filters.
        const url = new URL(window.location.href);
        url.searchParams.delete('open');
        window.history.replaceState(
            window.history.state,
            '',
            url.pathname + url.search,
        );
    };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Medication follow-ups" />
            <PageHeader
                icon={ShieldCheck}
                title="Safety & oversight"
                titleChip={
                    <PageHeaderStatusChip variant="neutral">
                        Follow-ups
                    </PageHeaderStatusChip>
                }
                subline="One record for medication work · your approved houses · times in New Zealand"
                actions={
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search person or medicine"
                    />
                }
                meters={
                    <>
                        <PageHeaderMeterBlock
                            label="Open"
                            onClick={() => go({ state: 'open', page: 1 })}
                            pressed={state === 'open'}
                        >
                            <PageHeaderMeterBig>
                                {meters.open}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Still to do
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Overdue"
                            tone={meters.overdue ? 'critical' : 'brand'}
                            onClick={() => go({ state: 'overdue', page: 1 })}
                            pressed={state === 'overdue'}
                        >
                            <PageHeaderMeterBig>
                                {meters.overdue}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Past the chosen time
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Lead review"
                            tone={meters.lead ? 'warning' : 'brand'}
                            onClick={() => go({ state: 'lead', page: 1 })}
                            pressed={state === 'lead'}
                        >
                            <PageHeaderMeterBig>
                                {meters.lead}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                House or clinical lead
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Time not set"
                            onClick={() =>
                                go({ state: 'unscheduled', page: 1 })
                            }
                            pressed={state === 'unscheduled'}
                        >
                            <PageHeaderMeterBig>
                                {meters.unscheduled}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Needs an explicit time
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                }
                filters={
                    <>
                        <PageHeaderFilterSelect
                            icon={ClipboardList}
                            label="All follow-ups"
                            value={filters.type ?? 'all'}
                            onChange={(type) =>
                                go({
                                    type: type === 'all' ? undefined : type,
                                    page: 1,
                                })
                            }
                            options={[
                                { value: 'all', label: 'All follow-ups' },
                                ...Object.entries(types).map(
                                    ([value, label]) => ({ value, label }),
                                ),
                            ]}
                        />
                        <PageHeaderFilterSelect
                            icon={History}
                            label="Open work"
                            value={state}
                            allValue="open"
                            onChange={(value) => go({ state: value, page: 1 })}
                            options={[
                                { value: 'open', label: 'Open work' },
                                { value: 'overdue', label: 'Overdue' },
                                { value: 'lead', label: 'Lead review' },
                                { value: 'unscheduled', label: 'Time not set' },
                                { value: 'done', label: 'Done and history' },
                            ]}
                        />
                    </>
                }
                rail={<EmarHubRail counts={{ followups: meters.open }} />}
            />
            <div className="flex min-w-0 flex-col gap-5 p-5">
                <MedicationFollowupList
                    rows={followups.data}
                    onOpen={open}
                    hiddenControlled={hidden_controlled}
                />
                {followups.last_page > 1 && (
                    <nav
                        aria-label="Follow-up pages"
                        className="flex flex-wrap items-center justify-between gap-3"
                    >
                        <span className="text-caption">
                            {followups.from ?? 0}–{followups.to ?? 0} of{' '}
                            {followups.total}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                disabled={followups.current_page <= 1}
                                onClick={() =>
                                    go({ page: followups.current_page - 1 })
                                }
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                disabled={
                                    followups.current_page >=
                                    followups.last_page
                                }
                                onClick={() =>
                                    go({ page: followups.current_page + 1 })
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
            <MedicationFollowupDialog
                id={dialog?.id ?? null}
                mode={dialog?.mode}
                onClose={close}
                onSaved={() =>
                    router.reload({
                        only: ['followups', 'meters', 'hidden_controlled'],
                    })
                }
                onReoffer={(row) => setReoffer(row.reoffer_target ?? null)}
            />
            {reoffer && (
                <RecordDoseDialog
                    target={reoffer}
                    entry="follow-up"
                    mode="reoffer"
                    signedAs={{ name: actor?.name ?? '', role_label: null }}
                    onClose={() => setReoffer(null)}
                    onRecorded={() =>
                        router.reload({
                            only: ['followups', 'meters', 'hidden_controlled'],
                            preserveScroll: true,
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
