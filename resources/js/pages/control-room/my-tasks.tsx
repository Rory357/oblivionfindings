import { useAlertActions } from '@/components/control-room/alert-record/alert-actions';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { buildControlRoomAlertRowActions } from '@/components/control-room/control-room-alert-row-actions';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import { AlertPreview } from '@/components/control-room/live-response/alert-preview';
import { ResponseWorklist } from '@/components/control-room/live-response/response-worklist';
import { useAlertSelection } from '@/components/control-room/live-response/use-alert-selection';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page';
import { PopupConfirm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, CheckCircle2, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface MyAlert extends AlertWorklistRow {
    sla_status: 'on_track' | 'at_risk' | 'breached' | null;
}

interface MyFollowup {
    id: number;
    content: string;
    type: string;
    followup_at: string | null;
    created_at: string | null;
    alert: {
        id: number;
        reference_number: string | null;
        alert_type: string;
        severity: string;
        status: string;
    } | null;
}

interface MyShift {
    id: number;
    name: string;
    role: string;
    starts_at: string;
    duration_minutes: number;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
}

interface Props {
    my_alerts: MyAlert[];
    my_followups: MyFollowup[];
    my_shift: MyShift | null;
    stats: { my_open: number; my_resolved_today: number; my_critical: number };
    can: { manage: boolean; create: boolean; assign: boolean; escalate: boolean };
    detail?: AlertWorkspaceDetail | null;
}

function duration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    if (hours === 0) return `${rest} min`;
    return rest ? `${hours} h ${rest} min` : `${hours} h`;
}

function scrollToSection(id: string) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * Live response › My work — what this person owns: their alerts, their
 * follow-ups and their duty (the approved "My work" view; the audit found the
 * old page's My Day link pointed at the wrong address).
 */
export default function MyTasks({
    my_alerts,
    my_followups,
    my_shift,
    stats,
    can,
    detail = null,
}: Props) {
    const [completing, setCompleting] = useState<MyFollowup | null>(null);
    const [completingBusy, setCompletingBusy] = useState(false);
    const actions = useAlertActions(detail);
    const selection = useAlertSelection({
        rows: my_alerts,
        detail,
        runAction: actions.run,
    });

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({
                only: ['my_alerts', 'my_followups', 'my_shift', 'stats', 'detail'],
            });
        }, 30_000);
        return () => window.clearInterval(poll);
    }, []);

    const rowActions = (row: AlertWorklistRow) =>
        buildControlRoomAlertRowActions(row, {
            select: (id) => selection.select(id),
            openAction: (id, key) => selection.select(id, key),
            post: (href) => router.post(href, {}, { preserveScroll: true }),
            visit: (href) => router.visit(href),
            copy: (value) => void navigator.clipboard?.writeText(value),
        });

    const overdue = (note: MyFollowup) =>
        note.followup_at !== null && new Date(note.followup_at).getTime() < Date.now();

    return (
        <ControlRoomPage
            headTitle="My work"
            header={{
                subline: 'Your assigned alerts, follow-ups and duty',
                actions: (
                    <>
                        <PageHeaderGlassButton icon={CalendarCheck} asChild>
                            <Link href="/my-day">Open My Day</Link>
                        </PageHeaderGlassButton>
                        {can.create ? (
                            <PageHeaderPrimaryButton icon={Plus} asChild>
                                <Link href="/control-room/alerts?new=1">Add alert</Link>
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Open"
                            tone={stats.my_open > 0 ? 'warning' : 'brand'}
                            onClick={() => scrollToSection('my-alerts')}
                            ariaLabel="Go to my alerts"
                        >
                            <PageHeaderMeterBig>{stats.my_open}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>assigned to me</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Critical"
                            tone={stats.my_critical > 0 ? 'critical' : 'brand'}
                            onClick={() => scrollToSection('my-alerts')}
                            ariaLabel="Go to my critical alerts"
                        >
                            <PageHeaderMeterBig>{stats.my_critical}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>needs attention</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Follow-ups"
                            onClick={() => scrollToSection('my-followups')}
                            ariaLabel="Go to my follow-ups"
                        >
                            <PageHeaderMeterBig>{my_followups.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>due or coming up</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Resolved"
                            tone="success"
                            href="/control-room/alerts?lens=history&assigned_to=me"
                            ariaLabel="Show alerts I resolved"
                        >
                            <PageHeaderMeterBig>{stats.my_resolved_today}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>today</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
            }}
        >
            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.75fr)]">
                <div className="flex min-w-0 flex-col gap-5">
                    <div id="my-alerts" className="scroll-mt-5">
                        <ResponseWorklist
                            title="My alerts"
                            description="Assigned to you, most urgent first."
                            worklist={{
                                data: my_alerts,
                                links: [],
                                meta: {
                                    current_page: 1,
                                    last_page: 1,
                                    total: my_alerts.length,
                                },
                            }}
                            selectedId={selection.selectedId}
                            onSelect={(id) => selection.select(id)}
                            getActions={rowActions}
                            emptyTitle="Nothing assigned to you"
                            emptyDescription="Alerts you claim or are given appear here. Unowned alerts are under Active."
                        />
                    </div>

                    <Card id="my-followups" className="scroll-mt-5 gap-0 overflow-hidden py-0">
                        <div className="border-b border-border px-5 py-4">
                            <h2 className="text-section-title">Follow-ups</h2>
                            <p className="text-subtle mt-0.5">
                                Reminders you set on alert notes.
                            </p>
                        </div>
                        {my_followups.length === 0 ? (
                            <div className="px-5 py-8">
                                <EmptyState
                                    icon={CheckCircle2}
                                    title="No follow-ups due"
                                    description="Add a follow-up to a note on any alert and it shows here."
                                />
                            </div>
                        ) : (
                            <ul className="divide-y divide-border">
                                {my_followups.map((note) => (
                                    <li
                                        key={note.id}
                                        className="flex items-start gap-4 px-5 py-3.5"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="line-clamp-2 text-sm text-foreground">
                                                {note.content}
                                            </p>
                                            <p className="text-caption mt-1 flex flex-wrap gap-x-3">
                                                <span
                                                    className={
                                                        overdue(note)
                                                            ? 'font-medium text-status-critical'
                                                            : undefined
                                                    }
                                                    title={formatDateTime(note.followup_at)}
                                                >
                                                    {note.followup_at
                                                        ? `${overdue(note) ? 'Overdue' : 'Due'} ${formatRelative(note.followup_at)}`
                                                        : 'No date set'}
                                                </span>
                                                {note.alert ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            selection.select(note.alert!.id)
                                                        }
                                                        className="font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    >
                                                        {note.alert.reference_number ??
                                                            `Alert ${note.alert.id}`}
                                                    </button>
                                                ) : null}
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setCompleting(note)}
                                        >
                                            Mark done
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="flex min-w-0 flex-col gap-5 xl:sticky xl:top-5">
                    <AlertPreview row={selection.row} detail={detail} onRun={actions.run} />
                    {my_shift ? (
                        <Card className="gap-3 px-5 py-5">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-section-title">My duty</h2>
                                    <p className="text-subtle mt-0.5">
                                        {my_shift.name} · {my_shift.role} · started{' '}
                                        {formatRelative(my_shift.starts_at)} (
                                        {duration(my_shift.duration_minutes)})
                                    </p>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/control-room/shifts">Duty handover</Link>
                                </Button>
                            </div>
                            <dl className="grid grid-cols-3 gap-2 text-center">
                                {(
                                    [
                                        ['Raised', my_shift.alerts_created],
                                        ['Resolved', my_shift.alerts_resolved],
                                        ['Escalated', my_shift.alerts_escalated],
                                    ] as const
                                ).map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex flex-col-reverse rounded-[10px] bg-muted/40 px-2 py-2.5"
                                    >
                                        <dt className="text-caption">{label}</dt>
                                        <dd className="text-section-title tabular-nums">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </Card>
                    ) : null}
                </div>
            </div>

            {actions.host}
            <PopupConfirm
                open={completing !== null}
                onClose={() => setCompleting(null)}
                onConfirm={() => {
                    if (!completing) return;
                    setCompletingBusy(true);
                    router.post(
                        `/control-room/my-tasks/followups/${completing.id}/complete`,
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => {
                                setCompletingBusy(false);
                                setCompleting(null);
                            },
                        },
                    );
                }}
                icon={CheckCircle2}
                title="Mark this follow-up done?"
                description={completing?.content}
                confirmLabel="Mark done"
                processing={completingBusy}
            />
        </ControlRoomPage>
    );
}
