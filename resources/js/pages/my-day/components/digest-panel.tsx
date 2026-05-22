import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    Check,
    ClipboardList,
    ShieldCheck,
    StickyNote,
    type LucideIcon,
} from 'lucide-react';
import { useMemo } from 'react';

import { PageTabs } from '@/components/page/page-tabs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { cn } from '@/lib/utils';

import type {
    MyDayHandover,
    MyDayIncident,
    MyDayNotification,
    MyDayTaskFollowup,
} from '../lib/types';

interface DigestPanelProps {
    tab: 'handover' | 'alerts' | 'notifs';
    onTabChange: (tab: 'handover' | 'alerts' | 'notifs') => void;
    handover?: MyDayHandover | null;
    /** Control-room alerts + note follow-ups from /my-day's `tasks` payload. */
    alertTasks: MyDayTaskFollowup[];
    /** Client incidents reported by this worker. */
    incidents: MyDayIncident[];
    notifications: MyDayNotification[];
    onAckAlert?: (alert: MyDayTaskFollowup) => void;
    onSnoozeAlert?: (alert: MyDayTaskFollowup) => void;
    onConfirmHandoverRead?: () => void;
}

/**
 * Unified open-item shape that lets the Alerts pane render alerts, incidents
 * and follow-ups in one priority-sorted stream — replacing the deprecated
 * "Things that need you" grid that used to sit below the rail.
 */
type OpenItemKind = 'alert' | 'incident' | 'followup';

interface OpenItem {
    id: string;
    kind: OpenItemKind;
    title: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    clientName?: string | null;
    occurredAt: string;
    sla?: string | null;
    href?: string | null;
    canAck?: boolean;
    raw?: MyDayTaskFollowup;
}

/**
 * Static meta for each open-item kind. The label is resolved through the
 * i18n hook at render time (since hooks can't run inside this module-level
 * map), so this carries only the i18n key.
 */
const KIND_META: Record<OpenItemKind, { labelKey: 'digest_alert' | 'digest_incident' | 'digest_followup'; tone: 'critical' | 'warning' | 'info'; icon: LucideIcon }> = {
    alert: { labelKey: 'digest_alert', tone: 'critical', icon: AlertTriangle },
    incident: { labelKey: 'digest_incident', tone: 'warning', icon: ShieldCheck },
    followup: { labelKey: 'digest_followup', tone: 'info', icon: ClipboardList },
};

const TONE_TILE: Record<'critical' | 'warning' | 'info', string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-accent text-primary',
};

const TONE_BADGE: Record<'critical' | 'warning' | 'info', string> = {
    critical: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    warning: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
};

export function DigestPanel({
    tab,
    onTabChange,
    handover,
    alertTasks,
    incidents,
    notifications,
    onAckAlert,
    onSnoozeAlert,
    onConfirmHandoverRead,
}: DigestPanelProps) {
    const t = useMyDayLabels();
    const openItems = useMemo(() => combineOpenItems(alertTasks, incidents), [alertTasks, incidents]);

    return (
        <div
            data-test="my-day-digest"
            className="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div className="px-2">
                <PageTabs
                    value={tab}
                    onValueChange={(next) => onTabChange(next as 'handover' | 'alerts' | 'notifs')}
                    dense
                    items={[
                        {
                            value: 'handover',
                            label: t('digest_handover'),
                            icon: StickyNote,
                            badge: handover?.unread ? (
                                <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning">
                                    {t('digest_new_badge')}
                                </Badge>
                            ) : null,
                        },
                        {
                            value: 'alerts',
                            label: t('digest_needs_you'),
                            icon: AlertTriangle,
                            badge: openItems.length > 0 ? openItems.length : null,
                        },
                        {
                            value: 'notifs',
                            label: t('digest_updates'),
                            icon: Bell,
                            badge: notifications.length > 0 ? notifications.length : null,
                        },
                    ]}
                >
                    <TabsContent value="handover" className="m-0">
                        <HandoverPane handover={handover} onConfirmRead={onConfirmHandoverRead} />
                    </TabsContent>
                    <TabsContent value="alerts" className="m-0">
                        <NeedsYouPane
                            items={openItems}
                            onAck={onAckAlert}
                            onSnooze={onSnoozeAlert}
                        />
                    </TabsContent>
                    <TabsContent value="notifs" className="m-0">
                        <NotifsPane notifications={notifications} />
                    </TabsContent>
                </PageTabs>
            </div>
        </div>
    );
}

function HandoverPane({
    handover,
    onConfirmRead,
}: {
    handover?: MyDayHandover | null;
    onConfirmRead?: () => void;
}) {
    const t = useMyDayLabels();
    if (!handover) {
        return (
            <div className="px-4 py-6 text-center text-sm text-muted-foreground">
                {t('digest_no_handover')}
            </div>
        );
    }
    return (
        <div className="px-4 py-3.5">
            <div className="mb-2.5 flex items-center gap-2.5">
                {handover.from ? (
                    <Avatar className="h-8 w-8">
                        <AvatarFallback
                            style={{
                                background: `oklch(0.85 0.10 ${handover.from.hue})`,
                                color: `oklch(0.28 0.16 ${handover.from.hue})`,
                            }}
                            className="text-[12px] font-semibold"
                        >
                            {handover.from.initials}
                        </AvatarFallback>
                    </Avatar>
                ) : null}
                <div>
                    <div className="text-[13.5px] font-semibold">{handover.from?.name ?? t('digest_previous_shift')}</div>
                    <div className="text-[11.5px] text-muted-foreground">
                        {handover.from?.role ?? t('digest_previous_shift')}
                        {handover.recorded_at ? (
                            <>
                                {' · '}ended{' '}
                                {new Date(handover.recorded_at).toLocaleTimeString([], {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    hour12: false,
                                })}
                            </>
                        ) : null}
                    </div>
                </div>
                {handover.unread ? (
                    <Badge
                        variant="outline"
                        className="ml-auto border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning"
                    >
                        {t('digest_unread')}
                    </Badge>
                ) : null}
            </div>
            {handover.summary ? (
                <p className="mt-2.5 text-[13px] leading-[1.55]">{handover.summary}</p>
            ) : null}
            {handover.flags && handover.flags.length > 0 ? (
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                    {handover.flags.map((flag, i) => (
                        <Badge
                            key={i}
                            variant="outline"
                            className={cn(
                                'text-[10.5px]',
                                flag.tone === 'warn'
                                    ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                                    : 'border-status-info/30 bg-status-info-bg text-status-info',
                            )}
                        >
                            {flag.label}
                        </Badge>
                    ))}
                </div>
            ) : null}
            <div className="mt-3 flex gap-1.5">
                <Button
                    size="sm"
                    className="flex-1"
                    onClick={() => onConfirmRead?.()}
                    disabled={!onConfirmRead}
                >
                    {t('digest_confirm_read')}
                    <Check className="ml-1 h-3 w-3" />
                </Button>
            </div>
        </div>
    );
}

function NeedsYouPane({
    items,
    onAck,
    onSnooze,
}: {
    items: OpenItem[];
    onAck?: (alert: MyDayTaskFollowup) => void;
    onSnooze?: (alert: MyDayTaskFollowup) => void;
}) {
    const t = useMyDayLabels();
    if (items.length === 0) {
        return (
            <div className="px-4 py-6 text-center text-sm text-muted-foreground">
                {t('digest_nothing_needs')}
            </div>
        );
    }

    const p1 = items.filter((i) => i.priority === 'critical').length;

    return (
        <div>
            <div className="flex items-center gap-2 px-4 py-2 text-[11px] text-muted-foreground">
                <span>{items.length} open</span>
                {p1 > 0 ? (
                    <Badge
                        variant="outline"
                        className="border-status-critical/30 bg-status-critical-bg text-[10px] text-status-critical"
                    >
                        {p1} P1
                    </Badge>
                ) : null}
            </div>
            {items.map((item) => (
                <OpenItemRow
                    key={item.id}
                    item={item}
                    onAck={onAck}
                    onSnooze={onSnooze}
                />
            ))}
        </div>
    );
}

function OpenItemRow({
    item,
    onAck,
    onSnooze,
}: {
    item: OpenItem;
    onAck?: (alert: MyDayTaskFollowup) => void;
    onSnooze?: (alert: MyDayTaskFollowup) => void;
}) {
    const t = useMyDayLabels();
    const meta = KIND_META[item.kind];
    const Icon = meta.icon;
    const isCrit = item.priority === 'critical';
    return (
        <div
            className={cn(
                'flex items-start gap-2.5 border-b border-border px-4 py-3 last:border-b-0',
                isCrit && 'bg-status-critical-bg/40',
            )}
        >
            <div
                className={cn(
                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                    TONE_TILE[meta.tone],
                )}
            >
                <Icon className="h-3.5 w-3.5" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                    <Badge variant="outline" className={cn('text-[10px]', TONE_BADGE[meta.tone])}>
                        {t(meta.labelKey)}
                    </Badge>
                    {isCrit ? (
                        <Badge
                            variant="outline"
                            className="border-status-critical/30 bg-status-critical-bg text-[10px] text-status-critical"
                        >
                            P1
                        </Badge>
                    ) : null}
                    <span className="ml-auto text-[10.5px] text-muted-foreground">
                        {timeSince(item.occurredAt)}
                    </span>
                </div>
                <div className="mt-1 text-[12.5px] font-semibold leading-snug text-pretty">
                    {item.title}
                </div>
                <div className="mt-0.5 text-[11px] text-muted-foreground">
                    {[item.clientName, item.sla].filter(Boolean).join(' · ') || '—'}
                </div>
                {isCrit && item.raw ? (
                    <div className="mt-2 flex gap-1.5">
                        <Button size="sm" onClick={() => onAck?.(item.raw!)}>
                            {t('digest_acknowledge')}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => onSnooze?.(item.raw!)}>
                            {t('digest_snooze_15m')}
                        </Button>
                    </div>
                ) : item.href ? (
                    <div className="mt-2">
                        <Button asChild size="sm" variant="ghost">
                            <Link href={item.href}>
                                {t('digest_open')}
                                <ArrowRight className="ml-1 h-2.5 w-2.5" />
                            </Link>
                        </Button>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function NotifsPane({ notifications }: { notifications: MyDayNotification[] }) {
    const t = useMyDayLabels();
    if (notifications.length === 0) {
        return (
            <div className="px-4 py-6 text-center text-sm text-muted-foreground">
                {t('digest_nothing_new')}
            </div>
        );
    }
    return (
        <div>
            {notifications.map((n) => (
                <div
                    key={n.id}
                    className="flex items-start gap-2.5 border-b border-border px-4 py-2.5 last:border-b-0"
                >
                    <span
                        className={cn(
                            'mt-2 h-1.5 w-1.5 shrink-0 rounded-full',
                            n.tone === 'primary' && 'bg-primary',
                            n.tone === 'info' && 'bg-status-success',
                            (n.tone === 'muted' || !n.tone) && 'bg-muted-foreground',
                        )}
                    />
                    <div className="min-w-0 flex-1">
                        <div className="text-[12.5px] font-medium">{n.title}</div>
                        <div className="mt-0.5 text-[10.5px] text-muted-foreground">{n.at}</div>
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * Combine the controller's `tasks` (control-room alerts + note follow-ups) and
 * `incidents` payload into a single priority-sorted open-item list. Sort order:
 * critical > high > medium > low; ties broken by most-recent.
 */
function combineOpenItems(tasks: MyDayTaskFollowup[], incidents: MyDayIncident[]): OpenItem[] {
    const fromTasks: OpenItem[] = tasks.map((t) => ({
        id: `task-${t.id}`,
        kind: t.type === 'note_followup' || t.type === 'followup' ? 'followup' : (t.type as OpenItemKind),
        title: t.title,
        priority: t.priority,
        clientName: t.meta?.client_name ?? null,
        occurredAt: t.created_at,
        sla: t.meta?.sla_status ? humaniseSla(t.meta.sla_status) : null,
        href: t.source_url,
        canAck: !!t.meta?.can_ack,
        raw: t,
    }));

    const fromIncidents: OpenItem[] = incidents.map((i) => ({
        id: `incident-${i.id}`,
        kind: 'incident',
        title: i.title,
        priority: mapSeverity(i.severity),
        clientName: i.client_name,
        occurredAt: i.occurred_at,
        sla: i.requires_followup ? 'Follow-up required' : null,
        href: i.url,
    }));

    return [...fromTasks, ...fromIncidents].sort((a, b) => {
        const rank: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 };
        const diff = (rank[a.priority] ?? 3) - (rank[b.priority] ?? 3);
        if (diff !== 0) return diff;
        return new Date(b.occurredAt).getTime() - new Date(a.occurredAt).getTime();
    });
}

function mapSeverity(s: string): 'critical' | 'high' | 'medium' | 'low' {
    if (s === 'critical' || s === 'high' || s === 'medium' || s === 'low') return s;
    return 'medium';
}

function humaniseSla(s: 'on_track' | 'at_risk' | 'breached'): string {
    if (s === 'breached') return 'SLA breached';
    if (s === 'at_risk') return 'SLA at risk';
    return 'On track';
}

function timeSince(iso: string): string {
    const ms = Date.now() - new Date(iso).getTime();
    const m = Math.floor(ms / 60_000);
    if (m < 1) return 'just now';
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    return `${Math.floor(h / 24)}d ago`;
}

export default DigestPanel;
