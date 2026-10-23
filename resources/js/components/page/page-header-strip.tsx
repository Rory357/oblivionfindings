/**
 * Event Horizon header — record facts and the one-strip row (approved by
 * Stephan 23 Oct 2026 with the Health & safety drawings, design viewer v35).
 *
 * A header carries at most ONE strip under its identity row:
 *   - <PageHeaderNumbers>: up to four equal number boxes for hubs and lists
 *     (number first, label and context beside it, every box a link);
 *   - <PageHeaderStages>: a record's stage line with its next step on top —
 *     where it is up to, who does what next. One banner: the next step lives
 *     here, never in a second band under the header.
 * <PageHeaderFacts> are small value + label chips for a record (severity,
 * owner, a due date). Status colours use the header's fixed status floors
 * (app.css `.eh-*`), never the brand.
 */
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type PageHeaderStripTone = 'critical' | 'warning' | 'success';

/* ------------------------------------------------------------------ */
/*  Facts                                                              */
/* ------------------------------------------------------------------ */

export interface PageHeaderFact {
    /** The fact itself, bold ("Moderate", "Fri 23 Oct 2026"). */
    value: ReactNode;
    /** What it is, quieter ("Severity", "Next review"). */
    label?: ReactNode;
    /** A toned dot in front — colour is never the only signal: keep the words. */
    tone?: PageHeaderStripTone | 'neutral';
}

export function PageHeaderFacts({ items }: { items: PageHeaderFact[] }) {
    const shown = items.filter((f) => f.value !== null && f.value !== undefined && f.value !== '');
    if (!shown.length) return null;
    return (
        <>
            {shown.map((f, i) => (
                <span key={i} className={cn('eh-fact', f.tone && `eh-fact--${f.tone}`)}>
                    {f.tone ? <i aria-hidden="true" /> : null}
                    <b>{f.value}</b>
                    {f.label ? <em>{f.label}</em> : null}
                </span>
            ))}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Numbers                                                            */
/* ------------------------------------------------------------------ */

export type PageHeaderNumberTarget =
    | { href: string; onClick?: () => void }
    | { href?: string; onClick: () => void };

/** One number box: the number, then its label and a short context line. Always a link. */
export function PageHeaderNumber({
    value,
    label,
    caption,
    tone,
    percent,
    href,
    onClick,
    preserveState,
    preserveScroll,
    pressed,
    ariaLabel,
}: PageHeaderNumberTarget & {
    value: ReactNode;
    label: string;
    caption?: ReactNode;
    tone?: PageHeaderStripTone;
    /** Show the number as a percentage closing a small ring (0–100). */
    percent?: number;
    preserveState?: boolean;
    preserveScroll?: boolean;
    pressed?: boolean;
    ariaLabel?: string;
}) {
    const body = (
        <>
            <ArrowUpRight aria-hidden="true" className="eh-number-go" />
            <b className="eh-number-value">
                {percent !== undefined ? (
                    <span
                        aria-hidden="true"
                        className="eh-number-ring"
                        style={{ ['--p' as string]: Math.max(0, Math.min(100, percent)) }}
                    />
                ) : null}
                {value}
            </b>
            <span className="eh-number-text">
                <em>{label}</em>
                {caption ? <small>{caption}</small> : null}
            </span>
        </>
    );
    const shared = {
        'aria-label': ariaLabel ?? `View ${label.toLowerCase()}`,
        className: cn(
            'eh-number outline-none focus-visible:ring-2 focus-visible:ring-band-foreground/70',
            tone && `eh-number--${tone}`,
        ),
    };
    return href ? (
        <Link href={href} preserveState={preserveState} preserveScroll={preserveScroll} onClick={onClick} {...shared}>
            {body}
        </Link>
    ) : (
        <button type="button" onClick={onClick} aria-pressed={pressed} {...shared}>
            {body}
        </button>
    );
}

/** Up to four equal number boxes. A fifth number belongs in the page body, not here. */
export function PageHeaderNumbers({ children }: { children: ReactNode }) {
    return <div className="eh-numbers">{children}</div>;
}

/* ------------------------------------------------------------------ */
/*  Stages                                                             */
/* ------------------------------------------------------------------ */

export interface PageHeaderStage {
    label: string;
    /** Who and when, short ("Rāwiri Kingi · Mon 5 Oct"). */
    meta?: ReactNode;
    state: 'done' | 'now' | 'todo';
    /** Colour of the current stage when it needs attention. */
    tone?: PageHeaderStripTone;
}

export interface PageHeaderNextStep {
    /** Small kicker ("Next step", "Next step · due today"). */
    label?: string;
    /** The next step in one line ("Aroha Ngata records Jordan's recovery"). */
    text: ReactNode;
    /** One quieter line under it — who owns it, what it unlocks. */
    meta?: ReactNode;
    tone?: PageHeaderStripTone;
}

/**
 * A record's stage line. Done stages fill the line; the current stage is a
 * ring; later stages are hollow. The next step (optional) sits on top.
 */
export function PageHeaderStages({
    steps,
    next,
    ariaLabel = 'Progress',
}: {
    steps: PageHeaderStage[];
    next?: PageHeaderNextStep | null;
    ariaLabel?: string;
}) {
    if (!steps.length) return null;
    const done = steps.filter((s) => s.state === 'done').length;
    const lastDone = steps[steps.length - 1].state === 'done';
    // the filled part of the line runs to the current stage (half way past the last done one)
    const fraction = steps.length > 1 ? Math.min(1, Math.max(0, (done - (lastDone ? 1 : 0.5)) / (steps.length - 1))) : 1;
    const current = steps.find((s) => s.state === 'now');
    return (
        <div className="eh-stages">
            {next ? (
                <div className="eh-next">
                    <span className={cn('eh-next-kicker', next.tone && `eh-next-kicker--${next.tone}`)}>
                        <i aria-hidden="true" />
                        {next.label ?? 'Next step'}
                    </span>
                    <b>{next.text}</b>
                    {next.meta ? <small>{next.meta}</small> : null}
                </div>
            ) : null}
            <ol
                className="eh-stage-line"
                aria-label={current ? `${ariaLabel}: ${current.label}` : ariaLabel}
                style={{ ['--done' as string]: fraction.toFixed(3) }}
            >
                {steps.map((s, i) => (
                    <li
                        key={`${s.label}-${i}`}
                        aria-current={s.state === 'now' ? 'step' : undefined}
                        className={cn(
                            'eh-stage',
                            `eh-stage--${s.state}`,
                            s.state === 'now' && s.tone && `eh-stage--${s.tone}`,
                        )}
                    >
                        <i aria-hidden="true" />
                        <b>{s.label}</b>
                        {s.meta ? <small>{s.meta}</small> : null}
                        <span className="sr-only">
                            {s.state === 'done' ? ' (done)' : s.state === 'now' ? ' (current)' : ' (not started)'}
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
