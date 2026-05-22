import { Activity, AlertTriangle, Bell, Check, StickyNote } from 'lucide-react';

import { PageTabs } from '@/components/page/page-tabs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

import type {
    MyDayHandover,
    MyDayNotification,
    MyDayTaskFollowup,
} from '../lib/types';

interface DigestPanelProps {
    tab: 'handover' | 'alerts' | 'notifs';
    onTabChange: (tab: 'handover' | 'alerts' | 'notifs') => void;
    handover?: MyDayHandover | null;
    alerts: MyDayTaskFollowup[];
    notifications: MyDayNotification[];
    onAckAlert?: (alert: MyDayTaskFollowup) => void;
    onSnoozeAlert?: (alert: MyDayTaskFollowup) => void;
    onConfirmHandoverRead?: () => void;
}

export function DigestPanel({
    tab,
    onTabChange,
    handover,
    alerts,
    notifications,
    onAckAlert,
    onSnoozeAlert,
    onConfirmHandoverRead,
}: DigestPanelProps) {
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
                            label: 'Handover',
                            icon: StickyNote,
                            badge: handover?.unread ? (
                                <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning">
                                    New
                                </Badge>
                            ) : null,
                        },
                        {
                            value: 'alerts',
                            label: 'Alerts',
                            icon: AlertTriangle,
                            badge: alerts.length > 0 ? alerts.length : null,
                        },
                        {
                            value: 'notifs',
                            label: 'Updates',
                            icon: Bell,
                            badge: notifications.length > 0 ? notifications.length : null,
                        },
                    ]}
                >
                    <TabsContent value="handover" className="m-0">
                        <HandoverPane handover={handover} onConfirmRead={onConfirmHandoverRead} />
                    </TabsContent>
                    <TabsContent value="alerts" className="m-0">
                        <AlertsPane
                            alerts={alerts}
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
    if (!handover) {
        return (
            <div className="px-4 py-6 text-center text-sm text-muted-foreground">
                No handover for this shift.
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
                    <div className="text-[13.5px] font-semibold">{handover.from?.name ?? 'Previous shift'}</div>
                    <div className="text-[11.5px] text-muted-foreground">
                        {handover.from?.role ?? 'Previous shift'}
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
                        Unread
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
                <Button size="sm" className="flex-1" onClick={() => onConfirmRead?.()}>
                    Confirm read
                    <Check className="ml-1 h-3 w-3" />
                </Button>
                <Button size="sm" variant="outline" type="button">
                    <Activity className="h-3 w-3" /> 38s
                </Button>
            </div>
        </div>
    );
}

function AlertsPane({
    alerts,
    onAck,
    onSnooze,
}: {
    alerts: MyDayTaskFollowup[];
    onAck?: (alert: MyDayTaskFollowup) => void;
    onSnooze?: (alert: MyDayTaskFollowup) => void;
}) {
    if (alerts.length === 0) {
        return (
            <div className="px-4 py-6 text-center text-sm text-muted-foreground">
                No open alerts.
            </div>
        );
    }
    return (
        <div>
            {alerts.map((alert) => {
                const isCrit = alert.priority === 'critical';
                return (
                    <div
                        key={alert.id}
                        className={cn(
                            'flex items-start gap-2.5 border-b border-border px-4 py-3 last:border-b-0',
                            isCrit && 'bg-status-critical-bg',
                        )}
                    >
                        <div
                            className={cn(
                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                                isCrit
                                    ? 'bg-status-critical-bg text-status-critical'
                                    : 'bg-status-warning-bg text-status-warning',
                            )}
                        >
                            <AlertTriangle className="h-3.5 w-3.5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="text-[12.5px] font-semibold leading-snug">{alert.title}</div>
                            <div className="mt-0.5 text-[11px] text-muted-foreground">
                                {alert.meta?.client_name ?? '—'} · {timeSince(alert.created_at)}
                                {alert.meta?.sla_status ? ` · ${alert.meta.sla_status.replace('_', ' ')}` : ''}
                            </div>
                            {isCrit ? (
                                <div className="mt-2 flex gap-1.5">
                                    <Button size="sm" onClick={() => onAck?.(alert)}>
                                        Acknowledge
                                    </Button>
                                    <Button size="sm" variant="ghost" onClick={() => onSnooze?.(alert)}>
                                        Snooze 5m
                                    </Button>
                                </div>
                            ) : null}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function NotifsPane({ notifications }: { notifications: MyDayNotification[] }) {
    if (notifications.length === 0) {
        return (
            <div className="px-4 py-6 text-center text-sm text-muted-foreground">
                Nothing new.
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
