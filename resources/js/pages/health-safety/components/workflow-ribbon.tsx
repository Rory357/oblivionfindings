/* Safety workflow ribbon — a compact, shared "you-are-here" stepper that sits at
 * the TOP OF THE HERO BANNER on every safety register, so the whole module reads
 * as one connected workflow rather than a set of disjoint pages (and without a
 * standalone strip eating space above the hero). It names the spine every safety
 * event travels — Report & respond → Investigate → Resolve → Analyse — anchored by
 * the H&S command centre, highlights the stage the current register represents, and
 * links the others so a user always knows where they are and can move to the next
 * step in one click. Styled for the dark `--primary` hero gradient (translucent
 * primary-foreground tokens, matching the hero's other on-dark controls). NZ-only. */
import { Link } from '@inertiajs/react';
import {
    BarChart3,
    ChevronRight,
    ClipboardCheck,
    FileText,
    LayoutDashboard,
    ShieldAlert,
    Siren,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

export type WorkflowStage = 'report' | 'investigate' | 'drill' | 'resolve' | 'document' | 'analyse';

const STEPS: { key: WorkflowStage; label: string; href: string; icon: LucideIcon }[] = [
    { key: 'report', label: 'Report & respond', href: '/incidents', icon: ShieldAlert },
    { key: 'investigate', label: 'Investigate', href: '/health-safety/events', icon: ClipboardCheck },
    { key: 'drill', label: 'Drill & prepare', href: '/health-safety/drills', icon: Siren },
    { key: 'resolve', label: 'Resolve', href: '/health-safety/corrective-actions', icon: Wrench },
    { key: 'document', label: 'Document & control', href: '/health-safety/procedures', icon: FileText },
    { key: 'analyse', label: 'Analyse', href: '/health-safety/analytics', icon: BarChart3 },
];

/** `current` = which stage of the safety lifecycle this page sits at. The report
 *  front-doors (incidents / safeguarding / fleet incidents) all pass `report`.
 *  Renders inside `HeroShell` (on the primary gradient) as a slim top breadcrumb. */
export function WorkflowRibbon({ current }: { current: WorkflowStage }) {
    return (
        <nav aria-label="Safety workflow" className="-mt-1 flex flex-wrap items-center gap-0.5 text-xs">
            <Link
                href="/health-safety"
                title="Health & Safety command centre"
                className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground"
            >
                <LayoutDashboard className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">H&amp;S</span>
            </Link>
            {STEPS.map((step) => {
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
                        <ChevronRight className="mx-0.5 h-3.5 w-3.5 shrink-0 text-primary-foreground/40" aria-hidden />
                        {active ? (
                            <span
                                aria-current="step"
                                className="inline-flex items-center gap-1.5 rounded-lg bg-primary-foreground/20 px-2.5 py-1 font-semibold text-primary-foreground"
                            >
                                {inner}
                            </span>
                        ) : (
                            <Link
                                href={step.href}
                                className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground"
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
