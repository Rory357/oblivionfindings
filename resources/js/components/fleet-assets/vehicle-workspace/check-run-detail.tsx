import { localDateTimeLabel } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import {
    ClipboardCheck,
    Clock,
    FileText,
    History,
    MessageSquare,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { LockedVehicle } from './checks-kit';
import {
    ASSESSED_LABEL,
    DAILY_CHECK_KIND,
    outcomeLabel,
    outcomeTone,
    vehicleShort,
    versionLabel,
} from './checks-model';
import type { CheckRun, ChecksCan } from './checks-types';
import type { VehicleProfile } from './types';
import { StudioNotice } from './wizard-kit';
import { FILE_STATE_LABELS } from './workspace-model';

const SECTIONS = [
    {
        key: 'record',
        label: 'Check record',
        blurb: 'Outcome and original answers',
        icon: ClipboardCheck,
    },
    {
        key: 'source',
        label: 'Source & timing',
        blurb: 'Version and observation',
        icon: History,
    },
    {
        key: 'followup',
        label: 'Follow-up',
        blurb: 'Evidence and related work',
        icon: Wrench,
    },
];

/** A submitted check as recorded: original answers, source, timing and follow-up. */
export function RunDetailDialog({
    vehicle,
    run,
    can,
    returnLabel = 'Back to vehicle',
    onClose,
    onReport,
    onOpenWork,
    onAssess,
}: {
    vehicle: VehicleProfile;
    run: CheckRun;
    can: ChecksCan;
    returnLabel?: string;
    onClose: () => void;
    /** Create or link maintenance from this check. */
    onReport: (run: CheckRun) => void;
    /** Open the linked work order. */
    onOpenWork: (workOrderId: number) => void;
    /** Record "No issue found — release for use" for this check. */
    onAssess?: (run: CheckRun) => void;
}) {
    const [section, setSection] = useState(0);
    const tone = outcomeTone(run.outcome);
    const assessment = run.assessment;
    const canAssess = !!onAssess && !!run.assess?.available;
    const observed = run.observed_at
        ? localDateTimeLabel(toDatetimeLocal(run.observed_at))
        : 'Not recorded';
    const answerFiles = run.answers.filter((answer) => answer.evidence);
    const fileNames = [
        ...answerFiles.map((answer) => answer.evidence?.name ?? ''),
        ...run.files.map((file) => file.name),
    ].filter(Boolean);

    return (
        <WizardShell
            open
            title={`Check ${run.reference}`}
            description={`${run.template} · Original submitted record`}
            railIcon={ClipboardCheck}
            railTitle="Vehicle check"
            railSub={run.reference}
            steps={SECTIONS}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={SECTIONS[section].label}
            pct={null}
            maxWidth="min(92vw, 1100px)"
            onClose={onClose}
            railExtra={
                <div className="vehicle-studio">
                    <div className="detail-rail-summary">
                        <StatusBadge variant={tone}>
                            {outcomeLabel(run.outcome)}
                        </StatusBadge>
                        {assessment && (
                            <StatusBadge variant="success">
                                {ASSESSED_LABEL}
                            </StatusBadge>
                        )}
                        <p>{versionLabel(run.version)} · original version</p>
                    </div>
                </div>
            }
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    {returnLabel}
                </Button>
            }
            footerEnd={
                <>
                    {canAssess && (
                        <Button
                            variant="outline"
                            onClick={() => onAssess?.(run)}
                        >
                            <ShieldCheck className="size-4" />
                            No issue found…
                        </Button>
                    )}
                    {run.linked ? (
                        run.linked_work ? (
                            <Button
                                onClick={() =>
                                    run.linked_work &&
                                    onOpenWork(run.linked_work.id)
                                }
                            >
                                Review linked maintenance
                            </Button>
                        ) : (
                            <span className="text-subtle">
                                Linked to Maintenance work
                            </span>
                        )
                    ) : can.report ? (
                        <Button onClick={() => onReport(run)}>
                            Create or link maintenance
                        </Button>
                    ) : canAssess ? null : (
                        <span className="text-subtle">View-only access</span>
                    )}
                </>
            }
        >
            <WizardStepPane key={section}>
                <div className="vehicle-studio">
                    <div className="flow-stack">
                        <LockedVehicle vehicle={vehicle} />
                        {section === 0 && (
                            <>
                                <div className="section-intro">
                                    <div>
                                        <span className="eyebrow">
                                            SUBMITTED CHECK
                                        </span>
                                        <h3 className="text-section-title">
                                            {run.template}
                                        </h3>
                                    </div>
                                    <div className="inline-actions">
                                        <StatusBadge variant={tone}>
                                            {outcomeLabel(run.outcome)}
                                        </StatusBadge>
                                        {assessment && (
                                            <StatusBadge variant="success">
                                                {ASSESSED_LABEL}
                                            </StatusBadge>
                                        )}
                                    </div>
                                </div>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Answers as submitted"
                                >
                                    {run.answers.length ? (
                                        run.answers.map((answer) => (
                                            <ReviewRow
                                                key={answer.id}
                                                label={answer.label}
                                                value={[
                                                    answer.value ??
                                                        'Not answered',
                                                    answer.note,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            />
                                        ))
                                    ) : (
                                        <p className="body-copy">
                                            The questions shown at submission
                                            were not recorded with this earlier
                                            check.
                                        </p>
                                    )}
                                </ReviewCard>
                                <ReviewCard
                                    icon={MessageSquare}
                                    title="Observation notes"
                                >
                                    <p className="body-copy">
                                        {run.notes ||
                                            'No additional notes recorded.'}
                                    </p>
                                </ReviewCard>
                                {assessment ? (
                                    <ReviewCard
                                        icon={ShieldCheck}
                                        title="Maintenance decision"
                                    >
                                        <ReviewRow
                                            label="Decision"
                                            value={assessment.label}
                                        />
                                        <ReviewRow
                                            label="Released by"
                                            value={[
                                                assessment.assessed_by ??
                                                    'Staff member',
                                                assessment.assessed_at
                                                    ? formatDateTime(
                                                          assessment.assessed_at,
                                                      )
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        />
                                        <ReviewRow
                                            label="Reason"
                                            value={assessment.reason}
                                        />
                                        {assessment.issues.length > 0 && (
                                            <ReviewRow
                                                label="Recorded issues assessed"
                                                value={assessment.issues.join(
                                                    ', ',
                                                )}
                                            />
                                        )}
                                    </ReviewCard>
                                ) : run.blocking ? (
                                    <StudioNotice
                                        title="This check stops the vehicle being used"
                                        tone="warning"
                                    >
                                        {canAssess
                                            ? 'If Maintenance found no issue, record “No issue found — release for use”. Otherwise create or link maintenance.'
                                            : (run.assess?.reason ??
                                              'Bookings and checkout stay blocked until Maintenance assesses this check.')}
                                    </StudioNotice>
                                ) : null}
                                {run.check_kind === DAILY_CHECK_KIND ? (
                                    <StudioNotice title="Daily checks don’t stop bookings">
                                        This daily check is kept as recorded for
                                        follow-up. It doesn’t change bookings or
                                        a maintenance release. Create or link
                                        maintenance if something needs repair.
                                    </StudioNotice>
                                ) : (
                                    <StudioNotice title="The check is evidence, not a release">
                                        The original outcome stays with this
                                        submission. Maintenance assessment and
                                        any authorised release remain separate.
                                    </StudioNotice>
                                )}
                            </>
                        )}
                        {section === 1 && (
                            <>
                                <ReviewCard
                                    icon={FileText}
                                    title="Original source"
                                >
                                    <ReviewRow
                                        label="Template"
                                        value={run.template}
                                    />
                                    <ReviewRow
                                        label="Version"
                                        value={versionLabel(run.version)}
                                    />
                                    <ReviewRow
                                        label="Run reference"
                                        value={run.reference}
                                    />
                                    <ReviewRow
                                        label="Vehicle"
                                        value={vehicleShort(vehicle)}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Clock}
                                    title="Timing & author"
                                >
                                    <ReviewRow
                                        label="Observed"
                                        value={observed}
                                    />
                                    <ReviewRow
                                        label="Submitted"
                                        value={
                                            run.submitted_at
                                                ? `${formatDateTime(run.submitted_at)} · Pacific/Auckland`
                                                : 'Not recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Recorded by"
                                        value={
                                            run.recorded_by ?? 'Not recorded'
                                        }
                                    />
                                </ReviewCard>
                                {run.amendments.length > 0 && (
                                    <ReviewCard
                                        icon={FileText}
                                        title="Amendments"
                                    >
                                        {run.amendments.map((amendment) => (
                                            <ReviewRow
                                                key={amendment.id}
                                                label={[
                                                    amendment.recorded_by ??
                                                        'Staff member',
                                                    amendment.recorded_at
                                                        ? formatDateTime(
                                                              amendment.recorded_at,
                                                          )
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                                value={amendment.note}
                                            />
                                        ))}
                                    </ReviewCard>
                                )}
                                <StudioNotice title="Original evidence is preserved">
                                    Later templates or repair notes do not
                                    change these answers. Corrections retain
                                    their own author and record.
                                </StudioNotice>
                            </>
                        )}
                        {section === 2 && (
                            <>
                                <ReviewCard
                                    icon={Wrench}
                                    title="Maintenance follow-up"
                                >
                                    <ReviewRow
                                        label="Relationship"
                                        value={
                                            run.linked_work
                                                ? `Linked to ${run.linked_work.reference ?? `Work #${run.linked_work.id}`}`
                                                : run.linked
                                                  ? 'Linked to Maintenance work'
                                                  : 'No follow-up link recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Maintenance decision"
                                        value={
                                            assessment
                                                ? [
                                                      assessment.label,
                                                      assessment.assessed_by,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ')
                                                : run.blocking
                                                  ? 'Stops the vehicle being used until assessed'
                                                  : 'None recorded'
                                        }
                                    />
                                    <p className="body-copy mt-3">
                                        Carry this exact check and version into
                                        the maintenance report. The Coordinator
                                        assesses the condition and decides the
                                        next action.
                                    </p>
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Supporting evidence"
                                >
                                    <ReviewRow
                                        label="Original evidence"
                                        value={
                                            fileNames.join(', ') ||
                                            'No separate attachment recorded'
                                        }
                                    />
                                    {answerFiles.map((answer) =>
                                        answer.evidence?.url ? (
                                            <a
                                                className="attachment-item mt-3"
                                                key={`answer-${answer.id}`}
                                                href={answer.evidence.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <FileText
                                                    className="size-5"
                                                    aria-hidden
                                                />
                                                <span>
                                                    {answer.evidence.name}
                                                </span>
                                            </a>
                                        ) : null,
                                    )}
                                    {run.files.map((file) =>
                                        file.url ? (
                                            <a
                                                className="attachment-item mt-3"
                                                key={file.id}
                                                href={file.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <FileText
                                                    className="size-5"
                                                    aria-hidden
                                                />
                                                <span>{file.name}</span>
                                            </a>
                                        ) : (
                                            <div
                                                className="attachment-item mt-3"
                                                key={file.id}
                                            >
                                                <FileText
                                                    className="size-5"
                                                    aria-hidden
                                                />
                                                <div>
                                                    <strong>{file.name}</strong>
                                                    <small>
                                                        {FILE_STATE_LABELS[
                                                            file.state ?? ''
                                                        ]?.label ??
                                                            'Not available to open'}
                                                    </small>
                                                </div>
                                            </div>
                                        ),
                                    )}
                                    <p className="body-copy mt-3">
                                        Files added to related work do not
                                        rewrite the submitted answers.
                                    </p>
                                </ReviewCard>
                            </>
                        )}
                    </div>
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
