/* ＋Report launcher — the chooser opened from the H&S Overview header. Each tile is named after the page or form
 * it opens, and shows only to people allowed to use it (the same permission its route checks), so nobody picks
 * something that then refuses them. Tokens only. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    Clipboard,
    FlaskConical,
    HeartPulse,
    type LucideIcon,
    PersonStanding,
    ShieldAlert,
    Siren,
    TestTubeDiagonal,
    Users,
} from 'lucide-react';

/** The shared permission flags this chooser reads (module → flag → allowed). */
type Can = Partial<Record<string, Partial<Record<string, boolean>>>> | undefined;

type Workflow = {
    key: string;
    label: string;
    desc: string;
    icon: LucideIcon;
    /** Mirrors the permission the destination route checks. */
    allowed: (can: Can) => boolean;
};

const hazardCapture = (can: Can) =>
    Boolean(can?.hazards?.create || can?.hazards?.manage);

const WORKFLOWS: Workflow[] = [
    {
        key: 'incident',
        label: 'Report an incident',
        desc: 'Something happened to a person, or nearly did',
        icon: ShieldAlert,
        allowed: (can) => Boolean(can?.incidents?.create),
    },
    {
        key: 'hazard',
        label: 'Report a hazard',
        desc: 'Something that could cause harm, like a wet floor',
        icon: AlertOctagon,
        allowed: (can) => Boolean(can?.hazards?.create),
    },
    {
        key: 'first_aid',
        label: 'Record first aid',
        desc: 'First aid given to anyone at a site',
        icon: HeartPulse,
        allowed: hazardCapture,
    },
    {
        key: 'rtw',
        label: 'Report a workplace injury',
        desc: 'A staff member was hurt at work',
        icon: Activity,
        allowed: hazardCapture,
    },
    {
        key: 'restraint',
        label: 'Record a restraint',
        desc: 'A person’s movement or freedom was limited',
        icon: Clipboard,
        allowed: (can) =>
            Boolean(can?.restraints?.create || can?.restraints?.manage),
    },
    {
        key: 'exposure',
        label: 'Record a chemical exposure',
        desc: 'Pick the chemical, then record what happened',
        icon: TestTubeDiagonal,
        allowed: hazardCapture,
    },
    {
        key: 'drill',
        label: 'Plan an emergency drill',
        desc: 'Fire, evacuation, earthquake or lockdown',
        icon: Siren,
        allowed: hazardCapture,
    },
    {
        key: 'substance',
        label: 'Add a chemical',
        desc: 'Its safety data sheet and where it’s stored',
        icon: FlaskConical,
        allowed: hazardCapture,
    },
    {
        key: 'lone',
        label: 'Start working alone',
        desc: 'Set check-in times for someone working alone',
        icon: PersonStanding,
        allowed: (can) => Boolean(can?.hazards?.view && can?.hazards?.manage),
    },
    {
        key: 'participation',
        label: 'Add a health and safety representative',
        desc: 'A worker elected to represent staff on safety',
        icon: Users,
        allowed: (can) => Boolean(can?.hazards?.manage),
    },
];

/** The tiles this person may use (exported for tests). */
export function launcherWorkflows(can: Can): Workflow[] {
    return WORKFLOWS.filter((workflow) => workflow.allowed(can));
}

export function ReportLauncher({
    open,
    onClose,
    onWorkflow,
}: {
    open: boolean;
    onClose: () => void;
    onWorkflow: (key: string) => void;
}) {
    const can = usePage<SharedData>().props.auth?.can as unknown as Can;
    const workflows = launcherWorkflows(can);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Report something</DialogTitle>
                    <DialogDescription>
                        Choose what happened. Each choice opens the right form.
                    </DialogDescription>
                </DialogHeader>
                {workflows.length === 0 ? (
                    <p className="text-subtle">
                        You can’t record health and safety work. Ask your
                        manager or a health and safety lead to give you access.
                    </p>
                ) : (
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {workflows.map((w) => (
                            // eslint-disable-next-line no-restricted-syntax -- workflow chooser tile is a custom selector card, not a shadcn Button.
                            <button
                                key={w.key}
                                type="button"
                                onClick={() => onWorkflow(w.key)}
                                className="flex flex-col gap-1.5 rounded-xl border border-border bg-card p-3 text-left transition-all hover:border-primary/50 hover:bg-accent"
                            >
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <w.icon className="h-4 w-4" />
                                </span>
                                <span className="text-sm font-semibold text-foreground">
                                    {w.label}
                                </span>
                                <span className="text-[11px] text-muted-foreground">
                                    {w.desc}
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default ReportLauncher;
