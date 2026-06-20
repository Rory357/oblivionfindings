/* eslint-disable no-restricted-syntax -- bespoke detail-modal surfaces (chips,
 * lifecycle timeline, dashed buttons) are intentional custom layouts on semantic
 * tokens, mirroring restraint-event-detail-dialog.tsx + the wizard primitives. */
/* Behaviour support plan detail — the over-the-list governance modal on WizardShell.
 * Sections: overview · plan content (approved vs prohibited chips) · lifecycle
 * (draft→active→under_review→archived) · reviews (record-review pane). NZ-only. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import {
    CHIP,
    fmtDateFull,
    fmtDateTime,
    PLAN_REVIEW_OUTCOME_LABEL,
    PLAN_REVIEW_OUTCOME_OPTIONS,
    planStatusMeta,
    REVIEW_STATE_META,
    titleCase,
    type PlanDetail,
} from '@/pages/health-safety/restraints/shared';
import type { Page } from '@inertiajs/core';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    Archive,
    BookOpen,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ExternalLink,
    FileText,
    History,
    Plus,
    ShieldAlert,
    ThumbsDown,
    ThumbsUp,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ComponentType, type FormEvent } from 'react';

export type PlanSectionKey = 'overview' | 'content' | 'lifecycle' | 'reviews';
export type PlanActionKey = 'review';

export function BspDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    onRecordEvent,
}: {
    detail: PlanDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: PlanSectionKey;
    initialAction?: PlanActionKey | null;
    onRecordEvent?: () => void;
}) {
    const d = detail;
    const [section, setSection] = useState<PlanSectionKey>(initialSection);
    const [reviewing, setReviewing] = useState(initialAction === 'review');

    useEffect(() => {
        setSection(initialSection);
        setReviewing(initialAction === 'review');
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes
    }, [initialSection, initialAction, d.id]);

    const status = planStatusMeta(d.status);
    const reviewState = REVIEW_STATE_META[d.review_state] ?? REVIEW_STATE_META.ok;

    const SECTIONS: { key: PlanSectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'Status & review', icon: FileText },
        { key: 'content', label: 'Plan content', blurb: 'Approved vs prohibited', icon: BookOpen },
        { key: 'lifecycle', label: 'Lifecycle', blurb: titleCase(d.status), icon: Activity },
        { key: 'reviews', label: 'Reviews', blurb: `${d.reviews.length} recorded`, icon: History },
    ];
    const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));

    const transition = (verb: string) => router.post(`/health-safety/restraints/plans/${d.id}/${verb}`, {}, { preserveScroll: true, preserveState: true });

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP[status.tone]}`}>
                <status.icon className="h-3 w-3" /> {status.label}
            </span>
            {d.status === 'active' ? (
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${CHIP[reviewState.tone]}`}>
                    <CalendarClock className="h-3 w-3" /> {reviewState.label}
                </span>
            ) : null}
        </div>
    );

    const footerEnd = reviewing ? null : (
        <div className="flex flex-wrap items-center gap-2">
            {d.client ? (
                <Link href={`/operations/clients/${d.client.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
                    <ExternalLink className="h-4 w-4" /> Client profile
                </Link>
            ) : null}
            {onRecordEvent ? (
                <Button size="sm" variant="outline" onClick={onRecordEvent}>
                    <Plus className="mr-1.5 h-4 w-4" /> Record event
                </Button>
            ) : null}
            {d.can.manage && d.status === 'draft' ? (
                <Button size="sm" onClick={() => transition('activate')}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Activate
                </Button>
            ) : null}
            {d.can.manage && d.status === 'active' ? (
                <Button size="sm" variant="outline" onClick={() => transition('submit-review')}>
                    <ClipboardCheck className="mr-1.5 h-4 w-4" /> Submit for review
                </Button>
            ) : null}
            {d.can.review ? (
                <Button size="sm" variant={d.status === 'under_review' ? 'default' : 'outline'} onClick={() => setReviewing(true)}>
                    <ClipboardCheck className="mr-1.5 h-4 w-4" /> Record review
                </Button>
            ) : null}
            {d.can.manage && d.status !== 'archived' ? (
                <Button size="sm" variant="outline" onClick={() => transition('archive')} className="border-status-critical/40 text-status-critical hover:text-status-critical">
                    <Archive className="mr-1.5 h-4 w-4" /> Archive
                </Button>
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Behaviour support plan ${d.reference}`}
            description={`${d.title} — ${status.label}`}
            railIcon={BookOpen}
            railTitle={d.reference}
            railSub={`${d.client?.name ?? 'Unknown client'} · ${status.label}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {reviewing ? (
                <RecordReviewPane d={d} onDone={() => setReviewing(false)} />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} /> : null}
                    {section === 'content' ? <ContentSection d={d} /> : null}
                    {section === 'lifecycle' ? <LifecycleSection d={d} /> : null}
                    {section === 'reviews' ? <ReviewsSection d={d} onRecord={() => setReviewing(true)} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d }: { d: PlanDetail }) {
    const reviewState = REVIEW_STATE_META[d.review_state] ?? REVIEW_STATE_META.ok;
    return (
        <div className="flex flex-col gap-4">
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <div className="mb-1 text-lg font-bold">{d.title}</div>
                <div className="text-xs text-muted-foreground">{d.reference}</div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Plan</div>
                    <ReviewRow label="Client" value={d.client?.name} />
                    <ReviewRow label="Status" value={titleCase(d.status)} />
                    <ReviewRow label="Restrictive practice" value={d.restrictive_practice_type ? titleCase(d.restrictive_practice_type) : undefined} />
                    <ReviewRow label="Restraint events" value={d.events_count > 0 ? `${d.events_count}` : undefined} />
                </div>
                <div className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Authoring & review</div>
                    <ReviewRow label="Developed by" value={d.developed_by?.name} />
                    <ReviewRow label="Developed on" value={fmtDateFull(d.developed_at)} />
                    <ReviewRow label="Next review" value={fmtDateFull(d.review_date)} />
                    <ReviewRow
                        label="Review state"
                        value={<span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[reviewState.tone]}`}>{reviewState.label}</span>}
                    />
                </div>
            </div>
            {d.status === 'active' && d.review_state === 'overdue' ? (
                <InfoCard icon={CalendarClock} tone="crit">This plan&apos;s review is overdue. An active plan governing restrictive practice must be kept current.</InfoCard>
            ) : null}
        </div>
    );
}

function ContentSection({ d }: { d: PlanDetail }) {
    return (
        <div className="flex flex-col gap-4">
            <Block icon={Activity} title="Triggers / antecedents" body={d.triggers} />
            <Block icon={ShieldAlert} title="De-escalation strategies" body={d.de_escalation_strategies} />
            <div className="grid gap-4 sm:grid-cols-2">
                <ChipBlock icon={ThumbsUp} title="Approved interventions" tone="approved" items={d.approved_interventions} />
                <ChipBlock icon={ThumbsDown} title="Prohibited interventions" tone="prohibited" items={d.prohibited_interventions} />
            </div>
            {d.notes ? <Block icon={FileText} title="Notes" body={d.notes} /> : null}
        </div>
    );
}

function LifecycleSection({ d }: { d: PlanDetail }) {
    const STAGES = ['draft', 'active', 'under_review', 'archived'];
    const currentIdx = STAGES.indexOf(d.status);
    return (
        <div className="flex flex-col gap-4">
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <div className="mb-3 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Plan lifecycle</div>
                <ol className="flex flex-col gap-3">
                    {STAGES.map((stage, i) => {
                        const meta = planStatusMeta(stage);
                        const done = i < currentIdx;
                        const active = i === currentIdx;
                        return (
                            <li key={stage} className="flex items-center gap-3">
                                <span
                                    className={`grid h-7 w-7 shrink-0 place-items-center rounded-full ${active ? 'bg-primary text-primary-foreground' : done ? 'bg-status-success-bg text-status-success' : 'bg-muted text-muted-foreground'}`}
                                >
                                    <meta.icon className="h-3.5 w-3.5" />
                                </span>
                                <div className="min-w-0">
                                    <div className={`text-sm ${active ? 'font-bold' : 'font-semibold'}`}>{meta.label}</div>
                                    {active && d.status_changed_at ? (
                                        <div className="text-xs text-muted-foreground">
                                            Since {fmtDateTime(d.status_changed_at)}
                                            {d.status_changed_by ? ` · ${d.status_changed_by.name}` : ''}
                                        </div>
                                    ) : null}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </div>
            <InfoCard icon={ClipboardCheck} tone="info">
                Use the Options bar below to move the plan through its lifecycle: activate a draft, submit an active plan for review, record a review, or archive a retired plan.
            </InfoCard>
        </div>
    );
}

function ReviewsSection({ d, onRecord }: { d: PlanDetail; onRecord: () => void }) {
    return (
        <div className="flex flex-col gap-3">
            {d.reviews.length === 0 ? (
                <EmptyState icon={History} title="No reviews yet" blurb="Record the first review to track how this plan reduces restrictive practice over time." />
            ) : (
                d.reviews.map((r) => (
                    <div key={r.id} className="rounded-xl border border-border bg-card/70 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                                <ClipboardCheck className="h-3 w-3" /> {PLAN_REVIEW_OUTCOME_LABEL[r.outcome] ?? titleCase(r.outcome)}
                            </span>
                            <span className="text-xs text-muted-foreground">{fmtDateTime(r.reviewed_at)}</span>
                        </div>
                        {r.notes ? <p className="mt-2 text-sm leading-relaxed whitespace-pre-wrap">{r.notes}</p> : null}
                        <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                            {r.reviewed_by ? <span>By {r.reviewed_by}</span> : null}
                            {r.next_review_date ? <span>Next review {fmtDateFull(r.next_review_date)}</span> : null}
                            {r.resulting_status ? <span>→ {titleCase(r.resulting_status)}</span> : null}
                        </div>
                    </div>
                ))
            )}
            {d.can.review ? (
                <button type="button" onClick={onRecord} className="flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-border py-3 text-sm font-semibold text-primary transition-colors hover:bg-primary/5">
                    <ClipboardCheck className="h-4 w-4" /> Record review
                </button>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Record-review pane (Add-Client idiom)                              */
/* ------------------------------------------------------------------ */

function RecordReviewPane({ d, onDone }: { d: PlanDetail; onDone: () => void }) {
    const form = useForm({
        outcome: 'continued',
        next_review_date: '',
        resulting_status: d.status === 'under_review' ? 'active' : d.status,
        notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/restraints/plans/${d.id}/review`, {
            preserveScroll: true,
            onSuccess: (page: Page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={ClipboardCheck} title="Record plan review" blurb="Capture the review outcome and set the next review date." />
            <InfoCard icon={ThumbsUp} tone="info">Reviews drive least-restrictive practice — record whether the plan can be reduced, and when it&apos;s next due.</InfoCard>
            <Field label="Outcome" required error={form.errors.outcome}>
                <SelectInput value={form.data.outcome} onChange={(v) => form.setData('outcome', v)} placeholder="Select outcome" options={PLAN_REVIEW_OUTCOME_OPTIONS} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Next review date" error={form.errors.next_review_date}>
                    <Input type="date" value={form.data.next_review_date} onChange={(e) => form.setData('next_review_date', e.target.value)} />
                </Field>
                <Field label="Resulting status" error={form.errors.resulting_status}>
                    <SelectInput
                        value={form.data.resulting_status}
                        onChange={(v) => form.setData('resulting_status', v)}
                        placeholder="Status after review"
                        options={[
                            { value: 'active', label: 'Active' },
                            { value: 'under_review', label: 'Under review' },
                            { value: 'archived', label: 'Archived' },
                        ]}
                    />
                </Field>
            </div>
            <Field label="Review notes" error={form.errors.notes}>
                <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="What changed, and why?" />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save review
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Small shared bits                                                  */
/* ------------------------------------------------------------------ */

function Block({ icon: Icon, title, body }: { icon: LucideIcon; title: string; body: string | null }) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                <Icon className="h-3.5 w-3.5" /> {title}
            </div>
            <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">{body || <span className="text-muted-foreground">—</span>}</p>
        </div>
    );
}

function ChipBlock({ icon: Icon, title, tone, items }: { icon: LucideIcon; title: string; tone: 'approved' | 'prohibited'; items: string[] }) {
    const chipCls = tone === 'approved' ? 'border-status-success/40 bg-status-success-bg text-status-success' : 'border-status-critical/40 bg-status-critical-bg text-status-critical';
    return (
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                <Icon className="h-3.5 w-3.5" /> {title}
            </div>
            {items.length ? (
                <div className="flex flex-wrap gap-1.5">
                    {items.map((it) => (
                        <span key={it} className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[13px] font-medium ${chipCls}`}>
                            <Icon className="h-3 w-3" /> {it}
                        </span>
                    ))}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">None recorded.</p>
            )}
        </div>
    );
}

function EmptyState({ icon: Icon, title, blurb }: { icon: LucideIcon; title: string; blurb: string }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center">
            <Icon className="h-8 w-8 text-muted-foreground" />
            <div className="text-sm font-semibold">{title}</div>
            <p className="max-w-sm text-xs text-muted-foreground">{blurb}</p>
        </div>
    );
}
