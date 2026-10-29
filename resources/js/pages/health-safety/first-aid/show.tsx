/**
 * One treatment record (approved H&S drawings pg-hsn-85, 85b–85g — Stephan 23
 * Oct 2026): one band on every section shows where the treatment is up to
 * (treated › recorded › incident and review › follow-ups › archived) and the
 * next follow-up on top. The command forms open in the main column as before;
 * Edit still opens the treatment wizard.
 */
import {
    FIRST_AID_COMMAND_LABELS,
    FIRST_AID_COMMAND_SECTIONS,
    FirstAidCommandForm,
    firstAidCommandAllowed,
} from '@/components/health-safety/first-aid/command-form';
import {
    firstAidDeliveryLabel,
    FirstAidArchiveRing,
    FirstAidConnections,
    FirstAidEvidence,
    FirstAidFollowups,
    FirstAidHistory,
    FirstAidIncident,
    FirstAidInjury,
    FirstAidOverview,
    FirstAidPeople,
    nextFollowup,
} from '@/components/health-safety/first-aid/record-panels';
import { FirstAidTreatmentWizard } from '@/components/health-safety/first-aid/treatment-wizard';
import type {
    FirstAidCommand,
    FirstAidPickers,
    FirstAidRecord,
    FirstAidSection,
} from '@/components/health-safety/first-aid/types';
import {
    dateTimeWithYear,
    plural,
} from '@/components/incidents/incident-stages';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderStages,
    type PageHeaderFact,
    type PageHeaderNextStep,
    type PageHeaderStage,
} from '@/components/page/page-header-strip';
import { SideColumn, Surface } from '@/components/signature/blocks';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime, toDateInput } from '@/lib/datetime';
import {
    FIRST_AID_REGISTER,
    firstAidHref,
    firstAidLocation,
    firstAidReturn,
} from '@/lib/health-safety/first-aid-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    CheckCircle2,
    HeartPulse,
    Link2,
    MoreHorizontal,
    Plus,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { outcomeLabel, outcomeTone } from './options';

const SECTIONS = [
    { key: 'overview', label: 'Overview' },
    { key: 'injury', label: 'Injury and treatment' },
    { key: 'incident', label: 'Incident and review' },
    { key: 'followups', label: 'Follow-ups' },
    { key: 'evidence', label: 'Evidence' },
    { key: 'history', label: 'History' },
    { key: 'connections', label: 'Linked records' },
] as const;
/** The drawing's header: Add evidence and Edit beside Add follow-up; the rest under More. */
const GLASS: FirstAidCommand[] = ['add-evidence', 'edit'];
const MORE: FirstAidCommand[] = ['link-incident', 'archive'];
const MORE_ICONS: Partial<Record<FirstAidCommand, typeof Link2>> = {
    'link-incident': Link2,
    archive: Archive,
};

type Props = {
    detail: FirstAidRecord;
    pickers: FirstAidPickers;
    return_to?: string | null;
};
export default function FirstAidShow(props: Props) {
    return <FirstAidWorkspace key={props.detail.id} {...props} />;
}
function FirstAidWorkspace({ detail: d, pickers, return_to }: Props) {
    const { url } = usePage();
    const { section, command, followup, attachment } = firstAidLocation(url);
    const returnTo = firstAidReturn(return_to);
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    const reference = d.reference ?? `FA-${d.id}`;
    const childId = command === 'remove-evidence' ? attachment : followup;
    const open = d.followups.filter((item) => !item.completed_at);
    const href = (
        next: FirstAidSection,
        action: FirstAidCommand | null = null,
        child?: number,
    ) =>
        firstAidHref(d.id, {
            section: next,
            command: action,
            returnTo,
            ...(action === 'remove-evidence'
                ? { attachment: child }
                : { followup: child }),
        });
    const replace = (
        next: FirstAidSection,
        action: FirstAidCommand | null = null,
        child?: number,
    ) => {
        interacted.current = true;
        router.replace({
            url: href(next, action, child),
            preserveState: true,
            preserveScroll: true,
        });
    };
    const move = (
        next: FirstAidSection,
        action: FirstAidCommand | null = null,
        child?: number,
    ) => drafts.request(() => replace(next, action, child));
    const run = (action: FirstAidCommand) =>
        move(FIRST_AID_COMMAND_SECTIONS[action], action);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
        if (!command && section === 'followups' && followup) {
            const selected = document.getElementById(`followup-${followup}`);
            selected?.scrollIntoView?.({ block: 'nearest' });
            selected?.focus();
        }
    }, [section, command, followup, attachment]);

    const archived = d.status === 'archived';
    const allowed = (
        ['add-followup', ...GLASS, ...MORE] as FirstAidCommand[]
    ).filter((action) => firstAidCommandAllowed(d, action));
    const glass = GLASS.filter((action) => allowed.includes(action));
    const more = MORE.filter((action) => allowed.includes(action));
    const primary = allowed.includes('add-followup') ? 'add-followup' : null;

    /* ---- facts ---- */
    const tone = outcomeTone(d.treatment_outcome);
    const facts: PageHeaderFact[] = [
        {
            value: outcomeLabel(d.treatment_outcome),
            label: 'Outcome',
            tone: tone === 'neutral' ? undefined : tone,
        },
        {
            value: d.treatment_date
                ? formatTime(d.treatment_date)
                : 'Not recorded',
            label: 'Treated at',
        },
        {
            value: open.length,
            label: 'Open follow-ups',
            tone: open.length ? 'warning' : undefined,
        },
    ];

    /* ---- the stage line and the next step ---- */
    const delivery = d.source_delivery;
    const incident = d.related_incident;
    const incidentRef = incident
        ? (incident.reference ??
          incident.reference_number ??
          `Incident report · Ref ${incident.id}`)
        : null;
    const incidentWaiting =
        d.incident_reported &&
        !incident &&
        d.incident_link_state !== 'unavailable';
    const today = toDateInput(new Date());
    const nextItem = nextFollowup(d);
    const overdue =
        !!nextItem?.due_at && toDateInput(nextItem.due_at) < today;
    const dueToday =
        !!nextItem?.due_at && toDateInput(nextItem.due_at) === today;
    const unowned = open.filter((item) => !item.assigned_to);
    const reached = [
        true,
        true,
        !incidentWaiting,
        open.length === 0,
        archived,
    ];
    const nowAt = reached.indexOf(false);
    const state = (index: number): PageHeaderStage['state'] =>
        nowAt === -1 || index < nowAt
            ? 'done'
            : index === nowAt
              ? 'now'
              : 'todo';
    const steps: PageHeaderStage[] = [
        {
            label: 'Treated',
            meta: d.first_aider?.name ?? 'First aider not recorded',
            state: state(0),
        },
        {
            label: 'Recorded',
            meta: d.created_at ? formatDate(d.created_at) : undefined,
            state: state(1),
        },
        {
            label: 'Incident and review',
            meta: incidentRef
                ? `${incidentRef}${delivery ? ` · ${firstAidDeliveryLabel(delivery.status).toLowerCase()}` : ''}`
                : d.incident_reported
                  ? d.incident_link_state === 'unavailable'
                      ? 'Incident reported · you don’t have access to it — ask your health and safety manager'
                      : 'Incident reported · not linked yet'
                  : 'No incident reported',
            state: state(2),
            tone: 'warning',
        },
        {
            label: 'Follow-ups',
            meta: open.length
                ? `${open.length} open`
                : d.followups.length
                  ? 'All done'
                  : 'None recorded',
            state: state(3),
            tone: overdue ? 'critical' : 'warning',
        },
        {
            label: 'Archived',
            meta: d.archived_at ? formatDate(d.archived_at) : undefined,
            state: state(4),
        },
    ];
    const next: PageHeaderNextStep | null = archived
        ? null
        : nextItem
          ? {
                label: overdue
                    ? 'Next step · overdue'
                    : nextItem.due_at
                      ? `Next step · due ${dueToday ? 'today' : formatDate(nextItem.due_at)}, ${formatTime(nextItem.due_at)}`
                      : 'Next step',
                text: nextItem.notes,
                meta: [
                    `${nextItem.assigned_to?.name ?? 'Needs an owner'} · Ref ${nextItem.id}`,
                    unowned.filter((item) => item.id !== nextItem.id).length
                        ? `${plural(unowned.filter((item) => item.id !== nextItem.id).length, 'other follow-up')} still ${unowned.filter((item) => item.id !== nextItem.id).length === 1 ? 'needs' : 'need'} an owner`
                        : null,
                ]
                    .filter(Boolean)
                    .join(' · '),
                tone:
                    overdue || !nextItem.assigned_to
                        ? 'critical'
                        : dueToday
                          ? 'warning'
                          : undefined,
            }
          : incidentWaiting
            ? {
                  label: 'Incident and review',
                  text: 'An incident was reported but isn’t linked to this treatment yet',
                  meta: 'The incident still needs its own review — recording first aid doesn’t close it.',
                  tone: 'warning',
              }
            : null;

    const main =
        command && command !== 'edit' ? (
            <Surface aria-label={FIRST_AID_COMMAND_LABELS[command]}>
                <div className="p-5 sm:p-6">
                    <FirstAidCommandForm
                        key={`${d.id}-${command}-${childId ?? ''}`}
                        detail={d}
                        pickers={pickers}
                        command={command}
                        childId={childId}
                        returnTo={returnTo}
                        onSaved={() => replace(section)}
                        onCancel={() => move(section)}
                    />
                </div>
            </Surface>
        ) : section === 'injury' ? (
            <FirstAidInjury detail={d} />
        ) : section === 'incident' ? (
            <FirstAidIncident detail={d} />
        ) : section === 'followups' ? (
            <FirstAidFollowups
                detail={d}
                selected={followup}
                onEdit={(id) => move('followups', 'edit-followup', id)}
                onComplete={(id) => move('followups', 'complete-followup', id)}
            />
        ) : section === 'evidence' ? (
            <FirstAidEvidence
                detail={d}
                onRemove={(id) => move('evidence', 'remove-evidence', id)}
            />
        ) : section === 'history' ? (
            <FirstAidHistory detail={d} />
        ) : section === 'connections' ? (
            <FirstAidConnections detail={d} />
        ) : (
            <FirstAidOverview detail={d} />
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Safety registers', href: '/health-safety/registers' },
                { title: 'First aid', href: FIRST_AID_REGISTER },
                { title: `First aid — ${d.treated_person_name}`, href: firstAidHref(d.id) },
            ]}
        >
            <Head title={`First aid — ${d.treated_person_name} · ${reference}`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={returnTo}
                            icon={HeartPulse}
                            title={`First aid — ${d.treated_person_name}`}
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={archived ? 'neutral' : 'info'}
                                    icon={archived ? Archive : CheckCircle2}
                                >
                                    {archived
                                        ? 'Archived'
                                        : 'Treatment recorded'}
                                </PageHeaderStatusChip>
                            }
                            subline={[
                                d.site?.name ?? 'Site not recorded',
                                d.treatment_date
                                    ? dateTimeWithYear(d.treatment_date)
                                    : null,
                                reference,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                            actions={
                                allowed.length ? (
                                    <div
                                        role="group"
                                        aria-label="Treatment record actions"
                                        className="contents"
                                    >
                                        {glass.map((action) => (
                                            <PageHeaderGlassButton
                                                key={action}
                                                onClick={() => run(action)}
                                            >
                                                {FIRST_AID_COMMAND_LABELS[action]}
                                            </PageHeaderGlassButton>
                                        ))}
                                        {more.length ? (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <PageHeaderGlassButton
                                                        icon={MoreHorizontal}
                                                        aria-label="More treatment record actions"
                                                    />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {more.map((action) => {
                                                        const Icon =
                                                            MORE_ICONS[action] ??
                                                            Link2;
                                                        return (
                                                            <DropdownMenuItem
                                                                key={action}
                                                                onSelect={() =>
                                                                    run(action)
                                                                }
                                                            >
                                                                <Icon className="size-4" />
                                                                {
                                                                    FIRST_AID_COMMAND_LABELS[
                                                                        action
                                                                    ]
                                                                }
                                                            </DropdownMenuItem>
                                                        );
                                                    })}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        ) : null}
                                        {primary ? (
                                            <PageHeaderPrimaryButton
                                                icon={Plus}
                                                onClick={() => run(primary)}
                                            >
                                                {FIRST_AID_COMMAND_LABELS[primary]}
                                            </PageHeaderPrimaryButton>
                                        ) : null}
                                    </div>
                                ) : undefined
                            }
                            facts={<PageHeaderFacts items={facts} />}
                            strip={
                                <PageHeaderStages
                                    steps={steps}
                                    next={next}
                                    ariaLabel="Treatment record stage"
                                />
                            }
                            rail={
                                <PageHeaderRail<FirstAidSection>
                                    items={SECTIONS.map((item) => ({
                                        ...item,
                                        count:
                                            item.key === 'followups'
                                                ? open.length || undefined
                                                : item.key === 'evidence'
                                                  ? d.attachments.length ||
                                                    undefined
                                                  : undefined,
                                    }))}
                                    value={section}
                                    onSelect={(key) => move(key)}
                                    ariaLabel="First-aid record sections"
                                />
                            }
                        />
                    }
                >
                    <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                        <div
                            ref={panel}
                            tabIndex={-1}
                            className="grid min-w-0 gap-5 outline-none"
                        >
                            {main}
                        </div>
                        <aside
                            className="min-w-0"
                            aria-label="Treatment people and follow-ups"
                        >
                            <SideColumn>
                                {section === 'overview' && !command ? (
                                    <FirstAidArchiveRing detail={d} />
                                ) : null}
                                <FirstAidPeople detail={d} />
                            </SideColumn>
                        </aside>
                    </div>
                    {command === 'edit' && (
                        <FirstAidTreatmentWizard
                            detail={d}
                            pickers={pickers}
                            returnTo={returnTo}
                            onClose={() => move('overview')}
                            onSaved={() =>
                                router.get(
                                    href('overview'),
                                    {},
                                    {
                                        preserveState: false,
                                        preserveScroll: true,
                                    },
                                )
                            }
                        />
                    )}
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
