import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    ClipboardList,
    ShieldCheck,
    Sparkles,
    type LucideIcon,
} from 'lucide-react';
import { type ComponentType, useMemo, useState } from 'react';

import { PageTabs } from '@/components/page/page-tabs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { residentHue, residentInitials } from '../lib/resident-hue';
import type { MyDayIncident, MyDayTaskFollowup } from '../lib/types';

type OpenItemType = 'alert' | 'incident' | 'followup';

interface OpenItem {
    id: string;
    type: OpenItemType;
    title: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
    client?: { id: number; name: string; first_name: string; initials: string; hue: number } | null;
    time: string;
    href?: string;
    canAck?: boolean;
}

interface OpenItemsSectionProps {
    tasks: MyDayTaskFollowup[];
    incidents: MyDayIncident[];
    onAckAlert?: (item: MyDayTaskFollowup) => void;
}

const META: Record<OpenItemType, { label: string; tone: 'critical' | 'warning' | 'info'; icon: LucideIcon }> = {
    alert: { label: 'Alert', tone: 'critical', icon: AlertTriangle },
    incident: { label: 'Incident', tone: 'warning', icon: ShieldCheck },
    followup: { label: 'Follow-up', tone: 'info', icon: ClipboardList },
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

export function OpenItemsSection({ tasks, incidents, onAckAlert }: OpenItemsSectionProps) {
    const [filter, setFilter] = useState<'all' | OpenItemType>('all');
    const items = useMemo(() => combineItems(tasks, incidents), [tasks, incidents]);

    const counts = {
        all: items.length,
        alert: items.filter((i) => i.type === 'alert').length,
        incident: items.filter((i) => i.type === 'incident').length,
        followup: items.filter((i) => i.type === 'followup').length,
    };

    const visible = (filter === 'all' ? items : items.filter((i) => i.type === filter)).slice().sort(byPriority);
    const p1Count = items.filter((i) => i.priority === 'critical').length;

    if (items.length === 0) return null;

    return (
        <section
            data-test="my-day-open-items"
            className="pt-8"
        >
            <div className="mb-2 flex items-baseline gap-3 px-1">
                <h2 className="text-lg font-semibold tracking-tight">Things that need you</h2>
                <span className="text-[12.5px] text-muted-foreground">
                    {counts.all} open
                    {p1Count > 0 ? ` · ${p1Count} P1` : ''}
                </span>
            </div>

            <PageTabs
                value={filter}
                onValueChange={(next) => setFilter(next as 'all' | OpenItemType)}
                items={[
                    { value: 'all', label: 'All', icon: Sparkles, badge: counts.all },
                    { value: 'alert', label: 'Alerts', icon: AlertTriangle, badge: counts.alert },
                    { value: 'incident', label: 'Incidents', icon: ShieldCheck, badge: counts.incident },
                    { value: 'followup', label: 'Follow-ups', icon: ClipboardList, badge: counts.followup },
                ]}
            />

            <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {visible.map((item) => (
                    <OpenItemCard key={item.id} item={item} onAck={onAckAlert} />
                ))}
            </div>
        </section>
    );
}

function OpenItemCard({
    item,
    onAck,
}: {
    item: OpenItem & { _source?: MyDayTaskFollowup };
    onAck?: (raw: MyDayTaskFollowup) => void;
}) {
    const meta = META[item.type];
    const Icon = meta.icon;
    const isCrit = item.priority === 'critical';
    return (
        <div
            className={cn(
                'flex min-h-[130px] flex-col gap-2.5 rounded-xl border bg-card p-3.5',
                isCrit ? 'border-status-critical/30' : 'border-border',
            )}
        >
            <div className="flex items-center gap-2">
                <div
                    className={cn(
                        'flex h-[26px] w-[26px] items-center justify-center rounded-md',
                        TONE_TILE[meta.tone],
                    )}
                >
                    <Icon className="h-3 w-3" />
                </div>
                <Badge variant="outline" className={cn('text-[10.5px]', TONE_BADGE[meta.tone])}>
                    {meta.label}
                </Badge>
                {isCrit ? (
                    <Badge
                        variant="outline"
                        className="border-status-critical/30 bg-status-critical-bg text-[10.5px] text-status-critical"
                    >
                        P1
                    </Badge>
                ) : null}
                <span className="ml-auto text-[11px] text-muted-foreground">{timeSince(item.time)}</span>
            </div>
            <div className="flex-1 text-[13.5px] font-semibold leading-snug text-pretty">{item.title}</div>
            <div className="flex items-center gap-2">
                {item.client ? (
                    <>
                        <Avatar className="h-5 w-5">
                            <AvatarFallback
                                className="text-[9px] font-semibold"
                                style={{
                                    background: `oklch(0.85 0.10 ${item.client.hue})`,
                                    color: `oklch(0.28 0.16 ${item.client.hue})`,
                                }}
                            >
                                {item.client.initials}
                            </AvatarFallback>
                        </Avatar>
                        <span className="text-[11.5px] text-muted-foreground">{item.client.name}</span>
                    </>
                ) : null}
                {isCrit ? (
                    <Button
                        size="sm"
                        className="ml-auto"
                        onClick={() => item._source && onAck?.(item._source)}
                    >
                        Acknowledge
                    </Button>
                ) : item.href ? (
                    <Button asChild size="sm" variant="ghost" className="ml-auto">
                        <Link href={item.href}>
                            Open
                            <ArrowRight className="ml-1 h-2.5 w-2.5" />
                        </Link>
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

function combineItems(
    tasks: MyDayTaskFollowup[],
    incidents: MyDayIncident[],
): Array<OpenItem & { _source?: MyDayTaskFollowup }> {
    const fromTasks: Array<OpenItem & { _source?: MyDayTaskFollowup }> = tasks.map((t) => ({
        id: `task-${t.id}`,
        type: t.type === 'note_followup' ? 'followup' : (t.type as OpenItemType),
        title: t.title,
        priority: t.priority,
        client: t.meta?.client_name
            ? {
                  id: t.meta?.client_id ?? 0,
                  name: t.meta.client_name,
                  first_name: t.meta.client_name.split(' ')[0],
                  initials: residentInitials(
                      t.meta.client_name.split(' ')[0] ?? '',
                      t.meta.client_name.split(' ')[1],
                  ),
                  hue: residentHue(t.meta.client_id ?? t.meta.client_name),
              }
            : null,
        time: t.created_at,
        href: t.source_url,
        canAck: t.meta?.can_ack,
        _source: t,
    }));

    const fromIncidents: OpenItem[] = incidents.map((i) => ({
        id: `incident-${i.id}`,
        type: 'incident',
        title: i.title,
        priority: mapSeverity(i.severity),
        client: i.client_name
            ? {
                  id: i.client_id ?? 0,
                  name: i.client_name,
                  first_name: i.client_name.split(' ')[0],
                  initials: residentInitials(
                      i.client_name.split(' ')[0] ?? '',
                      i.client_name.split(' ')[1],
                  ),
                  hue: residentHue(i.client_id ?? i.client_name),
              }
            : null,
        time: i.occurred_at,
        href: i.url,
    }));

    return [...fromTasks, ...fromIncidents];
}

function mapSeverity(s: string): 'critical' | 'high' | 'medium' | 'low' {
    if (s === 'critical' || s === 'high' || s === 'medium' || s === 'low') return s;
    return 'medium';
}

function byPriority(a: OpenItem, b: OpenItem): number {
    const rank: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 };
    return (rank[a.priority] ?? 3) - (rank[b.priority] ?? 3);
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

export default OpenItemsSection;
