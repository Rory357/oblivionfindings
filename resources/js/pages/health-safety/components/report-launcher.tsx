/* ＋Report launcher (WS6/WS7) — the chooser opened from the hero. A 9-workflow grid.
 * `inPlace` tiles open their wizard in place via onWorkflow(key); the rest navigate to their
 * register/create pages until their wizard is built (WS7 progressively flips them). Tokens only. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
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
    Users,
} from 'lucide-react';

type Workflow = {
    key: string;
    label: string;
    desc: string;
    icon: LucideIcon;
    /** Opens its wizard in place via onWorkflow(key). */
    inPlace?: boolean;
    /** Interim navigate-away target until the in-place wizard is built. */
    href?: string;
};

const WORKFLOWS: Workflow[] = [
    { key: 'incident', label: 'Report incident / near-miss', desc: 'Events register · WorkSafe check', icon: ShieldAlert, inPlace: true },
    { key: 'hazard', label: 'Log hazard + risk assessment', desc: 'L×C matrix · hierarchy of control', icon: AlertOctagon, inPlace: true },
    { key: 'first_aid', label: 'Record first-aid treatment', desc: 'First-aid register', icon: HeartPulse, inPlace: true },
    { key: 'restraint', label: 'Log restraint event', desc: 'Least-restrictive · debrief', icon: Clipboard, inPlace: true },
    { key: 'drill', label: 'Record emergency drill', desc: 'Fire / evacuation / lockdown', icon: Siren, inPlace: true },
    { key: 'rtw', label: 'Injury → return-to-work', desc: 'ACC claim · RTW plan', icon: Activity, inPlace: true },
    { key: 'substance', label: 'Add hazardous substance', desc: 'SDS · Hazardous Substances Regs 2017', icon: FlaskConical, inPlace: true },
    { key: 'lone', label: 'Lone-worker check-in', desc: 'Check-in / escalate to on-call', icon: PersonStanding, inPlace: true },
    { key: 'participation', label: 'Worker participation / committee', desc: 'HSR · committee minutes', icon: Users, inPlace: true },
];

export function ReportLauncher({
    open,
    onClose,
    onWorkflow,
}: {
    open: boolean;
    onClose: () => void;
    onWorkflow: (key: string) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Report a health &amp; safety event</DialogTitle>
                    <DialogDescription>
                        Choose a workflow — everything is recorded against the relevant NZ register.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {WORKFLOWS.map((w) => (
                        // eslint-disable-next-line no-restricted-syntax -- workflow chooser tile is a custom selector card, not a shadcn Button.
                        <button
                            key={w.key}
                            type="button"
                            onClick={() => {
                                if (w.inPlace) {
                                    onWorkflow(w.key);
                                } else if (w.href) {
                                    onClose();
                                    router.visit(w.href);
                                }
                            }}
                            className="flex flex-col gap-1.5 rounded-xl border border-border bg-card p-3 text-left transition-all hover:border-primary/50 hover:bg-accent"
                        >
                            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <w.icon className="h-4 w-4" />
                            </span>
                            <span className="text-sm font-semibold text-foreground">{w.label}</span>
                            <span className="text-[11px] text-muted-foreground">{w.desc}</span>
                        </button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default ReportLauncher;
