import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    formatDateOnly,
    formatDateTimeLong,
    toDatetimeLocal,
} from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    ClipboardCheck,
    FileSignature,
    FileText,
    GitCompare,
    HelpCircle,
    Mail,
    Phone,
    Pill,
    Stethoscope,
    UserRound,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ReviewFormShell } from './_form-shell';
import {
    ChoiceTiles,
    ConcealedMedicine,
    Facts,
    Field,
    Notice,
    OutcomeFields,
    ReviewPicker,
    SourceUpload,
    StoredSource,
    useReviewCommand,
    validateOutcome,
} from './_ui';
import {
    changeStatus,
    DECISION_LABELS,
    itemOutcomeLabel,
    OUTCOME_LABELS,
} from './model';
import type {
    DecisionMethod,
    DecisionState,
    OutcomeDraft,
    PickerOption,
    Review,
    ReviewAction,
    ReviewItem,
    ReviewPermissions,
} from './types';

export function ChangeDialog({
    review,
    item,
    can,
    onClose,
    onAction,
}: {
    review: Review;
    item: ReviewItem;
    can: ReviewPermissions;
    onClose: () => void;
    onAction: (action: ReviewAction) => void;
}) {
    const [step, setStep] = useState(0);
    const status = changeStatus(item);
    const hidden = item.controlled_hidden;
    const pending =
        item.outcome === 'pending_controlled' && !item.classification_pending;
    const watch = item.outcome === 'watch';
    const steps = [
        {
            key: 'recommendation',
            label: pending ? 'Outcome to add' : 'The recommendation',
            blurb: `Review ${review.id}`,
            icon: Pill,
        },
        {
            key: 'decision',
            label: 'The prescriber',
            blurb: 'Their decision and source',
            icon: FileSignature,
        },
        {
            key: 'next',
            label: watch ? 'The follow-up' : 'Orders & check',
            blurb: watch
                ? 'Watch for what was agreed'
                : 'Enter, confirm and check',
            icon: ClipboardCheck,
        },
    ];
    let next: ReactNode;
    if (pending && can.manage && can.controlled && !hidden)
        next = (
            <Button
                type="button"
                onClick={() => onAction({ type: 'outcome', review, item })}
            >
                Add the outcome
            </Button>
        );
    else if (!hidden && can.manage && item.decision === 'waiting')
        next = (
            <Button
                type="button"
                onClick={() => onAction({ type: 'decision', review, item })}
            >
                Record the decision
            </Button>
        );
    else if (!hidden && item.order_url)
        next = (
            <Button type="button" onClick={() => router.visit(item.order_url!)}>
                <ArrowUpRight className="size-4" />
                {item.linked_order_version_id
                    ? 'Open the order'
                    : 'Enter the change in Orders'}
            </Button>
        );
    else if (!hidden && item.followup_url)
        next = (
            <Button
                type="button"
                onClick={() => router.visit(item.followup_url!)}
            >
                <ArrowUpRight className="size-4" /> Open the follow-up
            </Button>
        );
    const body: Record<string, ReactNode> = {
        recommendation: (
            <div className="space-y-4">
                {item.classification_pending && (
                    <Notice warning title="Medicine classification to check">
                        Orders establishes the medicine’s canonical
                        classification. Until then, restricted readers see no
                        medicine name, recommendation or source.
                    </Notice>
                )}
                <StatusBadge
                    variant={status.variant}
                    className="whitespace-normal"
                >
                    {status.label}
                </StatusBadge>
                {hidden ? (
                    <ConcealedMedicine name={item.name} />
                ) : (
                    <Facts
                        rows={[
                            ['Medicine', item.name],
                            ['Outcome', itemOutcomeLabel(item)],
                            [
                                'Recommended by',
                                [review.reviewer_name, review.reviewer_role]
                                    .filter(Boolean)
                                    .join(' · '),
                            ],
                            [
                                'At the review',
                                formatDateOnly(review.completed_date),
                            ],
                            ...(item.recommendation
                                ? [
                                      [
                                          'Recommendation',
                                          item.recommendation,
                                      ] as [string, ReactNode],
                                  ]
                                : []),
                            ...(item.watch_text
                                ? [
                                      ['Watch for', item.watch_text] as [
                                          string,
                                          ReactNode,
                                      ],
                                      [
                                          'Until',
                                          formatDateOnly(item.watch_until),
                                      ] as [string, ReactNode],
                                  ]
                                : []),
                        ]}
                    />
                )}
                {pending ? (
                    <Notice warning title="The outcome is still to add">
                        The review was recorded without this medicine’s outcome.
                        A lead with controlled-medicine access adds it. It is
                        not marked Continue.
                    </Notice>
                ) : (
                    <Notice title="This does not change the prescription">
                        A recommendation is entered in Orders after the
                        prescriber agrees. The chart changes only through the
                        checked order process.
                    </Notice>
                )}
            </div>
        ),
        decision: pending ? (
            <Notice title="Add the outcome first">
                The prescriber step follows a recorded recommendation.
            </Notice>
        ) : watch || item.outcome === 'continue' ? (
            <Notice title="No prescription change is recommended">
                {watch
                    ? 'The review created a watch item. Its follow-up is recorded in Tasks.'
                    : 'The current order continues.'}
            </Notice>
        ) : hidden ? (
            <ConcealedMedicine name={item.name} />
        ) : (
            <div className="space-y-4">
                <Facts
                    rows={[
                        [
                            'Decision',
                            item.decision === 'agreed'
                                ? 'Agreed'
                                : item.decision === 'not_agreed'
                                  ? 'Not agreed'
                                  : 'Waiting for the prescriber',
                        ],
                        [
                            'Prescriber',
                            item.prescriber_name ?? 'Not recorded yet',
                        ],
                        [
                            'When',
                            item.decision_date
                                ? item.decision_at
                                    ? formatDateTimeLong(item.decision_at)
                                    : formatDateOnly(item.decision_date)
                                : 'Not recorded yet',
                        ],
                        [
                            'How they told you',
                            item.decision_method
                                ? DECISION_LABELS[item.decision_method]
                                : 'Not recorded yet',
                        ],
                        ...(item.decision_note
                            ? [
                                  ['What they said', item.decision_note] as [
                                      string,
                                      ReactNode,
                                  ],
                              ]
                            : []),
                    ]}
                />
                {item.decision_source && (
                    <StoredSource
                        source={item.decision_source}
                        id={`review:${review.id}:decision:${item.id}`}
                    />
                )}
                {item.decision === 'waiting' && (
                    <Notice title="Waiting for the decision">
                        Nothing changes on the chart while the prescriber’s
                        decision is pending.
                    </Notice>
                )}
            </div>
        ),
        next: watch ? (
            <div className="space-y-4">
                <Facts
                    rows={[
                        [
                            'Watch for',
                            hidden
                                ? 'Details need controlled-medicine access'
                                : (item.watch_text ?? 'Not recorded'),
                        ],
                        ['Until', formatDateOnly(item.watch_until)],
                        ['Owner', review.owner_name ?? 'Unassigned'],
                        [
                            'Follow-up',
                            item.followup_url
                                ? 'Open in Tasks'
                                : 'Not linked yet',
                        ],
                    ]}
                />
                <Notice title="The follow-up owns completion">
                    Open the follow-up to record what was checked and any next
                    action. The review keeps the recommendation and source.
                </Notice>
            </div>
        ) : pending ? (
            <Notice title="The outcome is still to add">
                A pending controlled outcome does not certify that the current
                prescription should continue.
            </Notice>
        ) : item.decision === 'not_agreed' ? (
            <Notice title="Not agreed — kept on the review">
                The recommendation and prescriber’s decision are retained.
                Nothing changes on the chart.
            </Notice>
        ) : (
            <div className="space-y-4">
                <Card className="gap-2 p-4">
                    <h3 className="text-section-title">
                        {item.linked_order_version_id
                            ? 'Entered in Orders — check status there'
                            : 'Enter the agreed change in Orders'}
                    </h3>
                    <p className="text-muted-foreground text-sm">
                        {item.linked_order_version_id
                            ? `Linked order version ${item.linked_order_version_id}. This link alone does not confirm it is checked or effective.`
                            : item.decision === 'agreed'
                              ? 'The prescriber agreed. A permitted lead enters the change in Orders.'
                              : 'The prescriber must decide before an order change is entered.'}
                    </p>
                </Card>
                {item.decision === 'agreed' &&
                    item.decision_method !== 'writing' && (
                        <Notice warning title="Written confirmation is needed">
                            A decision by phone, in person or at the review
                            follows Orders’ read-back and written-confirmation
                            checks.
                        </Notice>
                    )}
                <Notice title="An independent check follows">
                    Orders retains the effective prescription while a changed
                    version waits for its required check.
                </Notice>
                {item.decision === 'agreed' && !item.order_url && (
                    <p className="text-caption">
                        The Orders handoff is not available for this record yet.
                    </p>
                )}
            </div>
        ),
    };
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${item.name} — ${review.client_name}`}
            description={`${itemOutcomeLabel(item)} · Review ${review.id}.`}
            railIcon={GitCompare}
            railTitle="Review outcome"
            railSub={review.client_name}
            steps={steps}
            stepIndex={step}
            onStepClick={setStep}
            sequential={false}
            headerLabel={steps[step].label}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={next}
        >
            <WizardStepPane>{body[steps[step].key]}</WizardStepPane>
        </WizardShell>
    );
}

export function DecisionDialog({
    review,
    item,
    asAt,
    onClose,
}: {
    review: Review;
    item: ReviewItem;
    asAt: string;
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [state, setState] = useState<DecisionState | ''>('');
    const [prescriber, setPrescriber] = useState<PickerOption | null>(null);
    const [when, setWhen] = useState(toDatetimeLocal(asAt));
    const [method, setMethod] = useState<DecisionMethod | ''>('');
    const [note, setNote] = useState('');
    const [source, setSource] = useState<File | null>(null);
    const steps = [
        {
            key: 'what',
            label: 'What happened',
            blurb: 'Agreed, not agreed or asked',
            icon: FileSignature,
        },
        {
            key: 'who',
            label: 'Who and when',
            blurb: 'Prescriber, exact NZ time',
            icon: UserRound,
        },
        {
            key: 'source',
            label: 'Source & note',
            blurb: 'How they told you',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check, then record',
            icon: Check,
        },
    ];
    const validate = (step: number) => {
        const errors: Record<string, string> = {};
        if (step === 0 && !state)
            errors['decision-state'] = 'Choose what happened.';
        if (step === 1) {
            if (!prescriber)
                errors['decision-prescriber'] =
                    'Choose or enter the prescriber.';
            if (!validLocalDateTime(when))
                errors['decision-when'] = 'Choose when it happened.';
            else if (when > toDatetimeLocal(asAt))
                errors['decision-when'] = 'It cannot be in the future.';
        }
        if (step === 2) {
            if (state !== 'waiting' && !method)
                errors['decision-method'] = 'Choose how they told you.';
            if (state !== 'waiting' && method === 'writing' && !source)
                errors['decision-source'] = 'Attach what the prescriber wrote.';
            if ((state === 'not_agreed' || state === 'waiting') && !note.trim())
                errors['decision-note'] =
                    state === 'waiting'
                        ? 'Say how you asked and what you are waiting for.'
                        : 'Say what the prescriber said.';
        }
        return errors;
    };
    return (
        <ReviewFormShell
            title={`The prescriber’s decision — ${item.name}`}
            description={`${review.client_name} · ${itemOutcomeLabel(item)} · Review ${review.id}.`}
            steps={steps}
            command={command}
            dirty={!!(state || prescriber || method || note || source)}
            validate={validate}
            onSave={() =>
                command.submit(
                    `/emar/reviews/${review.id}/items/${item.id}/decision`,
                    {
                        revision: review.revision,
                        state,
                        prescriber_name: prescriber!.label,
                        decision_date: when,
                        method: state === 'waiting' ? null : method,
                        note: note.trim(),
                        source:
                            state !== 'waiting' && method === 'writing'
                                ? source
                                : null,
                    },
                )
            }
            onClose={onClose}
            saveLabel="Record the decision"
            successTitle={
                state === 'waiting'
                    ? 'Request recorded'
                    : 'Prescriber’s decision recorded'
            }
            successBlurb={
                state === 'waiting'
                    ? 'The recommendation still waits for a decision. Nothing changes on the chart.'
                    : state === 'not_agreed'
                      ? 'The recommendation and decision are kept. Nothing changes on the chart.'
                      : 'The agreed change goes through Orders and its required check.'
            }
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        <Card className="gap-1 p-4">
                            <p className="text-caption">The recommendation</p>
                            <p className="break-words text-sm">
                                {item.recommendation}
                            </p>
                        </Card>
                        <ChoiceTiles
                            id="decision-state"
                            label="What happened"
                            value={state}
                            onChange={setState}
                            error={command.errors['decision-state']}
                            choices={[
                                {
                                    value: 'agreed',
                                    label: 'Agreed',
                                    description:
                                        'Enter the change through Orders',
                                    icon: Check,
                                },
                                {
                                    value: 'not_agreed',
                                    label: 'Not agreed',
                                    description:
                                        'Kept on the review; nothing changes',
                                    icon: XCircle,
                                },
                                {
                                    value: 'waiting',
                                    label: 'Asked — no answer yet',
                                    description:
                                        'Record how and when you asked',
                                    icon: HelpCircle,
                                },
                            ]}
                        />
                    </>
                ) : step === 1 ? (
                    <>
                        <Field
                            id="decision-prescriber"
                            label="Prescriber"
                            required
                            error={command.errors['decision-prescriber']}
                        >
                            <ReviewPicker
                                id="decision-prescriber"
                                kind="prescriber"
                                value={prescriber}
                                onChange={setPrescriber}
                                clientId={review.client_id}
                                placeholder="Choose the prescriber"
                                error={command.errors['decision-prescriber']}
                                allowCustom
                            />
                        </Field>
                        <DateTimeField
                            id="decision-when"
                            label={
                                state === 'waiting'
                                    ? 'When you asked'
                                    : 'When they decided'
                            }
                            value={when}
                            onChange={setWhen}
                            error={command.errors['decision-when']}
                            clearable={false}
                        />
                    </>
                ) : step === 2 ? (
                    <>
                        {state !== 'waiting' && (
                            <>
                                <ChoiceTiles
                                    id="decision-method"
                                    label="How they told you"
                                    value={method}
                                    onChange={setMethod}
                                    error={command.errors['decision-method']}
                                    choices={[
                                        {
                                            value: 'writing',
                                            label: 'In writing',
                                            description:
                                                'Attach their letter, email or prescription',
                                            icon: Mail,
                                        },
                                        {
                                            value: 'phone',
                                            label: 'By phone',
                                            description:
                                                'Written confirmation before the check',
                                            icon: Phone,
                                        },
                                        {
                                            value: 'person',
                                            label: 'In person',
                                            description:
                                                'Written confirmation before the check',
                                            icon: UserRound,
                                        },
                                        {
                                            value: 'review',
                                            label: 'At the review',
                                            description:
                                                'The prescriber made this decision at the review',
                                            icon: Stethoscope,
                                        },
                                    ]}
                                />
                                {method === 'writing' && (
                                    <Field
                                        id="decision-source"
                                        label={
                                            <span id="decision-source-label">
                                                What they wrote
                                            </span>
                                        }
                                        required
                                        error={
                                            command.errors['decision-source'] ??
                                            command.errors.source
                                        }
                                    >
                                        <SourceUpload
                                            id="decision-source"
                                            file={source}
                                            onChange={setSource}
                                            error={
                                                command.errors[
                                                    'decision-source'
                                                ] ?? command.errors.source
                                            }
                                            processing={command.processing}
                                        />
                                    </Field>
                                )}
                                {state === 'agreed' &&
                                    method !== 'writing' &&
                                    method && (
                                        <Notice
                                            warning
                                            title="The Orders checks still apply"
                                        >
                                            Read-back and written confirmation
                                            are required when the change is
                                            entered. Recording this decision
                                            does not replace those checks.
                                        </Notice>
                                    )}
                            </>
                        )}
                        <Field
                            id="decision-note"
                            label={
                                state === 'waiting'
                                    ? 'How you asked, and what you are waiting for'
                                    : 'What the prescriber said'
                            }
                            required={
                                state === 'waiting' || state === 'not_agreed'
                            }
                            error={command.errors['decision-note']}
                        >
                            <Textarea
                                id="decision-note"
                                value={note}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                                rows={4}
                            />
                        </Field>
                    </>
                ) : (
                    <ReviewCard
                        icon={FileSignature}
                        title="Prescriber’s decision"
                        onEdit={() => edit(0)}
                    >
                        <ReviewRow
                            label="Decision"
                            value={
                                state === 'agreed'
                                    ? 'Agreed'
                                    : state === 'not_agreed'
                                      ? 'Not agreed'
                                      : 'Asked — no answer yet'
                            }
                        />
                        <ReviewRow
                            label="Prescriber"
                            value={prescriber?.label}
                        />
                        <ReviewRow
                            label="When"
                            value={localDateTimeLabel(when)}
                        />
                        {state !== 'waiting' && (
                            <ReviewRow
                                label="How"
                                value={method ? DECISION_LABELS[method] : '—'}
                            />
                        )}
                        <ReviewRow
                            label="Source"
                            value={source?.name ?? 'No source attached'}
                        />
                        <ReviewRow label="Note" value={note} />
                    </ReviewCard>
                )
            }
        />
    );
}
export function ControlledOutcomeDialog({
    review,
    item,
    onClose,
}: {
    review: Review;
    item: ReviewItem;
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [outcome, setOutcome] = useState<
        Omit<OutcomeDraft, 'client_medication_id'>
    >({ outcome: '', recommendation: '', watch_text: '', watch_until: '' });
    const steps = [
        {
            key: 'outcome',
            label: 'Add the outcome',
            blurb: 'From the clinician’s review',
            icon: Pill,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Append to the recorded review',
            icon: Check,
        },
    ];
    return (
        <ReviewFormShell
            title={`Add the outcome — ${item.name}`}
            description={`${review.client_name} · Review ${review.id} · ${review.reviewer_name ?? 'Clinician’s review'}.`}
            steps={steps}
            command={command}
            dirty={
                !!(
                    outcome.outcome ||
                    outcome.recommendation ||
                    outcome.watch_text ||
                    outcome.watch_until
                )
            }
            validate={(step) =>
                step === 0 ? validateOutcome('controlled', outcome) : {}
            }
            onSave={() =>
                command.submit(
                    `/emar/reviews/${review.id}/items/${item.id}/outcome`,
                    {
                        revision: review.revision,
                        outcome: outcome.outcome,
                        recommendation: outcome.recommendation.trim(),
                        watch_text: outcome.watch_text.trim(),
                        watch_until: outcome.watch_until || null,
                    },
                )
            }
            onClose={onClose}
            saveLabel="Add the outcome"
            successTitle="Outcome added"
            successBlurb="The original review stays unchanged in history. A recommended change goes through the prescriber and Orders."
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        <Notice title="Record the clinician’s outcome">
                            This appends the missing controlled-medicine
                            outcome. It does not replace the recorded review or
                            change the prescription.
                        </Notice>
                        <OutcomeFields
                            prefix="controlled"
                            value={outcome}
                            onChange={(patch) =>
                                setOutcome((current) => ({
                                    ...current,
                                    ...patch,
                                }))
                            }
                            errors={command.errors}
                        />
                    </>
                ) : (
                    <ReviewCard
                        icon={Pill}
                        title="Controlled-medicine outcome"
                        onEdit={() => edit(0)}
                    >
                        <ReviewRow label="Medicine" value={item.name} />
                        <ReviewRow
                            label="Outcome"
                            value={
                                outcome.outcome
                                    ? OUTCOME_LABELS[outcome.outcome]
                                    : 'Not chosen'
                            }
                        />
                        <ReviewRow
                            label="Recommendation"
                            value={outcome.recommendation}
                        />
                        <ReviewRow
                            label="Watch for"
                            value={outcome.watch_text}
                        />
                        <ReviewRow
                            label="Until"
                            value={formatDateOnly(outcome.watch_until)}
                        />
                    </ReviewCard>
                )
            }
        />
    );
}
