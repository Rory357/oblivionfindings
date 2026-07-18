import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { CheckCircle2, Gauge } from 'lucide-react';
import type { SiteReadinessData } from '../show';

const READINESS_TABS: Record<string, string> = {
    add_phone: 'contacts',
    add_email: 'contacts',
    assign_lead: 'contacts',
    add_after_hours: 'contacts',
    add_contact: 'contacts',
    add_emergency_plan: 'emergency_plan',
    add_med_storage: 'emergency_plan',
    upload_doc: 'documents',
    configure_rooms: 'plan',
    review_hazards: 'hazards',
    schedule_checklist: 'checklists',
    configure_geofence: 'assets',
};

export function SiteProfileReadiness({
    readiness,
    onNavigate,
}: {
    readiness: SiteReadinessData;
    onNavigate: (tab: string) => void;
}) {
    const groups = [
        { title: 'Critical setup', items: readiness.critical },
        { title: 'Recommended setup', items: readiness.recommended },
    ];
    return (
        <div className="grid gap-4 lg:grid-cols-[240px_1fr]">
            <Card>
                <CardContent className="flex min-h-52 flex-col items-center justify-center text-center">
                    <Gauge className="h-8 w-8 text-primary" />
                    <p className="mt-3 text-4xl font-bold tabular-nums">
                        {readiness.score}%
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Site readiness
                    </p>
                </CardContent>
            </Card>
            <div className="space-y-4">
                {groups.map((group) => (
                    <Card key={group.title}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {group.title}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y">
                            {group.items.map((item) => (
                                <div
                                    key={item.key}
                                    className="flex min-h-11 items-center gap-3 py-2.5"
                                >
                                    <CheckCircle2
                                        aria-label={
                                            item.done
                                                ? 'Complete'
                                                : 'Incomplete'
                                        }
                                        className={cn(
                                            'h-4 w-4 shrink-0',
                                            item.done
                                                ? 'text-status-success'
                                                : 'text-muted-foreground',
                                        )}
                                    />
                                    <span className="min-w-0 flex-1 text-sm">
                                        {item.label}
                                    </span>
                                    {!item.done ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                onNavigate(
                                                    READINESS_TABS[
                                                        item.action
                                                    ] ?? 'overview',
                                                )
                                            }
                                        >
                                            Fix
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}
