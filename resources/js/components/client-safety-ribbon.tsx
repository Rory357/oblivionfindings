import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    Accessibility,
    AlertTriangle,
    Brain,
    Ear,
    Eye,
    Info,
    MessageSquareOff,
    Shield,
    ShieldAlert,
    Zap,
    type LucideIcon,
} from 'lucide-react';

export type SafetyTone = 'danger' | 'warning' | 'info';

export type SafetyAllergy = {
    key: string | null;
    label: string;
    group: string | null;
};

export type SafetyRisk = {
    id: number;
    label: string;
    severity: string;
};

export type SafetyCareFlag = {
    key: string;
    label: string;
    tone: SafetyTone;
    icon: string;
};

export type ClientSafety = {
    has_any: boolean;
    allergies: SafetyAllergy[];
    critical_risks: SafetyRisk[];
    other_risks_count: number;
    active_risks_count: number;
    care_flags: SafetyCareFlag[];
    risk_level: string | null;
    safeguarding_flag: boolean;
};

export type ClientSafetySummary = {
    has_any: boolean;
    allergies_count: number;
    critical_risks_count: number;
    active_risks_count: number;
    safeguarding: boolean;
    risk_level: string | null;
    top_allergy: string | null;
    top_risk: string | null;
};

const iconMap: Record<string, LucideIcon> = {
    shield: Shield,
    alert: ShieldAlert,
    zap: Zap,
    accessibility: Accessibility,
    messageOff: MessageSquareOff,
    brain: Brain,
    eye: Eye,
    ear: Ear,
    spine: Accessibility,
    info: Info,
};

const toneClasses: Record<SafetyTone, string> = {
    danger:
        'border-red-300 bg-red-100 text-red-900 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-100',
    warning:
        'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100',
    info:
        'border-sky-300 bg-sky-100 text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-100',
};

function Pill({
    tone,
    icon: Icon,
    children,
    title,
}: {
    tone: SafetyTone;
    icon: LucideIcon;
    children: React.ReactNode;
    title?: string;
}) {
    const pill = (
        <span
            className={cn(
                'inline-flex min-w-0 max-w-full cursor-default items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium',
                toneClasses[tone],
            )}
        >
            <Icon aria-hidden="true" className="h-3.5 w-3.5 shrink-0" />
            <span className="min-w-0 truncate">{children}</span>
        </span>
    );

    if (!title) return pill;

    return (
        <Tooltip delayDuration={150}>
            <TooltipTrigger asChild>{pill}</TooltipTrigger>
            <TooltipContent
                side="top"
                className="max-w-xs whitespace-normal break-words text-center"
            >
                {title}
            </TooltipContent>
        </Tooltip>
    );
}

function riskTone(severity: string): SafetyTone {
    const s = severity.toLowerCase();
    if (s === 'critical') return 'danger';
    if (s === 'high') return 'warning';
    return 'info';
}

/**
 * Client Safety Ribbon — the calm, persistent safety surface for a client.
 *
 * Renders allergies, active high/critical risks, and care-critical flags as
 * plain-language pills. Returns `null` when there is nothing to show, so it's
 * safe to drop on any staff-facing client page without conditional wrapping.
 *
 * Pass `sticky` on long client pages so the ribbon stays visible while
 * scrolling on mobile.
 */
export default function ClientSafetyRibbon({
    safety,
    sticky = false,
    className,
}: {
    safety: ClientSafety | null | undefined;
    sticky?: boolean;
    className?: string;
}) {
    if (!safety || !safety.has_any) return null;

    const { allergies, critical_risks, care_flags, other_risks_count } = safety;

    const hasDanger =
        safety.safeguarding_flag ||
        critical_risks.some((r) => r.severity === 'critical') ||
        allergies.length > 0;

    return (
        <section
            aria-label="Client safety information"
            className={cn(
                'rounded-xl border-2 shadow-sm',
                hasDanger
                    ? 'border-red-200 bg-red-50/70 dark:border-red-500/30 dark:bg-red-500/10'
                    : 'border-amber-200 bg-amber-50/60 dark:border-amber-500/30 dark:bg-amber-500/10',
                sticky && 'sticky top-2 z-20 md:top-4',
                className,
            )}
        >
            <div className="flex items-start gap-3 p-3 md:p-4">
                <div
                    className={cn(
                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                        hasDanger
                            ? 'bg-red-200 text-red-700 dark:bg-red-500/30 dark:text-red-100'
                            : 'bg-amber-200 text-amber-700 dark:bg-amber-500/30 dark:text-amber-100',
                    )}
                >
                    <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span className="text-xs font-semibold tracking-wide text-foreground uppercase dark:text-foreground">
                            Safety information
                        </span>
                        <span className="text-xs text-muted-foreground dark:text-muted-foreground">
                            Check before starting shift.
                        </span>
                    </div>

                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {allergies.map((a, i) => (
                            <Pill
                                key={`allergy-${a.key ?? i}`}
                                tone="danger"
                                icon={AlertTriangle}
                                title={
                                    a.group
                                        ? `Allergy (${a.group}): ${a.label}`
                                        : `Allergy: ${a.label}`
                                }
                            >
                                Allergy: {a.label}
                            </Pill>
                        ))}

                        {critical_risks.map((r) => (
                            <Pill
                                key={`risk-${r.id}`}
                                tone={riskTone(r.severity)}
                                icon={ShieldAlert}
                                title={`${r.severity} severity`}
                            >
                                Risk: {r.label}
                            </Pill>
                        ))}

                        {care_flags.map((f) => {
                            const Icon = iconMap[f.icon] ?? Info;
                            return (
                                <Pill
                                    key={f.key}
                                    tone={f.tone}
                                    icon={Icon}
                                >
                                    {f.label}
                                </Pill>
                            );
                        })}

                        {other_risks_count > 0 && (
                            <Pill tone="info" icon={Info} title="Lower-severity active risks">
                                +{other_risks_count} other risk{other_risks_count === 1 ? '' : 's'}
                            </Pill>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}

/**
 * Tiny compact indicators for client list/index cards. Shows allergy count,
 * critical-risk count, and safeguarding at a glance; stays out of the way
 * when a client has no safety context.
 */
export function ClientSafetyBadges({
    summary,
    className,
}: {
    summary: ClientSafetySummary | null | undefined;
    className?: string;
}) {
    if (!summary || !summary.has_any) return null;

    return (
        <div className={cn('flex flex-wrap items-center gap-1', className)}>
            {summary.allergies_count > 0 && (
                <Pill
                    tone="danger"
                    icon={AlertTriangle}
                    title={
                        summary.top_allergy
                            ? `Allergy: ${summary.top_allergy}`
                            : 'Allergies recorded'
                    }
                >
                    {summary.allergies_count === 1 && summary.top_allergy
                        ? `Allergy: ${summary.top_allergy}`
                        : `${summary.allergies_count} ${summary.allergies_count === 1 ? 'allergy' : 'allergies'}`}
                </Pill>
            )}

            {summary.critical_risks_count > 0 && (
                <Pill
                    tone={summary.risk_level === 'critical' ? 'danger' : 'warning'}
                    icon={ShieldAlert}
                    title={summary.top_risk ? `Risk: ${summary.top_risk}` : 'Critical risks'}
                >
                    {summary.critical_risks_count === 1 && summary.top_risk
                        ? `Risk: ${summary.top_risk}`
                        : `${summary.critical_risks_count} critical risk${summary.critical_risks_count === 1 ? '' : 's'}`}
                </Pill>
            )}

            {summary.safeguarding && (
                <Pill tone="danger" icon={Shield} title="Safeguarding flag set">
                    Safeguarding
                </Pill>
            )}
        </div>
    );
}
