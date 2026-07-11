import { HrHero } from '@/components/hr/hr-hero';
import { Button } from '@/components/ui/button';
import { BookOpen, CheckSquare, Download, Plus, UserPlus } from 'lucide-react';

type TrainingSummary = {
    total_courses: number;
    mandatory_courses: number;
    total_enrollments: number;
    completion_rate: number;
    expiring_soon: number;
    overdue_assignments: number;
};

export function TrainingHero({
    summary,
    mandatoryCurrentPct,
    can,
    onCreate,
    onAssign,
    onRecord,
    onExport,
}: {
    summary: TrainingSummary;
    mandatoryCurrentPct: number;
    can: { manage: boolean; enroll: boolean; record: boolean };
    onCreate: () => void;
    onAssign: () => void;
    onRecord: () => void;
    onExport: () => void;
}) {
    return (
        <HrHero
            icon={BookOpen}
            title="Training & development"
            description="Courses, assignments, renewals, and workforce capability."
            stats={[
                {
                    label: 'Courses',
                    value: summary.total_courses,
                    href: '/hr/training/catalog?tab=catalog',
                },
                {
                    label: 'Mandatory',
                    value: summary.mandatory_courses,
                    href: '/hr/training/catalog?tab=catalog',
                },
                {
                    label: 'Enrolments',
                    value: summary.total_enrollments.toLocaleString('en-NZ'),
                    href: '/hr/training/catalog?tab=assignments',
                },
                {
                    label: 'Completion',
                    value: `${summary.completion_rate}%`,
                    href: '/hr/training/catalog?tab=dashboard',
                },
                {
                    label: 'Expiring ≤90d',
                    value: summary.expiring_soon,
                    href: '/hr/training/catalog?tab=dashboard',
                },
                {
                    label: 'Overdue',
                    value: summary.overdue_assignments,
                    href: '/hr/training/catalog?tab=assignments',
                    tone: 'warning',
                },
            ]}
            actions={
                <>
                    {can.manage ? (
                        <Button size="sm" variant="outline" onClick={onCreate}>
                            <Plus className="h-4 w-4" />
                            New course
                        </Button>
                    ) : null}
                    {can.enroll ? (
                        <Button size="sm" variant="outline" onClick={onAssign}>
                            <UserPlus className="h-4 w-4" />
                            Assign training
                        </Button>
                    ) : null}
                    {can.record ? (
                        <Button size="sm" variant="outline" onClick={onRecord}>
                            <CheckSquare className="h-4 w-4" />
                            Record completion
                        </Button>
                    ) : null}
                    <Button size="sm" onClick={onExport}>
                        <Download className="h-4 w-4" />
                        Export
                    </Button>
                </>
            }
        >
            <p className="text-sm text-primary-foreground/80">
                Mandatory training current:{' '}
                <strong className="text-primary-foreground">
                    {mandatoryCurrentPct}%
                </strong>
                {summary.overdue_assignments > 0 ? (
                    <span className="ml-3 font-semibold text-status-warning">
                        {summary.overdue_assignments} overdue renewals
                    </span>
                ) : null}
            </p>
        </HrHero>
    );
}

export default TrainingHero;
