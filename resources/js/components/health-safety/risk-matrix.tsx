/**
 * 5x5 Risk Matrix — display only.
 *
 * Shows a colour-coded grid with the current risk position highlighted.
 * Optionally shows residual risk as a second marker.
 *
 * No editing. No interaction beyond visual display.
 */

const CELL_COLORS: Record<string, string> = {
    low: 'bg-green-100 dark:bg-green-900/30',
    medium: 'bg-yellow-100 dark:bg-yellow-900/30',
    high: 'bg-orange-100 dark:bg-orange-900/30',
    extreme: 'bg-red-100 dark:bg-red-900/30',
};

const ACTIVE_RING = 'ring-2 ring-offset-1 ring-slate-900 dark:ring-white';
const RESIDUAL_RING = 'ring-2 ring-offset-1 ring-blue-500 ring-dashed';

function scoreToLevel(score: number): string {
    if (score >= 16) return 'extreme';
    if (score >= 10) return 'high';
    if (score >= 5) return 'medium';
    return 'low';
}

const LIKELIHOOD_LABELS = ['Rare', 'Unlikely', 'Possible', 'Likely', 'Almost Certain'];
const CONSEQUENCE_LABELS = ['Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'];

interface RiskMatrixProps {
    likelihood: number;         // 1-5
    consequence: number;        // 1-5
    residualLikelihood?: number | null;
    residualConsequence?: number | null;
    compact?: boolean;          // smaller size for inline use
}

export function RiskMatrix({
    likelihood,
    consequence,
    residualLikelihood,
    residualConsequence,
    compact = false,
}: RiskMatrixProps) {
    const cellSize = compact ? 'h-6 w-6 text-[9px]' : 'h-9 w-9 text-xs';
    const labelSize = compact ? 'text-[8px]' : 'text-[10px]';

    return (
        <div className="inline-block">
            {/* Y-axis label */}
            {!compact && (
                <div className={`mb-1 text-center ${labelSize} font-medium text-muted-foreground`}>
                    Likelihood vs Consequence
                </div>
            )}

            <div className="flex">
                {/* Y-axis */}
                <div className="flex flex-col-reverse justify-center gap-px mr-1">
                    {LIKELIHOOD_LABELS.map((label, i) => (
                        <div
                            key={i}
                            className={`flex items-center justify-end ${cellSize} ${labelSize} text-muted-foreground pr-1 truncate`}
                            style={{ maxWidth: compact ? 30 : 50 }}
                            title={label}
                        >
                            {compact ? i + 1 : label.slice(0, 3)}
                        </div>
                    ))}
                </div>

                {/* Grid */}
                <div>
                    <div className="flex flex-col-reverse gap-px">
                        {[1, 2, 3, 4, 5].map(l => (
                            <div key={l} className="flex gap-px">
                                {[1, 2, 3, 4, 5].map(c => {
                                    const score = l * c;
                                    const level = scoreToLevel(score);
                                    const isActive = l === likelihood && c === consequence;
                                    const isResidual = residualLikelihood != null && residualConsequence != null
                                        && l === residualLikelihood && c === residualConsequence;
                                    const isBoth = isActive && isResidual;

                                    return (
                                        <div
                                            key={`${l}-${c}`}
                                            className={[
                                                cellSize,
                                                'flex items-center justify-center rounded-sm font-semibold',
                                                CELL_COLORS[level],
                                                isActive && !isBoth ? ACTIVE_RING : '',
                                                isResidual && !isBoth ? RESIDUAL_RING : '',
                                                isBoth ? 'ring-2 ring-offset-1 ring-ring' : '',
                                            ].join(' ')}
                                            title={`L${l} x C${c} = ${score} (${level})`}
                                        >
                                            {score}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>

                    {/* X-axis */}
                    {!compact && (
                        <div className="flex gap-px mt-1 ml-0">
                            {CONSEQUENCE_LABELS.map((label, i) => (
                                <div
                                    key={i}
                                    className={`${cellSize} ${labelSize} text-center text-muted-foreground truncate`}
                                    title={label}
                                >
                                    {label.slice(0, 3)}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Legend */}
            {!compact && (residualLikelihood != null && residualConsequence != null) && (
                <div className="mt-2 flex gap-4 text-[10px] text-muted-foreground">
                    <span className="flex items-center gap-1">
                        <span className="inline-block h-3 w-3 rounded-sm ring-2 ring-slate-900 dark:ring-white ring-offset-1 bg-muted" />
                        Inherent
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="inline-block h-3 w-3 rounded-sm ring-2 ring-blue-500 ring-dashed ring-offset-1 bg-muted" />
                        Residual
                    </span>
                </div>
            )}
        </div>
    );
}
