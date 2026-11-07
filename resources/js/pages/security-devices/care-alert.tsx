/**
 * One care or safety alert, for someone it was sent to who can't open the Alerts page (safety fix
 * round, 7 Nov 2026, Stephan: "Open just that alert"). They see what happened, who's attending,
 * and say they're attending with one tap. Nothing else: no snooze, no assign, no other alerts.
 * CareAlertController decides who may open it.
 */
import { PageHeader, PageHeaderPrimaryButton, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { NextStep } from '@/components/security-devices/device-blocks';
import { FactRows, KnowList, Log, Note, SideBySide, SideColumn, type SgTone } from '@/components/signature/blocks';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { HandHelping, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

const BASE = '/security-devices';

export type CareAlertTone = 'critical' | 'warning' | 'success' | 'info';

export type CareAlertProps = {
    alert: {
        id: number;
        title: string;
        tone: 'critical' | 'warning';
        what: string;
        detail: string;
        site: string;
        device: string;
        opened_at: string | null;
        active: boolean;
        attending: { name: string; at: string | null; you: boolean } | null;
        /** What happens if nobody says they're attending: told already, told after N minutes, or nothing more. */
        escalation: { state: 'told' | 'will' | 'none'; minutes: number | null };
        timeline: { id: number; at: string; title: string; tone: CareAlertTone }[];
    };
};

/** The line under the alert: what happens next, exactly as the server will do it. */
function nextWords(alert: CareAlertProps['alert'], attendingWords: string | null): string {
    if (!alert.active) return 'This alert is done. Nothing more is needed from you.';
    if (attendingWords) return `${attendingWords} Everyone it was sent to can see that. A manager or Control Room marks it done.`;
    if (alert.escalation.state === 'told') return 'Nobody said they were attending in time, so the on-call manager or Control Room has been told.';
    if (alert.escalation.state === 'will' && alert.escalation.minutes) {
        return `Nobody has said they’re attending yet. If nobody does within ${alert.escalation.minutes} min of it starting, the on-call manager or Control Room is told.`;
    }
    return 'Nobody has said they’re attending yet. Control Room has it too.';
}

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

/** What the person sees after a tap that didn't go through, in plain words. */
function refusal(error: unknown): string {
    if (axios.isAxiosError(error)) {
        if (!error.response) return 'We couldn’t tell if that went through. Check your connection, then look again.';
        if (error.response.status === 409) return 'This alert is already done.';
        if (error.response.status === 404 || error.response.status === 403) return 'You can’t attend to this alert any more.';
    }
    return 'That didn’t go through. Try again.';
}

export default function CareAlert({ alert }: CareAlertProps) {
    const now = useNow();
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const attending = alert.attending;
    const canAttend = alert.active && attending === null;

    const attend = () => {
        setProcessing(true);
        setError(null);
        axios
            .post<{ message?: string }>(`${BASE}/care-alerts/${alert.id}/attend`, {}, { headers: { Accept: 'application/json' } })
            .then((response) => {
                toast.success(response.data?.message ?? 'Everyone told knows you’re on it.');
                router.reload({ only: ['alert'] });
            })
            .catch((failure) => {
                setError(refusal(failure));
                router.reload({ only: ['alert'] });
            })
            .finally(() => setProcessing(false));
    };

    const statusChip = !alert.active ? (
        <PageHeaderStatusChip variant="success">Done</PageHeaderStatusChip>
    ) : attending ? (
        <PageHeaderStatusChip variant="info">Being attended to</PageHeaderStatusChip>
    ) : (
        <PageHeaderStatusChip variant={alert.tone}>Needs someone</PageHeaderStatusChip>
    );

    const header = (
        <PageHeader
            variant="profile"
            frontline
            backHref={BASE}
            icon={ShieldAlert}
            title={alert.title}
            wrapTitle
            titleChip={statusChip}
            subline={[alert.site, alert.device].filter(Boolean).join(' · ')}
            actions={
                canAttend ? (
                    <PageHeaderPrimaryButton icon={HandHelping} onClick={attend} disabled={processing} aria-busy={processing}>
                        {processing ? 'Telling everyone…' : 'I’m attending'}
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
        />
    );

    const label = `${alert.tone === 'critical' ? 'Safety' : 'Care'} · since ${alert.opened_at ? seenTime(alert.opened_at, now) : 'just now'}`;
    const attendingWords = attending
        ? `${attending.you ? 'You’re' : `${attending.name} is`} attending${attending.at ? ` since ${seenTime(attending.at, now)}` : ''}.`
        : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: BASE },
                { title: 'Alert', href: `${BASE}/care-alerts/${alert.id}` },
            ]}
        >
            <Head title={alert.title} />
            <PageLayout hero={header}>
                <SideBySide cols="minmax(0, 1fr) 360px">
                    <div className="flex flex-col gap-5">
                        {error ? <Note tone="warning">{error}</Note> : null}
                        <NextStep
                            tone={alert.active ? alert.tone : 'success'}
                            label={label}
                            title={alert.what || alert.title}
                            sub={alert.detail || undefined}
                            then={nextWords(alert, attendingWords)}
                        />
                        {alert.timeline.length > 0 ? (
                            <Log
                                title="What’s happened"
                                sub="Oldest first"
                                limit={20}
                                items={alert.timeline.map((row) => ({
                                    key: row.id,
                                    time: seenTime(row.at, now),
                                    tone: row.tone as SgTone,
                                    title: row.title,
                                }))}
                            />
                        ) : null}
                    </div>

                    <SideColumn>
                        <FactRows
                            title="About this alert"
                            rows={[
                                ['House', alert.site || 'Not known'],
                                ['Device', alert.device || 'Not known'],
                                ['Opened', alert.opened_at ? formatDateTime(alert.opened_at) : 'Not known'],
                                ['Attending', attending ? (attending.you ? 'You' : attending.name) : 'Nobody yet'],
                            ]}
                        />
                        <KnowList
                            title="What you can do here"
                            items={[
                                { tone: 'success', text: 'Say you’re attending, so everyone it was sent to knows' },
                                { tone: 'none', text: 'No snoozing and no other alerts' },
                                { tone: 'none', text: 'A manager or Control Room marks it done' },
                            ]}
                        />
                    </SideColumn>
                </SideBySide>
            </PageLayout>
        </AppLayout>
    );
}
