import { Badge } from '@/components/ui/badge';
import {
    SafetyEmpty,
    SafetyRegisterCard,
    SafetyRegisterHeader,
    formatRegisterDate,
    registerLabel,
} from './safety-register';
import { SiteProfileLockedState } from './site-profile-states';

type InspectionSchedule = {
    id: number;
    title: string;
    type?: string | null;
    frequency?: string | null;
    next_due_date?: string | null;
    assigned_to?: string | null;
    is_active: boolean;
    overdue: boolean;
};

type InspectionRecord = {
    id: number;
    schedule_title?: string | null;
    due_date?: string | null;
    completed_at?: string | null;
    completed_by?: string | null;
    result?: string | null;
    findings?: string | null;
    corrective_actions?: string | null;
    linked_hazard_id?: number | null;
};

export type SiteInspectionsData =
    | {
          locked: true;
          items: never[];
          summary: null;
          href: null;
      }
    | {
          locked?: false;
          schedules: InspectionSchedule[];
          records: InspectionRecord[];
          can_manage: boolean;
          href: string;
      };

export function SiteProfileInspections({
    data,
}: {
    data: SiteInspectionsData;
}) {
    if (data.locked) {
        return <SiteProfileLockedState label="Site inspections" />;
    }

    return (
        <div className="space-y-5">
            <SafetyRegisterHeader
                title="Site inspections"
                description="Recurring inspection schedules and their full completion history, including findings, corrective actions and linked hazards."
                href={data.href}
                actionLabel="Manage inspections"
                count={data.schedules.length}
            />
            <div className="grid gap-5 xl:grid-cols-2">
                <SafetyRegisterCard title="Inspection schedules">
                    {data.schedules.length ? (
                        <div className="divide-y rounded-xl border">
                            {data.schedules.map((schedule) => (
                                <div key={schedule.id} className="p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <div className="font-medium">
                                                {schedule.title}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {registerLabel(schedule.type)} ·{' '}
                                                {registerLabel(
                                                    schedule.frequency,
                                                )}
                                            </div>
                                        </div>
                                        <Badge
                                            variant={
                                                schedule.overdue
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                        >
                                            {schedule.overdue
                                                ? 'Overdue'
                                                : schedule.is_active
                                                  ? 'Active'
                                                  : 'Inactive'}
                                        </Badge>
                                    </div>
                                    <div className="mt-3 text-sm text-muted-foreground">
                                        Due{' '}
                                        {formatRegisterDate(
                                            schedule.next_due_date,
                                        )}
                                        {schedule.assigned_to
                                            ? ` · ${schedule.assigned_to}`
                                            : ''}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <SafetyEmpty label="No inspection schedules are configured." />
                    )}
                </SafetyRegisterCard>

                <SafetyRegisterCard title="Inspection records">
                    {data.records.length ? (
                        <div className="divide-y rounded-xl border">
                            {data.records.map((record) => (
                                <div key={record.id} className="p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="font-medium">
                                            {record.schedule_title ??
                                                `Inspection ${record.id}`}
                                        </div>
                                        <Badge variant="outline">
                                            {registerLabel(record.result)}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Completed{' '}
                                        {formatRegisterDate(
                                            record.completed_at,
                                        )}
                                        {record.completed_by
                                            ? ` by ${record.completed_by}`
                                            : ''}
                                    </div>
                                    {record.findings ? (
                                        <p className="mt-3 text-sm">
                                            {record.findings}
                                        </p>
                                    ) : null}
                                    {record.corrective_actions ? (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Corrective action:{' '}
                                            {record.corrective_actions}
                                        </p>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <SafetyEmpty label="No completed inspections are recorded." />
                    )}
                </SafetyRegisterCard>
            </div>
        </div>
    );
}
