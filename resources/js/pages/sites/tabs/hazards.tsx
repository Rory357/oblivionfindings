import {
    ApplicableProceduresPanel,
    type ApplicableProcedure,
} from '@/components/health-safety/applicable-procedures-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    SafetyEmpty,
    SafetyRegisterCard,
    SafetyRegisterHeader,
    formatRegisterDate,
    registerLabel,
} from './safety-register';

type HazardRow = {
    id: number;
    reference?: string | null;
    description: string;
    severity?: string | null;
    risk_rating?: string | number | null;
    status?: string | null;
    due_date?: string | null;
    review_date?: string | null;
    href: string;
};

export type SiteHazardsData = {
    locked?: boolean;
    items: HazardRow[];
    procedures: ApplicableProcedure[];
    can_create: boolean;
    can_manage: boolean;
    href: string;
};

export function SiteProfileHazards({ data }: { data: SiteHazardsData }) {
    return (
        <div className="space-y-5">
            <SafetyRegisterHeader
                title="Hazard register"
                description="All hazards recorded for this Site, including their live risk rating, review window, owner workflow and resolution state."
                href={data.href}
                actionLabel="Open full register"
                count={data.items.length}
            >
                {data.can_create ? (
                    <Button asChild size="sm">
                        <Link href={`${data.href}/create`}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            Report hazard
                        </Link>
                    </Button>
                ) : null}
            </SafetyRegisterHeader>

            <SafetyRegisterCard title="Site hazards">
                {data.items.length ? (
                    <div className="divide-y rounded-xl border">
                        {data.items.map((hazard) => (
                            <Link
                                key={hazard.id}
                                href={hazard.href}
                                className="grid gap-3 p-4 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:grid-cols-[minmax(0,1fr)_auto]"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {hazard.reference ??
                                                `HZ-${hazard.id}`}
                                        </span>
                                        <Badge variant="outline">
                                            {registerLabel(hazard.status)}
                                        </Badge>
                                        <Badge variant="secondary">
                                            Risk{' '}
                                            {hazard.risk_rating ?? 'Not rated'}
                                        </Badge>
                                    </div>
                                    <p className="mt-2 font-medium">
                                        {hazard.description}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Severity{' '}
                                        {registerLabel(hazard.severity)}
                                    </p>
                                </div>
                                <div className="text-sm text-muted-foreground md:text-right">
                                    <div>
                                        Due{' '}
                                        {formatRegisterDate(hazard.due_date)}
                                    </div>
                                    <div>
                                        Review{' '}
                                        {formatRegisterDate(hazard.review_date)}
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <SafetyEmpty label="No hazards have been recorded for this Site." />
                )}
            </SafetyRegisterCard>

            <ApplicableProceduresPanel
                procedures={data.procedures}
                subtitle="Approved procedures that apply to this Site."
            />
        </div>
    );
}
