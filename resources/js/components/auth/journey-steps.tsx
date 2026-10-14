/**
 * A three-stop progress line drawn like the Journey mark's pathway: done
 * stops filled, the current one ringed, upcoming ones dotted. `expired`
 * greys the whole line (an invitation that has run out).
 */
export function JourneySteps({
    steps,
    current,
    expired = false,
    label,
}: {
    steps: [string, string, string];
    /** 0, 1 or 2 */
    current: 0 | 1 | 2;
    expired?: boolean;
    /** Accessible summary, e.g. "Step 2 of 3: choose how you sign in". */
    label: string;
}) {
    const dots: Array<{ cx: number; cy: number }> = [
        { cx: 10, cy: 24 },
        { cx: 192, cy: 12 },
        { cx: 374, cy: 10 },
    ];
    const segment = (index: 0 | 1) =>
        expired
            ? 'journey-steps__off'
            : index < current
              ? 'journey-steps__done'
              : 'journey-steps__next';
    const dot = (index: number) =>
        expired
            ? 'journey-steps__dot-off'
            : index < current
              ? 'journey-steps__dot-done'
              : index === current
                ? 'journey-steps__dot-now'
                : 'journey-steps__dot-next';

    return (
        <div role="img" aria-label={label} className="flex flex-col gap-1.5">
            <svg
                viewBox="0 0 384 34"
                aria-hidden="true"
                className="block h-auto w-full overflow-visible"
            >
                <path
                    className={segment(0)}
                    d="M10 24 C 70 26, 120 6, 192 12"
                />
                <path
                    className={segment(1)}
                    d="M192 12 C 262 18, 316 30, 374 10"
                />
                {dots.map((position, index) => (
                    <g key={index}>
                        {!expired && index === current && (
                            <circle
                                className="journey-steps__halo"
                                cx={position.cx}
                                cy={position.cy}
                                r="13"
                            />
                        )}
                        <circle
                            className={dot(index)}
                            cx={position.cx}
                            cy={position.cy}
                            r={index === 2 ? 6 : 6.5}
                        />
                    </g>
                ))}
            </svg>
            <div
                aria-hidden="true"
                className="grid grid-cols-3 text-[13px] font-semibold text-muted-foreground"
            >
                {steps.map((step, index) => (
                    <span
                        key={step}
                        className={
                            (index === 1
                                ? 'text-center '
                                : index === 2
                                  ? 'text-right '
                                  : '') +
                            (!expired && index === current
                                ? 'text-primary'
                                : '')
                        }
                    >
                        {step}
                    </span>
                ))}
            </div>
        </div>
    );
}
