/* Safety workflow ribbon — a compact, shared "you-are-here" stepper that sits at
 * the top of every safety register so the whole module reads as one connected
 * workflow rather than a set of disjoint pages. It names the spine every safety
 * event travels — Report & respond → Investigate → Resolve → Analyse — anchored by
 * the H&S command centre, highlights the stage the current register represents, and
 * links the others so a user always knows where they are and can move to the next
 * step in one click. Semantic tokens only. NZ-only, web-only. */
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    BarChart3,
    ChevronRight,
    ClipboardCheck,
    LayoutDashboard,
    ShieldAlert,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

export type WorkflowStage = 'report' | 'investigate' | 'resolve' | 'analyse';

const STEPS: { key: WorkflowStage; label: string; href: string; icon: LucideIcon }[] = [
    { key: 'report', label: 'Report & respond', href: '/incidents', icon: ShieldAlert },
    { key: 'investigate', label: 'Investigate', href: '/health-safety/events', icon: ClipboardCheck },
    { key: 'resolve', label: 'Resolve', href: '/health-safety/corrective-actions', icon: Wrench },
    { key: 'analyse', label: 'Analyse', href: '/health-safety/analytics', icon: BarChart3 },
];

/** `current` = which stage of the safety lifecycle this page sits at. The report
 *  front-doors (incidents / safeguarding / fleet incidents) all pass `report`. */
export function WorkflowRibbon({ current }: { current: WorkflowStage }) {
    return (
        <nav
            aria-label="Safety workflow"
            className="flex flex-wrap items-center gap-0.5 rounded-xl border border-border bg-card px-2 py-1.5 text-xs shadow-sm"
        >
            <Link
                href="/health-safety"
                title="Health & Safety command centre"
                className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
                <LayoutDashboard className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">H&amp;S</span>
            </Link>
            {STEPS.map((step, i) => {
                const active = step.key === current;
                const Icon = step.icon;
                const inner = (
                    <>
                        <Icon className="h-3.5 w-3.5" />
                        {step.label}
                    </>
                );
                return (
                    <span key={step.key} className="inline-flex items-center">
                        <ChevronRight className="mx-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground/40" aria-hidden />
                        {active ? (
                            <span
                                aria-current="step"
                                className="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-2.5 py-1 font-semibold text-primary"
                            >
                                {inner}
                            </span>
                        ) : (
                            <Link
                                href={step.href}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                                )}
                            >
                                {inner}
                            </Link>
                        )}
                    </span>
                );
            })}
        </nav>
    );
}
