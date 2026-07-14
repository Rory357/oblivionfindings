import { Link } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';

export type WorkflowRibbonStep<Key extends string = string> = {
    key: Key;
    label: string;
    href: string;
    icon: LucideIcon;
};

export function CommandWorkflowRibbon<Key extends string>({
    ariaLabel,
    home,
    current,
    steps,
}: {
    ariaLabel: string;
    home: WorkflowRibbonStep<'home'>;
    current: Key;
    steps: readonly WorkflowRibbonStep<Key>[];
}) {
    const HomeIcon = home.icon;

    return (
        <nav
            aria-label={ariaLabel}
            className="-mt-1 flex flex-wrap items-center gap-0.5 text-xs"
        >
            <Link
                href={home.href}
                title={home.label}
                className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground"
            >
                <HomeIcon className="h-3.5 w-3.5" aria-hidden />
                <span>{home.label}</span>
            </Link>
            {steps.map((step) => {
                const active = step.key === current;
                const Icon = step.icon;
                const inner = (
                    <>
                        <Icon className="h-3.5 w-3.5" aria-hidden />
                        {step.label}
                    </>
                );

                return (
                    <span key={step.key} className="inline-flex items-center">
                        <ChevronRight
                            className="mx-0.5 h-3.5 w-3.5 shrink-0 text-primary-foreground/40"
                            aria-hidden
                        />
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
