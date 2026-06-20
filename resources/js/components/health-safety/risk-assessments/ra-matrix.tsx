/* eslint-disable no-restricted-syntax -- Bespoke 5×5 risk matrix (interactive capture
 * + read-only display) with semantic tokens. The shared RiskMatrix is display-only and
 * collapses medium/high into one colour, so the RA module ships its own with the 4-band
 * tone scale and a clickable capture mode. */
import { cn } from '@/lib/utils';
import { CONSEQUENCE_LABELS, LIKELIHOOD_LABELS, levelTone, scoreLevel, type RaTone } from './ra-kit';

const CELL_BG: Record<RaTone, string> = {
    success: 'bg-status-success-bg',
    info: 'bg-primary/10',
    warning: 'bg-status-warning-bg',
    critical: 'bg-status-critical-bg',
    neutral: 'bg-muted',
};

const ROWS = [5, 4, 3, 2, 1]; // likelihood, top → bottom
const COLS = [1, 2, 3, 4, 5]; // consequence, left → right

/**
 * 5×5 likelihood × consequence matrix. Pass `onSelect` to make it an interactive
 * capture grid; omit it for a read-only display (inherent ring + optional dashed
 * residual overlay).
 */
export function RaMatrix({
    likelihood,
    consequence,
    residualLikelihood = null,
    residualConsequence = null,
    onSelect,
    compact = false,
}: {
    likelihood: number;
    consequence: number;
    residualLikelihood?: number | null;
    residualConsequence?: number | null;
    onSelect?: (likelihood: number, consequence: number) => void;
    compact?: boolean;
}) {
    const interactive = !!onSelect;
    const cellSize = compact ? 'h-[26px] w-[26px] text-[11px]' : 'h-[34px] w-[34px] text-xs';
    const hasResidual = residualLikelihood != null && residualConsequence != null;

    return (
        <div className="inline-flex flex-col gap-1">
            <div className="flex gap-2">
                <div className="flex flex-col gap-1">
                    {ROWS.map((l) => (
                        <div
                            key={l}
                            className={cn(
                                'flex items-center justify-end pr-1 text-right text-[9px] leading-tight text-muted-foreground',
                                compact ? 'h-[26px] w-14' : 'h-[34px] w-20',
                            )}
                        >
                            {LIKELIHOOD_LABELS[l - 1]}
                        </div>
                    ))}
                </div>

                <div>
                    <div className="flex flex-col gap-1">
                        {ROWS.map((l) => (
                            <div key={l} className="flex gap-1">
                                {COLS.map((c) => {
                                    const score = l * c;
                                    const tone = levelTone(scoreLevel(score));
                                    const active = l === likelihood && c === consequence;
                                    const resid = hasResidual && l === residualLikelihood && c === residualConsequence;
                                    const cls = cn(
                                        'flex items-center justify-center rounded font-semibold tabular-nums text-foreground',
                                        cellSize,
                                        CELL_BG[tone],
                                        active && 'relative z-10 ring-2 ring-ring ring-offset-2 ring-offset-card',
                                        !active && resid && 'relative z-10 outline outline-2 outline-dashed outline-primary -outline-offset-2',
                                        interactive && 'cursor-pointer transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    );
                                    return interactive ? (
                                        <button
                                            key={c}
                                            type="button"
                                            onClick={() => onSelect?.(l, c)}
                                            aria-label={`Likelihood ${l}, consequence ${c} — score ${score}, ${scoreLevel(score)}`}
                                            aria-pressed={active}
                                            className={cls}
                                        >
                                            {score}
                                        </button>
                                    ) : (
                                        <div key={c} className={cls}>
                                            {score}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>

                    <div className="mt-1 flex gap-1">
                        {COLS.map((c) => (
                            <div
                                key={c}
                                className={cn('text-center text-[8.5px] leading-tight text-muted-foreground', compact ? 'w-[26px]' : 'w-[34px]')}
                            >
                                {CONSEQUENCE_LABELS[c - 1]}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {hasResidual ? (
                <div className="mt-2 flex gap-3.5 text-[10px] text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm bg-muted ring-2 ring-ring" /> Inherent
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm bg-muted outline outline-2 outline-dashed outline-primary" /> Residual
                    </span>
                </div>
            ) : null}
        </div>
    );
}
