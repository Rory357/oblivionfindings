import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ArrowRight,
    CalendarDays,
    FileCheck2,
    Link2,
    Loader2,
    MessageSquare,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useVehicleChecks } from './checks-data';
import { ChecksLoadingDialog, DraftGuard, LockedVehicle } from './checks-kit';
import { estimateSummary, PROBLEM_TYPES, versionLabel } from './checks-model';
import type { VehicleChecks } from './checks-types';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import { openWorkOrder } from './studio-kit';
import type { VehicleWorkspace } from './types';
import { StudioNotice } from './wizard-kit';
import type { WorkspaceLocation } from './workspace-model';

/** The check a report starts from; its answers are never changed. */
export type ReportSource = {
    id: number;
    reference: string;
    template: string;
    version: number | null;
};

const STEPS = [
    {
        key: 'details',
        label: 'Report details',
        blurb: 'Condition and optional dates',
        icon: MessageSquare,
    },
    {
        key: 'link',
        label: 'Related work',
        blurb: 'Avoid a duplicate job',
        icon: Link2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Owner and original source',
        icon: FileCheck2,
    },
];

/**
 * Report a problem for Maintenance from the vehicle (header "Report a
 * problem") or from a recorded check ("Create or link maintenance"). The
 * report keeps the original check as its source; the site's approved
 * Coordinator assesses it. Reporting never releases the vehicle.
 */
export function ReportProblemDialog({
    workspace,
    checks,
    source,
    back,
    onClose,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    /** The Checks view passes its loaded data; other callers let the dialog load it. */
    checks?: VehicleChecks | null;
    /** Report from this check; omit for a manual vehicle report. */
    source?: ReportSource | null;
    /** Where the work order's back link returns to. */
    back?: WorkspaceLocation;
    onClose: () => void;
    onChanged: () => void;
}) {
    const own = useVehicleChecks(workspace.vehicle.id, '', !checks);
    const data = checks ?? own.data;

    if (!data)
        return (
            <ChecksLoadingDialog
                title="Create or link maintenance"
                load={own.load}
                onRetry={own.reload}
                onClose={onClose}
            />
        );
    if (!data.can.report)
        return (
            <Dialog open onOpenChange={(next) => !next && onClose()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Report a problem</DialogTitle>
                        <DialogDescription>
                            Reporting a problem for this vehicle needs
                            Maintenance reporting access at its site.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        );

    return (
        <ReportFlow
            workspace={workspace}
            checks={data}
            source={source ?? null}
            back={
                back ??
                (source
                    ? { tab: 'checks', view: 'recent' }
                    : { tab: 'maintenance', view: 'open' })
            }
            onClose={onClose}
            onChanged={() => {
                if (!checks) own.reload();
                onChanged();
            }}
        />
    );
}

function ReportFlow({
    workspace,
    checks,
    source,
    back,
    onClose,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    checks: VehicleChecks;
    source: ReportSource | null;
    back: WorkspaceLocation;
    onClose: () => void;
    onChanged: () => void;
}) {
    const { vehicle, work } = workspace;
    const reportOnly = !checks.can.link_work;
    const openWork = work.can_view ? work.open : [];
    const canLink = !reportOnly && openWork.length > 0;
    const initialTitle = source
        ? 'Condition concern from vehicle check'
        : 'Vehicle condition concern';
    const initialNotes = source
        ? 'Please review the original check and assess the next action.'
        : 'Please assess this vehicle concern and the next action.';
    const [step, setStep] = useState(0);
    const [title, setTitle] = useState(initialTitle);
    const [notes, setNotes] = useState(initialNotes);
    const [link, setLink] = useState('');
    const [choice, setChoice] = useState<'link' | 'new'>(
        canLink ? 'link' : 'new',
    );
    const [range, setRange] = useState<[string | null, string | null]>([
        null,
        null,
    ]);
    const [showDates, setShowDates] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState<{
        id: number;
        reference: string;
    } | null>(null);
    const [discard, setDiscard] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const linking = canLink && choice === 'link';
    const linked = openWork.find((row) => String(row.id) === link);
    const route = checks.route;
    const dirty =
        title !== initialTitle ||
        notes !== initialNotes ||
        !!link ||
        showDates ||
        choice !== (canLink ? 'link' : 'new');
    const summary = estimateSummary(showDates, range);
    const banner =
        error ||
        command.errors.asset_id ||
        command.errors.source_id ||
        command.message;

    useEffect(() => {
        if (error && step === 0) {
            const target = !title.trim()
                ? 'report-title'
                : showDates && (!range[0] || !range[1])
                  ? 'estimate-window'
                  : null;
            if (target) document.getElementById(target)?.focus();
        }
        // Focus moves only when a new error is shown.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [error, step]);

    // Server errors return to the step that holds the field.
    useEffect(() => {
        const keys = Object.keys(command.errors);
        if (
            keys.some((key) =>
                [
                    'title',
                    'description',
                    'estimated_start_date',
                    'estimated_end_date',
                ].includes(key),
            )
        )
            setStep(0);
        else if (keys.includes('existing_work_order_id')) setStep(1);
    }, [command.errors]);

    const close = () => {
        if (command.processing) return;
        if (saved || !dirty) onClose();
        else setDiscard(true);
    };

    const next = () => {
        if (
            step === 0 &&
            (!title.trim() || (showDates && (!range[0] || !range[1])))
        ) {
            setError(
                'Enter a title and finish the selected date range, or choose Not known yet.',
            );
            return;
        }
        if (step === 1 && linking && !link) {
            setError(
                'Choose an existing work record or choose Create a new report.',
            );
            return;
        }
        setError('');
        setStep(step + 1);
    };

    const submit = async () => {
        if (command.processing) return;
        if (!command.uncertain) {
            if (!title.trim() || (showDates && (!range[0] || !range[1]))) {
                setStep(0);
                setError(
                    'Enter a title and finish the chosen range, or choose Not known yet.',
                );
                return;
            }
            if (linking && !link) {
                setStep(1);
                setError(
                    'Choose an existing work record or create a new report.',
                );
                return;
            }
        }
        setError('');
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/maintenance-reports`,
            {
                title: title.trim(),
                description: notes.trim() || null,
                source_run_id: source?.id ?? null,
                existing_work_order_id: linking ? Number(link) : null,
                estimated_start_date: showDates ? range[0] : null,
                estimated_end_date: showDates ? range[1] : null,
            },
        );
        if (!result || !isJsonObject(result.work_order)) return;
        setSaved({
            id: Number(result.work_order.id),
            reference: String(
                result.work_order.reference ?? `Work #${result.work_order.id}`,
            ),
        });
        onChanged();
    };

    const titleOptions = [
        ...(PROBLEM_TYPES.includes(title) ? [] : [title]),
        ...PROBLEM_TYPES,
    ].map((option) => ({ value: option, label: option }));

    return (
        <>
            <WizardShell
                open
                title="Create or link maintenance"
                maxWidth="min(92vw, 1100px)"
                description="Review a check concern with its original source"
                railIcon={Wrench}
                railTitle="Maintenance report"
                railSub={
                    source ? `From ${source.reference}` : 'From vehicle profile'
                }
                steps={STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!command.locked) {
                        setError('');
                        setStep(index);
                    }
                }}
                pct={Math.round(
                    ([
                        Boolean(title.trim()),
                        Boolean(notes.trim()),
                        !showDates || Boolean(range[0] && range[1]),
                        !linking || Boolean(link),
                    ].filter(Boolean).length /
                        4) *
                        100,
                )}
                onClose={close}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={command.processing}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && !command.requiresReload && (
                            <Button
                                variant="outline"
                                disabled={command.locked}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {command.requiresReload ? (
                            <Button
                                onClick={() => {
                                    onChanged();
                                    onClose();
                                }}
                            >
                                Reload vehicle
                            </Button>
                        ) : (
                            <Button
                                disabled={
                                    command.processing ||
                                    // Without approved routing the report can't be accepted; the notice says why.
                                    (step >= 2 &&
                                        !command.uncertain &&
                                        !!route &&
                                        !route.approved)
                                }
                                onClick={
                                    step < 2 && !command.uncertain
                                        ? next
                                        : submit
                                }
                            >
                                {command.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.processing
                                    ? 'Confirming…'
                                    : step < 2 && !command.uncertain
                                      ? 'Continue'
                                      : command.uncertain
                                        ? 'Retry and recover report'
                                        : 'Confirm report'}
                            </Button>
                        )}
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Report linked"
                            blurb={
                                <>
                                    {saved.reference} retains{' '}
                                    {source
                                        ? `the original check ${source.reference}`
                                        : 'the original vehicle report'}
                                    . The site's Coordinator owns the next
                                    assessment. No safety release or financial
                                    approval has occurred.
                                </>
                            }
                            actions={
                                checks.can.view_maintenance ? (
                                    <Button
                                        onClick={() =>
                                            openWorkOrder(
                                                saved.id,
                                                vehicle.id,
                                                back,
                                            )
                                        }
                                    >
                                        Review {saved.reference}
                                        <ArrowRight className="size-4" />
                                    </Button>
                                ) : (
                                    <Button onClick={onClose}>
                                        Back to vehicle
                                    </Button>
                                )
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={step}>
                    <div className="vehicle-studio">
                        <div className="flow-stack">
                            <LockedVehicle vehicle={vehicle} />
                            <div className="source-strip">
                                <Link2 className="size-[15px]" aria-hidden />
                                <span>
                                    {source ? (
                                        <>
                                            Original check{' '}
                                            <strong>{source.reference}</strong>{' '}
                                            · {versionLabel(source.version)} ·
                                            Answers unchanged
                                        </>
                                    ) : (
                                        'Vehicle profile · No check is attached to this manual report'
                                    )}
                                </span>
                            </div>
                            {banner && (
                                <StudioNotice
                                    title="Report not confirmed"
                                    tone="critical"
                                >
                                    {banner}
                                </StudioNotice>
                            )}
                            {/* A retry resends this exact draft, so it stays locked until settled. */}
                            <fieldset
                                disabled={command.locked}
                                className="flow-stack min-w-0"
                            >
                                {step === 0 && (
                                    <>
                                        <div className="field">
                                            <label htmlFor="report-title">
                                                What needs attention?
                                            </label>
                                            <VehicleSearchSelect
                                                id="report-title"
                                                label="What needs attention?"
                                                value={title}
                                                invalid={!!command.errors.title}
                                                options={titleOptions}
                                                onChange={(value) => {
                                                    setTitle(value);
                                                    command.clearError('title');
                                                }}
                                                onAdd={
                                                    reportOnly
                                                        ? undefined
                                                        : (typed) => {
                                                              if (typed.trim())
                                                                  setTitle(
                                                                      typed
                                                                          .trim()
                                                                          .slice(
                                                                              0,
                                                                              255,
                                                                          ),
                                                                  );
                                                          }
                                                }
                                            />
                                            {command.errors.title && (
                                                <p
                                                    role="alert"
                                                    className="text-xs text-status-critical"
                                                >
                                                    {command.errors.title}
                                                </p>
                                            )}
                                        </div>
                                        <div className="field">
                                            <label htmlFor="report-notes">
                                                Details for the Coordinator
                                            </label>
                                            <Textarea
                                                id="report-notes"
                                                value={notes}
                                                maxLength={5000}
                                                onChange={(event) =>
                                                    setNotes(event.target.value)
                                                }
                                            />
                                        </div>
                                        <div
                                            className="field"
                                            id="estimate-window"
                                            role="group"
                                            tabIndex={-1}
                                            aria-labelledby="estimate-window-label"
                                            aria-describedby="estimate-instructions estimate-summary"
                                        >
                                            <label id="estimate-window-label">
                                                Estimated maintenance window ·
                                                optional
                                            </label>
                                            <div className="inline-actions">
                                                <Button
                                                    variant={
                                                        showDates
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    aria-pressed={showDates}
                                                    onClick={() =>
                                                        setShowDates(true)
                                                    }
                                                >
                                                    <CalendarDays className="size-4" />
                                                    Choose dates
                                                </Button>
                                                <Button
                                                    variant={
                                                        !showDates
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    aria-pressed={!showDates}
                                                    onClick={() => {
                                                        setShowDates(false);
                                                        setRange([null, null]);
                                                    }}
                                                >
                                                    Not known yet
                                                </Button>
                                            </div>
                                            {showDates && (
                                                <>
                                                    <p
                                                        id="estimate-instructions"
                                                        className="muted"
                                                    >
                                                        Choose the first and
                                                        final day. For one day,
                                                        choose the same day
                                                        twice. These are local
                                                        calendar dates.
                                                    </p>
                                                    <LeaveCalendarRange
                                                        start={range[0]}
                                                        end={range[1]}
                                                        required={false}
                                                        onChange={(
                                                            start,
                                                            end,
                                                        ) =>
                                                            setRange([
                                                                start,
                                                                end,
                                                            ])
                                                        }
                                                    />
                                                </>
                                            )}
                                            <div
                                                id="estimate-summary"
                                                className="estimate-summary"
                                                role="status"
                                                aria-live="polite"
                                            >
                                                <CalendarDays
                                                    className="size-[17px]"
                                                    aria-hidden
                                                />
                                                <span>{summary}</span>
                                            </div>
                                            {(command.errors
                                                .estimated_start_date ||
                                                command.errors
                                                    .estimated_end_date) && (
                                                <p
                                                    role="alert"
                                                    className="text-xs text-status-critical"
                                                >
                                                    {command.errors
                                                        .estimated_start_date ??
                                                        command.errors
                                                            .estimated_end_date}
                                                </p>
                                            )}
                                            <small className="muted">
                                                An estimate is advisory. It does
                                                not reserve the vehicle or book
                                                a provider.
                                            </small>
                                        </div>
                                    </>
                                )}
                                {step === 1 &&
                                    (reportOnly ? (
                                        <StudioNotice title="Coordinator review required">
                                            You can report the concern. Choosing
                                            or merging existing work requires
                                            Maintenance manager access.
                                        </StudioNotice>
                                    ) : !canLink ? (
                                        <StudioNotice title="No open work for this vehicle">
                                            A new report is created for the
                                            site's Coordinator to assess.
                                        </StudioNotice>
                                    ) : (
                                        <>
                                            <StudioNotice
                                                title="Related work found"
                                                tone="warning"
                                            >
                                                Review the existing condition
                                                concern before creating another
                                                job.
                                            </StudioNotice>
                                            <label className="choice">
                                                <input
                                                    type="radio"
                                                    name="link-mode"
                                                    checked={choice === 'link'}
                                                    onChange={() =>
                                                        setChoice('link')
                                                    }
                                                />
                                                <span>
                                                    <strong>
                                                        Link this report to
                                                        existing work
                                                    </strong>
                                                    <small>
                                                        Keep the new report and
                                                        original check as
                                                        separate evidence.
                                                    </small>
                                                </span>
                                            </label>
                                            {choice === 'link' && (
                                                <div className="field">
                                                    <label htmlFor="report-work">
                                                        Maintenance work
                                                    </label>
                                                    <VehicleSearchSelect
                                                        id="report-work"
                                                        label="Maintenance work"
                                                        value={link}
                                                        invalid={
                                                            !!command.errors
                                                                .existing_work_order_id
                                                        }
                                                        options={openWork.map(
                                                            (row) => ({
                                                                value: String(
                                                                    row.id,
                                                                ),
                                                                label: `${row.title ?? 'Maintenance work'} · ${row.reference ?? `Work #${row.id}`}`,
                                                                description: [
                                                                    vehicle.asset_tag,
                                                                    row.status.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    ),
                                                                    row.owner,
                                                                ]
                                                                    .filter(
                                                                        Boolean,
                                                                    )
                                                                    .join(
                                                                        ' · ',
                                                                    ),
                                                            }),
                                                        )}
                                                        onChange={(value) => {
                                                            setLink(value);
                                                            command.clearError(
                                                                'existing_work_order_id',
                                                            );
                                                        }}
                                                    />
                                                    {command.errors
                                                        .existing_work_order_id && (
                                                        <p
                                                            role="alert"
                                                            className="text-xs text-status-critical"
                                                        >
                                                            {
                                                                command.errors
                                                                    .existing_work_order_id
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                            <label className="choice">
                                                <input
                                                    type="radio"
                                                    name="link-mode"
                                                    checked={choice === 'new'}
                                                    onChange={() =>
                                                        setChoice('new')
                                                    }
                                                />
                                                <span>
                                                    <strong>
                                                        Create a new report
                                                    </strong>
                                                    <small>
                                                        Use when this is a
                                                        separate concern.
                                                        Coordinator review still
                                                        applies.
                                                    </small>
                                                </span>
                                            </label>
                                        </>
                                    ))}
                                {step === 2 && (
                                    <>
                                        <ReviewCard
                                            icon={Wrench}
                                            title="Report"
                                            onEdit={() => setStep(0)}
                                        >
                                            <ReviewRow
                                                label="Title"
                                                value={title}
                                            />
                                            <ReviewRow
                                                label="Details"
                                                value={notes.trim()}
                                            />
                                            <ReviewRow
                                                label="Estimated dates"
                                                value={summary}
                                            />
                                            <ReviewRow
                                                label="Original check"
                                                value={
                                                    source
                                                        ? `${source.reference} · ${versionLabel(source.version)}`
                                                        : 'Manual vehicle report'
                                                }
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={Link2}
                                            title="Routing"
                                            onEdit={() => setStep(1)}
                                        >
                                            <ReviewRow
                                                label="Work"
                                                value={
                                                    linking && linked
                                                        ? `${linked.reference ?? `Work #${linked.id}`} · ${linked.title ?? 'Maintenance work'}`
                                                        : 'New report for assessment'
                                                }
                                            />
                                            <ReviewRow
                                                label="Next owner"
                                                value={
                                                    linking && linked?.owner
                                                        ? `${linked.owner} · owns ${linked.reference ?? 'this work'}`
                                                        : route?.coordinator
                                                          ? `${route.coordinator} · ${route.site ? `${route.site} ` : 'Site '}Coordinator`
                                                          : 'No approved Coordinator'
                                                }
                                            />
                                            <ReviewRow
                                                label="Backup"
                                                value={
                                                    route?.backup
                                                        ? `${route.backup} · Nominated site backup`
                                                        : 'No approved backup'
                                                }
                                            />
                                        </ReviewCard>
                                        {route && !route.approved && (
                                            <StudioNotice
                                                title="Site routing needs approval"
                                                tone="critical"
                                            >
                                                This site has no approved
                                                Coordinator and backup, so the
                                                report can’t be submitted yet.
                                                Ask a manager to approve the
                                                site’s Maintenance routing; your
                                                draft is kept.
                                            </StudioNotice>
                                        )}
                                        <StudioNotice
                                            title="Reporting cannot authorise release"
                                            tone="warning"
                                        >
                                            Reporting or linking a concern
                                            cannot release the vehicle.
                                        </StudioNotice>
                                    </>
                                )}
                            </fieldset>
                        </div>
                    </div>
                </WizardStepPane>
            </WizardShell>
            <DraftGuard
                open={discard}
                onKeep={() => setDiscard(false)}
                onDiscard={() => {
                    setDiscard(false);
                    onClose();
                }}
            />
        </>
    );
}
