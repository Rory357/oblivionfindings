/* eslint-disable @typescript-eslint/no-explicit-any -- worklist rows are
 * server-shaped per tab; the active tab determines the row shape. */
import { cn } from '@/lib/utils';
import {
    FlagBadge,
    RegisterTableHeader,
    entityTone,
    initials,
} from '@/pages/health-safety/components/register-row-kit';
import {
    PRIVACY_DOT,
    PRIVACY_PILL,
    breachStatus,
    dpiaOutcome,
    fmtDate,
    fmtNum,
    holdStatus,
    requestStatus,
    requestType,
    riskLevel,
    titleCase,
    type PrivacyTone,
} from '@/pages/privacy/privacy-shared';
import {
    Clock,
    FileText,
    ListTodo,
    Lock,
    MousePointerClick,
    Scale,
    ShieldAlert,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { type ReactNode } from 'react';

export type WorklistRow = Record<string, any> & { id: number };

const HEADERS: Record<
    string,
    { icon: typeof FileText; title: string; subtitle: string; cols: string[] }
> = {
    overview: {
        icon: ListTodo,
        title: 'Privacy request worklist',
        subtitle: 'IPP 6 / IPP 7 · 20 working days',
        cols: ['Reference', 'Type', 'Subject', 'Status', 'Due', 'Assigned to'],
    },
    requests: {
        icon: FileText,
        title: 'Privacy requests',
        subtitle: 'IPP 6 / IPP 7 · 20 working days',
        cols: ['Reference', 'Type', 'Subject', 'Status', 'Due', 'Assigned to'],
    },
    breaches: {
        icon: ShieldAlert,
        title: 'Data breaches',
        subtitle: 'Notifiable breach register',
        cols: ['Reference', 'Breach', 'Status', 'Notification'],
    },
    legal_holds: {
        icon: Scale,
        title: 'Legal holds',
        subtitle: 'Preservation orders',
        cols: ['Reference', 'Reason', 'Type', 'Status', 'Review'],
    },
    retention: {
        icon: Lock,
        title: 'Retention policies',
        subtitle: 'Data lifecycle rules',
        cols: ['Policy', 'Retention', 'Status', 'Next review'],
    },
    dpia: {
        icon: ShieldCheck,
        title: 'Privacy impact assessments',
        subtitle: 'DPIA register',
        cols: ['Reference', 'Assessment', 'Type', 'Risk', 'Status'],
    },
    deletion_logs: {
        icon: Trash2,
        title: 'Deletion logs',
        subtitle: 'Last 30 days · anonymisation history',
        cols: ['Reference', 'Scope', 'Records', 'When', 'Status'],
    },
};

export function PrivacyWorklist({
    tab,
    rows,
    total,
    onOpen,
    onRowCtx,
}: {
    tab: string;
    rows: WorklistRow[];
    total: number;
    onOpen: (row: WorklistRow) => void;
    onRowCtx: (e: React.MouseEvent, row: WorklistRow) => void;
}) {
    const head = HEADERS[tab] ?? HEADERS.overview;

    return (
        <div>
            <RegisterTableHeader
                icon={head.icon}
                title={head.title}
                subtitle={String(total)}
                hint="Right-click a row for actions"
                hintIcon={MousePointerClick}
            />
            {rows.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
                    <head.icon className="h-8 w-8 text-muted-foreground/50" />
                    <div className="text-sm font-semibold">
                        Nothing here yet
                    </div>
                    <div className="text-[13px] text-muted-foreground">
                        No {head.title.toLowerCase()} match your filters.
                    </div>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr className="border-b border-border text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {head.cols.map((c) => (
                                    <th
                                        key={c}
                                        className="px-4 py-2.5 font-semibold"
                                    >
                                        {c}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {rows.map((r) => (
                                <tr
                                    key={r.id}
                                    onClick={() => onOpen(r)}
                                    onContextMenu={(e) => onRowCtx(e, r)}
                                    tabIndex={0}
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' ||
                                            e.key === ' '
                                        ) {
                                            e.preventDefault();
                                            onOpen(r);
                                        }
                                    }}
                                    className="cursor-pointer transition-colors outline-none hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {renderRow(tab, r)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function renderRow(tab: string, r: WorklistRow): ReactNode {
    switch (tab) {
        case 'breaches': {
            const st = breachStatus(r.status);
            return (
                <>
                    <Stack
                        main={r.reference}
                        sub={`Discovered ${fmtDate(r.discovered_at)}`}
                        dot={st.tone}
                    />
                    <Stack
                        main={r.nature_of_breach}
                        sub={`${fmtNum(r.affected)} individuals`}
                        clamp
                    />
                    <Pill tone={st.tone} label={st.label} />
                    <Td>
                        <div className="flex flex-wrap gap-1">
                            {r.opc_required && !r.opc_notified ? (
                                <FlagBadge
                                    icon={ShieldAlert}
                                    tone="critical"
                                    title="OPC notification due"
                                >
                                    OPC due
                                </FlagBadge>
                            ) : r.opc_notified ? (
                                <FlagBadge
                                    icon={ShieldCheck}
                                    tone="success"
                                    title="OPC notified"
                                >
                                    OPC notified
                                </FlagBadge>
                            ) : null}
                            {r.subject_required && !r.subject_notified ? (
                                <FlagBadge
                                    icon={Clock}
                                    tone="warning"
                                    title="Affected individuals to notify"
                                >
                                    Subjects due
                                </FlagBadge>
                            ) : null}
                            {!r.opc_required && !r.subject_required ? (
                                <span className="text-muted-foreground">—</span>
                            ) : null}
                        </div>
                    </Td>
                </>
            );
        }
        case 'legal_holds': {
            const st = holdStatus(r.status);
            return (
                <>
                    <Stack
                        main={r.reference}
                        sub={r.legal_authority}
                        dot={st.tone}
                    />
                    <Stack main={r.reason} clamp />
                    <Pill tone="info" label={titleCase(r.hold_type ?? '')} />
                    <Pill tone={st.tone} label={st.label} />
                    <Stack main={fmtDate(r.review_date)} sub="review" />
                </>
            );
        }
        case 'retention': {
            return (
                <>
                    <Stack
                        main={r.policy_name}
                        sub={r.model_type}
                        dot={r.active ? 'success' : 'neutral'}
                    />
                    <Stack
                        main={`${r.retention_period_years ?? '—'} year${r.retention_period_years === 1 ? '' : 's'}`}
                        sub={r.legal_basis}
                        clamp
                    />
                    <Pill
                        tone={r.active ? 'success' : 'neutral'}
                        label={r.active ? 'Active' : 'Inactive'}
                    />
                    <Td>
                        {r.next_review_at ? (
                            <span
                                className={cn(
                                    r.review_due &&
                                        'font-semibold text-status-critical',
                                )}
                            >
                                {fmtDate(r.next_review_at)}
                                {r.review_due ? ' · due' : ''}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </Td>
                </>
            );
        }
        case 'dpia': {
            const risk = riskLevel(r.overall_risk_level);
            const oc = dpiaOutcome(r.outcome);
            return (
                <>
                    <Stack
                        main={r.reference}
                        sub={r.project_or_process}
                        dot={risk.tone}
                    />
                    <Stack main={r.assessment_name} clamp />
                    <Pill
                        tone="info"
                        label={titleCase(r.assessment_type ?? '')}
                    />
                    <Pill tone={risk.tone} label={`${risk.label} risk`} />
                    <Pill tone={oc.tone} label={oc.label} />
                </>
            );
        }
        case 'deletion_logs': {
            return (
                <>
                    <Stack
                        main={r.reference}
                        sub={r.model_type}
                        dot="neutral"
                    />
                    <Stack
                        main={`${r.model_type} #${r.model_id}`}
                        sub={r.reason}
                        clamp
                    />
                    <Stack main={fmtNum(r.fields_count)} sub="fields" />
                    <Td>{fmtDate(r.anonymized_at)}</Td>
                    <Pill
                        tone={r.reversible ? 'warning' : 'neutral'}
                        label={r.reversible ? 'Reversible' : 'Executed'}
                    />
                </>
            );
        }
        default: {
            // overview / requests
            const st = requestStatus(r.status);
            const ty = requestType(r.request_type);
            return (
                <>
                    <Stack main={r.reference} sub={ty.label} dot={st.tone} />
                    <Pill tone={ty.tone} label={ty.label} />
                    <Entity
                        name={r.subject_name}
                        email={r.subject_email}
                        id={r.id}
                    />
                    <Pill tone={st.tone} label={st.label} />
                    <Td>
                        {r.due_date ? (
                            <span
                                className={cn(
                                    r.is_overdue &&
                                        'font-semibold text-status-critical',
                                )}
                            >
                                {fmtDate(r.due_date)}
                                {r.is_overdue ? ' · overdue' : ''}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </Td>
                    <Td>
                        {r.assigned_to ?? (
                            <span className="text-muted-foreground">
                                Unassigned
                            </span>
                        )}
                    </Td>
                </>
            );
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Cells                                                              */
/* ------------------------------------------------------------------ */

function Td({ children }: { children: ReactNode }) {
    return <td className="px-4 py-3 align-top">{children}</td>;
}

function Stack({
    main,
    sub,
    dot,
    clamp,
}: {
    main: ReactNode;
    sub?: ReactNode;
    dot?: PrivacyTone;
    clamp?: boolean;
}) {
    return (
        <Td>
            <div className="flex items-start gap-2">
                {dot ? (
                    <span
                        className={cn(
                            'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                            PRIVACY_DOT[dot],
                        )}
                    />
                ) : null}
                <div className="min-w-0">
                    <div
                        className={cn(
                            'font-medium',
                            clamp && 'line-clamp-1 max-w-[280px]',
                        )}
                    >
                        {main || (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </div>
                    {sub ? (
                        <div className="truncate text-xs text-muted-foreground">
                            {sub}
                        </div>
                    ) : null}
                </div>
            </div>
        </Td>
    );
}

function Pill({ tone, label }: { tone: PrivacyTone; label: string }) {
    return (
        <Td>
            <span
                className={cn(
                    'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                    PRIVACY_PILL[tone],
                )}
            >
                {label || '—'}
            </span>
        </Td>
    );
}

function Entity({
    name,
    email,
    id,
}: {
    name?: string;
    email?: string;
    id: number;
}) {
    return (
        <Td>
            <div className="flex items-center gap-2.5">
                <span
                    className={cn(
                        'grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold',
                        entityTone(id),
                    )}
                >
                    {initials(name)}
                </span>
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {name || 'Unknown'}
                    </div>
                    {email ? (
                        <div className="truncate text-xs text-muted-foreground">
                            {email}
                        </div>
                    ) : null}
                </div>
            </div>
        </Td>
    );
}
