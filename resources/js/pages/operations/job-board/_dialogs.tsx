import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    WORKER_TIMEZONE,
    formatDateOnly,
    formatDateTimeInZone,
} from '@/lib/datetime';
import {
    AlertTriangle,
    Briefcase,
    CalendarCheck,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Hand,
    Info,
    Loader2,
    MapPin,
    Search,
    Settings2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import {
    mayConfirmAction,
    positionStateLabel,
    type PositionSource,
} from './_action-contract';
import type { PostableShift } from './_posting-contract';
import type { BoardActionController } from './_use-board-action';
import type { PostingController } from './_use-posting';

/* ------------------------------------------------------------------ */
/*  Post position — WizardShell (Choose shift → Details → Review)       */
/* ------------------------------------------------------------------ */

const POST_STEPS: readonly WizardStep[] = [
    {
        key: 'shift',
        label: 'Choose shift',
        blurb: 'Find a shift that needs cover',
        icon: CalendarDays,
    },
    {
        key: 'details',
        label: 'Posting details',
        blurb: 'Skills, notes and expiry',
        icon: Settings2,
    },
    {
        key: 'review',
        label: 'Review & post',
        blurb: 'Check before posting',
        icon: CheckCircle2,
    },
];

function disabledReason(row: PostableShift): string | null {
    if (row.can_post) return null;
    if (row.reason === 'duplicate')
        return 'Already posted. An open or claimed posting exists, even if it has expired.';
    if (row.reason === 'terminal_shift')
        return 'This shift is completed or cancelled.';
    if (row.reason === 'request_cover_required')
        return 'Someone is rostered on this shift. Request replacement cover from the shift first.';
    return "This shift can't be posted right now.";
}

/** Stays mounted while closed: the page owns `form`, so an unconfirmed post
 * and its entries are kept while the person stays on this page. */
export function PostPositionDialog({
    open,
    onClose,
    form,
    week,
}: {
    open: boolean;
    onClose: () => void;
    form: PostingController;
    week: string;
}) {
    const [step, setStep] = useState(0);
    const [search, setSearch] = useState('');
    const [closing, setClosing] = useState<'draft' | 'unknown' | null>(null);
    const [startingAgain, setStartingAgain] = useState(false);
    const feedback = useRef<HTMLDivElement>(null);
    const unknown = form.outcome?.status === 'unknown';
    const confirmed = form.outcome?.status === 'confirmed';
    const locked = form.pending || unknown || confirmed;
    const review = form.review;
    const zone =
        review?.zone ??
        (form.listing.status === 'ready'
            ? form.listing.page.workerTimezone
            : '');
    const display = (value: string | null) =>
        value && zone ? formatDateTimeInZone(value, zone) : 'Not recorded';
    const [expiryDate = '', expiryTime = ''] = form.details.expiry.split('T');

    const next = () => {
        if (form.busy.current || locked) return;
        if (step === 0) {
            if (form.selection) setStep(1);
            return;
        }
        if (step === 1) {
            if (form.captureReview()) setStep(2);
            return;
        }
        void form.submit();
    };
    const goTo = (index: number) => {
        if (form.busy.current || locked) return;
        if (index === 2) {
            if (form.captureReview()) setStep(2);
            else setStep(form.selection ? 1 : 0);
        } else setStep(index);
    };
    const finish = () => {
        form.clearDraft();
        setStep(0);
        setSearch('');
        onClose();
    };
    const close = () => {
        if (form.busy.current || form.pending || form.checking) return;
        if (unknown) setClosing('unknown');
        else if (form.dirty && !confirmed) setClosing('draft');
        else finish();
    };

    useEffect(() => {
        if (form.error || form.outcome?.status === 'rejected')
            feedback.current?.focus();
        if (form.outcome?.status === 'rejected') setStep(0);
    }, [form.error, form.outcome]);

    const readyZone =
        form.listing.status === 'ready' ? form.listing.page.workerTimezone : '';

    return (
        <>
            <WizardShell
                open={open}
                onClose={close}
                title="Post position"
                description="Choose a shift that needs cover, add any details and post it so staff can claim it."
                railIcon={Briefcase}
                railTitle="Post position"
                railSub="Advertise a shift for cover"
                steps={POST_STEPS.map((item) => ({
                    ...item,
                    disabled: locked,
                }))}
                stepIndex={unknown ? 2 : step}
                onStepClick={goTo}
                frontline
                pct={review ? 100 : form.selection ? 50 : 0}
                pctLabel="Ready to post"
                footerStart={
                    step > 0 && !locked ? (
                        <Button variant="ghost" onClick={() => goTo(step - 1)}>
                            <ChevronLeft className="size-4" />
                            Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={close}
                            disabled={form.pending || form.checking}
                        >
                            {unknown ? 'Close form' : 'Cancel'}
                        </Button>
                        {!locked && (
                            <Button
                                onClick={next}
                                disabled={
                                    (step === 0 && !form.selection) ||
                                    (step === 2 && !review)
                                }
                            >
                                {step === 2 ? 'Post position' : 'Continue'}
                                {step < 2 && (
                                    <ChevronRight className="size-4" />
                                )}
                            </Button>
                        )}
                        {form.pending && (
                            <Button disabled>
                                <Loader2 className="size-4 animate-spin" />
                                Posting…
                            </Button>
                        )}
                    </>
                }
                success={
                    confirmed ? (
                        <WizardSuccessPane
                            title="Position posted"
                            blurb="The shift is now open for claims. Nobody is assigned until a coordinator approves a claim."
                            actions={<Button onClick={finish}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                {(form.error || form.outcome?.status === 'rejected') && (
                    <div
                        ref={feedback}
                        tabIndex={-1}
                        role="alert"
                        className="mb-4 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <InfoCard icon={AlertTriangle} tone="crit">
                            {form.error ||
                                (form.outcome?.status === 'rejected'
                                    ? form.outcome.message
                                    : '')}{' '}
                            Your entries are kept.
                        </InfoCard>
                    </div>
                )}
                {unknown && (
                    <div
                        className="mb-5 flex flex-col gap-3"
                        role="status"
                        aria-live="polite"
                    >
                        <StatusBadge variant="warning" className="self-start">
                            Not confirmed
                        </StatusBadge>
                        <InfoCard icon={AlertTriangle} tone="warn">
                            {form.outcome?.status === 'unknown' &&
                                form.outcome.message}
                        </InfoCard>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() => void form.checkCurrent()}
                                disabled={form.checking}
                            >
                                {form.checking && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Check the shift
                            </Button>
                            {form.checked && (
                                <Button
                                    variant="outline"
                                    onClick={() => setStartingAgain(true)}
                                >
                                    Start again
                                </Button>
                            )}
                        </div>
                        {form.readback && (
                            <p className="text-subtle break-words">
                                {form.readback}
                            </p>
                        )}
                        <p className="text-caption">
                            Closing keeps this attempt while you stay on this
                            page. Reloading or leaving the page clears it, and
                            neither undoes a post that saved.
                        </p>
                    </div>
                )}
                {!unknown && step === 0 && (
                    <WizardStepPane>
                        <StepHead
                            icon={CalendarDays}
                            title="Choose a shift"
                            blurb="Find the shift that needs cover. Posting it does not assign anyone."
                        />
                        <div
                            className="mb-4 flex flex-wrap gap-2"
                            role="group"
                            aria-label="Shift dates"
                        >
                            <Button
                                variant={
                                    form.range === 'week'
                                        ? 'default'
                                        : 'outline'
                                }
                                aria-pressed={form.range === 'week'}
                                onClick={() => form.changeRange('week')}
                            >
                                Week of {formatDateOnly(week)}
                            </Button>
                            <Button
                                variant={
                                    form.range === 'all' ? 'default' : 'outline'
                                }
                                aria-pressed={form.range === 'all'}
                                onClick={() => form.changeRange('all')}
                            >
                                All weeks
                            </Button>
                        </div>
                        <form
                            className="mb-4 flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.search(search);
                            }}
                        >
                            <div className="min-w-0 flex-1">
                                <Field label="Search shifts">
                                    <Input
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        placeholder="e.g. Matai House or Hamilton"
                                    />
                                </Field>
                            </div>
                            <Button variant="outline" type="submit">
                                <Search className="size-4" />
                                Search
                            </Button>
                        </form>
                        {form.listing.status === 'loading' && (
                            <div role="status">
                                <LoadingState
                                    message="Loading shifts…"
                                    className="py-6"
                                />
                            </div>
                        )}
                        {form.listing.status === 'error' && (
                            <div role="alert" className="flex flex-col gap-3">
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {form.listing.message}
                                </InfoCard>
                                <Button
                                    variant="outline"
                                    className="self-start"
                                    onClick={form.reloadList}
                                >
                                    Load the list again
                                </Button>
                            </div>
                        )}
                        {form.listing.status === 'ready' && (
                            <>
                                <p
                                    id="post-shift-list-label"
                                    className="text-subtle mb-3"
                                >
                                    Choose a shift · times in {readyZone}
                                </p>
                                {form.listing.page.data.length === 0 ? (
                                    <InfoCard icon={Info}>
                                        No shifts match this search and date
                                        range. Try All weeks or change the
                                        search.
                                    </InfoCard>
                                ) : (
                                    <div className="[overflow-wrap:anywhere]">
                                        <TilePicker
                                            frontline
                                            cols={2}
                                            labelledBy="post-shift-list-label"
                                            value={
                                                form.selection
                                                    ? String(form.selection.id)
                                                    : ''
                                            }
                                            onChange={(value) =>
                                                form.choose(Number(value))
                                            }
                                            options={form.listing.page.data.map(
                                                (row) => ({
                                                    key: String(row.id),
                                                    label: row.label,
                                                    icon: MapPin,
                                                    description: `${row.location || 'Location not recorded'} · ${row.starts_at ? formatDateTimeInZone(row.starts_at, readyZone) : 'Start not recorded'} → ${row.ends_at ? formatDateTimeInZone(row.ends_at, readyZone) : 'End not recorded'}`,
                                                    disabled:
                                                        disabledReason(row),
                                                }),
                                            )}
                                        />
                                    </div>
                                )}
                                {(form.page > 1 ||
                                    form.listing.page.has_more) && (
                                    <nav
                                        aria-label="Shift list pages"
                                        className="mt-4 flex flex-wrap items-center gap-3"
                                    >
                                        <Button
                                            variant="outline"
                                            disabled={form.page === 1}
                                            onClick={() =>
                                                form.changePage(form.page - 1)
                                            }
                                        >
                                            <ChevronLeft className="size-4" />
                                            Previous
                                        </Button>
                                        <span className="text-subtle">
                                            Page {form.page}
                                        </span>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                !form.listing.page.has_more
                                            }
                                            onClick={() =>
                                                form.changePage(form.page + 1)
                                            }
                                        >
                                            Next
                                            <ChevronRight className="size-4" />
                                        </Button>
                                    </nav>
                                )}
                            </>
                        )}
                    </WizardStepPane>
                )}
                {!unknown && step === 1 && (
                    <WizardStepPane>
                        <StepHead
                            icon={Settings2}
                            title="Posting details"
                            blurb="Add anything staff need to know before they claim this shift."
                        />
                        <fieldset
                            disabled={locked}
                            className="flex flex-col gap-5"
                        >
                            <Field
                                label="Required skills"
                                hint="Optional · one per line"
                            >
                                <Textarea
                                    value={form.details.skills}
                                    onChange={(event) =>
                                        form.changeDetails({
                                            ...form.details,
                                            skills: event.target.value,
                                        })
                                    }
                                    placeholder="e.g. Hoist transfers"
                                    rows={3}
                                />
                            </Field>
                            <Field
                                label="Posting notes"
                                hint="Optional · up to 2,000 characters"
                            >
                                <Textarea
                                    value={form.details.notes}
                                    onChange={(event) =>
                                        form.changeDetails({
                                            ...form.details,
                                            notes: event.target.value,
                                        })
                                    }
                                    placeholder="e.g. Parking is at the back of the house"
                                    rows={4}
                                />
                            </Field>
                            <fieldset className="date-time-field" data-compact>
                                <legend>
                                    Posting closes{' '}
                                    <span>{zone || WORKER_TIMEZONE}</span>
                                </legend>
                                <p
                                    id="post-expiry-hint"
                                    className="text-subtle mb-3"
                                >
                                    Optional. Leave both empty to keep the
                                    posting open. Closing a posting does not
                                    cancel a claim already made.
                                </p>
                                <div className="inline-fields">
                                    <div className="field">
                                        <label htmlFor="post-expiry-date">
                                            Date
                                        </label>
                                        <DatePicker
                                            id="post-expiry-date"
                                            label="Posting closes date"
                                            compact
                                            value={expiryDate}
                                            timeZone={zone || undefined}
                                            describedBy="post-expiry-hint"
                                            onChange={(date) =>
                                                form.changeDetails({
                                                    ...form.details,
                                                    expiry:
                                                        date || expiryTime
                                                            ? `${date}T${expiryTime}`
                                                            : '',
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="field">
                                        <label htmlFor="post-expiry-time">
                                            Time
                                        </label>
                                        <TimePicker
                                            id="post-expiry-time"
                                            label="Posting closes time"
                                            compact
                                            value={expiryTime}
                                            timezone={zone || undefined}
                                            describedBy="post-expiry-hint"
                                            onChange={(time) =>
                                                form.changeDetails({
                                                    ...form.details,
                                                    expiry:
                                                        expiryDate || time
                                                            ? `${expiryDate}T${time}`
                                                            : '',
                                                })
                                            }
                                        />
                                    </div>
                                </div>
                                {form.details.expiry && (
                                    <Button
                                        variant="ghost"
                                        className="mt-2"
                                        onClick={() =>
                                            form.changeDetails({
                                                ...form.details,
                                                expiry: '',
                                            })
                                        }
                                    >
                                        Clear closing time
                                    </Button>
                                )}
                            </fieldset>
                            <InfoCard icon={Info}>
                                Required skills describe the cover. They do not
                                override qualification, leave or other
                                eligibility checks.
                            </InfoCard>
                        </fieldset>
                    </WizardStepPane>
                )}
                {(unknown || step === 2) && (
                    <WizardStepPane>
                        <StepHead
                            icon={CheckCircle2}
                            title={
                                unknown
                                    ? 'The post you sent'
                                    : 'Review your posting'
                            }
                            blurb="Check the shift and the details staff will see."
                        />
                        {review ? (
                            <div className="grid min-w-0 gap-4 [overflow-wrap:anywhere]">
                                <ReviewCard
                                    icon={CalendarDays}
                                    title="Shift"
                                    onEdit={locked ? undefined : () => goTo(0)}
                                >
                                    <ReviewRow
                                        label="Shift"
                                        value={review.intent.row.label}
                                    />
                                    <ReviewRow
                                        label="Location"
                                        value={
                                            review.intent.row.location ||
                                            'Not recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Starts"
                                        value={display(
                                            review.intent.row.starts_at,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Ends"
                                        value={display(
                                            review.intent.row.ends_at,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Time zone"
                                        value={review.zone}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Settings2}
                                    title="Posting details"
                                    onEdit={locked ? undefined : () => goTo(1)}
                                >
                                    <ReviewRow
                                        label="Required skills"
                                        value={
                                            <span className="whitespace-pre-wrap">
                                                {review.intent.body
                                                    .required_skills.length
                                                    ? review.intent.body.required_skills.join(
                                                          '\n',
                                                      )
                                                    : 'None added'}
                                            </span>
                                        }
                                    />
                                    <ReviewRow
                                        label="Notes"
                                        value={
                                            <span className="whitespace-pre-wrap">
                                                {review.intent.body.notes ||
                                                    'None added'}
                                            </span>
                                        }
                                    />
                                    <ReviewRow
                                        label="Posting closes"
                                        value={
                                            review.intent.body.expires_at
                                                ? display(
                                                      review.intent.body
                                                          .expires_at,
                                                  )
                                                : 'Stays open'
                                        }
                                    />
                                </ReviewCard>
                                {!unknown && (
                                    <InfoCard icon={Info}>
                                        Posting lets staff claim this shift. A
                                        coordinator still approves the claim
                                        before anyone is assigned.
                                    </InfoCard>
                                )}
                            </div>
                        ) : (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                Choose a shift and add the posting details
                                before you review.
                            </InfoCard>
                        )}
                    </WizardStepPane>
                )}
            </WizardShell>
            <DiscardDraftDialog
                open={closing === 'draft'}
                mode="create"
                frontline
                description="Your entries in this posting will be discarded. Nothing has been posted from them."
                onKeepEditing={() => setClosing(null)}
                onDiscard={() => {
                    setClosing(null);
                    finish();
                }}
            />
            <ConfirmDialog
                open={closing === 'unknown'}
                frontline
                variant="default"
                title="Close this unconfirmed post?"
                description="The position may already have saved. Closing doesn't undo it. While you stay on this page, Post position reopens this attempt so you can check it."
                cancelText="Keep it open"
                confirmText="Close form"
                onClose={() => setClosing(null)}
                onConfirm={() => {
                    setClosing(null);
                    onClose();
                }}
            />
            <ConfirmDialog
                open={startingAgain}
                frontline
                variant="default"
                title="Start the post again?"
                description="Your earlier attempt may still have saved. Only post again if the check showed the shift isn't posted. Your entries are kept; choose the shift again from the updated list."
                cancelText="Keep checking"
                confirmText="Start again"
                onClose={() => setStartingAgain(false)}
                onConfirm={() => {
                    if (form.startAgain()) {
                        setStep(0);
                        setSearch('');
                    }
                    setStartingAgain(false);
                }}
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Claim shift / Approve claim — simple dialog (480px token)           */
/* ------------------------------------------------------------------ */

function ShiftSummary({ source }: { source: PositionSource }) {
    const date = (value: string | null) =>
        value ? formatDateTimeInZone(value, WORKER_TIMEZONE) : 'Not recorded';
    return (
        <ReviewCard icon={CalendarCheck} title="Shift">
            <ReviewRow label="Client" value={source.display.client} />
            <ReviewRow
                label="House or site"
                value={source.display.site ?? 'Not recorded'}
            />
            <ReviewRow label="Starts" value={date(source.display.starts_at)} />
            <ReviewRow label="Ends" value={date(source.display.ends_at)} />
            <ReviewRow
                label="Right now"
                value={positionStateLabel(
                    source.display.status,
                    source.display.claimant,
                )}
            />
        </ReviewCard>
    );
}

function ReasonList({ reasons }: { reasons: string[] }) {
    if (reasons.length === 1) return <p>{reasons[0]}</p>;
    return (
        <ul className="list-disc space-y-1 pl-4">
            {reasons.map((reason) => (
                <li key={reason}>{reason}</li>
            ))}
        </ul>
    );
}

/** Stays mounted in the page; `action` (the page's one owner) decides when it
 * is open. It cannot be closed while a request is in flight. */
export function BoardActionDialog({
    action,
}: {
    action: BoardActionController;
}) {
    const { phase, target } = action;
    const approve = target?.action === 'approve';
    const title = approve ? 'Approve claim' : 'Claim shift';
    const description = approve
        ? 'Approving assigns this shift to the person who claimed it. The usual staffing and eligibility rules still apply.'
        : 'Claiming asks for this shift. A coordinator approves it before the shift is yours.';
    const ready = phase.kind === 'ready' ? phase : null;
    const canConfirm =
        !!ready && !!target && mayConfirmAction(ready.source, target);
    const sending = phase.kind === 'sending';
    const source =
        phase.kind === 'ready'
            ? phase.source
            : phase.kind === 'sending' ||
                phase.kind === 'confirmed' ||
                phase.kind === 'unknown'
              ? (phase.intent.source as PositionSource)
              : null;

    return (
        <Dialog
            open={action.open}
            onOpenChange={(next) => {
                if (!next) action.close();
            }}
        >
            <DialogContent
                className="frontline-dialog max-h-[90vh] overflow-x-hidden overflow-y-auto"
                style={{
                    width: 'min(92vw, 480px)',
                    maxWidth: 'min(92vw, 480px)',
                }}
                onEscapeKeyDown={(event) => {
                    if (sending) event.preventDefault();
                }}
                onPointerDownOutside={(event) => {
                    if (sending) event.preventDefault();
                }}
                onInteractOutside={(event) => {
                    if (sending) event.preventDefault();
                }}
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        {approve ? (
                            <CheckCircle2 className="size-4 text-primary" />
                        ) : (
                            <Hand className="size-4 text-primary" />
                        )}
                        {title}
                    </DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div
                    className="mt-1 flex min-w-0 flex-col gap-4 break-words"
                    aria-busy={phase.kind === 'loading' || sending}
                >
                    {phase.kind === 'loading' && (
                        <div role="status">
                            <LoadingState
                                message="Loading the shift's details…"
                                className="py-6"
                            />
                        </div>
                    )}

                    {phase.kind === 'load-error' && (
                        <div role="alert">
                            <InfoCard icon={AlertTriangle} tone="crit">
                                {phase.message}
                            </InfoCard>
                        </div>
                    )}

                    {ready?.notice && (
                        <div role="alert">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                {ready.notice}
                            </InfoCard>
                        </div>
                    )}

                    {phase.kind === 'confirmed' && (
                        <div role="status" className="flex flex-col gap-3">
                            <StatusBadge
                                variant="success"
                                className="self-start"
                            >
                                <CheckCircle2 className="size-3" />
                                {phase.confirmation.outcome === 'approved'
                                    ? 'Claim approved'
                                    : 'Claim sent'}
                            </StatusBadge>
                            <p className="text-subtle">
                                {phase.confirmation.outcome === 'approved'
                                    ? 'The shift is now assigned. The list has been refreshed.'
                                    : "A coordinator will review your claim. You're not assigned until they approve it."}
                            </p>
                            {phase.confirmation.warnings.length > 0 && (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    <p className="font-medium">
                                        Saved with things to check
                                    </p>
                                    <ReasonList
                                        reasons={phase.confirmation.warnings}
                                    />
                                </InfoCard>
                            )}
                        </div>
                    )}

                    {phase.kind === 'unknown' && (
                        <div
                            role="status"
                            aria-live="polite"
                            className="flex flex-col gap-3"
                        >
                            <StatusBadge
                                variant="warning"
                                className="self-start"
                            >
                                Not confirmed
                            </StatusBadge>
                            <InfoCard icon={AlertTriangle} tone="warn">
                                {approve
                                    ? "We couldn't confirm the approval. It may still have gone through."
                                    : "We couldn't confirm your claim. It may still have gone through."}{' '}
                                Check the shift before you try again.
                            </InfoCard>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={phase.checking}
                                    onClick={() => void action.checkCurrent()}
                                >
                                    {phase.checking && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}
                                    Check the shift
                                </Button>
                                {phase.current &&
                                    target &&
                                    mayConfirmAction(
                                        phase.current.source,
                                        target,
                                    ) && (
                                        <Button
                                            variant="outline"
                                            onClick={action.startAgain}
                                        >
                                            Try again
                                        </Button>
                                    )}
                            </div>
                            {phase.checkError && (
                                <p role="alert" className="text-subtle">
                                    {phase.checkError}
                                </p>
                            )}
                            {phase.current && (
                                <p className="text-subtle">
                                    {phase.current.summary}
                                </p>
                            )}
                            <p className="text-caption">
                                Closing doesn't undo anything. The list
                                refreshes so you can see the latest state.
                            </p>
                        </div>
                    )}

                    {source && phase.kind !== 'confirmed' && (
                        <ShiftSummary
                            source={
                                phase.kind === 'unknown' && phase.current
                                    ? phase.current.source
                                    : source
                            }
                        />
                    )}

                    {ready &&
                    ready.source.eligibility?.warning_reasons.length ? (
                        <InfoCard icon={AlertTriangle} tone="warn">
                            <p className="font-medium">
                                Check before you continue
                            </p>
                            <ReasonList
                                reasons={
                                    ready.source.eligibility.warning_reasons
                                }
                            />
                            <p className="text-caption mt-1">
                                These don't stop you. The usual eligibility
                                rules still decide whether it can go ahead.
                            </p>
                        </InfoCard>
                    ) : null}

                    {ready && !canConfirm && (
                        <InfoCard icon={AlertTriangle} tone="crit">
                            <p className="font-medium">
                                {approve
                                    ? "This claim can't be approved right now."
                                    : "You can't claim this shift right now."}
                            </p>
                            {ready.source.eligibility?.blocked_reasons
                                .length ? (
                                <ReasonList
                                    reasons={
                                        ready.source.eligibility.blocked_reasons
                                    }
                                />
                            ) : ready.source.eligibility?.assessment_status ===
                              'unknown' ? (
                                <p>
                                    Eligibility checks aren't available right
                                    now, so this can't go ahead.
                                </p>
                            ) : (
                                <p>
                                    It is{' '}
                                    {positionStateLabel(
                                        ready.source.display.status,
                                        ready.source.display.claimant,
                                    )}
                                    .
                                </p>
                            )}
                        </InfoCard>
                    )}
                </div>

                <DialogFooter className="mt-4 flex-wrap">
                    <Button
                        variant="outline"
                        disabled={sending}
                        onClick={() => action.close()}
                    >
                        {phase.kind === 'confirmed'
                            ? 'Done'
                            : phase.kind === 'unknown'
                              ? 'Close and refresh'
                              : 'Cancel'}
                    </Button>
                    {phase.kind === 'load-error' && (
                        <Button onClick={action.reload}>Load again</Button>
                    )}
                    {(canConfirm || sending) && (
                        <Button
                            disabled={sending}
                            onClick={() => void action.confirm()}
                        >
                            {sending && (
                                <Loader2 className="mr-2 size-4 animate-spin" />
                            )}
                            {sending
                                ? approve
                                    ? 'Approving…'
                                    : 'Claiming…'
                                : title}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
