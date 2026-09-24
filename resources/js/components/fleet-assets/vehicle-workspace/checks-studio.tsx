import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import {
    CalendarDays,
    ClipboardCheck,
    FileText,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    AmendmentWizard,
    CheckEvidenceDialog,
    ManageRequirementWizard,
} from './check-actions';
import { AssessCheckDialog } from './check-assessment';
import { CheckFlowDialog } from './check-flow';
import { ChecklistLibrary } from './check-library';
import { RunDetailDialog } from './check-run-detail';
import { useVehicleChecks } from './checks-data';
import {
    ASSESSED_LABEL,
    checkDueStatus,
    outcomeLabel,
    outcomeTone,
    runObservedDay,
    runTone,
    versionLabel,
} from './checks-model';
import type { CheckRun } from './checks-types';
import './checks.css';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { ReportProblemDialog, type ReportSource } from './report-problem';
import { openWorkOrder, SectionHeading } from './studio-kit';
import './studio.css';
import type { VehicleWorkspace } from './types';
import { todayInAuckland, type ChecksView } from './workspace-model';

type ChecksDialog =
    | { kind: 'check'; templateId?: number | null }
    | { kind: 'run'; run: CheckRun }
    | { kind: 'report'; source: ReportSource | null }
    | { kind: 'plan' }
    | { kind: 'amend'; run: CheckRun }
    | { kind: 'upload'; run: CheckRun }
    | { kind: 'assess'; run: CheckRun };

const sourceOf = (run: CheckRun): ReportSource => ({
    id: run.id,
    reference: run.reference,
    template: run.template,
    version: run.version,
});

/**
 * Checks & inspections, built to the approved PKG-02B v13 design: the next
 * requirement card, then either the original observations (Recent checks) or
 * the controlled template library (Templates). Data comes from the vehicle's
 * checks endpoint; every action is backed by it.
 */
export function ChecksStudio({
    workspace,
    view,
    focusRunId,
    onFocusHandled,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    view: ChecksView;
    /** Open this check once the checks load (a readiness link's `run`). */
    focusRunId?: number;
    /** The linked check was opened (or isn't listed); the link can be dropped. */
    onFocusHandled?: () => void;
    /** Something changed here: reload the workspace (header, readiness). */
    onChanged: () => void;
}) {
    const { vehicle } = workspace;
    const { data, load, reload } = useVehicleChecks(
        vehicle.id,
        workspace.as_of,
    );
    const layout = useVehicleCollectionView('recent-checks');
    const [dialog, setDialog] = useState<ChecksDialog | null>(null);
    const templates = view === 'templates';
    const refresh = () => {
        reload();
        onChanged();
    };
    // A readiness link names the check holding the vehicle: open it once.
    const handledFocus = useRef<number | null>(null);
    useEffect(() => {
        if (!data || !focusRunId || handledFocus.current === focusRunId) return;
        handledFocus.current = focusRunId;
        const run = data.runs.data.find((item) => item.id === focusRunId);
        if (run) setDialog({ kind: 'run', run });
        onFocusHandled?.();
    }, [data, focusRunId, onFocusHandled]);

    if (!data) {
        return load === 'loading' ? (
            <div className="checks-studio" aria-busy="true">
                <p className="sr-only" role="status">
                    Loading checks and inspections…
                </p>
                <aside className="studio-card check-plan">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="h-6 w-32" />
                    <Skeleton className="h-5 w-24" />
                    <Skeleton className="h-10 w-40" />
                </aside>
                <section className="studio-card">
                    <SkeletonTable rows={4} columns={4} />
                </section>
            </div>
        ) : (
            <section className="studio-card">
                <ErrorState
                    title={
                        load === 'forbidden'
                            ? 'Checks aren’t available'
                            : 'Checks couldn’t be loaded'
                    }
                    message={
                        load === 'forbidden'
                            ? 'You no longer have access to this vehicle’s checks. Reload the vehicle to see what is available.'
                            : 'The vehicle’s checks and checklists could not be loaded. Try again.'
                    }
                    onRetry={load === 'error' ? reload : undefined}
                />
            </section>
        );
    }

    const { can, requirement } = data;
    const required =
        data.templates.find(
            (template) => template.id === requirement.template_id,
        ) ?? null;
    const status = checkDueStatus(requirement.due_on, todayInAuckland());
    const runs = data.runs.data;
    const start = (templateId?: number | null) =>
        setDialog({ kind: 'check', templateId });

    return (
        <div className="checks-studio">
            <aside className="studio-card check-plan">
                <SectionHeading
                    eyebrow="NEXT REQUIREMENT"
                    title={required?.name ?? 'No checklist chosen'}
                />
                <div className="check-due-date">
                    <CalendarDays className="size-6" aria-hidden />
                    <strong>
                        {requirement.due_on
                            ? formatDateOnly(requirement.due_on)
                            : 'No date set'}
                    </strong>
                </div>
                <div className="check-plan-status">
                    <StatusBadge variant={status.tone}>
                        {status.label}
                    </StatusBadge>
                </div>
                <dl>
                    <dt>Owner</dt>
                    <dd>{requirement.owner?.name ?? 'Not assigned'}</dd>
                    <dt>Template</dt>
                    <dd>
                        {required
                            ? `${versionLabel(required.version)} · latest published version`
                            : 'No checklist available'}
                    </dd>
                </dl>
                <Button
                    variant="outline"
                    className="w-full"
                    disabled={!can.manage_requirement || !data.templates.length}
                    onClick={() => setDialog({ kind: 'plan' })}
                >
                    Manage requirement
                </Button>
                <Button
                    variant="ghost"
                    className="w-full"
                    disabled={!can.start || !required}
                    onClick={() => start(required?.id)}
                >
                    Start a retest
                </Button>
                <p className="studio-footnote">
                    Original answers and files stay with the submitted version.
                    A pass does not release a restriction.
                </p>
            </aside>
            <section className="studio-card">
                <SectionHeading
                    eyebrow={
                        templates
                            ? 'CONTROLLED TEMPLATES'
                            : 'ORIGINAL OBSERVATIONS'
                    }
                    title={
                        templates ? 'Check templates' : 'Checks & inspections'
                    }
                >
                    {!templates && (
                        <VehicleCollectionToggle
                            label="Recent checks"
                            view={layout.view}
                            onChange={layout.setView}
                        />
                    )}
                    <Button
                        disabled={!can.start || !data.templates.length}
                        onClick={() => start(required?.id)}
                    >
                        <ClipboardCheck className="size-4" />
                        Start check
                    </Button>
                </SectionHeading>
                {templates ? (
                    <ChecklistLibrary
                        vehicle={vehicle}
                        checks={data}
                        onRun={(templateId) => start(templateId)}
                        onChanged={refresh}
                    />
                ) : (
                    <VehicleRecordCollection
                        label="Recent checks"
                        view={layout.view}
                        total={data.runs.total}
                        columns={[
                            { label: 'Observed / version', width: '1.1fr' },
                            { label: 'Result', width: '.7fr' },
                            { label: 'Evidence & follow-up', width: '1.6fr' },
                        ]}
                        empty={{
                            title: 'No checks submitted yet',
                            description:
                                'Start a check and attach the original observations.',
                        }}
                        records={runs.map((run) => ({
                            id: run.id,
                            name: run.template,
                            subline: run.reference,
                            icon: ClipboardCheck,
                            tone: runTone(run),
                            fields: [
                                <>
                                    <strong>{runObservedDay(run)}</strong>
                                    <small>
                                        Template{' '}
                                        {versionLabel(
                                            run.version,
                                        ).toLowerCase()}
                                    </small>
                                </>,
                                <span key="result" className="inline-actions">
                                    <StatusBadge
                                        variant={outcomeTone(run.outcome)}
                                    >
                                        {outcomeLabel(run.outcome)}
                                    </StatusBadge>
                                    {run.assessment && (
                                        <StatusBadge variant="success">
                                            {ASSESSED_LABEL}
                                        </StatusBadge>
                                    )}
                                </span>,
                                <>
                                    <small>
                                        {run.evidence_count}{' '}
                                        {run.evidence_count === 1
                                            ? 'evidence file'
                                            : 'evidence files'}
                                    </small>
                                    <div className="collection-actions">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={!can.upload}
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'upload',
                                                    run,
                                                })
                                            }
                                        >
                                            <Upload className="size-[14px]" />
                                            Upload evidence
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={!can.amend}
                                            onClick={() =>
                                                setDialog({
                                                    kind: 'amend',
                                                    run,
                                                })
                                            }
                                        >
                                            Add amendment
                                        </Button>
                                    </div>
                                </>,
                            ],
                            onOpen: () => setDialog({ kind: 'run', run }),
                            footer: {
                                primary: run.reference,
                                secondary: 'Original observations retained',
                            },
                            actions: [
                                {
                                    label: 'View check',
                                    icon: ClipboardCheck,
                                    onClick: () =>
                                        setDialog({ kind: 'run', run }),
                                },
                                ...(can.upload
                                    ? [
                                          {
                                              label: 'Upload evidence',
                                              icon: Upload,
                                              onClick: () =>
                                                  setDialog({
                                                      kind: 'upload',
                                                      run,
                                                  }),
                                          },
                                      ]
                                    : []),
                                ...(can.amend
                                    ? [
                                          {
                                              label: 'Add amendment',
                                              icon: FileText,
                                              onClick: () =>
                                                  setDialog({
                                                      kind: 'amend',
                                                      run,
                                                  }),
                                          },
                                      ]
                                    : []),
                                ...(can.assess && run.assess?.available
                                    ? [
                                          {
                                              label: 'No issue found — release for use',
                                              icon: ShieldCheck,
                                              onClick: () =>
                                                  setDialog({
                                                      kind: 'assess',
                                                      run,
                                                  }),
                                          },
                                      ]
                                    : []),
                            ],
                        }))}
                    />
                )}
            </section>
            {dialog?.kind === 'check' && (
                <CheckFlowDialog
                    workspace={workspace}
                    checks={data}
                    templateId={dialog.templateId}
                    onClose={() => setDialog(null)}
                    onChanged={refresh}
                />
            )}
            {dialog?.kind === 'run' && (
                <RunDetailDialog
                    vehicle={vehicle}
                    run={dialog.run}
                    can={can}
                    onClose={() => setDialog(null)}
                    onReport={(run) =>
                        setDialog({ kind: 'report', source: sourceOf(run) })
                    }
                    onOpenWork={(workOrderId) =>
                        openWorkOrder(workOrderId, vehicle.id, {
                            tab: 'checks',
                            view,
                        })
                    }
                    onAssess={
                        can.assess
                            ? (run) => setDialog({ kind: 'assess', run })
                            : undefined
                    }
                />
            )}
            {dialog?.kind === 'assess' && (
                <AssessCheckDialog
                    workspace={workspace}
                    run={dialog.run}
                    onClose={() => setDialog(null)}
                    onSaved={refresh}
                />
            )}
            {dialog?.kind === 'report' && (
                <ReportProblemDialog
                    workspace={workspace}
                    checks={data}
                    source={dialog.source}
                    back={{ tab: 'checks', view }}
                    onClose={() => setDialog(null)}
                    onChanged={refresh}
                />
            )}
            {dialog?.kind === 'plan' && (
                <ManageRequirementWizard
                    workspace={workspace}
                    checks={data}
                    onClose={() => setDialog(null)}
                    onSaved={refresh}
                />
            )}
            {dialog?.kind === 'amend' && (
                <AmendmentWizard
                    vehicle={vehicle}
                    run={dialog.run}
                    onClose={() => setDialog(null)}
                    onSaved={refresh}
                />
            )}
            {dialog?.kind === 'upload' && (
                <CheckEvidenceDialog
                    vehicle={vehicle}
                    run={dialog.run}
                    onClose={() => setDialog(null)}
                    onSaved={refresh}
                />
            )}
        </div>
    );
}
