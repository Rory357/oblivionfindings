/* Health & Safety worklists (WS4): actionable rows for overdue corrective actions, open
 * investigations, WorkSafe-notifiable events and the unified expiring feed (all from the WS1
 * payload). Each row: status pill (tone+icon+label) + title/sub + owner + due. Left-click →
 * HsDetailDialog; right-click → ShiftContextMenu (View detail · View client · View staff ·
 * View register · Print) with the client/staff deep-link ids the builders emit. Tokens only. */
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    Check,
    ClipboardCheck,
    ClipboardList,
    Clock,
    ExternalLink,
    Eye,
    FileText,
    type LucideIcon,
    Printer,
    Search,
    ShieldAlert,
    User,
    UserCog,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { HsDetailDialog, type HsDetail } from './hs-detail-dialog';

/* ------------------------------------------------------------------ */
/*  Payload row types (match the WS1 HsDashboardService builders)      */
/* ------------------------------------------------------------------ */

export type CorrectiveActionRow = {
    id: number;
    reference: string | null;
    title: string | null;
    priority: string | null;
    status: string | null;
    due_date: string | null;
    days_overdue: number | null;
    owner: string | null;
    client_id: number | null;
    staff_id: number | null;
    event_reference: string | null;
};

export type InvestigationRow = {
    id: number;
    reference: string | null;
    type: string | null;
    status: string | null;
    target_completion_date: string | null;
    is_overdue: boolean;
    owner: string | null;
    client_id: number | null;
    staff_id: number | null;
    event_reference: string | null;
};

export type NotifiableRow = {
    id: number;
    title: string | null;
    incident_type: string | null;
    status: string;
    occurred_at: string | null;
    notified_at: string | null;
    notification_deadline: string | null;
    site_preserved: boolean;
    worksafe_ref: string | null;
    related_incident_id: number | null;
};

export type ExpiringRow = {
    type: string;
    type_label: string;
    label: string | null;
    due_date: string | null;
    days_until: number | null;
    register_url: string;
    site: string | null;
};

export type WorklistsPayload = {
    overdue_corrective_actions: CorrectiveActionRow[];
    open_investigations: InvestigationRow[];
    notifiable_events: NotifiableRow[];
    expiring: ExpiringRow[];
};

export type WorklistKey = 'corrective_actions' | 'investigations' | 'notifiable' | 'expiring';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

type PillTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

const PILL_CLASS: Record<PillTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
};

const TAG_TONE_BG: Record<PillTone, string> = {
    success: 'var(--status-success-bg)',
    warning: 'var(--status-warning-bg)',
    critical: 'var(--status-critical-bg)',
    info: 'var(--status-info-bg)',
    neutral: 'var(--muted)',
};

/* Tinted rounded-square badge behind each card-header icon (prototype: 30px, --x-bg / --x). */
const BADGE_TONE: Record<PillTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-accent text-primary',
};

function fmtDate(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function titleCase(value?: string | null): string {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const AVATAR_TONES = [
    'bg-primary text-primary-foreground',
    'bg-status-info text-white',
    'bg-status-success text-white',
    'bg-status-warning text-white',
];

function avatarTone(name: string): string {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return AVATAR_TONES[h % AVATAR_TONES.length];
}

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

type NormRow = {
    key: string;
    pill: { label: string; tone: PillTone; icon?: LucideIcon };
    title: string;
    sub: string;
    owner: string | null;
    due: string | null;
    detail: HsDetail;
    clientId: number | null;
    staffId: number | null;
    registerUrl: string;
    registerLabel: string;
    tag: string;
    tagTone: PillTone;
    meta: string;
};

/* ------------------------------------------------------------------ */
/*  Per-type row builders (pure)                                       */
/* ------------------------------------------------------------------ */

function correctiveActionRows(rows: CorrectiveActionRow[]): NormRow[] {
    return rows.map((r) => ({
        key: `ca-${r.id}`,
        pill: {
            label: r.days_overdue != null ? `${r.days_overdue}d overdue` : 'Overdue',
            tone: ['high', 'critical', 'urgent'].includes((r.priority ?? '').toLowerCase()) ? 'critical' : 'warning',
            icon: ['high', 'critical', 'urgent'].includes((r.priority ?? '').toLowerCase()) ? AlertTriangle : undefined,
        },
        title: r.title ?? r.reference ?? `Corrective action #${r.id}`,
        sub: [r.reference, titleCase(r.priority)].filter(Boolean).join(' · '),
        owner: r.owner,
        due: fmtDate(r.due_date),
        clientId: r.client_id,
        staffId: r.staff_id,
        registerUrl: '/health-safety/corrective-actions',
        registerLabel: 'Corrective actions',
        tag: 'CA',
        tagTone: 'critical',
        meta: r.reference ?? `#${r.id}`,
        detail: {
            title: 'Corrective action',
            description: 'Read-only detail of an overdue corrective action.',
            railIcon: ClipboardCheck,
            railTitle: r.title ?? r.reference ?? `#${r.id}`,
            railSub: r.reference ?? 'Corrective action',
            cardTitle: 'Corrective action',
            cardIcon: ClipboardList,
            registerUrl: '/health-safety/corrective-actions',
            registerLabel: 'Corrective actions',
            clientId: r.client_id,
            staffId: r.staff_id,
            rows: [
                { label: 'Reference', value: r.reference },
                { label: 'Title', value: r.title },
                { label: 'Priority', value: titleCase(r.priority) },
                { label: 'Status', value: titleCase(r.status) },
                { label: 'Due date', value: fmtDate(r.due_date) },
                { label: 'Days overdue', value: r.days_overdue != null ? `${r.days_overdue}` : '—' },
                { label: 'Owner', value: r.owner },
                { label: 'Linked event', value: r.event_reference },
            ],
        },
    }));
}

function investigationRows(rows: InvestigationRow[]): NormRow[] {
    return rows.map((r) => ({
        key: `inv-${r.id}`,
        pill: r.is_overdue
            ? { label: 'Overdue', tone: 'critical', icon: Clock }
            : { label: titleCase(r.status), tone: 'info', icon: Search },
        title: `Investigation ${r.reference ?? `#${r.id}`}`,
        sub: titleCase(r.type),
        owner: r.owner,
        due: fmtDate(r.target_completion_date),
        clientId: r.client_id,
        staffId: r.staff_id,
        registerUrl: '/health-safety/events',
        registerLabel: 'Investigations',
        tag: 'INV',
        tagTone: r.is_overdue ? 'critical' : 'info',
        meta: r.reference ?? `#${r.id}`,
        detail: {
            title: 'Investigation',
            description: 'Read-only detail of an open H&S investigation.',
            railIcon: Search,
            railTitle: r.reference ?? `Investigation #${r.id}`,
            railSub: titleCase(r.type),
            cardTitle: 'Investigation',
            cardIcon: Search,
            registerUrl: '/health-safety/events',
            registerLabel: 'Investigations',
            clientId: r.client_id,
            staffId: r.staff_id,
            rows: [
                { label: 'Reference', value: r.reference },
                { label: 'Type', value: titleCase(r.type) },
                { label: 'Status', value: titleCase(r.status) },
                { label: 'Target completion', value: fmtDate(r.target_completion_date) },
                { label: 'Overdue', value: r.is_overdue ? 'Yes' : 'No' },
                { label: 'Lead investigator', value: r.owner },
                { label: 'Linked event', value: r.event_reference },
            ],
        },
    }));
}

function notifiableRows(rows: NotifiableRow[]): NormRow[] {
    return rows.map((r) => {
        const awaiting = r.status === 'pending';
        const notifiedSub = [r.worksafe_ref ? `Ref ${r.worksafe_ref}` : null, r.notified_at ? fmtDate(r.notified_at) : null]
            .filter(Boolean)
            .join(' · ');
        return {
            key: `ntf-${r.id}`,
            pill: awaiting
                ? { label: 'Awaiting', tone: 'critical', icon: Clock }
                : { label: titleCase(r.status), tone: 'success', icon: Check },
            title: r.title ?? `Notifiable event #${r.id}`,
            sub: awaiting ? 'Notify WorkSafe — action required' : notifiedSub || titleCase(r.incident_type),
            owner: null,
            due: null,
            clientId: null,
            staffId: null,
            registerUrl: '/health-safety/reports/worksafe-register',
            registerLabel: 'WorkSafe register',
            tag: 'WS',
            tagTone: awaiting ? 'warning' : 'success',
            meta: titleCase(r.incident_type),
            detail: {
                title: 'WorkSafe notifiable event',
                description: 'HSWA 2015 notifiable event — records kept ≥ 5 years.',
                railIcon: ShieldAlert,
                railTitle: r.title ?? `Notifiable #${r.id}`,
                railSub: titleCase(r.incident_type),
                cardTitle: 'Notifiable event',
                cardIcon: ShieldAlert,
                registerUrl: '/health-safety/reports/worksafe-register',
                registerLabel: 'WorkSafe register',
                rows: [
                    { label: 'Title', value: r.title },
                    { label: 'Type', value: titleCase(r.incident_type) },
                    { label: 'Status', value: titleCase(r.status) },
                    { label: 'Occurred', value: fmtDate(r.occurred_at) },
                    { label: 'Notified', value: fmtDate(r.notified_at) },
                    { label: 'Deadline', value: fmtDate(r.notification_deadline) },
                    { label: 'Site preserved', value: r.site_preserved ? 'Yes' : 'No' },
                    { label: 'WorkSafe ref', value: r.worksafe_ref },
                ],
            },
        };
    });
}

function expiringRows(rows: ExpiringRow[]): NormRow[] {
    return rows.map((r, i) => {
        const overdue = r.days_until != null && r.days_until < 0;
        return {
            key: `exp-${r.type}-${i}`,
            pill: overdue
                ? { label: r.days_until != null ? `Expired ${Math.abs(r.days_until)}d` : 'Overdue', tone: 'critical' }
                : { label: r.days_until != null ? `${r.days_until} days` : 'Due', tone: 'warning' },
            title: r.label ?? r.type_label,
            sub: r.type_label,
            owner: null,
            due: null,
            clientId: null,
            staffId: null,
            registerUrl: r.register_url,
            registerLabel: 'Register',
            tag: 'EXP',
            tagTone: overdue ? 'critical' : 'warning',
            meta: r.type_label,
            detail: {
                title: 'Expiring register item',
                description: 'An item due for review or renewal.',
                railIcon: CalendarClock,
                railTitle: r.label ?? r.type_label,
                railSub: r.type_label,
                cardTitle: 'Expiring item',
                cardIcon: CalendarClock,
                registerUrl: r.register_url,
                registerLabel: 'Register',
                rows: [
                    { label: 'Item', value: r.label },
                    { label: 'Type', value: r.type_label },
                    { label: 'Due date', value: fmtDate(r.due_date) },
                    { label: 'Days until due', value: r.days_until != null ? `${r.days_until}` : '—' },
                    { label: 'Site', value: r.site },
                ],
            },
        };
    });
}

/* ------------------------------------------------------------------ */
/*  Orchestrator                                                       */
/* ------------------------------------------------------------------ */

type CardConfig = {
    key: WorklistKey;
    icon: LucideIcon;
    iconTone: PillTone;
    title: string;
    subtitle: string;
    registerUrl: string | null;
    registerLabel: string;
    rows: NormRow[];
    emptyText: string;
};

export function HsWorklists({
    worklists,
    show,
}: {
    worklists: WorklistsPayload;
    show: WorklistKey[];
}) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [detail, setDetail] = useState<HsDetail | null>(null);

    const openCtx = (e: React.MouseEvent, row: NormRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View detail', onClick: () => setDetail(row.detail) },
        ];
        if (row.clientId) {
            items.push({ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/clients/${row.clientId}`) });
        }
        if (row.staffId) {
            items.push({ icon: <UserCog className="h-3.5 w-3.5" />, label: 'View staff', onClick: () => router.visit(`/staff/${row.staffId}`) });
        }
        items.push({ sep: true });
        items.push({ icon: <ExternalLink className="h-3.5 w-3.5" />, label: row.registerLabel, onClick: () => router.visit(row.registerUrl) });
        items.push({ icon: <Printer className="h-3.5 w-3.5" />, label: 'Print', onClick: () => window.print() });

        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: row.tag,
            tagBg: TAG_TONE_BG[row.tagTone],
            meta: row.meta,
            items,
        });
    };

    const allConfigs: Record<WorklistKey, CardConfig> = {
        corrective_actions: {
            key: 'corrective_actions',
            icon: Clock,
            iconTone: 'critical',
            title: 'Overdue corrective actions',
            subtitle: `${worklists.overdue_corrective_actions.length} past due · right-click a row for actions`,
            registerUrl: '/health-safety/corrective-actions',
            registerLabel: 'View register',
            rows: correctiveActionRows(worklists.overdue_corrective_actions),
            emptyText: 'No overdue corrective actions.',
        },
        investigations: {
            key: 'investigations',
            icon: Search,
            iconTone: 'info',
            title: 'Open investigations',
            subtitle: 'Active H&S investigations',
            registerUrl: '/health-safety/events',
            registerLabel: 'View all',
            rows: investigationRows(worklists.open_investigations),
            emptyText: 'No open investigations.',
        },
        notifiable: {
            key: 'notifiable',
            icon: FileText,
            iconTone: 'critical',
            title: 'WorkSafe-notifiable events',
            subtitle: 'HSWA 2015 · notified / awaiting',
            registerUrl: '/health-safety/reports/worksafe-register',
            registerLabel: 'Register',
            rows: notifiableRows(worklists.notifiable_events),
            emptyText: 'No notifiable events on record.',
        },
        expiring: {
            key: 'expiring',
            icon: CalendarClock,
            iconTone: 'warning',
            title: 'Expiring soon',
            subtitle: 'Risk assessments · SDS · drills · competencies',
            registerUrl: null,
            registerLabel: 'View registers',
            rows: expiringRows(worklists.expiring),
            emptyText: 'Nothing expiring soon.',
        },
    };

    const configs = show.map((k) => allConfigs[k]);

    return (
        <>
            <div className={cn('grid gap-4', configs.length > 1 && 'lg:grid-cols-2')}>
                {configs.map((cfg) => (
                    <Card key={cfg.key}>
                        <CardHeader className="flex flex-row items-start justify-between gap-2 pb-3">
                            <div className="flex items-center gap-2.5">
                                <span
                                    className={cn(
                                        'inline-flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[9px]',
                                        BADGE_TONE[cfg.iconTone],
                                    )}
                                >
                                    <cfg.icon className="h-4 w-4" />
                                </span>
                                <div>
                                    <CardTitle className="text-sm font-bold leading-tight">{cfg.title}</CardTitle>
                                    <p className="mt-0.5 text-[11.5px] text-muted-foreground">{cfg.subtitle}</p>
                                </div>
                            </div>
                            {cfg.registerUrl ? (
                                <Link
                                    href={cfg.registerUrl}
                                    className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-primary hover:underline"
                                >
                                    {cfg.registerLabel}
                                    <ArrowRight className="h-3 w-3" />
                                </Link>
                            ) : null}
                        </CardHeader>
                        <CardContent className="space-y-1.5">
                            {cfg.rows.length === 0 ? (
                                <p className="py-6 text-center text-xs text-muted-foreground">{cfg.emptyText}</p>
                            ) : (
                                cfg.rows.map((row) => (
                                    // eslint-disable-next-line no-restricted-syntax -- worklist row is a custom full-width selector card, not a shadcn Button.
                                    <button
                                        key={row.key}
                                        type="button"
                                        onClick={() => setDetail(row.detail)}
                                        onContextMenu={(e) => openCtx(e, row)}
                                        className="flex w-full items-center gap-3 rounded-lg border border-transparent px-2 py-2 text-left transition-colors hover:border-border hover:bg-muted/50"
                                    >
                                        <span
                                            className={cn(
                                                'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                PILL_CLASS[row.pill.tone],
                                            )}
                                        >
                                            {row.pill.icon ? <row.pill.icon className="h-3 w-3" /> : null}
                                            {row.pill.label}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] font-medium text-foreground">{row.title}</span>
                                            <span className="block truncate text-[11px] text-muted-foreground">{row.sub}</span>
                                        </span>
                                        <span className="flex shrink-0 items-center gap-2">
                                            {row.owner ? (
                                                <span
                                                    title={row.owner}
                                                    className={cn(
                                                        'flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-bold',
                                                        avatarTone(row.owner),
                                                    )}
                                                >
                                                    {initials(row.owner)}
                                                </span>
                                            ) : null}
                                            {row.due ? (
                                                <span className="w-12 text-right text-[11px] tabular-nums text-muted-foreground">{row.due}</span>
                                            ) : null}
                                        </span>
                                    </button>
                                ))
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
            {detail ? <HsDetailDialog detail={detail} onClose={() => setDetail(null)} /> : null}
        </>
    );
}

export default HsWorklists;
