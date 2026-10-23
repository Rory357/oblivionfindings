import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import {
    drillHref,
    drillLocation,
    type DrillCommand,
    type DrillSection,
} from '@/lib/health-safety/drill-navigation';
import { outcomeMeta, statusMeta } from '@/pages/health-safety/drills/shared';
import { router, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    FileText,
    History,
    Link2,
    Paperclip,
    Siren,
    Timer,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DrillCompletionForm } from './drill-completion-form';
import { DrillEvidence } from './drill-evidence';
import { DrillOverview, DrillRunFacts } from './drill-facts';
import {
    DrillConnections,
    DrillFindings,
    DrillHistory,
    DrillParticipants,
} from './drill-panels';
import { DrillPlanningForm } from './drill-planning-form';
import {
    DrillRecordCommandForm,
    type DrillRecordCommand,
} from './drill-record-command';
import { DrillRemedyForm } from './drill-remedy-form';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'run', label: 'Run & timings', icon: Timer },
    { key: 'participants', label: 'Participants', icon: Users },
    { key: 'findings', label: 'Findings', icon: ClipboardList },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;
export function DrillWorkspace({
    detail: d,
    pickers,
}: {
    detail: DrillWorkspaceDetail;
    pickers: DrillPickers;
}) {
    const { url } = usePage();
    const location = drillLocation(url);
    const [section, setSection] = useState<DrillSection>(location.section);
    const [command, setCommand] = useState<DrillCommand | null>(
        location.command,
    );
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    useEffect(() => {
        const next = drillLocation(url);
        setSection(next.section);
        setCommand(next.command);
    }, [url]);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
    }, [section, command]);
    const href = (
        next: DrillSection,
        action: DrillCommand | null = null,
        finding: number | null = location.finding,
        participant: number | null = null,
    ) =>
        drillHref(d.id, {
            section: next,
            command: action,
            finding,
            participant,
            returnTo: location.returnTo,
        });
    const move = (
        next: DrillSection,
        action: DrillCommand | null = null,
        finding: number | null = location.finding,
        participant: number | null = null,
    ) =>
        drafts.request(() => {
            interacted.current = true;
            router.replace({
                url: href(next, action, finding, participant),
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setSection(next);
                    setCommand(action);
                },
            });
        });
    const saved = () =>
        router.replace({
            url: href(section),
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setCommand(null),
        });
    const cancel = () => move(section);
    const status = statusMeta(
        d.status === 'scheduled' &&
            d.scheduled_at &&
            Date.parse(d.scheduled_at) < Date.now()
            ? 'overdue'
            : d.status,
    );
    const outcome = outcomeMeta(d.outcome);
    const finding = d.findings.find((item) => item.id === location.finding);
    const shortCommand =
        command &&
        [
            'start',
            'cancel',
            'add-participant',
            'edit-participant',
            'add-finding',
            'edit-finding',
            'resolve-finding',
        ].includes(command);
    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        frontline
                        wrapTitle
                        backHref={location.returnTo}
                        icon={Siren}
                        title={d.reference}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    status.tone === 'info'
                                        ? 'neutral'
                                        : status.tone
                                }
                            >
                                {status.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${d.title} · ${d.site?.name ?? 'Site not recorded'}`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Attendance"
                                    href={href('participants')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.counts.attended}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Confirmed of {d.counts.assigned}{' '}
                                        assigned
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Open findings"
                                    href={href('findings')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.counts.findings_open}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {d.counts.findings_overdue} overdue
                                        findings
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Evidence"
                                    href={href('evidence')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.attachments.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Recorded files
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Outcome"
                                    href={href('run')}
                                >
                                    <PageHeaderMeterBig>
                                        {outcome?.label ?? 'Not recorded'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Recorded drill result
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={[...SECTIONS]}
                                value={section}
                                onSelect={(next) => move(next as DrillSection)}
                                ariaLabel="Drill sections"
                            />
                        }
                    />
                }
            >
                <div className="space-y-5">
                    <div
                        role="group"
                        className="flex flex-wrap gap-3"
                        aria-label="Drill actions"
                    >
                        {d.can.edit && (
                            <Button
                                className="frontline-tap"
                                variant="outline"
                                onClick={() => move('overview', 'edit')}
                            >
                                Edit planning
                            </Button>
                        )}
                        {d.can.start && (
                            <Button
                                className="frontline-tap"
                                onClick={() => move('run', 'start')}
                            >
                                Start drill
                            </Button>
                        )}
                        {d.can.complete && (
                            <Button
                                className="frontline-tap"
                                onClick={() => move('run', 'complete')}
                            >
                                Record completion
                            </Button>
                        )}
                        {d.can.cancel && (
                            <Button
                                className="frontline-tap"
                                variant="outline"
                                onClick={() => move('overview', 'cancel')}
                            >
                                Cancel drill
                            </Button>
                        )}
                    </div>
                    <Card>
                        <CardContent className="p-5 sm:p-6">
                            <div
                                ref={panel}
                                tabIndex={-1}
                                className="min-w-0 outline-none"
                            >
                                {command ? (
                                    <div
                                        key={`${command}-${location.finding ?? ''}-${location.participant ?? ''}`}
                                    >
                                        {shortCommand && (
                                            <DrillRecordCommandForm
                                                detail={d}
                                                pickers={pickers}
                                                command={
                                                    command as DrillRecordCommand
                                                }
                                                findingId={location.finding}
                                                participantId={
                                                    location.participant
                                                }
                                                context={{
                                                    return_to:
                                                        location.returnTo,
                                                    section,
                                                    action: command,
                                                    ...(location.participant
                                                        ? {
                                                              participant:
                                                                  location.participant,
                                                          }
                                                        : {}),
                                                }}
                                                onSaved={saved}
                                                onCancel={cancel}
                                            />
                                        )}
                                        {(command === 'edit' ||
                                            command === 'schedule-next') && (
                                            <DrillPlanningForm
                                                detail={d}
                                                pickers={pickers}
                                                mode={command}
                                                returnTo={location.returnTo}
                                                onSaved={saved}
                                                onCancel={cancel}
                                            />
                                        )}
                                        {command === 'complete' && (
                                            <DrillCompletionForm
                                                detail={d}
                                                pickers={pickers}
                                                returnTo={location.returnTo}
                                                onSaved={saved}
                                                onCancel={cancel}
                                            />
                                        )}
                                        {command === 'corrective-action' &&
                                            (finding ? (
                                                <DrillRemedyForm
                                                    detail={d}
                                                    finding={finding}
                                                    returnTo={location.returnTo}
                                                    onSaved={saved}
                                                    onCancel={cancel}
                                                />
                                            ) : (
                                                <p role="alert">
                                                    The selected finding is not
                                                    available in this drill.
                                                </p>
                                            ))}
                                    </div>
                                ) : (
                                    <>
                                        {section === 'overview' && (
                                            <DrillOverview detail={d} />
                                        )}
                                        {section === 'run' && (
                                            <DrillRunFacts detail={d} />
                                        )}
                                        {section === 'participants' && (
                                            <DrillParticipants
                                                detail={d}
                                                move={move}
                                            />
                                        )}
                                        {section === 'findings' && (
                                            <DrillFindings
                                                detail={d}
                                                selected={location.finding}
                                                move={move}
                                            />
                                        )}
                                        {section === 'evidence' && (
                                            <DrillEvidence
                                                detail={d}
                                                returnTo={location.returnTo}
                                            />
                                        )}
                                        {section === 'history' && (
                                            <DrillHistory detail={d} />
                                        )}
                                        {section === 'connections' && (
                                            <DrillConnections
                                                detail={d}
                                                move={move}
                                            />
                                        )}
                                    </>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    );
}
