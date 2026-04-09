import { CheckCircle2, XCircle, AlertTriangle, Info } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EligibilityStatusBadge, deriveEligibilityStatus } from './eligibility-status-badge';
import { cn } from '@/lib/utils';

export interface CheckedRule {
    rule: string;
    passed: boolean;
    severity: 'block' | 'warning' | 'info';
    overrideable: boolean;
    message: string | null;
}

export interface EligibilityResultData {
    is_eligible?: boolean;
    is_allowed?: boolean;
    blocked_reasons?: string[];
    warning_reasons?: string[];
    checked_rules?: CheckedRule[];
    overrideable_warnings?: Array<{ rule: string; message: string; overrideable: boolean }>;
}

interface EligibilityDetailPanelProps {
    result: EligibilityResultData;
    staffName?: string;
    showPassedChecks?: boolean;
    className?: string;
}

const RULE_LABELS: Record<string, string> = {
    conflict: 'Shift conflicts',
    time_off: 'Time-off availability',
    turnaround: 'Shift turnaround gap',
    compliance: 'Compliance requirements',
    coverage_roles: 'Coverage roles',
    overfill: 'Coverage capacity',
    availability: 'Staff availability',
    availability_leave: 'Approved leave',
    fatigue_daily: 'Daily hours limit',
    fatigue_weekly: 'Weekly hours limit',
    fatigue_rest: 'Rest between shifts',
    fatigue_consecutive: 'Consecutive days',
    site_assignment: 'Site assignment',
    driver_licence: 'Driver licence',
};

function ruleLabel(rule: string): string {
    return RULE_LABELS[rule] ?? rule.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

export function EligibilityDetailPanel({
    result,
    staffName,
    showPassedChecks = true,
    className,
}: EligibilityDetailPanelProps) {
    const { status } = deriveEligibilityStatus(result);
    const blocks = result.blocked_reasons ?? [];
    const warnings = result.warning_reasons ?? [];
    const checks = result.checked_rules ?? [];
    const passedChecks = checks.filter(c => c.passed);
    const hasIssues = blocks.length > 0 || warnings.length > 0;

    const borderClass = status === 'blocked'
        ? 'border-red-200 dark:border-red-800'
        : status === 'warnings'
            ? 'border-yellow-200 dark:border-yellow-800'
            : 'border-green-200 dark:border-green-800';

    return (
        <Card className={cn(borderClass, className)}>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-medium">
                        {staffName ? `Eligibility: ${staffName}` : 'Eligibility Check'}
                    </CardTitle>
                    <EligibilityStatusBadge status={status} warningCount={warnings.length} />
                </div>
            </CardHeader>
            <CardContent className="space-y-3 pt-0">
                {/* Hard blocks */}
                {blocks.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-xs font-medium uppercase tracking-wider text-red-700 dark:text-red-400">
                            Hard blocks
                        </p>
                        <ul className="space-y-1">
                            {blocks.map((reason, i) => (
                                <li key={i} className="flex items-start gap-2 text-sm text-red-700 dark:text-red-400">
                                    <XCircle className="mt-0.5 size-3.5 shrink-0" />
                                    <span>{reason}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Warnings */}
                {warnings.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-xs font-medium uppercase tracking-wider text-yellow-700 dark:text-yellow-400">
                            Warnings
                        </p>
                        <ul className="space-y-1">
                            {warnings.map((reason, i) => (
                                <li key={i} className="flex items-start gap-2 text-sm text-yellow-700 dark:text-yellow-400">
                                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                                    <span>{reason}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Passed checks */}
                {showPassedChecks && passedChecks.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                            Passed checks
                        </p>
                        <ul className="space-y-0.5">
                            {passedChecks.map((check, i) => (
                                <li key={i} className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <CheckCircle2 className="size-3 shrink-0 text-green-500" />
                                    <span>{ruleLabel(check.rule)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Clean pass message */}
                {!hasIssues && passedChecks.length === 0 && (
                    <div className="flex items-center gap-2 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" />
                        <span>All eligibility checks passed.</span>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
