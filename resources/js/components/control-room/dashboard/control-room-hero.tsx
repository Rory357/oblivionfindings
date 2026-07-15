import {
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
    fmt,
} from '@/components/command-centre/hero-kit';
import { CommandWorkflowRibbon } from '@/components/command-centre/workflow-ribbon';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import {
    Activity,
    BellPlus,
    ClipboardCheck,
    HeartPulse,
    ListTodo,
    RadioTower,
    ShieldAlert,
    Siren,
    UserRoundCheck,
} from 'lucide-react';

export type DeskHero = {
    active: number;
    critical: number;
    sla_breached: number;
    unassigned: number;
    oldest_open_at: string | null;
    last_24_hours: {
        alerts: number;
        resolved: number;
        avg_response_minutes: number | null;
    };
};

export type DeskHandover = {
    needs_incident: number;
    awaiting_health_safety: number;
    accepted_in_progress: number;
    operational_complete_governance_open: number;
};

export function ControlRoomHero({
    hero,
    handover,
    canCreate,
    canViewAnalytics,
    onOpenAnalytics,
}: {
    hero: DeskHero;
    handover: DeskHandover;
    canCreate: boolean;
    canViewAnalytics: boolean;
    onOpenAnalytics: () => void;
}) {
    return (
        <section data-desk-section="workflow">
            <HeroShell
                footer={
                    <HeroSummaryStrip label="Last 24 hours">
                        <HeroSummaryMetric tone="neutral">
                            {hero.last_24_hours.alerts} received
                        </HeroSummaryMetric>
                        <HeroSummaryMetric tone="success">
                            {hero.last_24_hours.resolved} resolved
                        </HeroSummaryMetric>
                        <HeroSummaryMetric tone="warning">
                            {hero.last_24_hours.avg_response_minutes === null
                                ? 'Response time unavailable'
                                : `${hero.last_24_hours.avg_response_minutes} min average response`}
                        </HeroSummaryMetric>
                        {canViewAnalytics ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={onOpenAnalytics}
                                className="ml-auto h-7 bg-primary-foreground/10 px-2.5 text-xs text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                Open analytics
                            </Button>
                        ) : null}
                    </HeroSummaryStrip>
                }
            >
                <CommandWorkflowRibbon
                    ariaLabel="Incident response workflow"
                    home={{
                        key: 'home',
                        label: 'Control Room',
                        href: '/control-room',
                        icon: RadioTower,
                    }}
                    current="detect"
                    steps={[
                        {
                            key: 'detect',
                            label: 'Detect & respond',
                            href: '/control-room',
                            icon: Siren,
                        },
                        {
                            key: 'record',
                            label: 'Incident record',
                            href: '/control-room/incidents',
                            icon: ShieldAlert,
                        },
                        {
                            key: 'govern',
                            label: 'H&S governance',
                            href: '/health-safety/events',
                            icon: HeartPulse,
                        },
                        {
                            key: 'complete',
                            label: 'Close & learn',
                            href: '/tasks',
                            icon: ClipboardCheck,
                        },
                    ]}
                />

                <div
                    data-desk-section="hero"
                    className="grid gap-6 xl:grid-cols-[minmax(320px,0.85fr)_minmax(620px,1.65fr)] xl:items-center"
                >
                    <div className="flex items-start gap-4">
                        <HeroMedallion icon={RadioTower} />
                        <div className="min-w-0 space-y-3">
                            <HeroStatusPill>
                                Live operational desk
                            </HeroStatusPill>
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
                                    Control Room Desk
                                </h1>
                                <p className="mt-1 max-w-xl text-sm text-primary-foreground/75">
                                    See what needs action now, keep ownership
                                    clear, and hand incidents into H&S without
                                    losing the story.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {canCreate ? (
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <Link href="/control-room/alerts?new=1">
                                            <BellPlus
                                                className="h-4 w-4"
                                                aria-hidden
                                            />
                                            New alert
                                        </Link>
                                    </Button>
                                ) : null}
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href="/control-room/alerts">
                                        Active alerts
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href="/control-room?assigned_to=me">
                                        <ListTodo
                                            className="h-4 w-4"
                                            aria-hidden
                                        />
                                        My queue
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 xl:grid-cols-2">
                        <HeroCluster title="Now" icon={Activity} columns={4}>
                            <HeroClusterTile
                                label="Active"
                                value={fmt(hero.active)}
                                caption="need an owner"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Critical"
                                value={fmt(hero.critical)}
                                caption="act first"
                                tone={
                                    hero.critical > 0 ? 'critical' : 'success'
                                }
                            />
                            <HeroClusterTile
                                label="SLA breached"
                                value={fmt(hero.sla_breached)}
                                caption="outside target"
                                tone={
                                    hero.sla_breached > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                label="Unassigned"
                                value={fmt(hero.unassigned)}
                                caption="claim or assign"
                                tone={
                                    hero.unassigned > 0 ? 'warning' : 'success'
                                }
                            />
                        </HeroCluster>
                        <HeroCluster
                            title="Continuity"
                            icon={UserRoundCheck}
                            columns={4}
                        >
                            <HeroClusterTile
                                href="/control-room/incidents"
                                label="Record"
                                value={fmt(handover.needs_incident)}
                                caption="incident needed"
                                tone={
                                    handover.needs_incident > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/health-safety/events"
                                label="H&S waiting"
                                value={fmt(handover.awaiting_health_safety)}
                                caption="accept handover"
                                tone={
                                    handover.awaiting_health_safety > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/health-safety/events"
                                label="In progress"
                                value={fmt(handover.accepted_in_progress)}
                                caption="H&S owner active"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-safety/events"
                                label="Governance"
                                value={fmt(
                                    handover.operational_complete_governance_open,
                                )}
                                caption="still open"
                                tone={
                                    handover.operational_complete_governance_open >
                                    0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                        </HeroCluster>
                    </div>
                </div>
            </HeroShell>
        </section>
    );
}
