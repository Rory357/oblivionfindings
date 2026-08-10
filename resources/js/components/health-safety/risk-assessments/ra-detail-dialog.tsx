/* eslint-disable no-restricted-syntax -- Risk Assessment detail-as-modal. Mirrors the
 * event-detail-dialog chrome (248px rail + scroll-contained main column + Options footer)
 * with the same dialog sizing as the Add-Client wizard. Styled native controls; semantic
 * design tokens only. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    Archive,
    Download,
    ExternalLink,
    FileText,
    Image as ImageIcon,
    Layers,
    Pencil,
    RefreshCw,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    X,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    AcceptableBadge,
    attachMeta,
    cap,
    fmtDate,
    levelTone,
    RA_TONE_CHIP,
    StatusChip,
} from './ra-kit';
import { RaMatrix } from './ra-matrix';
import type { RaDetail, RaLevel, RaModalKind } from './types';

function MetaCard({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="rounded-lg border border-border bg-card/65 px-3 py-2">
            <div className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-0.5 text-[13px] font-semibold">{value}</div>
        </div>
    );
}

function ScoreHead({
    score,
    level,
}: {
    score: number | null;
    level: RaLevel | null;
}) {
    if (score == null || !level)
        return <span className="text-sm text-muted-foreground">—</span>;
    const tone = levelTone(level);
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className={cn(
                    'inline-flex h-6 w-6 items-center justify-center rounded-md text-xs font-bold',
                    RA_TONE_CHIP[tone],
                )}
            >
                {score}
            </span>
            <span
                className={cn(
                    'inline-flex rounded-md px-2 py-0.5 text-[11px] font-bold',
                    RA_TONE_CHIP[tone],
                )}
            >
                {cap(level)}
            </span>
        </span>
    );
}

export function RaDetailDialog({
    detail,
    open,
    onClose,
    onAction,
    onOpenAssessment,
}: {
    detail: RaDetail;
    open: boolean;
    onClose: () => void;
    onAction: (kind: RaModalKind) => void;
    onOpenAssessment?: (id: number) => void;
}) {
    const canManage = detail.can.manage;
    const AttachIcon = attachMeta(detail.attached_to.type).icon;

    const actions: {
        key: RaModalKind;
        label: string;
        icon: LucideIcon;
        destructive?: boolean;
    }[] = [];
    if (canManage) {
        if (detail.status === 'draft') {
            actions.push(
                { key: 'edit', label: 'Edit draft', icon: Pencil },
                {
                    key: 'approve',
                    label: 'Approve & activate',
                    icon: ShieldCheck,
                },
                {
                    key: 'archive',
                    label: 'Archive',
                    icon: Archive,
                    destructive: true,
                },
            );
        } else if (detail.status === 'active') {
            actions.push(
                { key: 'review', label: 'Mark for review', icon: RefreshCw },
                {
                    key: 'residual',
                    label: 'Record review / residual',
                    icon: RefreshCw,
                },
                { key: 'supersede', label: 'Supersede', icon: Layers },
                {
                    key: 'archive',
                    label: 'Archive',
                    icon: Archive,
                    destructive: true,
                },
            );
        } else if (detail.status === 'under_review') {
            actions.push(
                {
                    key: 'residual',
                    label: 'Record review / residual',
                    icon: RefreshCw,
                },
                { key: 'supersede', label: 'Supersede', icon: Layers },
                {
                    key: 'archive',
                    label: 'Archive',
                    icon: Archive,
                    destructive: true,
                },
            );
        }
    }

    const removeAttachment = (attId: number) =>
        router.delete(
            `/health-safety/risk-assessments/${detail.id}/attachments/${attId}`,
            { preserveScroll: true, preserveState: true },
        );

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{
                    maxWidth: 'min(94vw, 1080px)',
                    width: 'min(94vw, 1080px)',
                }}
            >
                <DialogTitle className="sr-only">{detail.title}</DialogTitle>
                <DialogDescription className="sr-only">
                    Risk assessment {detail.reference_number}
                </DialogDescription>

                <div className="flex h-[min(92vh,860px)] min-h-0 overflow-hidden">
                    {/* ── Rail ── */}
                    <aside className="hidden w-[248px] shrink-0 flex-col gap-2 overflow-y-auto border-r border-sidebar-border bg-sidebar p-4 sm:flex">
                        <div className="flex items-center gap-2.5">
                            <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary/10 text-primary">
                                <ShieldAlert className="h-5 w-5" />
                            </span>
                            <div>
                                <div className="text-sm leading-tight font-bold">
                                    Risk assessment
                                </div>
                                <div className="text-[11px] text-muted-foreground tabular-nums">
                                    {detail.reference_number}
                                </div>
                            </div>
                        </div>
                        <span className="w-fit">
                            <StatusChip status={detail.status} />
                        </span>

                        <MetaCard
                            label="Attached to"
                            value={
                                <span className="inline-flex items-center gap-1.5">
                                    <AttachIcon className="h-3.5 w-3.5 text-muted-foreground" />
                                    {detail.attached_to.name}
                                </span>
                            }
                        />
                        <MetaCard
                            label="Assessed by"
                            value={detail.assessed_by_name ?? '—'}
                        />
                        {detail.approved_by_name ? (
                            <MetaCard
                                label="Approved by"
                                value={detail.approved_by_name}
                            />
                        ) : null}
                        <MetaCard
                            label="Review due"
                            value={fmtDate(detail.review_due_at)}
                        />

                        <div className="mt-auto pt-3 text-[11px] leading-relaxed text-muted-foreground">
                            {detail.superseded_by ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onOpenAssessment?.(
                                            detail.superseded_by!.id,
                                        )
                                    }
                                    className="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                                >
                                    <ExternalLink className="h-3 w-3" />{' '}
                                    Superseded by{' '}
                                    {detail.superseded_by.reference_number}
                                </button>
                            ) : (
                                <>
                                    Created {fmtDate(detail.created_at)} by{' '}
                                    {detail.created_by_name ?? '—'}
                                </>
                            )}
                        </div>
                    </aside>

                    {/* ── Main ── */}
                    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                        <header className="flex shrink-0 items-start justify-between gap-3 border-b border-border px-5 py-4">
                            <div>
                                <div className="text-[12px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    Overview
                                </div>
                                <h2 className="mt-0.5 text-lg font-bold tracking-tight">
                                    {detail.title}
                                </h2>
                                {detail.risk_description ? (
                                    <p className="mt-1 max-w-xl text-[13px] leading-relaxed text-muted-foreground">
                                        {detail.risk_description}
                                    </p>
                                ) : null}
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                aria-label="Close"
                                className="grid h-8 w-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </header>

                        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                            {/* matrices */}
                            <div className="grid gap-4 lg:grid-cols-2">
                                <div className="rounded-xl border border-border p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-[12px] font-bold tracking-wide text-muted-foreground uppercase">
                                            Inherent risk
                                        </span>
                                        <ScoreHead
                                            score={detail.risk_score}
                                            level={detail.risk_level}
                                        />
                                    </div>
                                    <RaMatrix
                                        likelihood={detail.likelihood}
                                        consequence={detail.consequence}
                                        compact
                                    />
                                </div>
                                <div className="rounded-xl border border-border p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-[12px] font-bold tracking-wide text-muted-foreground uppercase">
                                            Residual risk
                                        </span>
                                        <span className="inline-flex items-center gap-2">
                                            <AcceptableBadge
                                                value={detail.risk_acceptable}
                                            />
                                            <ScoreHead
                                                score={
                                                    detail.residual_risk_score
                                                }
                                                level={
                                                    detail.residual_risk_level
                                                }
                                            />
                                        </span>
                                    </div>
                                    {detail.residual_risk_score != null ? (
                                        <RaMatrix
                                            likelihood={detail.likelihood}
                                            consequence={detail.consequence}
                                            residualLikelihood={
                                                detail.residual_likelihood
                                            }
                                            residualConsequence={
                                                detail.residual_consequence
                                            }
                                            compact
                                        />
                                    ) : (
                                        <p className="py-6 text-center text-sm text-muted-foreground">
                                            Not yet scored — record a review to
                                            set the residual risk.
                                        </p>
                                    )}
                                </div>
                            </div>

                            {/* controls */}
                            <div className="mt-5 grid gap-4 lg:grid-cols-2">
                                <ControlBlock
                                    title="Existing controls"
                                    body={detail.existing_controls}
                                />
                                <ControlBlock
                                    title="Additional controls"
                                    body={detail.additional_controls}
                                />
                            </div>

                            {/* notes */}
                            {detail.approval_note || detail.last_review_note ? (
                                <div className="mt-5 grid gap-4 lg:grid-cols-2">
                                    {detail.approval_note ? (
                                        <ControlBlock
                                            title="Approver note"
                                            body={detail.approval_note}
                                        />
                                    ) : null}
                                    {detail.last_review_note ? (
                                        <ControlBlock
                                            title="Last review note"
                                            body={detail.last_review_note}
                                        />
                                    ) : null}
                                </div>
                            ) : null}

                            {/* evidence */}
                            <div className="mt-6">
                                <div className="mb-2 flex items-center gap-2 text-[12px] font-bold tracking-wide text-muted-foreground uppercase">
                                    <FileText className="h-3.5 w-3.5" />{' '}
                                    Supporting evidence
                                    {detail.attachments.length ? (
                                        <span className="text-muted-foreground">
                                            · {detail.attachments.length}
                                        </span>
                                    ) : null}
                                </div>
                                {detail.attachments.length ? (
                                    <div className="flex flex-col gap-2">
                                        {detail.attachments.map((a) => (
                                            <div
                                                key={a.id}
                                                className="flex items-center gap-3 rounded-lg border border-border bg-card/60 p-2.5"
                                            >
                                                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                                    {a.is_image ? (
                                                        <ImageIcon className="h-4 w-4" />
                                                    ) : (
                                                        <FileText className="h-4 w-4" />
                                                    )}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="truncate text-[13px] font-semibold">
                                                        {a.original_name}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        {a.kind
                                                            ? `${cap(a.kind.replace('_', ' '))} · `
                                                            : ''}
                                                        {a.uploaded_by_name ??
                                                            '—'}
                                                        {a.notes
                                                            ? ` · ${a.notes}`
                                                            : ''}
                                                    </div>
                                                </div>
                                                <a
                                                    href={a.download_url}
                                                    className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                    aria-label={`Download ${a.original_name}`}
                                                >
                                                    <Download className="h-4 w-4" />
                                                </a>
                                                {canManage ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            removeAttachment(
                                                                a.id,
                                                            )
                                                        }
                                                        aria-label={`Remove ${a.original_name}`}
                                                        className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-status-critical"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                ) : null}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-[13px] text-muted-foreground">
                                        No evidence attached yet.
                                    </p>
                                )}
                                {canManage ? (
                                    <div className="mt-3">
                                        <AttachmentUploader
                                            endpoint={`/health-safety/risk-assessments/${detail.id}/attachments`}
                                            noteField="notes"
                                            accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                                            hint="SWMS, method statements, photos, SDS, plans — up to 20 MB each"
                                        />
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        {/* Options footer */}
                        {actions.length ? (
                            <footer className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-border bg-muted/30 px-5 py-3.5">
                                <span className="text-[12px] text-muted-foreground">
                                    Lifecycle options
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    {actions.map((a) => (
                                        <Button
                                            key={a.key}
                                            type="button"
                                            size="sm"
                                            variant={
                                                a.destructive
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                            onClick={() => onAction(a.key)}
                                        >
                                            <a.icon className="h-3.5 w-3.5" />{' '}
                                            {a.label}
                                        </Button>
                                    ))}
                                </div>
                            </footer>
                        ) : null}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ControlBlock({ title, body }: { title: string; body: string | null }) {
    return (
        <div>
            <div className="mb-1.5 text-[12px] font-bold tracking-wide text-muted-foreground uppercase">
                {title}
            </div>
            <p className="text-[13px] leading-relaxed text-foreground">
                {body || <span className="text-muted-foreground">—</span>}
            </p>
        </div>
    );
}
