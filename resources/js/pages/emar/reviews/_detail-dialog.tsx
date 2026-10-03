import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarPlus,
    FileSignature,
    FileText,
    History,
    Pill,
    Stethoscope,
    UserRound,
    Users,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ConcealedMedicine, Facts, Notice, StoredSource } from './_ui';
import {
    cadenceLabel,
    changeStatus,
    itemOutcomeLabel,
    kindLabel,
    LOCATION_LABELS,
    medicationRecordUrl,
    MOVE_LABELS,
    reviewStatus,
    TRIGGER_LABELS,
} from './model';
import type {
    Review,
    ReviewAction,
    ReviewEvent,
    ReviewPermissions,
} from './types';

export function ReviewDetailDialog({
    review,
    can,
    today,
    defaultInterval,
    onClose,
    onAction,
}: {
    review: Review;
    can: ReviewPermissions;
    today: string;
    defaultInterval: { months: number; reviewed: boolean };
    onClose: () => void;
    onAction: (action: ReviewAction) => void;
}) {
    const [step, setStep] = useState(0);
    const recorded = review.status === 'completed';
    const cadence = review.cadence ?? { ...defaultInterval, own: false };
    const status = reviewStatus(review, today);
    const steps = [
        {
            key: 'review',
            label: 'This review',
            blurb: `${kindLabel(review)} · due ${formatDateOnly(review.scheduled_date)}`,
            icon: Stethoscope,
        },
        {
            key: 'medicines',
            label: 'Medicines',
            blurb: recorded
                ? `${review.items.length} outcomes`
                : 'After the review',
            icon: Pill,
        },
        {
            key: 'participants',
            label: 'Who took part',
            blurb: 'The person and whānau',
            icon: Users,
        },
        {
            key: 'summary',
            label: 'Summary & letter',
            blurb: 'The clinician’s own words',
            icon: FileText,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Earlier dates and actions, kept',
            icon: History,
        },
    ];
    const body: Record<string, ReactNode> = {
        review: (
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant={status.variant}>
                        {status.label}
                    </StatusBadge>
                    {review.away && (
                        <span className="text-muted-foreground text-sm">
                            Away — {review.away}
                        </span>
                    )}
                </div>
                <Facts
                    rows={[
                        [
                            'Person',
                            `${review.client_name} · ${review.site_name ?? 'No house'}`,
                        ],
                        [
                            'Kind',
                            `${kindLabel(review)}${review.trigger_code ? ` — ${TRIGGER_LABELS[review.trigger_code] ?? review.trigger_code}` : ''}`,
                        ],
                        ...(review.trigger_reason
                            ? [
                                  ['What happened', review.trigger_reason] as [
                                      string,
                                      ReactNode,
                                  ],
                              ]
                            : []),
                        ['Due', formatDateOnly(review.scheduled_date)],
                        ['Owner', review.owner_name ?? 'Unassigned'],
                        [
                            'Appointment',
                            review.appointment_date
                                ? `${formatDateOnly(review.appointment_date)}${review.appointment_time ? ` · ${review.appointment_time}` : ''} · Pacific/Auckland`
                                : 'Not booked with a clinician yet',
                        ],
                        [
                            'Clinician',
                            [
                                review.reviewer_name,
                                review.reviewer_role,
                                review.clinician_practice,
                            ]
                                .filter(Boolean)
                                .join(' · ') || 'Not recorded yet',
                        ],
                        [
                            'Where',
                            review.appointment_location
                                ? LOCATION_LABELS[review.appointment_location]
                                : 'Not recorded yet',
                        ],
                        [
                            'How often',
                            `${cadenceLabel(cadence.months)} · ${cadence.own ? 'Set for this person' : 'Organisation default'}`,
                        ],
                        [
                            'Next review',
                            formatDateOnly(
                                recorded
                                    ? review.next_review_date
                                    : review.next_regular_review_date,
                                'Not booked yet',
                            ),
                        ],
                    ]}
                />
                {!cadence.reviewed && (
                    <Notice title="Interval not reviewed">
                        The existing interval is awaiting clinical-lead review.
                        It does not certify a clinical policy.
                    </Notice>
                )}
                {review.review_type === 'regular' &&
                    review.status === 'scheduled' && (
                        <p className="text-caption">
                            Regular reviews can be moved with a reason. They are
                            not cancelled.
                        </p>
                    )}
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            router.visit(medicationRecordUrl(review.client_id))
                        }
                    >
                        <UserRound className="size-4" /> Medication record
                    </Button>
                    {can.manage && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                onAction({
                                    type: 'interval',
                                    clientId: review.client_id,
                                    clientName: review.client_name,
                                    cadence,
                                })
                            }
                        >
                            <CalendarClock className="size-4" /> Change how
                            often
                        </Button>
                    )}
                </div>
            </div>
        ),
        medicines:
            recorded &&
            (review.legacy_outcomes?.length ||
                review.legacy_recommendations ||
                review.legacy_medications_reviewed?.length) ? (
                <div className="space-y-4">
                    <Notice title="Historical review recommendations">
                        These are the original recorded recommendations. They do
                        not prove that a prescriber agreed or that an order was
                        entered and checked.
                    </Notice>
                    {review.legacy_medications_reviewed?.length ? (
                        <div>
                            <h3 className="text-section-title">
                                Medicines reviewed
                            </h3>
                            <ul className="mt-2 list-disc space-y-1 break-words pl-5 text-sm">
                                {review.legacy_medications_reviewed.map(
                                    (medicine, index) => (
                                        <li key={index}>{medicine}</li>
                                    ),
                                )}
                            </ul>
                        </div>
                    ) : null}
                    {review.legacy_recommendations && (
                        <p className="whitespace-pre-wrap break-words text-sm">
                            {review.legacy_recommendations}
                        </p>
                    )}
                    {review.legacy_outcomes?.length ? (
                        <ul className="space-y-3">
                            {review.legacy_outcomes.map((outcome, index) => (
                                <li key={index}>
                                    <Card className="gap-2 p-4">
                                        <p className="text-sm font-semibold">
                                            {outcome.drug}
                                        </p>
                                        <Facts
                                            rows={[
                                                [
                                                    'Recorded recommendation',
                                                    outcome.action,
                                                ],
                                                [
                                                    'Rationale',
                                                    outcome.rationale ??
                                                        'Not recorded',
                                                ],
                                                [
                                                    'Historical prescriber status',
                                                    outcome.gp_status ??
                                                        'Not recorded',
                                                ],
                                                [
                                                    'Historical stage',
                                                    outcome.stage ??
                                                        'Not recorded',
                                                ],
                                            ]}
                                        />
                                    </Card>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : recorded ? (
                <ul className="space-y-3">
                    {review.items.map((item) => {
                        const itemStatus = changeStatus(item);
                        return (
                            <li key={item.id}>
                                <Card className="gap-3 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        {item.controlled_hidden ? (
                                            <ConcealedMedicine
                                                name={item.name}
                                            />
                                        ) : (
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold">
                                                    {item.name}
                                                </p>
                                                <p className="text-caption mt-1">
                                                    {itemOutcomeLabel(item)}
                                                </p>
                                                {item.recommendation && (
                                                    <p className="mt-1 break-words text-sm">
                                                        {item.recommendation}
                                                    </p>
                                                )}
                                                {item.watch_text && (
                                                    <p className="mt-1 break-words text-sm">
                                                        Watch for{' '}
                                                        {item.watch_text} ·
                                                        until{' '}
                                                        {formatDateOnly(
                                                            item.watch_until,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                        <StatusBadge
                                            variant={itemStatus.variant}
                                            className="whitespace-normal"
                                        >
                                            {itemStatus.label}
                                        </StatusBadge>
                                    </div>
                                    {item.outcome !== 'continue' && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="w-fit"
                                            onClick={() =>
                                                onAction({
                                                    type: 'change',
                                                    review,
                                                    item,
                                                })
                                            }
                                        >
                                            Open the{' '}
                                            {item.outcome ===
                                            'pending_controlled'
                                                ? 'pending outcome'
                                                : 'change'}
                                        </Button>
                                    )}
                                </Card>
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <Card className="p-2">
                    <EmptyState
                        icon={Pill}
                        title="Outcomes are recorded after the review"
                        description="Each current medicine gets its own outcome. A recommendation then goes to the prescriber and Orders."
                    />
                </Card>
            ),
        participants: recorded ? (
            <div className="space-y-4">
                <Facts
                    rows={[
                        [
                            'Done by',
                            [
                                review.reviewer_name,
                                review.reviewer_role,
                                review.clinician_practice,
                            ]
                                .filter(Boolean)
                                .join(' · '),
                        ],
                        [
                            'When',
                            review.happened_at
                                ? formatDateTimeLong(review.happened_at)
                                : formatDateOnly(review.completed_date),
                        ],
                        [
                            'How',
                            review.review_location
                                ? LOCATION_LABELS[review.review_location]
                                : review.appointment_location
                                  ? LOCATION_LABELS[review.appointment_location]
                                  : 'Not recorded',
                        ],
                        [
                            review.client_name,
                            review.participants?.person === 'took'
                                ? 'Took part'
                                : review.participants?.person === 'not'
                                  ? `Did not take part${review.participants.person_reason ? ` — ${review.participants.person_reason}` : ''}`
                                  : review.legacy_whanau
                                    ? 'Not separately recorded in this historical review'
                                    : 'Not available to your access',
                        ],
                        [
                            'Whānau, welfare guardian or EPOA',
                            review.participants
                                ? `${review.participants.whanau === 'took' ? 'Took part' : review.participants.whanau === 'told' ? 'Told afterwards' : 'Not involved'}${review.participants.whanau_detail ? ` — ${review.participants.whanau_detail}` : ''}`
                                : review.legacy_whanau
                                  ? `Historical record: ${review.legacy_whanau.involved ? 'involved' : 'not involved'}${review.legacy_whanau.notes ? ` — ${review.legacy_whanau.notes}` : ''}`
                                  : 'Not available to your access',
                        ],
                    ]}
                />
                {review.legacy_whanau && !review.participants && (
                    <p className="text-caption">
                        The original whānau involvement and notes are kept as
                        recorded. Participation was not separately recorded.
                    </p>
                )}
                {!review.participants && !review.legacy_whanau && (
                    <Notice title="Participation details are restricted">
                        Free-text participation details may contain medicine
                        information. They are shown with the review’s clinical
                        access rules.
                    </Notice>
                )}
            </div>
        ) : (
            <p className="text-subtle">Recorded with the outcome.</p>
        ),
        summary: !recorded ? (
            <p className="text-subtle">Recorded with the outcome.</p>
        ) : review.clinical_summary === null ? (
            <Notice title="Clinical summary and source are restricted">
                The clinician’s free text and letter are shown only with the
                required review and medicine access. Permitted structured
                outcomes remain in Medicines.
            </Notice>
        ) : (
            <div className="space-y-4">
                <div>
                    <h3 className="text-section-title">
                        In the clinician’s words
                    </h3>
                    <p className="mt-2 whitespace-pre-wrap break-words text-sm">
                        {review.clinical_summary}
                    </p>
                </div>
                {review.source ? (
                    <StoredSource
                        source={review.source}
                        id={`review:${review.id}:source`}
                    />
                ) : (
                    <p className="text-caption">No written review attached.</p>
                )}
                <Facts
                    rows={[
                        ...(review.drug_burden_index !== null
                            ? [
                                  [
                                      'Drug burden index — the clinician’s',
                                      String(review.drug_burden_index),
                                  ] as [string, ReactNode],
                              ]
                            : []),
                        ...(review.falls_last_quarter !== null
                            ? [
                                  [
                                      'Falls in the last quarter — the clinician’s note',
                                      String(review.falls_last_quarter),
                                  ] as [string, ReactNode],
                              ]
                            : []),
                    ]}
                />
            </div>
        ),
        history: (
            <ol className="space-y-4" aria-label="Review history">
                {(review.history ?? []).map((event) => (
                    <li key={event.id} className="flex items-start gap-3">
                        <History
                            className="text-primary mt-1 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <div>
                            <p className="break-words text-sm">
                                {historyLabel(event)}
                            </p>
                            <p className="text-caption mt-1">
                                {event.actor_name ?? 'System'} ·{' '}
                                {formatDateTimeLong(event.created_at)}
                            </p>
                        </div>
                    </li>
                ))}
                {!review.history?.length && (
                    <li className="text-subtle">
                        No history is available for this review.
                    </li>
                )}
            </ol>
        ),
    };
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`Review ${review.id} — ${review.client_name}`}
            description={`${kindLabel(review)} medication review, due ${formatDateOnly(review.scheduled_date)}.`}
            railIcon={Stethoscope}
            railTitle={`Medication review ${review.id}`}
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
            footerEnd={
                review.status === 'scheduled' && can.manage ? (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onAction({ type: 'move', review })}
                        >
                            Move the review
                        </Button>
                        <Button
                            type="button"
                            onClick={() =>
                                onAction({
                                    type: review.appointment_date
                                        ? 'record'
                                        : 'appointment',
                                    review,
                                })
                            }
                        >
                            {review.appointment_date ? (
                                <FileSignature className="size-4" />
                            ) : (
                                <CalendarPlus className="size-4" />
                            )}
                            {review.appointment_date
                                ? 'Record the outcome'
                                : 'Book the appointment'}
                        </Button>
                    </>
                ) : undefined
            }
        >
            <WizardStepPane>
                <div className="space-y-4">{body[steps[step].key]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}
function historyLabel(event: ReviewEvent): string {
    const titles: Record<string, string> = {
        booked: 'Review booked',
        moved: 'Review moved',
        completed: 'Outcome recorded',
        outcome_added: 'Pending outcome added',
        prescriber_decision: 'Prescriber’s decision recorded',
        appointment: 'Appointment updated',
        cancelled: 'Triggered review cancelled',
        closed: 'Review closed',
        interval_changed: 'Review interval changed',
    };
    const details = event.details;
    if (event.event === 'moved')
        return `Moved from ${formatDateOnly(details.from_date)} to ${formatDateOnly(details.to_date)}${details.reason_code ? ` — ${MOVE_LABELS[details.reason_code] ?? 'Reason recorded'}` : ''}${details.reason ? `: ${details.reason}` : ''}`;
    if (event.event === 'cancelled' && details.reason)
        return `Triggered review cancelled — ${details.reason}`;
    return titles[event.event] ?? 'Review updated';
}
