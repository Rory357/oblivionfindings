/* H&S previews open the canonical record or register. Optional summaries are
 * read-only; shared card actions work with touch, keyboard and right-click. */
import { worksafeLabel } from '@/components/health-safety/event-detail-dialog';
import { boardReportHref } from '@/lib/health-safety/board-reports';
import { hsLabel, humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { Log, QuietLine, RowList } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
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
import { useEffect, useState, type MouseEvent } from 'react';

import { type HsDetail, HsDetailDialog } from './hs-detail-dialog';

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
    /** Set while the owner has marked it completed and it waits for verification. */
    completed_days_ago?: number | null;
    owner: string | null;
    client_id: number | null;
    staff_id: number | null;
    event_reference: string | null;
};

/** "completed today", "completed 1 day ago", "completed 4 days ago". */
export function completedAgo(days: number): string {
    return days === 0 ? 'completed today' : days === 1 ? 'completed 1 day ago' : `completed ${days} days ago`;
}

export type InvestigationRow = {
    id: number;
    event_id?: number | null;
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
    event_reference: string;
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

export type AcceptanceRow = {
    id: number;
    event_reference: string;
    title: string;
    severity: string;
    reported_at: string | null;
    site: string | null;
    client: string | null;
    owner: string | null;
    action_url: string;
};

export type AttentionWorklist = {
    key: 'awaiting_hs_acceptance';
    label: string;
    help: string;
    count: number;
    items: AcceptanceRow[];
};

export type WorklistsPayload = {
    attention: AttentionWorklist[];
    overdue_corrective_actions: CorrectiveActionRow[];
    open_investigations: InvestigationRow[];
    notifiable_events: NotifiableRow[];
    expiring: ExpiringRow[];
    ppe_expiring_state?: PpeExpiringState;
};

export type PpeExpiringState = {
    confirmed_count: number | null;
    total: number | null;
    repair_count: number | null;
    physical_record_count: number | null;
    displayed_count: number;
    truncated: boolean;
    source_integrity: 'intact' | 'legacy_unknown' | 'repair_required' | 'restricted';
};

export type WorklistKey =
    | 'acceptance'
    | 'corrective_actions'
    | 'investigations'
    | 'notifiable'
    | 'expiring';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

type PillTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';


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
    actionUrl?: string;
    actionLabel?: string;
};

/* ------------------------------------------------------------------ */
/*  Per-type row builders (pure)                                       */
/* ------------------------------------------------------------------ */

function correctiveActionRows(rows: CorrectiveActionRow[]): NormRow[] {
    return rows.map((r) => ({
        key: `ca-${r.id}`,
        actionUrl: `/health-safety/corrective-actions/${r.id}`,
        actionLabel: `Open corrective action: ${r.title ?? r.reference ?? r.id}`,
        pill: {
            label:
                r.status === 'completed'
                    ? 'Waiting for verification'
                    : r.days_overdue != null
                      ? `${r.days_overdue}d overdue`
                      : 'Overdue',
            tone: ['high', 'critical', 'urgent'].includes(
                (r.priority ?? '').toLowerCase(),
            )
                ? 'critical'
                : 'warning',
            icon: ['high', 'critical', 'urgent'].includes(
                (r.priority ?? '').toLowerCase(),
            )
                ? AlertTriangle
                : undefined,
        },
        title: r.title ?? r.reference ?? `Corrective action #${r.id}`,
        sub: [r.reference, hsLabel('action_priority', r.priority)].filter(Boolean).join(' · '),
        owner: r.owner,
        due: formatDateOnly(r.due_date),
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
                { label: 'Priority', value: hsLabel('action_priority', r.priority) },
                { label: 'Status', value: hsLabel('action_status', r.status) },
                { label: 'Due date', value: formatDateOnly(r.due_date) },
                {
                    label: 'Days overdue',
                    value: r.days_overdue != null ? `${r.days_overdue}` : '—',
                },
                { label: 'Owner', value: r.owner },
                { label: 'Linked event', value: r.event_reference },
            ],
        },
    }));
}

function acceptanceRows(rows: AcceptanceRow[]): NormRow[] {
    return rows.map((row) => ({
        key: `accept-${row.id}`,
        pill: {
            label: 'Accept now',
            tone: ['critical', 'high'].includes(row.severity)
                ? 'critical'
                : 'warning',
            icon: ShieldAlert,
        },
        title: row.title,
        sub: [row.event_reference, row.client, row.site]
            .filter(Boolean)
            .join(' · '),
        owner: row.owner,
        due: null,
        clientId: null,
        staffId: null,
        registerUrl: row.action_url,
        registerLabel: 'Accept H&S handover',
        tag: 'H&S',
        tagTone: 'warning',
        meta: row.event_reference,
        actionUrl: row.action_url,
        actionLabel: `Accept H&S handover for ${row.event_reference}`,
        detail: {
            title: 'Awaiting H&S acceptance',
            description:
                'A named H&S owner must accept governance responsibility.',
            railIcon: ShieldAlert,
            railTitle: row.title,
            railSub: row.event_reference,
            cardTitle: 'H&S handover',
            cardIcon: ShieldAlert,
            registerUrl: row.action_url,
            registerLabel: 'Accept H&S handover',
            rows: [
                { label: 'Reference', value: row.event_reference },
                { label: 'Title', value: row.title },
                { label: 'Severity', value: hsLabel('severity', row.severity) },
                { label: 'Client', value: row.client },
                { label: 'Site', value: row.site },
                { label: 'Proposed owner', value: row.owner },
            ],
        },
    }));
}

function investigationRows(rows: InvestigationRow[]): NormRow[] {
    return rows.map((r) => ({
        key: `inv-${r.id}`,
        actionUrl: r.event_id ? `/health-safety/events/${r.event_id}` : '/health-safety/events',
        actionLabel: r.event_id ? `Open investigation: ${r.reference ?? r.id}` : `Find investigation: ${r.reference ?? r.id}`,
        pill: r.is_overdue
            ? { label: 'Overdue', tone: 'critical', icon: Clock }
            : { label: hsLabel('investigation_status', r.status), tone: 'info', icon: Search },
        title: `Investigation ${r.reference ?? `#${r.id}`}`,
        sub: hsLabel('investigation_type', r.type),
        owner: r.owner,
        due: formatDateOnly(r.target_completion_date),
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
            railSub: hsLabel('investigation_type', r.type),
            cardTitle: 'Investigation',
            cardIcon: Search,
            registerUrl: '/health-safety/events',
            registerLabel: 'Investigations',
            clientId: r.client_id,
            staffId: r.staff_id,
            rows: [
                { label: 'Reference', value: r.reference },
                { label: 'Type', value: hsLabel('investigation_type', r.type) },
                { label: 'Status', value: hsLabel('investigation_status', r.status) },
                {
                    label: 'Target completion',
                    value: formatDateOnly(r.target_completion_date),
                },
                { label: 'Overdue', value: r.is_overdue ? 'Yes' : 'No' },
                { label: 'Lead investigator', value: r.owner },
                { label: 'Linked event', value: r.event_reference },
            ],
        },
    }));
}

function notifiableRows(rows: NotifiableRow[], registerUrl: string): NormRow[] {
    return rows.map((r) => {
        const awaiting = r.status === 'pending';
        const acknowledged = r.status === 'acknowledged';
        const statusLabel = worksafeLabel({
            notifiable: true,
            status: r.status,
        });
        const notifiedSub = [
            r.worksafe_ref ? `Ref ${r.worksafe_ref}` : null,
            r.notified_at ? formatDateTimeLong(r.notified_at) : null,
        ]
            .filter(Boolean)
            .join(' · ');
        return {
            key: `ntf-${r.id}`,
            actionUrl: `/health-safety/events/${r.id}`,
            actionLabel: `Open notifiable event: ${r.event_reference}`,
            pill: {
                label: statusLabel,
                tone: awaiting
                    ? 'critical'
                    : acknowledged
                      ? 'success'
                      : 'warning',
                icon: awaiting ? Clock : acknowledged ? Check : ShieldAlert,
            },
            title:
                r.title ?? `${r.event_reference} · WorkSafe notifiable event`,
            sub: awaiting
                ? 'Notify WorkSafe — action required'
                : notifiedSub || humaniseHsValue(r.incident_type),
            owner: null,
            due: null,
            clientId: null,
            staffId: null,
            registerUrl,
            registerLabel: 'WorkSafe notifications',
            tag: 'WS',
            tagTone: awaiting ? 'warning' : 'success',
            meta: humaniseHsValue(r.incident_type),
            detail: {
                title: 'WorkSafe notifiable event',
                description:
                    'Recorded notification details. Open the event to manage notification and follow-up.',
                railIcon: ShieldAlert,
                railTitle: r.title ?? r.event_reference,
                railSub: humaniseHsValue(r.incident_type),
                cardTitle: 'Notifiable event',
                cardIcon: ShieldAlert,
                registerUrl,
                registerLabel: 'WorkSafe notifications',
                rows: [
                    { label: 'Title', value: r.title },
                    { label: 'H&S reference', value: r.event_reference },
                    { label: 'Type', value: humaniseHsValue(r.incident_type) },
                    { label: 'Status', value: statusLabel },
                    { label: 'Occurred', value: formatDateTimeLong(r.occurred_at) },
                    { label: 'Notified', value: formatDateTimeLong(r.notified_at) },
                    {
                        label: 'Deadline',
                        value: formatDateTimeLong(r.notification_deadline),
                    },
                    {
                        label: 'Site preserved',
                        value: r.site_preserved ? 'Yes' : 'No',
                    },
                    { label: 'WorkSafe ref', value: r.worksafe_ref },
                ],
            },
        };
    });
}

function expiringRows(rows: ExpiringRow[]): NormRow[] {
    return rows.map((r, i) => {
        const ppe = r.type.startsWith('ppe_');
        const repair = r.type === 'ppe_repair';
        const overdue = r.days_until != null && r.days_until < 0;
        return {
            key: `exp-${r.type}-${i}`,
            actionUrl: r.register_url,
            actionLabel: ppe ? `Open equipment: ${repair ? 'source needs review' : r.label ?? r.type_label}` : `Open register: ${r.label ?? r.type_label}`,
            pill: repair ? { label: 'Source needs review', tone: 'warning' } : overdue
                ? {
                      label:
                          r.days_until != null
                              ? `Expired ${Math.abs(r.days_until)} day${Math.abs(r.days_until) === 1 ? '' : 's'} ago`
                              : 'Overdue',
                      tone: 'critical',
                  }
                : {
                      label:
                          r.days_until == null
                              ? 'Due'
                              : r.days_until === 0
                                ? 'Due today'
                                : `In ${r.days_until} day${r.days_until === 1 ? '' : 's'}`,
                      tone: 'warning',
                  },
            title: repair ? 'Equipment facts need review' : r.label ?? r.type_label,
            sub: [r.type_label, r.site].filter(Boolean).join(' · '),
            owner: null,
            due: repair ? null : formatDateOnly(r.due_date, 'Due date not recorded'),
            clientId: null,
            staffId: null,
            registerUrl: r.register_url,
            registerLabel: ppe ? 'Open equipment' : 'Register',
            tag: 'EXP',
            tagTone: overdue ? 'critical' : 'warning',
            meta: r.type_label,
            detail: {
                title: repair ? 'Equipment source needs review' : 'Expiring register item',
                description: repair ? 'Recorded equipment facts are unconfirmed. Open the source record to review its status.' : 'An item due for review or renewal.',
                railIcon: CalendarClock,
                railTitle: repair ? 'Equipment facts need review' : r.label ?? r.type_label,
                railSub: r.type_label,
                cardTitle: 'Expiring item',
                cardIcon: CalendarClock,
                registerUrl: r.register_url,
                registerLabel: ppe ? 'Open equipment' : 'Register',
                rows: [
                    { label: 'Item', value: repair ? 'Not confirmed' : r.label },
                    { label: 'Type', value: r.type_label },
                    { label: 'Due date', value: repair ? 'Not confirmed' : formatDateOnly(r.due_date) },
                    {
                        label: 'Days until due',
                        value: !repair && r.days_until != null ? `${r.days_until}` : 'Not confirmed',
                    },
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
    count?: number;
    countLabel?: string;
};

export function HsWorklists({
    worklists,
    show,
}: {
    worklists: WorklistsPayload;
    show: WorklistKey[];
}) {
    const context = useEntityContextMenu<NormRow>();
    const [detailKey, setDetailKey] = useState<string | null>(null);

    // The WorkSafe notifications report is governance.view-gated; for register-only
    // roles (hazards.view) fall back to the non-gated events WorkSafe tab so the
    // worklist's register links (card, context menu, detail dialog) don't 403.
    const canViewGovReports =
        usePage<SharedData>().props.auth.can?.governance?.view ?? false;
    const worksafeRegisterUrl = canViewGovReports
        ? boardReportHref('notifications')
        : '/health-safety/events?tab=worksafe';

    const visit = (href: string) => { context.close(); router.visit(href); };
    const actions = (row: NormRow): MenuItem[] => [
        { label: 'Open record or register', icon: ExternalLink, onClick: () => visit(row.actionUrl ?? row.registerUrl) },
        { label: 'View recorded summary', icon: Eye, onClick: () => { context.close(); setDetailKey(row.key); } },
        ...(row.clientId ? [{ label: 'View client', icon: User, onClick: () => visit(`/clients/${row.clientId}`) }] : []),
        ...(row.staffId ? [{ label: 'View staff', icon: UserCog, onClick: () => visit(`/staff/${row.staffId}`) }] : []),
        { separator: true },
        { label: row.registerLabel, icon: ExternalLink, onClick: () => visit(row.registerUrl) },
        { label: 'Print', icon: Printer, onClick: () => { context.close(); window.print(); } },
    ];

    const allConfigs: Record<WorklistKey, CardConfig> = {
        acceptance: {
            key: 'acceptance',
            icon: ShieldAlert,
            iconTone: 'warning',
            title: 'Waiting for Health & safety to accept',
            subtitle:
                worklists.attention[0]?.help ??
                'A named Health & safety owner must accept responsibility for each one',
            registerUrl: null,
            registerLabel: 'Open action',
            rows: acceptanceRows(worklists.attention[0]?.items ?? []),
            emptyText: 'No matching H&S handovers in this preview.',
            count: worklists.attention[0]?.count ?? 0,
            countLabel: `${worklists.attention[0]?.count ?? 0} H&S handover${(worklists.attention[0]?.count ?? 0) === 1 ? '' : 's'} awaiting acceptance`,
        },
        corrective_actions: {
            key: 'corrective_actions',
            icon: Clock,
            iconTone: 'critical',
            title: 'Overdue corrective actions',
            subtitle: 'Past their due date · right-click a row for options',
            registerUrl: '/health-safety/corrective-actions',
            registerLabel: 'View register',
            rows: correctiveActionRows(worklists.overdue_corrective_actions),
            emptyText: 'No matching overdue actions in this preview.',
        },
        investigations: {
            key: 'investigations',
            icon: Search,
            iconTone: 'info',
            title: 'Open investigations',
            subtitle: 'Investigations under way',
            registerUrl: '/health-safety/events',
            registerLabel: 'View all',
            rows: investigationRows(worklists.open_investigations),
            emptyText: 'No matching investigations in this preview.',
        },
        notifiable: {
            key: 'notifiable',
            icon: FileText,
            iconTone: 'critical',
            title: 'WorkSafe notifiable events',
            subtitle: 'Notified, or still to notify',
            registerUrl: worksafeRegisterUrl,
            registerLabel: 'Register',
            rows: notifiableRows(
                worklists.notifiable_events,
                worksafeRegisterUrl,
            ),
            emptyText: 'No matching notifiable events in this preview.',
        },
        expiring: {
            key: 'expiring',
            icon: CalendarClock,
            iconTone: 'warning',
            title: 'Reviews and equipment due',
            subtitle: 'Risk assessments, safety data sheets, drills and equipment. A due list only — it doesn’t confirm equipment is fit to use.',
            registerUrl: null,
            registerLabel: 'View registers',
            rows: expiringRows(worklists.expiring.filter((row) => worklists.ppe_expiring_state?.source_integrity !== 'restricted' || !row.type.startsWith('ppe_'))),
            emptyText: 'No matching due items in this preview.',
        },
    };

    const configs = show.map((k) => allConfigs[k]);
    const currentRows = configs.flatMap((config) => config.rows);
    // Menu/summary facts always come from the current preview. A withdrawn
    // source must disappear even if its context menu or summary was open.
    const contextRow = currentRows.find((row) => row.key === context.ctx?.record.key);
    const detail = currentRows.find((row) => row.key === detailKey)?.detail;
    const closeContext = context.close;
    useEffect(() => {
        if (detailKey !== null && !detail) setDetailKey(null);
        if (context.ctx !== null && !contextRow) closeContext();
    }, [detailKey, detail, context.ctx, contextRow, closeContext]);

    // Drawn as the approved H&S drawings' lists (23 Oct): time-first logs for dated work, plain
    // rows with one status for the rest. Every row keeps its link, its kebab and the same menu on
    // right-click, and "View recorded summary" still opens the detail pop-up.
    const PILL_RING = { critical: 'critical', warning: 'warning', success: 'success', info: 'info', neutral: 'none' } as const;
    const rowMenu = (row: NormRow) => ({
        href: row.actionUrl ?? row.registerUrl,
        menu: actions(row),
        onContextMenu: (event: MouseEvent<HTMLElement>) => context.open(event, row),
    });
    const pill = (row: NormRow) => (
        <StatusBadge variant={row.pill.tone} size="sm">
            {row.pill.icon && <row.pill.icon aria-hidden="true" className="size-3" />}
            {row.pill.label}
        </StatusBadge>
    );
    const head = (cfg: CardConfig) => ({
        title: cfg.title,
        sub: cfg.subtitle,
        action: cfg.registerUrl ? { label: cfg.registerLabel, href: cfg.registerUrl } : null,
    });
    const block = (cfg: CardConfig) => {
        if (cfg.key === 'corrective_actions' || cfg.key === 'investigations') {
            return cfg.rows.length ? (
                <Log
                    key={cfg.key}
                    {...head(cfg)}
                    limit={50}
                    items={cfg.rows.map((row) => ({
                        key: row.key,
                        time: row.due ?? '',
                        tone: PILL_RING[row.pill.tone],
                        title: row.title,
                        sub: [row.pill.label, row.sub, row.owner].filter(Boolean).join(' · '),
                        menu: actions(row),
                        onContextMenu: (event: MouseEvent<HTMLElement>) => context.open(event, row),
                        action: { label: 'Open', ariaLabel: row.actionLabel, href: row.actionUrl ?? row.registerUrl },
                    }))}
                />
            ) : (
                <QuietLine key={cfg.key} title={cfg.title} text={cfg.emptyText} />
            );
        }
        const status =
            cfg.key === 'expiring'
                ? worklists.ppe_expiring_state?.source_integrity === 'restricted'
                    ? 'Equipment attention requires current safety and Site access.'
                    : worklists.ppe_expiring_state?.source_integrity === 'repair_required'
                      ? 'Some equipment facts are unconfirmed. Open the equipment record to review its source status.'
                      : !worklists.ppe_expiring_state
                        ? 'Equipment attention is not confirmed.'
                        : null
                : null;
        return (
            <RowList
                key={cfg.key}
                {...head(cfg)}
                sub={
                    <>
                        {status ?? cfg.subtitle}
                        {cfg.key === 'expiring' && worklists.ppe_expiring_state?.truncated
                            ? ' Some equipment items are outside this preview. Open the PPE register for the remaining records.'
                            : ''}
                        {cfg.count !== undefined ? <span aria-label={cfg.countLabel}>{` · ${cfg.count} waiting`}</span> : null}
                    </>
                }
                empty={cfg.emptyText}
                items={cfg.rows.map((row) => ({
                    key: row.key,
                    title: row.title,
                    sub: row.sub,
                    badge: pill(row),
                    leading: cfg.key === 'notifiable' ? <ShieldAlert aria-hidden="true" className="size-4" /> : undefined,
                    ...rowMenu(row),
                }))}
            />
        );
    };

    return (
        <>
            <div className={cn('grid items-start gap-5', configs.length > 1 && 'lg:grid-cols-2')}>
                {configs.map(block)}
            </div>

            {context.ctx && contextRow && <EntityContextMenu x={context.ctx.x} y={context.ctx.y} title={contextRow.title} items={actions(contextRow)} onClose={context.close} />}
            {detail && <HsDetailDialog detail={detail} onClose={() => setDetailKey(null)} />}
        </>
    );
}

export default HsWorklists;

