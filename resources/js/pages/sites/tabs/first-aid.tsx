import { Badge } from '@/components/ui/badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    SafetyEmpty,
    SafetyRegisterCard,
    SafetyRegisterHeader,
    registerLabel,
} from './safety-register';

type FirstAidRow = {
    id: number;
    reference?: string | null;
    treatment_date?: string | null;
    person?: string | null;
    injury?: string | null;
    outcome?: string | null;
    ambulance_called: boolean;
    first_aider?: string | null;
    incident_reported: boolean;
    related_incident_id?: number | null;
    open_followups_count: number;
    href: string;
};

export type SiteFirstAidData = {
    locked?: boolean;
    items: FirstAidRow[];
    can_manage: boolean;
    href: string;
};

export function SiteProfileFirstAid({ data }: { data: SiteFirstAidData }) {
    return (
        <div className="space-y-5">
            <SafetyRegisterHeader
                title="First-aid register"
                description="Recorded treatment, ambulance calls, first-aider attribution and open follow-up work."
                href={data.href}
                actionLabel="Open first-aid register"
                count={data.items.length}
            />
            <SafetyRegisterCard title="Treatments and follow-ups">
                {data.items.length ? (
                    <div className="divide-y rounded-xl border">
                        {data.items.map((record) => (
                            <Link
                                key={record.id}
                                href={record.href}
                                className="frontline-tap frontline-focus block p-4 transition-colors hover:bg-muted/40"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="font-medium">
                                            {record.person ??
                                                'Person not recorded'}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {record.reference ??
                                                `FA-${record.id}`}{' '}
                                            ·{' '}
                                            {formatDateTimeLong(
                                                record.treatment_date,
                                                'Treatment time not recorded',
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {record.ambulance_called ? (
                                            <Badge variant="destructive">
                                                Ambulance called
                                            </Badge>
                                        ) : null}
                                        {record.open_followups_count > 0 ? (
                                            <Badge variant="secondary">
                                                {record.open_followups_count}{' '}
                                                open follow-up
                                            </Badge>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                                    <span>{registerLabel(record.injury)}</span>
                                    <span className="text-muted-foreground">
                                        {registerLabel(record.outcome)}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {record.first_aider
                                            ? `First aider: ${record.first_aider}`
                                            : 'First aider not recorded'}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <SafetyEmpty label="No first-aid treatments are recorded for this Site." />
                )}
            </SafetyRegisterCard>
        </div>
    );
}
