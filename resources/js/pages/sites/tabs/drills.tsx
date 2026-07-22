import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';
import {
    SafetyEmpty,
    SafetyRegisterCard,
    SafetyRegisterHeader,
    formatRegisterDate,
    registerLabel,
} from './safety-register';

type DrillRow = {
    id: number;
    title: string;
    type?: string | null;
    scheduled_at?: string | null;
    completed_at?: string | null;
    drill_status?: string | null;
    outcome?: string | null;
    participants: number;
    evacuation_time_seconds?: number | null;
    open_findings: number;
    href: string;
};

export type SiteDrillsData = {
    locked?: boolean;
    items: DrillRow[];
    can_manage: boolean;
    href: string;
};

export function SiteProfileDrills({ data }: { data: SiteDrillsData }) {
    return (
        <div className="space-y-5">
            <SafetyRegisterHeader
                title="Emergency drills"
                description="Scheduled and completed Site drills with outcomes, participation, evacuation time and unresolved findings."
                href={data.href}
                actionLabel="Open drill register"
                count={data.items.length}
            />
            <SafetyRegisterCard title="Drill history and schedule">
                {data.items.length ? (
                    <div className="divide-y rounded-xl border">
                        {data.items.map((drill) => (
                            <Link
                                key={drill.id}
                                href={drill.href}
                                className="block p-4 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="font-medium">
                                            {drill.title}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {registerLabel(drill.type)} ·{' '}
                                            {formatRegisterDate(
                                                drill.scheduled_at,
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {registerLabel(drill.drill_status)}
                                        </Badge>
                                        {drill.open_findings > 0 ? (
                                            <Badge variant="destructive">
                                                {drill.open_findings} open{' '}
                                                {drill.open_findings === 1
                                                    ? 'finding'
                                                    : 'findings'}
                                            </Badge>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="mt-3 grid gap-2 text-sm text-muted-foreground sm:grid-cols-3">
                                    <span>
                                        {drill.participants} participants
                                    </span>
                                    <span>
                                        {drill.evacuation_time_seconds
                                            ? `${drill.evacuation_time_seconds}s evacuation`
                                            : 'Evacuation time not recorded'}
                                    </span>
                                    <span>
                                        {drill.outcome
                                            ? registerLabel(drill.outcome)
                                            : 'Outcome pending'}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <SafetyEmpty label="No emergency drills are recorded for this Site." />
                )}
            </SafetyRegisterCard>
        </div>
    );
}
