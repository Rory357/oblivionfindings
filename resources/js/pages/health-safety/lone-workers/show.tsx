import {
    SESSION_COMMAND_LABELS,
    SESSION_COMMAND_SECTIONS,
    sessionCommandAllowed,
    SessionCommandForm,
} from '@/components/health-safety/lone-workers/session-command-form';
import {
    deliveryLabel,
    observationLabel,
    SessionCheckIns,
    SessionConnections,
    SessionHistory,
    SessionLocation,
    SessionOverview,
    SessionResponse,
} from '@/components/health-safety/lone-workers/session-panels';
import type {
    SafetySession,
    SessionCommand,
    SessionPickers,
    SessionSection,
} from '@/components/health-safety/lone-workers/types';
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
    loneWorkerHref,
    loneWorkerReturn,
} from '@/lib/health-safety/lone-worker-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    FileText,
    History,
    Link2,
    MapPin,
    Radio,
    Siren,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const REGISTER = '/health-safety/lone-workers';
const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText },
    { key: 'check-ins', label: 'Check-ins', icon: ClipboardList },
    { key: 'response', label: 'Response', icon: Siren },
    { key: 'location', label: 'Location', icon: MapPin },
    { key: 'history', label: 'History', icon: History },
    { key: 'connections', label: 'Connected work', icon: Link2 },
] as const;

export default function LoneWorkerShow({
    detail: d,
    pickers,
    return_to: incomingReturnTo,
    section: initial = 'overview',
}: {
    detail: SafetySession;
    pickers: SessionPickers;
    return_to?: string | null;
    section?: SessionSection;
}) {
    const return_to = loneWorkerReturn(incomingReturnTo);
    const { url } = usePage();
    const [section, setSection] = useState<SessionSection>(initial);
    const [command, setCommand] = useState<SessionCommand | null>(null);
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    useEffect(() => {
        const params = new URL(url, 'https://session.invalid').searchParams;
        const key = params.get('section');
        setSection(
            SECTIONS.some((item) => item.key === key)
                ? (key as SessionSection)
                : 'overview',
        );
        const action = params.get('action');
        setCommand(
            action && Object.hasOwn(SESSION_COMMAND_LABELS, action)
                ? (action as SessionCommand)
                : null,
        );
    }, [url]);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
    }, [section, command]);
    const href = (next: SessionSection, action: SessionCommand | null = null) =>
        loneWorkerHref(d.id, {
            section: next,
            command: action,
            returnTo: return_to,
        });
    const replace = (
        next: SessionSection,
        action: SessionCommand | null = null,
    ) => {
        interacted.current = true;
        router.replace({
            url: href(next, action),
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setSection(next);
                setCommand(action);
            },
        });
    };
    const move = (next: SessionSection, action: SessionCommand | null = null) =>
        drafts.request(() => replace(next, action));
    const status =
        d.status === 'emergency'
            ? 'Help requested'
            : d.status === 'completed'
              ? 'Ended'
              : d.is_check_in_overdue
                ? 'Checkpoint overdue'
                : 'Session active';
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Lone-worker safety', href: REGISTER },
                { title: d.reference, href: `${REGISTER}/sessions/${d.id}` },
            ]}
        >
            <Head title={`${d.reference} · Lone-worker safety`} />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            frontline
                            wrapTitle
                            backHref={return_to}
                            icon={Radio}
                            title={d.reference}
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={
                                        d.status === 'emergency'
                                            ? 'critical'
                                            : d.is_check_in_overdue
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                >
                                    {status}
                                </PageHeaderStatusChip>
                            }
                            subline={`${d.worker?.name ?? 'Worker not recorded'} · ${d.site?.name ?? 'Site not recorded'}`}
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Last received"
                                        href={href('check-ins')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.observation.received_at
                                                ? formatTime(
                                                      d.observation.received_at,
                                                  )
                                                : 'Not recorded'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {d.observation.received_at
                                                ? formatDateLong(
                                                      d.observation.received_at,
                                                  )
                                                : 'No receipt confirmed'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Next checkpoint"
                                        href={href('check-ins')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.ended_at
                                                ? 'Ended'
                                                : d.next_check_in_at
                                                  ? formatTime(
                                                        d.next_check_in_at,
                                                    )
                                                  : 'Unknown'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {!d.ended_at && d.next_check_in_at
                                                ? formatDateLong(
                                                      d.next_check_in_at,
                                                  )
                                                : 'Session schedule'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Check-in evidence"
                                        href={href('check-ins')}
                                    >
                                        <PageHeaderMeterBig>
                                            {d.check_ins.length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {observationLabel(
                                                d.observation.state,
                                            )}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Help request"
                                        href={href('response')}
                                    >
                                        <PageHeaderMeterBig>
                                            {deliveryLabel(d.source_delivery)}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Delivery is separate from response
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    items={[...SECTIONS]}
                                    value={section}
                                    onSelect={(next) =>
                                        move(next as SessionSection)
                                    }
                                    ariaLabel="Safety session sections"
                                />
                            }
                        />
                    }
                >
                    <div className="space-y-5">
                        <div
                            role="group"
                            className="flex flex-wrap gap-3"
                            aria-label="Safety session actions"
                        >
                            {(
                                Object.keys(
                                    SESSION_COMMAND_LABELS,
                                ) as SessionCommand[]
                            )
                                .filter((action) =>
                                    sessionCommandAllowed(d, action),
                                )
                                .map((action) => (
                                    <Button
                                        key={action}
                                        className="frontline-tap"
                                        variant={
                                            action === 'check-in'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            move(
                                                SESSION_COMMAND_SECTIONS[
                                                    action
                                                ],
                                                action,
                                            )
                                        }
                                    >
                                        {SESSION_COMMAND_LABELS[action]}
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
                                    {command ? (
                                        <SessionCommandForm
                                            key={`${d.id}-${command}`}
                                            detail={d}
                                            pickers={pickers}
                                            command={command}
                                            returnTo={return_to}
                                            onSaved={() => replace(section)}
                                            onCancel={() => move(section)}
                                        />
                                    ) : (
                                        <>
                                            {section === 'overview' && (
                                                <SessionOverview detail={d} />
                                            )}
                                            {section === 'check-ins' && (
                                                <SessionCheckIns detail={d} />
                                            )}
                                            {section === 'response' && (
                                                <SessionResponse detail={d} />
                                            )}
                                            {section === 'location' && (
                                                <SessionLocation detail={d} />
                                            )}
                                            {section === 'history' && (
                                                <SessionHistory detail={d} />
                                            )}
                                            {section === 'connections' && (
                                                <SessionConnections
                                                    detail={d}
                                                />
                                            )}
                                        </>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                    {drafts.confirmation}
                </PageLayout>
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
