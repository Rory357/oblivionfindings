import {
    EVENT_COMMAND_LABELS,
    eventCommandAllowed,
    RestraintEventCommandForm,
} from '@/components/health-safety/restraints/event-command-form';
import type {
    RestraintEventDetail,
    RestraintPickers,
    SourceFact,
    SourcePerson,
} from '@/components/health-safety/restraints/types';
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
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintLocation,
    restraintReturn,
    type RestraintEventCommand,
    type RestraintEventSection,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    ClipboardList,
    FileText,
    HeartPulse,
    History,
    Link2,
    MessageCircle,
    Paperclip,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'review', label: 'Review and owner', icon: ClipboardCheck },
    { key: 'monitoring', label: 'Monitoring', icon: HeartPulse },
    { key: 'debrief', label: 'Debrief', icon: MessageCircle },
    { key: 'followups', label: 'Follow-ups', icon: ClipboardList },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;
type Props = {
    detail: RestraintEventDetail;
    workspace: 'view' | 'review';
    pickers?: Partial<RestraintPickers>;
    return_to?: string | null;
};
const personName = (person: SourcePerson | null) =>
    person?.name ??
    (person
        ? 'Identity retained; current directory access unavailable'
        : 'Not recorded');
const when = (value: string | null | undefined) =>
    value ? formatDateTimeLong(value) : 'Not recorded';
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 border-b border-border py-3 sm:grid-cols-[12rem_minmax(0,1fr)]">
            <dt className="text-caption text-muted-foreground">{label}</dt>
            <dd className="text-subtle min-w-0 break-words whitespace-pre-wrap">
                {children ?? 'Not recorded'}
            </dd>
        </div>
    );
}
const sectionForCommand = (
    command: RestraintEventCommand,
): RestraintEventSection =>
    command.includes('observation')
        ? 'monitoring'
        : command.includes('debrief')
          ? 'debrief'
          : command.includes('followup')
            ? 'followups'
            : command.includes('evidence')
              ? 'evidence'
              : command === 'correct'
                ? 'overview'
                : 'review';

export default function RestraintEventShow(props: Props) {
    return <EventWorkspace key={props.detail.event.id} {...props} />;
}
function EventWorkspace({ detail, workspace, pickers, return_to }: Props) {
    const d = detail.event;
    const { url } = usePage();
    const location = restraintLocation(url, 'events');
    const section = location.section as RestraintEventSection;
    const command = location.command as RestraintEventCommand | null;
    const child = command?.includes('observation')
        ? location.observation
        : command?.includes('debrief')
          ? location.debrief
          : command?.includes('evidence')
            ? location.attachment
            : location.followup;
    const returnTo = return_to
        ? restraintReturn(return_to)
        : workspace === 'view'
          ? RESTRAINT_REGISTER
          : '/my-day';
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    const reference = d.reference_number ?? `RE-${d.id}`;
    const href = (
        next: RestraintEventSection,
        action: RestraintEventCommand | null = null,
        id?: number | null,
    ) =>
        restraintHref('events', d.id, {
            workspace: workspace as RestraintWorkspace,
            section: next,
            command: action,
            returnTo,
            child: id,
            childKey: action?.includes('observation')
                ? 'observation'
                : action?.includes('debrief')
                  ? 'debrief'
                  : action?.includes('evidence')
                    ? 'attachment'
                    : 'followup',
        });
    const replace = (
        next: RestraintEventSection,
        action: RestraintEventCommand | null = null,
        id?: number | null,
    ) => {
        interacted.current = true;
        router.replace({
            url: href(next, action, id),
            preserveState: true,
            preserveScroll: true,
        });
    };
    const move = (
        next: RestraintEventSection,
        action: RestraintEventCommand | null = null,
        id?: number | null,
    ) => drafts.request(() => replace(next, action, id));
    const act = (action: RestraintEventCommand, id?: number) =>
        move(sectionForCommand(action), action, id);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
        if (!command && location.followup) {
            const target = document.getElementById(
                `restraint-followup-${location.followup}`,
            );
            target?.focus();
            target?.scrollIntoView({ block: 'nearest' });
        }
    }, [command, section, location.followup]);
    const open = d.followups.filter(
        (item) => !item.completed_at && !item.cancelled_at,
    ).length;
    const button = (action: RestraintEventCommand, id?: number) =>
        eventCommandAllowed(detail, action, id) ? (
            <Button
                key={action}
                variant="outline"
                type="button"
                className="frontline-tap"
                onClick={() => act(action, id)}
            >
                {EVENT_COMMAND_LABELS[action]}
            </Button>
        ) : null;
    const history = (
        rows: SourceFact[],
        kind: 'review' | 'monitoring' | 'debrief' | 'history',
    ) =>
        rows.length ? (
            <ol className="space-y-5">
                {[...rows].reverse().map((item) => {
                    const superseded = rows.some(
                        (row) => row.supersedes_id === item.id,
                    );
                    return (
                        <li
                            key={item.id}
                            className="space-y-3 rounded-xl border border-border p-4"
                        >
                            <p className="text-subtle font-medium">
                                {when(item.occurred_at ?? item.created_at)}
                                {superseded ? ' · Earlier evidence' : ''}
                            </p>
                            <p className="text-caption text-muted-foreground">
                                Recorded by {personName(item.recorder)}
                                {item.actual_actor
                                    ? ' · Actual participant: ' +
                                      personName(item.actual_actor)
                                    : ''}{' '}
                                · Source revision {item.source_revision}
                            </p>
                            <dl>
                                {Object.entries(item.facts)
                                    .filter(
                                        ([, value]) =>
                                            typeof value === 'string' ||
                                            typeof value === 'number' ||
                                            Array.isArray(value),
                                    )
                                    .map(([key, value]) => (
                                        <Fact
                                            key={key}
                                            label={key.replaceAll('_', ' ')}
                                        >
                                            {Array.isArray(value)
                                                ? value.join(', ')
                                                : String(value)}
                                        </Fact>
                                    ))}
                            </dl>
                            {detail.can.review &&
                                !superseded &&
                                kind === 'monitoring' &&
                                button('correct-observation', item.id)}
                            {detail.can.review &&
                                !superseded &&
                                kind === 'debrief' &&
                                button('correct-debrief', item.id)}
                        </li>
                    );
                })}
            </ol>
        ) : (
            <p className="text-subtle text-muted-foreground">
                No{' '}
                {kind === 'monitoring'
                    ? 'monitoring observations'
                    : kind === 'debrief'
                      ? 'debrief evidence'
                      : kind === 'review'
                        ? 'review evidence'
                        : 'history'}{' '}
                recorded.
            </p>
        );
    const connections = {
        client: 'Person',
        incident: 'Incident',
        plan: 'Behaviour-support plan',
        plan_version: 'Plan version used',
        plan_approval: 'Approval recorded',
        stay: 'Respite stay',
        health_safety: 'H&S review',
        control_room: 'Control Room response',
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(workspace === 'view'
                    ? [
                          {
                              title: 'Restrictive interventions',
                              href: RESTRAINT_REGISTER,
                          },
                      ]
                    : []),
                { title: reference, href: href('overview') },
            ]}
        >
            <Head title={`${reference} · Restrictive intervention`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            frontline
                            wrapTitle
                            backHref={returnTo}
                            icon={ShieldAlert}
                            title={reference}
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={
                                        d.needs_review ? 'warning' : 'neutral'
                                    }
                                >
                                    {d.needs_review
                                        ? 'Review needed'
                                        : 'Review recorded'}
                                </PageHeaderStatusChip>
                            }
                            subline={`${d.client.name} · ${d.site?.name ?? 'Site not recorded'}`}
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Intervention"
                                        onClick={() => move('overview')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.restraint_type}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.severity} severity
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Review owner"
                                        onClick={() => move('review')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.review_owner_id?.name ??
                                                (d.review_owner_id
                                                    ? 'Identity retained'
                                                    : 'Unassigned')}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.review_due_at
                                                ? 'Due ' + when(d.review_due_at)
                                                : 'Due time not set'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Follow-ups"
                                        onClick={() => move('followups')}
                                    >
                                        <PageHeaderMeterBig>
                                            {open}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Open person-centred actions
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Evidence"
                                        onClick={() => move('evidence')}
                                    >
                                        <PageHeaderMeterBig>
                                            {
                                                d.attachments.filter(
                                                    (item) =>
                                                        item.availability ===
                                                        'available',
                                                ).length
                                            }
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Available private files
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={[...SECTIONS]}
                                    value={section}
                                    onSelect={(next) => move(next)}
                                    ariaLabel="Restrictive intervention sections"
                                />
                            }
                        />
                    }
                >
                    <div className="space-y-5">
                        <div
                            className="flex flex-wrap gap-3"
                            role="group"
                            aria-label="Restrictive intervention actions"
                        >
                            {button('review')}
                            {button('assign-review')}
                            {button('add-followup')}
                            {button('add-observation')}
                            {button('record-debrief')}
                            {button('add-evidence')}
                            {button('correct')}
                        </div>
                        <Card>
                            <CardContent className="p-5 sm:p-6">
                                <div
                                    ref={panel}
                                    tabIndex={-1}
                                    className="min-w-0 space-y-5 outline-none"
                                >
                                    {command ? (
                                        <RestraintEventCommandForm
                                            key={`${d.id}-${command}-${child ?? ''}`}
                                            detail={detail}
                                            command={command}
                                            childId={child}
                                            workspace={workspace}
                                            pickers={pickers}
                                            returnTo={returnTo}
                                            onCancel={() => move(section)}
                                            onDone={() =>
                                                router.get(
                                                    href(section),
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                        preserveState: false,
                                                    },
                                                )
                                            }
                                        />
                                    ) : (
                                        <>
                                            <h2 className="text-section-title">
                                                {
                                                    SECTIONS.find(
                                                        (item) =>
                                                            item.key ===
                                                            section,
                                                    )?.label
                                                }
                                            </h2>
                                            {section === 'overview' && (
                                                <>
                                                    <dl>
                                                        <Fact label="Actual start">
                                                            {when(d.started_at)}
                                                        </Fact>
                                                        <Fact label="Actual end">
                                                            {when(d.ended_at)}
                                                        </Fact>
                                                        <Fact label="Duration">
                                                            {d.duration_minutes ===
                                                            null
                                                                ? 'Not recorded'
                                                                : `${d.duration_minutes} minutes`}
                                                        </Fact>
                                                        <Fact label="What led to it">
                                                            {
                                                                d.trigger_description
                                                            }
                                                        </Fact>
                                                        <Fact label="Alternatives attempted">
                                                            {
                                                                d.de_escalation_attempted
                                                            }
                                                        </Fact>
                                                        <Fact label="What happened">
                                                            {
                                                                d.restraint_description
                                                            }
                                                        </Fact>
                                                        <Fact label="Person’s response">
                                                            {d.person_response}
                                                        </Fact>
                                                        <Fact label="Support afterwards">
                                                            {
                                                                d.post_incident_support
                                                            }
                                                        </Fact>
                                                        <Fact label="Injury">
                                                            {d.injury_occurred
                                                                ? d.injury_details ||
                                                                  'Injury recorded; detail unavailable'
                                                                : 'No injury recorded'}
                                                        </Fact>
                                                        <Fact label="Within support plan">
                                                            {d.within_support_plan
                                                                ? 'Reported within plan'
                                                                : 'Reported outside plan'}
                                                        </Fact>
                                                        <Fact label="Recorded authorization">
                                                            {d
                                                                .plan_authorization
                                                                .explanation ??
                                                                d.plan_authorization.state.replaceAll(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                        </Fact>
                                                        <Fact label="Reason for difference">
                                                            {d.deviation_reason}
                                                        </Fact>
                                                        <Fact label="Staff involved">
                                                            {d.staff_involved
                                                                .map((item) =>
                                                                    'kind' in
                                                                    item
                                                                        ? item.text
                                                                        : personName(
                                                                              item,
                                                                          ),
                                                                )
                                                                .join(', ') ||
                                                                'Not recorded'}
                                                        </Fact>
                                                        <Fact label="Authorised by">
                                                            {personName(
                                                                d.authorised_by,
                                                            )}
                                                        </Fact>
                                                    </dl>
                                                    {d.source_provenance ===
                                                        'legacy_uncertainty' && (
                                                        <p className="text-subtle text-status-warning">
                                                            This is a historical
                                                            record. Earlier
                                                            approval and source
                                                            details have not
                                                            been reconstructed
                                                            as new facts.
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                            {section === 'review' && (
                                                <>
                                                    <dl>
                                                        <Fact label="Named owner">
                                                            {personName(
                                                                d.review_owner_id,
                                                            )}
                                                        </Fact>
                                                        <Fact label="Review due">
                                                            {when(
                                                                d.review_due_at,
                                                            )}
                                                        </Fact>
                                                        <Fact label="First review">
                                                            {when(
                                                                d.reviewed_at,
                                                            )}
                                                        </Fact>
                                                        <Fact label="Reviewed source">
                                                            {d.reviewed_source_revision ===
                                                            null
                                                                ? 'Not recorded'
                                                                : `Revision ${d.reviewed_source_revision} of current revision ${d.source_revision}`}
                                                        </Fact>
                                                    </dl>
                                                    {history(
                                                        d.reviews,
                                                        'review',
                                                    )}
                                                    <div className="flex flex-wrap gap-3">
                                                        {detail.connections
                                                            .incident
                                                            ? button(
                                                                  'unlink-incident',
                                                              )
                                                            : button(
                                                                  'link-incident',
                                                              )}
                                                    </div>
                                                </>
                                            )}
                                            {section === 'monitoring' &&
                                                history(
                                                    d.observations,
                                                    'monitoring',
                                                )}
                                            {section === 'debrief' &&
                                                history(d.debriefs, 'debrief')}
                                            {section === 'followups' &&
                                                (d.followups.length ? (
                                                    <ul className="space-y-4">
                                                        {d.followups.map(
                                                            (item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    id={`restraint-followup-${item.id}`}
                                                                    tabIndex={
                                                                        -1
                                                                    }
                                                                    className="space-y-3 rounded-xl border border-border p-4 outline-none"
                                                                >
                                                                    <p className="text-subtle whitespace-pre-wrap">
                                                                        {
                                                                            item.notes
                                                                        }
                                                                    </p>
                                                                    <p className="text-caption text-muted-foreground">
                                                                        Owner:{' '}
                                                                        {personName(
                                                                            item.assignee,
                                                                        )}{' '}
                                                                        ·{' '}
                                                                        {item.due_at
                                                                            ? 'Due ' +
                                                                              when(
                                                                                  item.due_at,
                                                                              )
                                                                            : 'Due time not set'}
                                                                    </p>
                                                                    <p className="text-subtle">
                                                                        {item.cancelled_at
                                                                            ? 'Cancelled: ' +
                                                                              item.cancellation_reason
                                                                            : item.completed_at
                                                                              ? `${item.completion_outcome?.outcome.replaceAll('_', ' ') ?? 'Outcome recorded'} · ${when(item.completed_at)}`
                                                                              : 'Open'}
                                                                    </p>
                                                                    {item
                                                                        .completion_outcome
                                                                        ?.notes && (
                                                                        <p className="text-subtle whitespace-pre-wrap">
                                                                            {
                                                                                item
                                                                                    .completion_outcome
                                                                                    .notes
                                                                            }
                                                                        </p>
                                                                    )}
                                                                    <div className="flex flex-wrap gap-3">
                                                                        {button(
                                                                            'complete-followup',
                                                                            item.id,
                                                                        )}
                                                                        {button(
                                                                            'reassign-followup',
                                                                            item.id,
                                                                        )}
                                                                        {button(
                                                                            'correct-followup',
                                                                            item.id,
                                                                        )}
                                                                        {button(
                                                                            'cancel-followup',
                                                                            item.id,
                                                                        )}
                                                                    </div>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                ) : (
                                                    <p className="text-subtle text-muted-foreground">
                                                        No follow-ups recorded.
                                                    </p>
                                                ))}
                                            {section === 'evidence' &&
                                                (d.attachments.length ? (
                                                    <ul className="space-y-4">
                                                        {d.attachments.map(
                                                            (item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="space-y-3 rounded-xl border border-border p-4"
                                                                >
                                                                    <p className="text-subtle font-medium break-words">
                                                                        {
                                                                            item.original_name
                                                                        }
                                                                    </p>
                                                                    <p className="text-caption text-muted-foreground">
                                                                        {item.category?.replaceAll(
                                                                            '_',
                                                                            ' ',
                                                                        ) ??
                                                                            'Evidence'}{' '}
                                                                        ·{' '}
                                                                        {
                                                                            item.availability
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {when(
                                                                            item.created_at,
                                                                        )}
                                                                    </p>
                                                                    {item.notes && (
                                                                        <p className="text-subtle whitespace-pre-wrap">
                                                                            {
                                                                                item.notes
                                                                            }
                                                                        </p>
                                                                    )}
                                                                    {item.removal_reason && (
                                                                        <p className="text-subtle">
                                                                            Removal
                                                                            reason:{' '}
                                                                            {
                                                                                item.removal_reason
                                                                            }
                                                                        </p>
                                                                    )}
                                                                    <div className="flex flex-wrap gap-3">
                                                                        {item.download_url && (
                                                                            <Button
                                                                                asChild
                                                                                variant="outline"
                                                                                className="frontline-tap"
                                                                            >
                                                                                <a
                                                                                    href={
                                                                                        item.download_url
                                                                                    }
                                                                                >
                                                                                    Download
                                                                                    private
                                                                                    file
                                                                                </a>
                                                                            </Button>
                                                                        )}
                                                                        {button(
                                                                            'remove-evidence',
                                                                            item.id,
                                                                        )}
                                                                    </div>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                ) : (
                                                    <p className="text-subtle text-muted-foreground">
                                                        No evidence files
                                                        recorded.
                                                    </p>
                                                ))}
                                            {section === 'history' && (
                                                <>
                                                    {history(
                                                        d.reviews,
                                                        'history',
                                                    )}
                                                    <h3 className="text-section-title">
                                                        Delivery and reminders
                                                    </h3>
                                                    {d.deliveries.length ? (
                                                        <ul className="space-y-3">
                                                            {d.deliveries.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) => (
                                                                    <li
                                                                        key={
                                                                            index
                                                                        }
                                                                        className="text-subtle"
                                                                    >
                                                                        {item.purpose.replaceAll(
                                                                            '_',
                                                                            ' ',
                                                                        )}{' '}
                                                                        ·{' '}
                                                                        {item.status.replaceAll(
                                                                            '_',
                                                                            ' ',
                                                                        )}
                                                                        {item.delivered_at
                                                                            ? ' · ' +
                                                                              when(
                                                                                  item.delivered_at,
                                                                              )
                                                                            : ''}
                                                                        {item.failure_code
                                                                            ? ' · Needs delivery review'
                                                                            : ''}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    ) : (
                                                        <p className="text-subtle text-muted-foreground">
                                                            No delivery history
                                                            retained.
                                                        </p>
                                                    )}
                                                    <p className="text-caption text-muted-foreground">
                                                        Delivery confirms that
                                                        work was passed on. It
                                                        does not complete the
                                                        intervention review or
                                                        another record.
                                                    </p>
                                                </>
                                            )}
                                            {section === 'connections' && (
                                                <>
                                                    <ul className="space-y-3">
                                                        {Object.entries(
                                                            connections,
                                                        ).map(
                                                            ([key, label]) => {
                                                                const link =
                                                                    detail
                                                                        .connections[
                                                                        key as keyof typeof detail.connections
                                                                    ];
                                                                return link ? (
                                                                    <li
                                                                        key={
                                                                            key
                                                                        }
                                                                    >
                                                                        <Button
                                                                            asChild
                                                                            variant="outline"
                                                                            className="frontline-tap"
                                                                        >
                                                                            <Link
                                                                                href={`${link.href}${link.href.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(restraintHref('events', d.id, { workspace, section: 'connections' }))}`}
                                                                            >
                                                                                {
                                                                                    label
                                                                                }
                                                                            </Link>
                                                                        </Button>
                                                                    </li>
                                                                ) : (
                                                                    <li
                                                                        key={
                                                                            key
                                                                        }
                                                                        className="text-subtle text-muted-foreground"
                                                                    >
                                                                        {label}:
                                                                        no
                                                                        accessible
                                                                        connection
                                                                    </li>
                                                                );
                                                            },
                                                        )}
                                                    </ul>
                                                    <p className="text-subtle text-muted-foreground">
                                                        Each connected record
                                                        has its own owner,
                                                        permissions and
                                                        completion steps.
                                                    </p>
                                                </>
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
        </AppLayout>
    );
}
