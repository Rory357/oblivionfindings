import {
    BehaviourSupportPlanCommandForm,
    PLAN_COMMAND_LABELS,
} from '@/components/health-safety/restraints/plan-command-form';
import type {
    PlanContent,
    PlanDetail,
    RestraintPickers,
} from '@/components/health-safety/restraints/types';
import { approvalModeLabel } from '@/components/health-safety/settings/approval-policy';
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
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintLocation,
    restraintReturn,
    type RestraintPlanCommand,
    type RestraintPlanSection,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    ClipboardCheck,
    FileText,
    GitBranch,
    History,
    Link2,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: BookOpen },
    { key: 'content', label: 'Plan content', icon: FileText },
    { key: 'review', label: 'Reviews', icon: ClipboardCheck },
    { key: 'approval', label: 'Approval', icon: ShieldCheck },
    { key: 'versions', label: 'Versions', icon: GitBranch },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;
type Props = {
    detail: PlanDetail;
    workspace: RestraintWorkspace;
    pickers?: Partial<RestraintPickers>;
    return_to?: string | null;
    section?: RestraintPlanSection;
};
const time = (value: string | null | undefined) =>
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
function Content({ value }: { value: PlanContent }) {
    const names = {
        title: 'Plan title',
        triggers: 'Known triggers',
        de_escalation_strategies: 'Alternatives and de-escalation',
        approved_interventions: 'Proposed interventions',
        prohibited_interventions: 'Interventions that must not be used',
        restrictive_practice_type: 'Restrictive practice type',
        notes: 'Notes',
    };
    return (
        <dl>
            {Object.entries(names).map(([key, label]) => (
                <Fact label={label} key={key}>
                    {value[key as keyof PlanContent] ?? 'Not recorded'}
                </Fact>
            ))}
            <Fact label="Developed date">
                {value.developed_at
                    ? formatDateOnly(value.developed_at)
                    : 'Not recorded'}
            </Fact>
            <Fact label="Next review date">
                {value.review_date
                    ? formatDateOnly(value.review_date)
                    : 'Not set'}
            </Fact>
        </dl>
    );
}

export default function BehaviourSupportPlanShow(props: Props) {
    return <PlanWorkspace key={props.detail.id} {...props} />;
}
function PlanWorkspace({
    detail: d,
    workspace,
    pickers,
    return_to,
    section: providedSection,
}: Props) {
    const { url } = usePage();
    const location = restraintLocation(url, 'plans');
    const section = (
        new URLSearchParams(url.split('?')[1]).has('section')
            ? location.section
            : location.approval
              ? 'approval'
              : location.version
                ? 'versions'
                : (providedSection ?? 'overview')
    ) as RestraintPlanSection;
    const command = location.command as RestraintPlanCommand | null;
    const returnTo = return_to
        ? restraintReturn(return_to)
        : d.can.view
          ? RESTRAINT_REGISTER + '?lens=plans'
          : '/my-day';
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    const reference = d.reference_number ?? `BSP-${d.id}`;
    const href = (
        next: RestraintPlanSection,
        action: RestraintPlanCommand | null = null,
        id?: number,
        key: 'version' | 'approval' = 'version',
        mode = workspace,
    ) =>
        restraintHref('plans', d.id, {
            workspace: mode,
            section: next,
            command: action,
            returnTo,
            child: id,
            childKey: key,
        });
    const replace = (
        next: RestraintPlanSection,
        action: RestraintPlanCommand | null = null,
        id?: number,
        key: 'version' | 'approval' = 'version',
    ) => {
        interacted.current = true;
        router.replace({
            url: href(next, action, id, key),
            preserveState: true,
            preserveScroll: true,
        });
    };
    const move = (
        next: RestraintPlanSection,
        action: RestraintPlanCommand | null = null,
        id?: number,
        key: 'version' | 'approval' = 'version',
    ) => drafts.request(() => replace(next, action, id, key));
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
        if (!command && section === 'approval' && location.approval) {
            const target = document.getElementById(
                `plan-approval-${location.approval}`,
            );
            target?.focus();
            target?.scrollIntoView({ block: 'nearest' });
        }
    }, [section, command, location.approval]);
    const action = (key: RestraintPlanCommand) => {
        const canKey =
            key === 'decision'
                ? 'approve'
                : (key.replaceAll('-', '_') as keyof PlanDetail['can']);
        if (!d.can[canKey]) return null;
        const target =
            key === 'decision'
                ? 'approval'
                : key === 'review'
                  ? 'review'
                  : key === 'edit'
                    ? 'content'
                    : 'overview';
        const mode =
            key === 'decision'
                ? 'approval'
                : key === 'review'
                  ? 'review'
                  : workspace;
        return (
            <Button
                key={key}
                type="button"
                variant="outline"
                className="frontline-tap"
                onClick={() => {
                    if (mode !== workspace)
                        drafts.request(() =>
                            router.get(
                                href(target, key, undefined, 'version', mode),
                            ),
                        );
                    else move(target, key);
                }}
            >
                {PLAN_COMMAND_LABELS[key]}
            </Button>
        );
    };
    const selected = d.selected_version;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(d.can.view
                    ? [
                          {
                              title: 'Behaviour-support plans',
                              href: RESTRAINT_REGISTER + '?lens=plans',
                          },
                      ]
                    : []),
                { title: reference, href: href('overview') },
            ]}
        >
            <Head title={`${reference} · Behaviour-support plan`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            frontline
                            wrapTitle
                            backHref={returnTo}
                            icon={BookOpen}
                            title={d.title}
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={
                                        d.status === 'active'
                                            ? 'neutral'
                                            : d.status === 'under_review'
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                >
                                    {d.status.replaceAll('_', ' ')}
                                </PageHeaderStatusChip>
                            }
                            subline={`${reference} · ${d.client.name} · ${d.site?.name ?? 'Site not recorded'}`}
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Current candidate"
                                        onClick={() => move('content')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.candidate
                                                ? 'Version ' +
                                                  d.candidate.sequence
                                                : 'Not retained'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Review the exact proposed content
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Published version"
                                        onClick={() => move('versions')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.published_version
                                                ? 'Version ' +
                                                  d.published_version.sequence
                                                : 'Not retained'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.legacy_approval_unknown
                                                ? 'Approval provenance unknown'
                                                : 'Recorded publication'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Review due"
                                        onClick={() => move('review')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.content.review_date
                                                ? formatDateOnly(
                                                      d.content.review_date,
                                                  )
                                                : 'Not set'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.reviews.length} retained reviews
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Connected interventions"
                                        onClick={() => move('connections')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.connections.events.length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Accessible source records
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={[...SECTIONS]}
                                    value={section}
                                    onSelect={(next) => move(next)}
                                    ariaLabel="Behaviour-support plan sections"
                                />
                            }
                        />
                    }
                >
                    <div className="space-y-5">
                        <div
                            className="flex flex-wrap gap-3"
                            role="group"
                            aria-label="Plan actions"
                        >
                            {action('edit')}
                            {action('submit-review')}
                            {action('review')}
                            {action('decision')}
                            {action('activate')}
                            {action('archive')}
                        </div>
                        <Card>
                            <CardContent className="p-5 sm:p-6">
                                <div
                                    ref={panel}
                                    tabIndex={-1}
                                    className="min-w-0 space-y-5 outline-none"
                                >
                                    {command ? (
                                        <BehaviourSupportPlanCommandForm
                                            key={`${d.id}-${command}`}
                                            detail={d}
                                            command={command}
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
                                                        <Fact label="Plan status">
                                                            {d.status.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Fact>
                                                        <Fact label="Recorded developer">
                                                            {d.developer
                                                                ?.name ??
                                                                (d.developer
                                                                    ? 'Identity retained; current directory access unavailable'
                                                                    : 'Not recorded')}
                                                        </Fact>
                                                        <Fact label="Current approval workflow">
                                                            {d.current_policy
                                                                ? approvalModeLabel(
                                                                      d
                                                                          .current_policy
                                                                          .mode,
                                                                  )
                                                                : 'Shown in the authorised review or approval workspace'}
                                                        </Fact>
                                                        <Fact label="Current publication">
                                                            {d.published_version
                                                                ? 'Version ' +
                                                                  d
                                                                      .published_version
                                                                      .sequence
                                                                : 'No retained publication provenance'}
                                                        </Fact>
                                                    </dl>
                                                    {d.legacy_approval_unknown && (
                                                        <p className="text-subtle text-status-warning">
                                                            Historical status
                                                            does not establish
                                                            current approval. No
                                                            missing specialist
                                                            decision has been
                                                            invented.
                                                        </p>
                                                    )}
                                                    <p className="text-subtle text-muted-foreground">
                                                        {d.approval_explanation}
                                                    </p>
                                                </>
                                            )}
                                            {section === 'content' && (
                                                <>
                                                    {d.candidate &&
                                                        !d.candidate
                                                            .integrity_valid && (
                                                            <p
                                                                role="alert"
                                                                className="text-subtle text-status-critical"
                                                            >
                                                                The retained
                                                                candidate needs
                                                                an integrity
                                                                review before
                                                                signoff.
                                                            </p>
                                                        )}
                                                    <Content
                                                        value={
                                                            d.candidate
                                                                ?.content ??
                                                            d.content
                                                        }
                                                    />
                                                </>
                                            )}
                                            {section === 'review' &&
                                                (d.reviews.length ? (
                                                    <ol className="space-y-4">
                                                        {[...d.reviews]
                                                            .reverse()
                                                            .map((item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="space-y-3 rounded-xl border border-border p-4"
                                                                >
                                                                    <p className="text-subtle font-medium">
                                                                        {
                                                                            item.outcome
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {time(
                                                                            item.reviewed_at,
                                                                        )}
                                                                    </p>
                                                                    <p className="text-caption text-muted-foreground">
                                                                        Actual
                                                                        reviewer:{' '}
                                                                        {item
                                                                            .reviewer
                                                                            ?.name ??
                                                                            'Identity not available'}{' '}
                                                                        ·{' '}
                                                                        {item.plan_version_id
                                                                            ? 'Candidate ' +
                                                                              item.plan_version_id
                                                                            : 'Version not retained'}
                                                                    </p>
                                                                    {item.notes && (
                                                                        <p className="text-subtle whitespace-pre-wrap">
                                                                            {
                                                                                item.notes
                                                                            }
                                                                        </p>
                                                                    )}
                                                                    <p className="text-subtle">
                                                                        Recorded
                                                                        status:{' '}
                                                                        {item.resulting_status?.replaceAll(
                                                                            '_',
                                                                            ' ',
                                                                        ) ??
                                                                            'Unchanged'}
                                                                        {item.requested_resulting_status &&
                                                                        item.requested_resulting_status !==
                                                                            item.resulting_status
                                                                            ? ' · Requested: ' +
                                                                              item.requested_resulting_status.replaceAll(
                                                                                  '_',
                                                                                  ' ',
                                                                              )
                                                                            : ''}
                                                                    </p>
                                                                    {item.approval_disposition && (
                                                                        <p className="text-caption text-muted-foreground">
                                                                            {item.approval_disposition.replaceAll(
                                                                                '_',
                                                                                ' ',
                                                                            )}
                                                                        </p>
                                                                    )}
                                                                </li>
                                                            ))}
                                                    </ol>
                                                ) : (
                                                    <p className="text-subtle text-muted-foreground">
                                                        No reviews recorded.
                                                    </p>
                                                ))}
                                            {section === 'approval' && (
                                                <>
                                                    {d.current_policy && (
                                                        <p className="text-subtle">
                                                            {approvalModeLabel(
                                                                d.current_policy
                                                                    .mode,
                                                            )}
                                                        </p>
                                                    )}
                                                    {d.approvals.length ? (
                                                        <ol className="space-y-4">
                                                            {[...d.approvals]
                                                                .reverse()
                                                                .map((item) => (
                                                                    <li
                                                                        key={
                                                                            item.id
                                                                        }
                                                                        className="space-y-3 rounded-xl border border-border p-4"
                                                                        tabIndex={
                                                                            -1
                                                                        }
                                                                        id={`plan-approval-${item.id}`}
                                                                    >
                                                                        <p className="text-subtle font-medium">
                                                                            {
                                                                                item.decision
                                                                            }{' '}
                                                                            ·{' '}
                                                                            {time(
                                                                                item.approved_at,
                                                                            )}
                                                                        </p>
                                                                        <dl>
                                                                            <Fact label="Exact candidate">
                                                                                {
                                                                                    item.plan_version_id
                                                                                }
                                                                            </Fact>
                                                                            <Fact label="Recorded approver">
                                                                                {item
                                                                                    .approver
                                                                                    ?.name ??
                                                                                    'Protected identity'}
                                                                            </Fact>
                                                                            <Fact label="Decision origin">
                                                                                {item.origin.replaceAll(
                                                                                    '_',
                                                                                    ' ',
                                                                                )}
                                                                            </Fact>
                                                                            <Fact label="Workflow at decision">
                                                                                {item.applied_policy
                                                                                    ? approvalModeLabel(
                                                                                          item
                                                                                              .applied_policy
                                                                                              .mode,
                                                                                      )
                                                                                    : 'Not retained'}
                                                                            </Fact>
                                                                            <Fact label="Effective from">
                                                                                {time(
                                                                                    item.effective_from,
                                                                                )}
                                                                            </Fact>
                                                                            <Fact label="Effective until">
                                                                                {item.effective_to
                                                                                    ? time(
                                                                                          item.effective_to,
                                                                                      )
                                                                                    : 'No end date recorded'}
                                                                            </Fact>
                                                                            {item.basis && (
                                                                                <Fact label="Decision basis">
                                                                                    {
                                                                                        item.basis
                                                                                    }
                                                                                </Fact>
                                                                            )}
                                                                        </dl>
                                                                        <ul className="space-y-3">
                                                                            {item.evidence.map(
                                                                                (
                                                                                    evidence,
                                                                                    index,
                                                                                ) => (
                                                                                    <li
                                                                                        key={
                                                                                            index
                                                                                        }
                                                                                    >
                                                                                        {evidence.href ? (
                                                                                            <Button
                                                                                                asChild
                                                                                                variant="outline"
                                                                                                className="frontline-tap"
                                                                                            >
                                                                                                <a
                                                                                                    href={
                                                                                                        evidence.href
                                                                                                    }
                                                                                                >
                                                                                                    {evidence.label ??
                                                                                                        'Open decision evidence'}
                                                                                                </a>
                                                                                            </Button>
                                                                                        ) : (
                                                                                            <p className="text-caption text-muted-foreground">
                                                                                                {evidence.label ??
                                                                                                    'Evidence identity concealed'}{' '}
                                                                                                ·{' '}
                                                                                                {evidence.availability ??
                                                                                                    'Unavailable'}
                                                                                            </p>
                                                                                        )}
                                                                                    </li>
                                                                                ),
                                                                            )}
                                                                        </ul>
                                                                    </li>
                                                                ))}
                                                        </ol>
                                                    ) : (
                                                        <p className="text-subtle text-muted-foreground">
                                                            No approval
                                                            decisions retained.
                                                        </p>
                                                    )}
                                                    <p className="text-caption text-muted-foreground">
                                                        A recorded decision does
                                                        not establish verified
                                                        professional competence,
                                                        consent or medication
                                                        authority.
                                                    </p>
                                                </>
                                            )}
                                            {section === 'versions' && (
                                                <>
                                                    {d.versions.length ? (
                                                        <ul className="space-y-3">
                                                            {[...d.versions]
                                                                .reverse()
                                                                .map((item) => (
                                                                    <li
                                                                        key={
                                                                            item.id
                                                                        }
                                                                    >
                                                                        <Button
                                                                            variant="outline"
                                                                            className="frontline-tap"
                                                                            onClick={() =>
                                                                                drafts.request(
                                                                                    () =>
                                                                                        router.get(
                                                                                            href(
                                                                                                'versions',
                                                                                                null,
                                                                                                item.id,
                                                                                            ),
                                                                                        ),
                                                                                )
                                                                            }
                                                                        >
                                                                            Version{' '}
                                                                            {
                                                                                item.sequence
                                                                            }{' '}
                                                                            ·{' '}
                                                                            {
                                                                                item.kind
                                                                            }{' '}
                                                                            ·{' '}
                                                                            {time(
                                                                                item.recorded_at,
                                                                            )}
                                                                        </Button>
                                                                    </li>
                                                                ))}
                                                        </ul>
                                                    ) : (
                                                        <p className="text-subtle text-muted-foreground">
                                                            No immutable
                                                            versions retained.
                                                        </p>
                                                    )}
                                                    {selected && (
                                                        <div className="space-y-4">
                                                            <h3 className="text-section-title">
                                                                Version{' '}
                                                                {
                                                                    selected.sequence
                                                                }{' '}
                                                                ·{' '}
                                                                {selected.kind}
                                                            </h3>
                                                            <Content
                                                                value={
                                                                    selected.content
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                            {section === 'history' &&
                                                (d.history.length ? (
                                                    <ol className="space-y-3">
                                                        {[...d.history]
                                                            .reverse()
                                                            .map((item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    className="text-subtle"
                                                                >
                                                                    {item.command.replaceAll(
                                                                        '_',
                                                                        ' ',
                                                                    )}{' '}
                                                                    · source
                                                                    version{' '}
                                                                    {
                                                                        item.version
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {time(
                                                                        item.recorded_at,
                                                                    )}{' '}
                                                                    ·{' '}
                                                                    {item.actor
                                                                        ?.name ??
                                                                        'Identity retained'}
                                                                </li>
                                                            ))}
                                                    </ol>
                                                ) : (
                                                    <p className="text-subtle text-muted-foreground">
                                                        No command history
                                                        retained.
                                                    </p>
                                                ))}
                                            {section === 'connections' && (
                                                <>
                                                    <ul className="space-y-3">
                                                        {Object.entries({
                                                            client: 'Person',
                                                            site: 'Site',
                                                            stay: 'Respite stay',
                                                        }).map(
                                                            ([key, label]) => {
                                                                const item =
                                                                    d
                                                                        .connections[
                                                                        key as
                                                                            | 'client'
                                                                            | 'site'
                                                                            | 'stay'
                                                                    ];
                                                                return item ? (
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
                                                                                href={
                                                                                    item.href
                                                                                }
                                                                            >
                                                                                {
                                                                                    label
                                                                                }
                                                                            </Link>
                                                                        </Button>
                                                                    </li>
                                                                ) : null;
                                                            },
                                                        )}
                                                        {d.connections.events.map(
                                                            (item) => (
                                                                <li
                                                                    key={
                                                                        item.id
                                                                    }
                                                                >
                                                                    <Button
                                                                        asChild
                                                                        variant="outline"
                                                                        className="frontline-tap"
                                                                    >
                                                                        <Link
                                                                            href={`${item.href}${item.href.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(restraintHref('plans', d.id, { workspace, section: 'connections' }))}`}
                                                                        >
                                                                            Intervention{' '}
                                                                            {
                                                                                item.id
                                                                            }
                                                                        </Link>
                                                                    </Button>
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                    <p className="text-subtle text-muted-foreground">
                                                        Interventions keep their
                                                        own review owners,
                                                        follow-ups and
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
