import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    BRAND_CONTRAST_TARGET,
    brandContrastReport,
    isVeryLightBrand,
} from '@/lib/derive-palette';
import { AlertTriangle, Check, X } from 'lucide-react';
import { useMemo } from 'react';

const HEX_RE = /^#[0-9a-fA-F]{6}$/;

const formatRatio = (ratio: number) => `${ratio.toFixed(1)}:1`;

/**
 * Guidance only — never blocks saving. Page headers are floored dark with
 * always-white text (--band-foreground), so a very light brand gets a short
 * confirmation that headers stay readable. The warning remains for any
 * check that still falls below WCAG AA (4.5:1), with the measured contrast
 * of each and the lightest darker shade that passes.
 */
export function BrandContrastNotice({
    hex,
    onUseShade,
}: {
    hex: string;
    onUseShade: (hex: string) => void;
}) {
    const report = useMemo(
        () => (HEX_RE.test(hex) ? brandContrastReport(hex) : null),
        [hex],
    );

    if (!report) return null;

    if (report.passes) {
        if (!isVeryLightBrand(hex)) return null;
        return (
            <Alert
                className="border-status-success/30 bg-status-success-bg text-status-success"
                data-testid="brand-contrast-ok"
            >
                <Check />
                <AlertTitle className="line-clamp-none">
                    Headers stay readable
                </AlertTitle>
                <AlertDescription className="text-status-success">
                    <p>
                        Page headers and banners are always dark with white
                        text, so this light colour keeps them readable. Buttons
                        and badges filled with it switch to dark text
                        automatically.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    const suggestion = report.suggestion;
    const worst = report.checks.reduce((a, b) => (b.ratio < a.ratio ? b : a));

    return (
        <Alert
            className="border-status-warning/30 bg-status-warning-bg text-status-warning"
            data-testid="brand-contrast-notice"
        >
            <AlertTriangle />
            <AlertTitle className="line-clamp-none">
                Some header text will be hard to read
            </AlertTitle>
            <AlertDescription className="space-y-2 text-status-warning">
                <p>
                    With this colour, parts of every page header fall below the{' '}
                    {BRAND_CONTRAST_TARGET}:1 contrast people need to read them
                    comfortably. Lowest measured:{' '}
                    <span className="font-semibold">
                        {formatRatio(worst.ratio)}
                    </span>{' '}
                    ({worst.label.toLowerCase()}).
                </p>
                <ul className="space-y-1">
                    {report.checks.map((check) => {
                        const ok = check.ratio >= BRAND_CONTRAST_TARGET;
                        return (
                            <li
                                key={check.id}
                                className="flex items-center gap-2"
                            >
                                {ok ? (
                                    <Check
                                        className="size-3.5 shrink-0"
                                        aria-hidden
                                    />
                                ) : (
                                    <X
                                        className="size-3.5 shrink-0"
                                        aria-hidden
                                    />
                                )}
                                <span>
                                    {check.label}:{' '}
                                    <span className="font-semibold">
                                        {formatRatio(check.ratio)}
                                    </span>
                                    {ok
                                        ? ' — fine'
                                        : ` — needs ${BRAND_CONTRAST_TARGET}:1`}
                                </span>
                            </li>
                        );
                    })}
                </ul>
                {suggestion ? (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <span
                            className="size-5 shrink-0 rounded-md border"
                            style={{ backgroundColor: suggestion }}
                            aria-hidden
                        />
                        <span>
                            A darker shade that reads well:{' '}
                            <span className="font-mono uppercase">
                                {suggestion}
                            </span>
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => onUseShade(suggestion)}
                        >
                            Use suggested shade
                        </Button>
                    </div>
                ) : null}
                <p className="text-xs">You can still save this colour.</p>
            </AlertDescription>
        </Alert>
    );
}
