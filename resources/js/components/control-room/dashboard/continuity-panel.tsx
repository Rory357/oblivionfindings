import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CheckCircle2,
    ClipboardPlus,
    HeartPulse,
    ShieldCheck,
} from 'lucide-react';
import type { DeskHandover } from './control-room-hero';

const rows = [
    {
        key: 'needs_incident',
        label: 'Needs an incident record',
        help: 'Formalise the event before the operational detail is lost.',
        href: '/control-room/incidents',
        icon: ClipboardPlus,
    },
    {
        key: 'awaiting_health_safety',
        label: 'Waiting for H&S acceptance',
        help: 'A governance owner must accept the handover.',
        href: '/health-safety/events',
        icon: HeartPulse,
    },
    {
        key: 'accepted_in_progress',
        label: 'Accepted and in progress',
        help: 'H&S owns the governed follow-up.',
        href: '/health-safety/events',
        icon: ShieldCheck,
    },
    {
        key: 'operational_complete_governance_open',
        label: 'Response complete, governance open',
        help: 'Operational work is done; actions or review remain.',
        href: '/health-safety/events',
        icon: CheckCircle2,
    },
] as const;

export function ContinuityPanel({ handover }: { handover: DeskHandover }) {
    return (
        <Card data-desk-section="continuity" className="gap-4 py-5">
            <CardHeader className="gap-1 px-5">
                <CardTitle>Handover continuity</CardTitle>
                <CardDescription>
                    Nothing should disappear between Control Room, the incident
                    record, and H&S.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2 px-5">
                {rows.map((row) => {
                    const Icon = row.icon;
                    const count = handover[row.key];
                    return (
                        <Link
                            key={row.key}
                            href={row.href}
                            className="group flex items-start gap-3 rounded-xl border p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <Icon
                                    className="h-4 w-4 text-muted-foreground"
                                    aria-hidden
                                />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center justify-between gap-2 text-sm font-semibold">
                                    {row.label}
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums">
                                        {count}
                                    </span>
                                </span>
                                <span className="mt-0.5 block text-xs leading-5 text-muted-foreground">
                                    {row.help}
                                </span>
                            </span>
                            <ArrowRight
                                className="mt-2 h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                aria-hidden
                            />
                        </Link>
                    );
                })}
            </CardContent>
        </Card>
    );
}
