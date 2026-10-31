/* eslint-disable no-restricted-syntax -- Risk Assessment register table built on the
 * shared register-row-kit; bespoke row chrome (keyboard-openable rows, right-click
 * context menu) with semantic design tokens only. */
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import type { ShiftCtxItem } from '@/components/rostering';
import { riskHref } from '@/lib/health-safety/risk-navigation';
import { cn } from '@/lib/utils';
import {
    initials,
    RegisterTableHeader,
} from '@/pages/health-safety/components/register-row-kit';
import { Link } from '@inertiajs/react';
import {
    Archive,
    ExternalLink,
    Eye,
    Layers,
    Link2,
    MousePointerClick,
    Pencil,
    RefreshCw,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import {
    AcceptableBadge,
    AttachChip,
    attachMeta,
    attachTypeLabel,
    LevelBadge,
    LevelCell,
    RA_TONE_SOLID,
    RaStatusBadge,
    ReviewBadge,
    ReviewDue,
    StatusChip,
} from './ra-kit';
import type { RaRow } from './types';

const AVATAR_TONES = ['info', 'success', 'critical', 'neutral'] as const;

const COLS = [
    'Reference',
    'Title',
    'Attached to',
    'Status & review due',
    'Inherent',
    'Residual',
    'Acceptable',
    'Assessed by',
];

export interface RaCtxHandlers {
    onView: (r: RaRow) => void;
    onEdit: (r: RaRow) => void;
    onApprove: (r: RaRow) => void;
    onReview: (r: RaRow) => void;
    onResidual: (r: RaRow) => void;
    onSupersede: (r: RaRow) => void;
    onArchive: (r: RaRow) => void;
    onCopyLink: (r: RaRow) => void;
    onOpenCurrent?: (id: number) => void;
}

/** Context-menu items, gated by status + can.manage (mirrors the detail Options bar). */
export function buildRaCtxItems(
    row: RaRow,
    canManage: boolean,
    h: RaCtxHandlers,
): ShiftCtxItem[] {
    const items: ShiftCtxItem[] = [
        {
            icon: <Eye className="h-3.5 w-3.5" />,
            label: 'View assessment',
            sub: row.reference_number,
            tone: 'primary',
            onClick: () => h.onView(row),
        },
    ];

    if (canManage) {
        if (row.status === 'draft') {
            items.push(
                {
                    icon: <Pencil className="h-3.5 w-3.5" />,
                    label: 'Edit draft',
                    onClick: () => h.onEdit(row),
                },
                {
                    icon: <ShieldCheck className="h-3.5 w-3.5" />,
                    label: 'Approve & activate',
                    sub: 'Draft → active',
                    onClick: () => h.onApprove(row),
                },
            );
        } else if (row.status === 'active') {
            items.push(
                {
                    icon: <RefreshCw className="h-3.5 w-3.5" />,
                    label: 'Mark for review',
                    onClick: () => h.onReview(row),
                },
                {
                    icon: <RefreshCw className="h-3.5 w-3.5" />,
                    label: 'Record residual risk',
                    onClick: () => h.onResidual(row),
                },
                {
                    icon: <Layers className="h-3.5 w-3.5" />,
                    label: 'Supersede',
                    sub: 'New version',
                    onClick: () => h.onSupersede(row),
                },
            );
        } else if (row.status === 'under_review') {
            items.push(
                {
                    icon: <RefreshCw className="h-3.5 w-3.5" />,
                    label: 'Record residual risk',
                    onClick: () => h.onResidual(row),
                },
                {
                    icon: <Layers className="h-3.5 w-3.5" />,
                    label: 'Supersede',
                    sub: 'New version',
                    onClick: () => h.onSupersede(row),
                },
            );
        }
        if (
            row.status === 'draft' ||
            row.status === 'active' ||
            row.status === 'under_review'
        ) {
            items.push(
                { sep: true },
                {
                    icon: <Archive className="h-3.5 w-3.5" />,
                    label: 'Archive',
                    tone: 'critical',
                    onClick: () => h.onArchive(row),
                },
            );
        }
    }

    if (
        (row.status === 'superseded' || row.status === 'archived') &&
        row.superseded_by_id &&
        h.onOpenCurrent
    ) {
        items.push({
            icon: <ExternalLink className="h-3.5 w-3.5" />,
            label: 'Open current version',
            onClick: () => h.onOpenCurrent!(row.superseded_by_id!),
        });
    }

    items.push(
        { sep: true },
        {
            icon: <Link2 className="h-3.5 w-3.5" />,
            label: 'Copy link',
            onClick: () => h.onCopyLink(row),
        },
    );
    return items;
}

/**
 * The same actions as `buildRaCtxItems`, as the shared list menu: one list
 * behind the row's kebab and its right-click (DESIGN.md entity rows).
 */
export function raMenuItems(
    row: RaRow,
    canManage: boolean,
    h: RaCtxHandlers,
): MenuItem[] {
    const editable =
        row.status === 'draft' ||
        row.status === 'active' ||
        row.status === 'under_review';
    return compactMenu([
        { label: 'View assessment', icon: Eye, onClick: () => h.onView(row) },
        canManage &&
            row.status === 'draft' && {
                label: 'Edit draft',
                icon: Pencil,
                onClick: () => h.onEdit(row),
            },
        canManage &&
            row.status === 'draft' && {
                label: 'Approve & activate',
                icon: ShieldCheck,
                onClick: () => h.onApprove(row),
            },
        canManage &&
            row.status === 'active' && {
                label: 'Mark for review',
                icon: RefreshCw,
                onClick: () => h.onReview(row),
            },
        canManage &&
            (row.status === 'active' || row.status === 'under_review') && {
                label: 'Record residual risk',
                icon: RefreshCw,
                onClick: () => h.onResidual(row),
            },
        canManage &&
            (row.status === 'active' || row.status === 'under_review') && {
                label: 'Supersede',
                icon: Layers,
                onClick: () => h.onSupersede(row),
            },
        canManage && editable && { separator: true },
        canManage &&
            editable && {
                label: 'Archive',
                icon: Archive,
                danger: true,
                onClick: () => h.onArchive(row),
            },
        (row.status === 'superseded' || row.status === 'archived') &&
            !!row.superseded_by_id &&
            !!h.onOpenCurrent && {
                label: 'Open current version',
                icon: ExternalLink,
                onClick: () => h.onOpenCurrent!(row.superseded_by_id!),
            },
        { separator: true },
        { label: 'Copy link', icon: Link2, onClick: () => h.onCopyLink(row) },
    ]);
}

/**
 * The drawn register (pg-hsn-61): reference, the risk, what it applies to,
 * status, review due, the score before and after controls, and the assessor.
 * The row opens the assessment; the kebab and right-click share one list.
 */
export function RaRegisterTable({
    rows,
    title = 'Risk assessment register',
    sub,
    footer,
    empty,
    onOpen,
    actionsFor,
    hrefFor = riskHref,
}: {
    rows: RaRow[];
    title?: string;
    sub?: ReactNode;
    footer?: ReactNode;
    empty?: ReactNode;
    onOpen: (id: number) => void;
    actionsFor: (row: RaRow) => MenuItem[];
    hrefFor?: (id: number) => string;
}) {
    const columns: RecordTableColumn<RaRow>[] = [
        {
            key: 'reference',
            label: 'Reference',
            nowrap: true,
            cell: (r) => (
                <Link
                    href={hrefFor(r.id)}
                    onClick={(event) => event.stopPropagation()}
                    className="rounded-sm font-medium text-foreground tabular-nums underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {r.reference_number}
                </Link>
            ),
        },
        {
            key: 'risk',
            label: 'Risk',
            className: 'min-w-[12rem] max-w-[16rem]',
            cell: (r) => (
                <RecordIdentity
                    title={r.title}
                    sub={
                        r.risk_description ? (
                            <span className="line-clamp-2">
                                {r.risk_description}
                            </span>
                        ) : undefined
                    }
                />
            ),
        },
        {
            key: 'applies',
            label: 'Applies to',
            className: 'min-w-[10rem]',
            cell: (r) => {
                const Icon = attachMeta(r.attached_to.type).icon;
                return (
                    <RecordIdentity
                        leading={<Icon className="size-3.5" />}
                        title={r.attached_to.name}
                        sub={attachTypeLabel(r.attached_to.type)}
                    />
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (r) => <RaStatusBadge status={r.status} />,
        },
        {
            key: 'review',
            label: 'Review due',
            nowrap: true,
            cell: (r) => (
                <ReviewDue
                    state={r.review_state}
                    dueAt={r.review_due_at}
                    status={r.status}
                />
            ),
        },
        {
            key: 'before',
            label: 'Before controls',
            nowrap: true,
            cell: (r) => <LevelBadge score={r.risk_score} level={r.risk_level} />,
        },
        {
            key: 'after',
            label: 'After controls',
            cell: (r) => (
                <div className="grid justify-items-start gap-1">
                    {r.residual_risk_score != null && r.residual_risk_level ? (
                        <LevelBadge
                            score={r.residual_risk_score}
                            level={r.residual_risk_level}
                        />
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                    <span className="text-subtle">
                        Acceptable:{' '}
                        {r.risk_acceptable === null
                            ? 'Not recorded'
                            : r.risk_acceptable
                              ? 'Yes'
                              : 'No'}
                    </span>
                </div>
            ),
        },
        {
            key: 'assessor',
            label: 'Assessed by',
            nowrap: true,
            cell: (r) =>
                r.assessed_by_name ? (
                    <RecordPerson name={r.assessed_by_name} />
                ) : (
                    <span className="text-muted-foreground">Not recorded</span>
                ),
        },
    ];
    return (
        <RecordTable
            title={title}
            sub={sub}
            ariaLabel="Risk assessments table"
            rows={rows}
            rowKey={(r) => r.id}
            onOpen={(r) => onOpen(r.id)}
            actionsFor={actionsFor}
            menuTitle={(r) => `${r.reference_number} · ${r.title}`}
            menuIcon={ShieldAlert}
            columns={columns}
            empty={empty}
            footer={rows.length ? footer : undefined}
        />
    );
}

export function RaTable({
    rows,
    title = 'Risk assessment register',
    countLabel,
    hint = 'Open an assessment to view its controls, history and next steps',
    emptyCta,
    onOpen,
    onCtx,
    hrefFor = riskHref,
}: {
    rows: RaRow[];
    title?: string;
    countLabel?: string;
    hint?: string;
    emptyCta?: ReactNode;
    onOpen: (id: number) => void;
    onCtx: (e: MouseEvent, row: RaRow) => void;
    hrefFor?: (id: number) => string;
}) {
    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <RegisterTableHeader
                icon={ShieldAlert}
                title={title}
                subtitle={countLabel}
                hint={hint}
                hintIcon={MousePointerClick}
            />

            <ul className="divide-y divide-border md:hidden">
                {rows.map((r) => (
                    <li key={r.id} className="space-y-3 p-4">
                        <Link
                            href={hrefFor(r.id)}
                            className="frontline-tap frontline-focus inline-flex flex-col justify-center text-primary underline"
                        >
                            <span className="font-semibold">
                                {r.reference_number} · {r.title}
                            </span>
                        </Link>
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusChip status={r.status} />
                            <AttachChip attached={r.attached_to} />
                        </div>
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt className="text-subtle">Inherent risk</dt>
                                <dd>
                                    <LevelCell
                                        score={r.risk_score}
                                        level={r.risk_level}
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-subtle">Residual risk</dt>
                                <dd>
                                    {r.residual_risk_score != null &&
                                    r.residual_risk_level ? (
                                        <LevelCell
                                            score={r.residual_risk_score}
                                            level={r.residual_risk_level}
                                        />
                                    ) : (
                                        'Not recorded'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-subtle">Review due</dt>
                                <dd>
                                    <ReviewBadge
                                        state={r.review_state}
                                        dueAt={r.review_due_at}
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-subtle">Assessed by</dt>
                                <dd>{r.assessed_by_name ?? 'Not recorded'}</dd>
                            </div>
                        </dl>
                    </li>
                ))}
                {!rows.length && (
                    <li className="space-y-3 p-5">
                        <p>No risk assessments match this view.</p>
                        {emptyCta}
                    </li>
                )}
            </ul>
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[980px] border-collapse text-[13px]">
                    <thead>
                        <tr className="border-b border-border text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            {COLS.map((c) => (
                                <th
                                    key={c}
                                    scope="col"
                                    className="px-4 py-2.5 font-semibold"
                                >
                                    {c}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => {
                            const overdue = r.review_state.kind === 'overdue';
                            const avatarTone =
                                AVATAR_TONES[r.id % AVATAR_TONES.length];
                            return (
                                <tr
                                    key={r.id}
                                    tabIndex={0}
                                    onClick={() => onOpen(r.id)}
                                    onContextMenu={(e) => onCtx(e, r)}
                                    onKeyDown={(e) => {
                                        if (e.target !== e.currentTarget)
                                            return;
                                        if (
                                            e.key === 'Enter' ||
                                            e.key === ' '
                                        ) {
                                            e.preventDefault();
                                            onOpen(r.id);
                                        }
                                    }}
                                    className={cn(
                                        'cursor-pointer border-b border-border transition-colors outline-none hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                        overdue && 'bg-status-critical-bg/35',
                                    )}
                                >
                                    <td className="px-4 py-3 align-top whitespace-nowrap">
                                        <Link
                                            href={hrefFor(r.id)}
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                            className="frontline-tap frontline-focus inline-flex items-center font-semibold text-primary tabular-nums underline"
                                        >
                                            {r.reference_number}
                                        </Link>
                                    </td>
                                    <td className="max-w-[230px] px-4 py-3 align-top">
                                        <span className="block leading-tight font-semibold">
                                            {r.title}
                                        </span>
                                        {r.risk_description ? (
                                            <span className="mt-0.5 block max-w-[220px] truncate text-[11.5px] text-muted-foreground">
                                                {r.risk_description}
                                            </span>
                                        ) : null}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <AttachChip attached={r.attached_to} />
                                    </td>
                                    <td className="space-y-2 px-4 py-3 align-top whitespace-nowrap">
                                        <StatusChip status={r.status} />
                                        <div>
                                            <ReviewBadge
                                                state={r.review_state}
                                                dueAt={r.review_due_at}
                                            />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <LevelCell
                                            score={r.risk_score}
                                            level={r.risk_level}
                                        />
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        {r.residual_risk_score != null &&
                                        r.residual_risk_level ? (
                                            <LevelCell
                                                score={r.residual_risk_score}
                                                level={r.residual_risk_level}
                                            />
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <AcceptableBadge
                                            value={r.risk_acceptable}
                                        />
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <span
                                            role="img"
                                            title={
                                                r.assessed_by_name ?? undefined
                                            }
                                            aria-label={
                                                r.assessed_by_name
                                                    ? `Assessed by ${r.assessed_by_name}`
                                                    : 'Assessor not recorded'
                                            }
                                            className={cn(
                                                'inline-flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-bold',
                                                RA_TONE_SOLID[avatarTone],
                                            )}
                                        >
                                            {initials(r.assessed_by_name)}
                                        </span>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                {rows.length === 0 ? (
                    <div className="px-4 py-16 text-center">
                        <ShieldAlert className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                        <p className="font-semibold text-muted-foreground">
                            No risk assessments here
                        </p>
                        <p className="mt-1 mb-4 text-[13px] text-muted-foreground/70">
                            Nothing matches this tab and filters.
                        </p>
                        {emptyCta}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
