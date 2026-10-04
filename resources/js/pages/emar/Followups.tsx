import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import { AdministrationFollowupDialog } from '@/components/emar/followups/administration-followup-dialog';
import { MedicationFollowupDialog } from '@/components/emar/followups/followup-dialog';
import { MedicationFollowupList } from '@/components/emar/followups/followup-list';
import type { MedicationFollowup } from '@/components/emar/followups/types';
import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import type { DoseTarget } from '@/components/emar/record-dose/types';
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
import { formatDateTime } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardList, History, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type LegacyEffectCheck = {
    source_key: string;
    administration_id: number;
    client: { id: number; name: string };
    site: { id: number; name: string };
    medication: { id: number; name: string };
    owner: { id: number; name: string } | null;
    due_at: string | null;
    given_at: string | null;
    can_prepare: boolean;
    record_url: string;
};
type LegacyChecks = {
    total: number;
    overdue: number;
    unscheduled: number;
    filtered_total: number;
    data: LegacyEffectCheck[];
    has_more: boolean;
};
type Props = {
    selected_followup_id?: number | null;
    legacy_effect_checks?: LegacyChecks;
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
    selected_followup_id = null,
    hidden_controlled = 0,
    legacy_effect_checks: legacy = {
        total: 0,
        overdue: 0,
        unscheduled: 0,
        filtered_total: 0,
        data: [],
        has_more: false,
    },
}: Props) {
    const page = usePage();
    const [legacyId, setLegacyId] = useState<number | null>(null);
    const breadcrumbs = useEmarBreadcrumbs();
    const queue = useOfflineQueueState();
    const previousPending = useRef(queue.pendingCount);
    const administration = new URLSearchParams(
        page.url.split('?')[1] ?? '',
    ).get('administration');
    const requestedAdministration =
        administration && /^[1-9]\d*$/.test(administration)
            ? Number(administration)
            : null;
    const permittedLegacyId =
        legacy.data.find(
            (item) =>
                item.administration_id === requestedAdministration &&
                item.can_prepare,
        )?.administration_id ?? null;
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
        else if (requestedAdministration && selected_followup_id)
            setDialog({ id: selected_followup_id, mode: 'action' });
        // Only a server-authorised legacy row may trigger preparation. Existing
        // canonical deep links open their read-only details, even outside this page.
        setLegacyId(selected_followup_id ? null : permittedLegacyId);
    }, [
        page.url,
        requestedAdministration,
        selected_followup_id,
        permittedLegacyId,
    ]);
    useEffect(() => {
        if (previousPending.current > queue.pendingCount)
            router.reload({
                only: [
                    'followups',
                    'meters',
                    'legacy_effect_checks',
                    'hidden_controlled',
                ],
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
        url.searchParams.delete('administration');
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
                                {meters.open + legacy.total}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Still to do
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Overdue"
                            tone={
                                meters.overdue + legacy.overdue
                                    ? 'critical'
                                    : 'brand'
                            }
                            onClick={() => go({ state: 'overdue', page: 1 })}
                            pressed={state === 'overdue'}
                        >
                            <PageHeaderMeterBig>
                                {meters.overdue + legacy.overdue}
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
                                {meters.unscheduled + legacy.unscheduled}
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
                rail={
                    <EmarHubRail
                        counts={{ followups: meters.open + legacy.total }}
                    />
                }
            />
            <div className="flex min-w-0 flex-col gap-5 pt-5">
                {legacy.data.length > 0 && (
                    <section
                        aria-labelledby="pending-effect-checks"
                        className="rounded-xl border bg-card p-4"
                    >
                        <h2
                            id="pending-effect-checks"
                            className="font-semibold"
                        >
                            Effect checks awaiting review
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            These recorded as-needed doses still need an effect
                            check. Opening a check does not record an outcome.
                        </p>
                        <ul className="mt-3 divide-y">
                            {legacy.data.map((item) => (
                                <li
                                    key={item.source_key}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {item.client.name} ·{' '}
                                            {item.medication.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {item.site.name} · Given{' '}
                                            {item.given_at
                                                ? formatDateTime(item.given_at)
                                                : 'time not recorded'}
                                        </p>
                                        <p className="text-sm">
                                            {item.due_at
                                                ? 'Check by ' +
                                                  formatDateTime(item.due_at)
                                                : 'Check time not set — arrange with the medication lead'}{' '}
                                            ·{' '}
                                            {item.owner?.name ??
                                                'Owner not assigned'}
                                        </p>
                                    </div>
                                    {item.can_prepare ? (
                                        <Button
                                            className="frontline-tap"
                                            onClick={() =>
                                                setLegacyId(
                                                    item.administration_id,
                                                )
                                            }
                                        >
                                            Open effect check
                                        </Button>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            An authorised medication worker must
                                            open this check.
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {legacy.has_more && (
                            <p className="text-sm text-muted-foreground">
                                Showing {legacy.data.length} of{' '}
                                {legacy.filtered_total}. Search by person or
                                medicine to find the remaining checks.
                            </p>
                        )}
                    </section>
                )}
                {(followups.data.length > 0 || legacy.data.length === 0) && (
                    <MedicationFollowupList
                        rows={followups.data}
                        onOpen={open}
                        hiddenControlled={hidden_controlled}
                    />
                )}
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
                        only: [
                            'followups',
                            'meters',
                            'legacy_effect_checks',
                            'hidden_controlled',
                        ],
                    })
                }
                onReoffer={(row) => setReoffer(row.reoffer_target ?? null)}
            />
            {legacyId && (
                <AdministrationFollowupDialog
                    key={legacyId}
                    administrationId={legacyId}
                    onClose={() => {
                        setLegacyId(null);
                        const url = new URL(window.location.href);
                        url.searchParams.delete('administration');
                        window.history.replaceState(
                            window.history.state,
                            '',
                            url.pathname + url.search,
                        );
                        router.reload({
                            only: [
                                'followups',
                                'meters',
                                'legacy_effect_checks',
                            ],
                            preserveScroll: true,
                        });
                    }}
                />
            )}
            {reoffer && (
                <RecordDoseDialog
                    target={reoffer}
                    entry="follow-up"
                    mode="reoffer"
                    signedAs={{ name: actor?.name ?? '', role_label: null }}
                    onClose={() => setReoffer(null)}
                    onRecorded={() =>
                        router.reload({
                            only: [
                                'followups',
                                'meters',
                                'legacy_effect_checks',
                                'hidden_controlled',
                            ],
                            preserveScroll: true,
                        })
                    }
                />
            )}
        </AppLayout>
    );
}
