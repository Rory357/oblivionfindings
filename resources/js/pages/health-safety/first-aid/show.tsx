import {
    FIRST_AID_COMMAND_LABELS,
    FIRST_AID_COMMAND_SECTIONS,
    FirstAidCommandForm,
    firstAidCommandAllowed,
} from '@/components/health-safety/first-aid/command-form';
import {
    FirstAidConnections,
    FirstAidEvidence,
    FirstAidFollowups,
    FirstAidHistory,
    FirstAidIncident,
    FirstAidInjury,
    FirstAidOverview,
} from '@/components/health-safety/first-aid/record-panels';
import { FirstAidTreatmentWizard } from '@/components/health-safety/first-aid/treatment-wizard';
import type {
    FirstAidCommand,
    FirstAidPickers,
    FirstAidRecord,
    FirstAidSection,
} from '@/components/health-safety/first-aid/types';
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
import { formatDateLong, formatTime } from '@/lib/datetime';
import {
    FIRST_AID_REGISTER,
    firstAidHref,
    firstAidLocation,
    firstAidReturn,
} from '@/lib/health-safety/first-aid-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    ClipboardList,
    FileText,
    HeartPulse,
    History,
    Link2,
    Paperclip,
    Stethoscope,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { outcomeLabel } from './options';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'injury', label: 'Injury and treatment', icon: Stethoscope },
    { key: 'incident', label: 'Incident and review', icon: Activity },
    { key: 'followups', label: 'Follow-ups', icon: ClipboardList },
    { key: 'evidence', label: 'Evidence', icon: Paperclip },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;

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
    const openFollowups = d.followups.filter(
        (item) => !item.completed_at,
    ).length;
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
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
        if (!command && section === 'followups' && followup) {
            const selected = document.getElementById(`followup-${followup}`);
            selected?.scrollIntoView?.({ block: 'nearest' });
            selected?.focus();
        }
    }, [section, command, followup, attachment]);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'First aid', href: FIRST_AID_REGISTER },
                { title: reference, href: firstAidHref(d.id) },
            ]}
        >
            <Head title={`${reference} · First aid`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            frontline
                            wrapTitle
                            backHref={returnTo}
                            icon={HeartPulse}
                            title={reference}
                            titleChip={
                                <PageHeaderStatusChip variant="neutral">
                                    {d.status === 'archived'
                                        ? 'Archived'
                                        : 'Treatment recorded'}
                                </PageHeaderStatusChip>
                            }
                            subline={`${d.treated_person_name} · ${d.site?.name ?? 'Site not recorded'}`}
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Treatment time"
                                        href={href('overview')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.treatment_date
                                                ? formatTime(d.treatment_date)
                                                : 'Not recorded'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.treatment_date
                                                ? formatDateLong(
                                                      d.treatment_date,
                                                  )
                                                : 'Time unavailable'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Recorded outcome"
                                        href={href('injury')}
                                    >
                                        <PageHeaderMeterBig>
                                            {outcomeLabel(d.treatment_outcome)}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.ambulance_called
                                                ? 'Ambulance called'
                                                : 'Treatment details'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Open follow-ups"
                                        href={href('followups')}
                                    >
                                        <PageHeaderMeterBig>
                                            {openFollowups}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Work recorded on this treatment
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
                                            {
                                                d.attachments.filter(
                                                    (item) =>
                                                        item.availability ===
                                                        'available',
                                                ).length
                                            }{' '}
                                            available files
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={[...SECTIONS]}
                                    value={section}
                                    onSelect={(next) =>
                                        move(next as FirstAidSection)
                                    }
                                    ariaLabel="First-aid record sections"
                                />
                            }
                        />
                    }
                >
                    <div className="space-y-5">
                        <div
                            role="group"
                            className="flex flex-wrap gap-3"
                            aria-label="Treatment record actions"
                        >
                            {(
                                [
                                    'edit',
                                    'add-followup',
                                    'add-evidence',
                                    'link-incident',
                                    'archive',
                                ] as FirstAidCommand[]
                            )
                                .filter((action) =>
                                    firstAidCommandAllowed(d, action),
                                )
                                .map((action) => (
                                    <Button
                                        key={action}
                                        className="frontline-tap"
                                        variant={
                                            action === 'add-followup'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            move(
                                                FIRST_AID_COMMAND_SECTIONS[
                                                    action
                                                ],
                                                action,
                                            )
                                        }
                                    >
                                        {FIRST_AID_COMMAND_LABELS[action]}
                                    </Button>
                                ))}
                        </div>
                        <Card>
                            <CardContent className="p-5 sm:p-6">
                                <div
                                    ref={panel}
                                    tabIndex={-1}
                                    className="min-w-0 outline-none"
                                >
                                    {command && command !== 'edit' ? (
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
                                    ) : (
                                        <>
                                            {section === 'overview' && (
                                                <FirstAidOverview detail={d} />
                                            )}
                                            {section === 'injury' && (
                                                <FirstAidInjury detail={d} />
                                            )}
                                            {section === 'incident' && (
                                                <FirstAidIncident detail={d} />
                                            )}
                                            {section === 'followups' && (
                                                <FirstAidFollowups
                                                    detail={d}
                                                    selected={followup}
                                                    onEdit={(id) =>
                                                        move(
                                                            'followups',
                                                            'edit-followup',
                                                            id,
                                                        )
                                                    }
                                                    onComplete={(id) =>
                                                        move(
                                                            'followups',
                                                            'complete-followup',
                                                            id,
                                                        )
                                                    }
                                                />
                                            )}
                                            {section === 'evidence' && (
                                                <FirstAidEvidence
                                                    detail={d}
                                                    onRemove={(id) =>
                                                        move(
                                                            'evidence',
                                                            'remove-evidence',
                                                            id,
                                                        )
                                                    }
                                                />
                                            )}
                                            {section === 'history' && (
                                                <FirstAidHistory detail={d} />
                                            )}
                                            {section === 'connections' && (
                                                <FirstAidConnections
                                                    detail={d}
                                                />
                                            )}
                                        </>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
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
