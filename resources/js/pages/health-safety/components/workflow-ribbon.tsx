import {
    CommandWorkflowRibbon,
    type WorkflowRibbonStep,
} from '@/components/command-centre/workflow-ribbon';
import {
    BarChart3,
    ClipboardCheck,
    FileText,
    LayoutDashboard,
    ShieldAlert,
    Siren,
    Wrench,
} from 'lucide-react';

export type WorkflowStage =
    | 'report'
    | 'investigate'
    | 'drill'
    | 'resolve'
    | 'document'
    | 'analyse';

const STEPS: readonly WorkflowRibbonStep<WorkflowStage>[] = [
    {
        key: 'report',
        label: 'Report & respond',
        href: '/incidents',
        icon: ShieldAlert,
    },
    {
        key: 'investigate',
        label: 'Investigate',
        href: '/health-safety/events',
        icon: ClipboardCheck,
    },
    {
        key: 'drill',
        label: 'Drill & prepare',
        href: '/health-safety/drills',
        icon: Siren,
    },
    {
        key: 'resolve',
        label: 'Resolve',
        href: '/health-safety/corrective-actions',
        icon: Wrench,
    },
    {
        key: 'document',
        label: 'Document & control',
        href: '/health-safety/procedures',
        icon: FileText,
    },
    {
        key: 'analyse',
        label: 'Analyse',
        href: '/health-safety/analytics',
        icon: BarChart3,
    },
];

export function WorkflowRibbon({ current }: { current: WorkflowStage }) {
    return (
        <CommandWorkflowRibbon
            ariaLabel="Safety workflow"
            home={{
                key: 'home',
                label: 'H&S',
                href: '/health-safety',
                icon: LayoutDashboard,
            }}
            current={current}
            steps={STEPS}
        />
    );
}
