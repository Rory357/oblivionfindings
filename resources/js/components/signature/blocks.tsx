/**
 * Signature page blocks — the body parts of the approved Health & safety drawings (Stephan, 23 Oct 2026, design
 * viewer v35). Each block answers one question in Oblivion's own shapes: the surface with its horizon line, the
 * ring (state and progress) and the Journey line. Styles: resources/css/signature-blocks.css.
 *
 * The props mirror the drawing blocks, so a page port reads straight from its drawing. Colour is never the only
 * signal: every ring sits beside words, and rings carry a screen-reader state.
 */
import { Link } from '@inertiajs/react';
import { ArrowRight, Repeat, type LucideIcon } from 'lucide-react';
import { Fragment, type CSSProperties, type MouseEvent, type ReactNode } from 'react';

import { EntityKebab, type MenuItem } from '@/components/lists/entity-menu';
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ */
/*  Shared bits                                                        */
/* ------------------------------------------------------------------ */

/** State of a ring glyph. `none` = not started / nothing recorded (dashed). */
export type SgTone = 'info' | 'warning' | 'success' | 'critical' | 'brand' | 'none';
export type SgStatusTone = 'critical' | 'warning' | 'success';

const TONE_WORDS: Record<SgTone, string> = {
    info: 'In progress',
    warning: 'Needs attention',
    success: 'Done',
    critical: 'Problem',
    brand: 'Current',
    none: 'Not done',
};

/** The inline ring glyph. Pass `label` to name the state for screen readers when the words beside it don't. */
export function Ring({ tone, label }: { tone?: SgTone | null; label?: string }) {
    return (
        <>
            <i aria-hidden="true" className={cn('sg-rg', tone && `sg-rg--${tone}`)} />
            {label ? <span className="sr-only">{label}: </span> : null}
        </>
    );
}

/** A small text action in a block's head: a page link or an in-page button. */
export interface SgAction {
    label: string;
    href?: string;
    onClick?: () => void;
}

function ActionLink({ action, className }: { action: SgAction; className?: string }) {
    if (action.href) {
        return (
            <Link href={action.href} className={cn('sg-act', className)} onClick={action.onClick}>
                {action.label}
            </Link>
        );
    }
    return (
        <button type="button" className={cn('sg-act', className)} onClick={action.onClick}>
            {action.label}
        </button>
    );
}

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0])
            .join('')
            .toUpperCase() || '?'
    );
}

/** The person chip used in lanes and footers: initials in the tint, then the name. */
export function PersonChip({ name }: { name: string }) {
    return (
        <span className="sg-person">
            <span aria-hidden="true">{initials(name)}</span>
            {name}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Surface                                                            */
/* ------------------------------------------------------------------ */

export interface SurfaceProps {
    title?: string;
    sub?: ReactNode;
    action?: SgAction | null;
    /** The horizon line takes a status tone when the block carries one. */
    tone?: SgStatusTone | null;
    className?: string;
    children?: ReactNode;
    'aria-label'?: string;
}

/** A card with the horizon line on its top edge and an optional head (title · sub · action). */
export function Surface({ title, sub, action, tone, className, children, ...rest }: SurfaceProps) {
    return (
        <section
            aria-label={rest['aria-label']}
            className={cn('sg-surface', tone && `sg-surface--${tone}`, className)}
        >
            {title ? (
                <div className="sg-head">
                    <h3>{title}</h3>
                    {sub ? <p>{sub}</p> : null}
                    {action ? <ActionLink action={action} /> : null}
                </div>
            ) : null}
            {children}
        </section>
    );
}

/** A plain section title for card grids (people, places) — no surface around the grid. */
export function SectionHead({ title, sub, action }: { title: string; sub?: ReactNode; action?: SgAction | null }) {
    return (
        <div className="sg-section-head">
            <h3>{title}</h3>
            {sub ? <p>{sub}</p> : null}
            {action ? <ActionLink action={action} /> : null}
        </div>
    );
}

/** A narrow side column: block heads put their caveat under the title; checklists go one per line. */
export function SideColumn({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('sg-side grid content-start gap-5', className)}>{children}</div>;
}

/** Two (or more) blocks side by side — a map beside its list. `cols` is a CSS grid-template-columns value. */
export function SideBySide({ cols = 'minmax(0, 1fr) minmax(0, 1fr)', children }: { cols?: string; children: ReactNode }) {
    return (
        <div className="sg-row" style={{ gridTemplateColumns: cols }}>
            {children}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Tally                                                              */
/* ------------------------------------------------------------------ */

export interface TallyItem {
    value: ReactNode;
    label: string;
    sub?: ReactNode;
    tone?: SgTone;
    /** Where the number lives — the number and its label become a link. */
    href?: string;
}

/** Related numbers on one line. Zeros fold into one calm sentence ("Nothing overdue or unassigned"). */
export function Tally({ title, sub, action, items, calm, keepZeros = false, ...rest }: Omit<SurfaceProps, 'children'> & { items: TallyItem[]; calm?: string; /** Measures where zero is a fact to show (rates, days since). */ keepZeros?: boolean }) {
    const isZero = (v: ReactNode) => !keepZeros && String(v).trim() === '0';
    const zeros = items.filter((i) => isZero(i.value));
    const shown = items.filter((i) => !isZero(i.value));
    const sentence = calm ?? (zeros.length ? `Nothing ${zeros.map((z) => z.label.toLowerCase()).join(' or ')}` : '');
    return (
        <Surface title={title} sub={sub} action={action} aria-label={rest['aria-label']}>
            <div className="sg-tally">
                {shown.map((i) => {
                    const body = (
                        <>
                            <b>{i.value}</b>
                            <span>
                                {i.tone ? <Ring tone={i.tone} /> : null}
                                {i.label}
                            </span>
                            {i.sub ? <small>{i.sub}</small> : null}
                        </>
                    );
                    return i.href ? (
                        <Link key={i.label} href={i.href} className="sg-t sg-t--link">
                            {body}
                        </Link>
                    ) : (
                        <div key={i.label} className="sg-t">
                            {body}
                        </div>
                    );
                })}
                {sentence ? (
                    <p className="sg-calm">
                        <Ring tone="success" />
                        {sentence}
                    </p>
                ) : null}
            </div>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Journey                                                            */
/* ------------------------------------------------------------------ */

export interface JourneyStep {
    label: string;
    meta?: ReactNode;
    state: 'done' | 'now' | 'todo';
    tone?: 'critical' | 'warning';
}

/** How far along the line is filled: to the current stage, half way past the last done one. */
export function journeyFraction(steps: { state: string }[]): number {
    if (steps.length < 2) return 1;
    const done = steps.filter((s) => s.state === 'done').length;
    const lastDone = steps[steps.length - 1].state === 'done';
    return Math.min(1, Math.max(0, (done - (lastDone ? 1 : 0.5)) / (steps.length - 1)));
}

/** The Journey line on its own (steps as stations) — for use inside other blocks. */
export function JourneyLine({ steps, label = 'Progress' }: { steps: JourneyStep[]; label?: string }) {
    const current = steps.find((s) => s.state === 'now');
    return (
        <ol
            className="sg-line"
            aria-label={current ? `${label}: ${current.label}` : label}
            style={{ '--done': journeyFraction(steps).toFixed(3) } as CSSProperties}
        >
            {steps.map((s, i) => (
                <li
                    key={`${s.label}-${i}`}
                    aria-current={s.state === 'now' ? 'step' : undefined}
                    className={cn(
                        'sg-st',
                        `sg-st--${s.state}`,
                        s.tone && `sg-st--${s.tone}`,
                        i === steps.length - 1 && s.state === 'done' && 'sg-st--end',
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
    );
}

/** A Journey block: the line in a surface, with an optional caption under a dashed rule. */
export function Journey({ title, sub, action, steps, caption }: Omit<SurfaceProps, 'children'> & { steps: JourneyStep[]; caption?: ReactNode }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            <div className="sg-journey">
                <JourneyLine steps={steps} label={title} />
                {caption ? <div className="sg-jcap">{caption}</div> : null}
            </div>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Ring card + checks                                                 */
/* ------------------------------------------------------------------ */

export interface CheckItem {
    label: ReactNode;
    state: SgTone;
    note?: ReactNode;
}

/** A checklist read by its rings — no badge per row. */
export function Checks({ items, single = false }: { items: CheckItem[]; single?: boolean }) {
    if (!items.length) return null;
    return (
        <ul className={cn('sg-checks', single && 'sg-checks--single')}>
            {items.map((c, i) => (
                <li key={i}>
                    <Ring tone={c.state} label={TONE_WORDS[c.state]} />
                    <span>{c.label}</span>
                    {c.note ? <small>{c.note}</small> : null}
                </li>
            ))}
        </ul>
    );
}

/**
 * Progress closes the ring (value of `of`); a closed ring has a centre. With `mark` the ring is a full state ring
 * in its tone around a short word ("No") — use it for a state that is not progress.
 */
export function RingCard({
    value = 0,
    of,
    mark,
    tone,
    label,
    sub,
    action,
    checks = [],
    className,
}: {
    value?: number;
    of?: number;
    mark?: string;
    tone?: SgStatusTone;
    label: string;
    sub?: ReactNode;
    action?: SgAction | null;
    checks?: CheckItem[];
    className?: string;
}) {
    const percent = of ? Math.round((value / of) * 100) : value;
    const full = !mark && percent >= 100;
    return (
        <Surface className={className} tone={mark ? tone : undefined}>
            <div className="sg-ringrow">
                {mark ? (
                    <span className={cn('sg-ringw sg-ringw--mark', tone && `sg-ringw--${tone}`)}>
                        <em>{mark}</em>
                    </span>
                ) : (
                    <span
                        className={cn('sg-ringw', full && 'sg-ringw--full')}
                        role="img"
                        aria-label={of ? `${value} of ${of}` : `${percent}%`}
                    >
                        <span className="sg-ringp" style={{ '--p': Math.max(0, Math.min(100, percent)) } as CSSProperties} />
                        {full ? null : <em aria-hidden="true">{percent}%</em>}
                    </span>
                )}
                <div className="min-w-0">
                    <h3>{label}</h3>
                    {sub ? <p>{sub}</p> : null}
                </div>
                {action ? <ActionLink action={action} /> : null}
            </div>
            <Checks items={checks} />
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Log                                                                */
/* ------------------------------------------------------------------ */

export interface LogItem {
    key?: string | number;
    time?: ReactNode;
    tone?: SgTone;
    title: ReactNode;
    sub?: ReactNode;
    action?: SgAction | null;
    /** The row's menu: a kebab at the end, and the same items on right-click (DESIGN.md entity rows). */
    menu?: MenuItem[];
    onContextMenu?: (event: MouseEvent<HTMLElement>) => void;
}

/** Log rows — time first, the ring for state, then words. */
export function Log({ title, sub, action, items, limit = 8 }: Omit<SurfaceProps, 'children'> & { items: LogItem[]; limit?: number }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            <ol className="sg-log">
                {items.slice(0, limit).map((i, n) => (
                    <li key={i.key ?? n} className="sg-lr" onContextMenu={i.onContextMenu}>
                        <time>{i.time ?? ''}</time>
                        {i.tone ? <Ring tone={i.tone} /> : <span />}
                        <div className="min-w-0">
                            <b>{i.title}</b>
                            {i.sub ? <small>{i.sub}</small> : null}
                        </div>
                        <span className="inline-flex items-center gap-2">
                            {i.action ? <ActionLink action={i.action} className="ml-0" /> : null}
                            {i.menu?.length ? <EntityKebab actions={i.menu} /> : null}
                        </span>
                    </li>
                ))}
            </ol>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  People and places                                                  */
/* ------------------------------------------------------------------ */

export interface PersonCardItem {
    name: string;
    sub?: ReactNode;
    /** `on` = on shift now (live ring); `away` = on leave (dashed). */
    presence?: 'on' | 'away';
    /** One flag under the name — a <StatusBadge>. */
    flag?: ReactNode;
    foot?: ReactNode;
    href?: string;
}

/** People as cards: the avatar ring, their role, one flag, one footer line. */
export function PeopleCards({ title, sub, action, cols = 3, items }: { title?: string; sub?: ReactNode; action?: SgAction | null; cols?: number; items: PersonCardItem[] }) {
    return (
        <section>
            {title ? <SectionHead title={title} sub={sub} action={action} /> : null}
            <ul className="sg-cards" style={{ '--cols': cols } as CSSProperties}>
                {items.map((p) => {
                    const body = (
                        <>
                            <div className="sg-ph">
                                <span aria-hidden="true" className={cn('sg-av', p.presence && `sg-av--${p.presence}`)}>
                                    {initials(p.name)}
                                </span>
                                <div>
                                    <b>{p.name}</b>
                                    {p.sub ? <small>{p.sub}</small> : null}
                                    {p.flag ? <span className="sg-flag">{p.flag}</span> : null}
                                </div>
                            </div>
                            {p.foot ? <div className="sg-pf">{p.foot}</div> : null}
                        </>
                    );
                    return (
                        <li key={p.name} className="sg-surface sg-p">
                            {p.href ? (
                                <Link href={p.href} className="grid gap-3 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    {body}
                                </Link>
                            ) : (
                                body
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

export interface PlaceCardItem {
    name: string;
    sub?: ReactNode;
    flag?: ReactNode;
    facts?: { tone: SgTone; text: ReactNode }[];
    href?: string;
}

/** Places (or options, registers) as cards: name, one flag, facts read by their rings. */
export function PlaceCards({ title, sub, action, cols = 3, items, lines = false }: { title?: string; sub?: ReactNode; action?: SgAction | null; cols?: number; items: PlaceCardItem[]; lines?: boolean }) {
    return (
        <section>
            {title ? <SectionHead title={title} sub={sub} action={action} /> : null}
            <ul className="sg-cards" style={{ '--cols': cols } as CSSProperties}>
                {items.map((p) => {
                    const body = (
                        <>
                            <div className="sg-ph">
                                <div>
                                    <b>{p.name}</b>
                                    {p.sub ? <small>{p.sub}</small> : null}
                                    {p.flag ? <span className="sg-flag">{p.flag}</span> : null}
                                </div>
                            </div>
                            {p.facts?.length ? (
                                <div className={cn('sg-facts2', lines && 'sg-facts2--lines')}>
                                    {p.facts.map((f, i) => (
                                        <span key={i}>
                                            <Ring tone={f.tone} />
                                            <span>{f.text}</span>
                                        </span>
                                    ))}
                                </div>
                            ) : null}
                        </>
                    );
                    return (
                        <li key={p.name} className="sg-surface sg-place">
                            {p.href ? (
                                <Link href={p.href} className="grid gap-2.5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    {body}
                                </Link>
                            ) : (
                                body
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/*  Quiet line, know list, story                                       */
/* ------------------------------------------------------------------ */

/** One quiet line for a small empty — in its place, not a full empty state. */
export function QuietLine({ title, sub, text, action }: { title?: string; sub?: ReactNode; text: ReactNode; action?: SgAction | null }) {
    return (
        <Surface title={title} sub={sub}>
            <p className="sg-quiet">
                <Ring tone="none" />
                <span>
                    {text}
                    {action ? (
                        <>
                            {' '}
                            <ActionLink action={action} className="ml-0" />
                        </>
                    ) : null}
                </span>
            </p>
        </Surface>
    );
}

/** Facts worth knowing, one per line, read by their rings. Keep a rule's wording exactly as it is. */
export function KnowList({ title, sub, action, items }: Omit<SurfaceProps, 'children'> & { items: { tone?: SgTone; text: ReactNode }[] }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            <ul className="sg-know">
                {items.map((i, n) => (
                    <li key={n}>
                        <Ring tone={i.tone ?? 'info'} />
                        <span>{i.text}</span>
                    </li>
                ))}
            </ul>
        </Surface>
    );
}

export interface StoryFact {
    icon?: LucideIcon;
    text: ReactNode;
}

/** What happened, told as a story: a row of fact chips, then short headed paragraphs. */
export function Story({ title, sub, action, facts = [], paras }: Omit<SurfaceProps, 'children'> & { facts?: StoryFact[]; paras: { h: string; t: ReactNode }[] }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            {facts.length ? (
                <div className="sg-chips">
                    {facts.map((f, i) => (
                        <span key={i} className="sg-chip">
                            {f.icon ? <f.icon aria-hidden="true" className="size-3.5" /> : null}
                            <span>{f.text}</span>
                        </span>
                    ))}
                </div>
            ) : null}
            <dl className="sg-paras">
                {paras.map((p, i) => (
                    <div key={i} className="sg-para">
                        <dt>{p.h}</dt>
                        <dd>{p.t}</dd>
                    </div>
                ))}
            </dl>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Flow (pipeline)                                                    */
/* ------------------------------------------------------------------ */

export interface FlowStage {
    label: string;
    n: ReactNode;
    sub?: ReactNode;
    tone?: 'critical' | 'warning' | 'info' | 'success';
    items?: { tone?: SgTone; text: string; href?: string }[];
}

/** The pipeline: how many wait at each stage, the oldest named, joined by the Journey line. */
export function Flow({ title, sub, action, stages, note }: Omit<SurfaceProps, 'children'> & { stages: FlowStage[]; note?: ReactNode }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            <ol className="sg-flow" style={{ '--n': stages.length } as CSSProperties}>
                {stages.map((s) => (
                    <li key={s.label} className={cn('sg-fs', s.tone && `sg-fs--${s.tone}`)}>
                        <i aria-hidden="true" />
                        <span>{s.label}</span>
                        <b>{s.n}</b>
                        {s.sub ? <small>{s.sub}</small> : null}
                        {(s.items ?? []).slice(0, 2).map((it, i) =>
                            it.href ? (
                                <Link key={i} href={it.href}>
                                    <Ring tone={it.tone} />
                                    <span>{it.text}</span>
                                </Link>
                            ) : (
                                <small key={i} className="flex items-center gap-1.5">
                                    <Ring tone={it.tone} />
                                    <span className="truncate">{it.text}</span>
                                </small>
                            ),
                        )}
                    </li>
                ))}
            </ol>
            {note ? <div className="sg-foot">{note}</div> : null}
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Risk map                                                           */
/* ------------------------------------------------------------------ */

export const RISK_LIKELIHOOD = ['Almost certain', 'Likely', 'Possible', 'Unlikely', 'Rare'];
export const RISK_CONSEQUENCE = ['Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'];

/** The risk bands the H&S code uses: low 1–4, medium 5–9, high 10–15, extreme 16–25. */
export function riskBand(score: number): 'low' | 'medium' | 'high' | 'extreme' {
    if (score >= 16) return 'extreme';
    if (score >= 10) return 'high';
    if (score >= 5) return 'medium';
    return 'low';
}

/**
 * The risk map: likelihood (rows, most likely first) × consequence (columns), coloured by band. `cells[r][c]` is
 * how many assessments sit in that cell; `mark` = [row, col] rings one cell (this record).
 */
export function RiskMap({
    title,
    sub,
    action,
    cells = [],
    mark,
    views,
    view = 0,
    onView,
    note,
    rows = RISK_LIKELIHOOD,
    cols = RISK_CONSEQUENCE,
}: Omit<SurfaceProps, 'children'> & {
    cells?: number[][];
    mark?: [number, number] | null;
    views?: string[];
    view?: number;
    onView?: (index: number) => void;
    note?: ReactNode;
    rows?: string[];
    cols?: string[];
}) {
    return (
        <Surface title={title} sub={sub} action={action}>
            {views?.length ? (
                <div className="sg-seg" role="group" aria-label="Risk view">
                    {views.map((v, i) => (
                        <button key={v} type="button" aria-pressed={i === view} onClick={() => onView?.(i)}>
                            {v}
                        </button>
                    ))}
                </div>
            ) : null}
            <div className="sg-matrix">
                <span className="sg-matrix-y" aria-hidden="true">
                    Likelihood
                </span>
                <div className="sg-mx" role="table" aria-label="Risk map: likelihood by consequence">
                    {rows.map((l, r) => (
                        <Fragment key={l}>
                            <span className="sg-ml" role="rowheader">
                                {l}
                            </span>
                            {cols.map((c, ci) => {
                                const score = (rows.length - r) * (ci + 1);
                                const n = cells[r]?.[ci] ?? 0;
                                const marked = mark && mark[0] === r && mark[1] === ci;
                                return (
                                    <span
                                        key={c}
                                        role="cell"
                                        title={`${l} × ${c}: ${score} (${riskBand(score)})`}
                                        aria-label={`${l}, ${c}: score ${score}, ${riskBand(score)}${n ? `, ${n} assessment${n === 1 ? '' : 's'}` : ''}${marked ? ', this assessment' : ''}`}
                                        className={cn('sg-mc', `sg-mc--${riskBand(score)}`, n > 0 && 'sg-mc--has', marked && 'sg-mc--mark')}
                                    >
                                        {n ? <b>{n}</b> : <em>{score}</em>}
                                    </span>
                                );
                            })}
                        </Fragment>
                    ))}
                    <span />
                    {cols.map((c) => (
                        <span key={c} className="sg-mcl" role="columnheader">
                            {c}
                        </span>
                    ))}
                </div>
                <span className="sg-matrix-x" aria-hidden="true">
                    Consequence
                </span>
            </div>
            <div className="sg-mleg">
                {(
                    [
                        ['low', 'Low 1–4'],
                        ['medium', 'Medium 5–9'],
                        ['high', 'High 10–15'],
                        ['extreme', 'Extreme 16–25'],
                    ] as const
                ).map(([k, t]) => (
                    <span key={k}>
                        <i aria-hidden="true" className={cn('sg-mc', `sg-mc--${k}`)} />
                        {t}
                    </span>
                ))}
                {note ? <em>{note}</em> : null}
            </div>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Duty clock                                                         */
/* ------------------------------------------------------------------ */

/** A duty with a clock: how long since we knew, the duty in the law's own words, then the action. */
export function DutyClock({
    tone,
    big,
    unit,
    kicker,
    title,
    sub,
    lines = [],
    actions,
}: {
    tone?: SgStatusTone;
    big: ReactNode;
    unit?: string;
    kicker?: string;
    title: string;
    sub?: ReactNode;
    lines?: { state?: SgTone; text: ReactNode }[];
    /** Buttons for the duty (secondary first, the one primary last). */
    actions?: ReactNode;
}) {
    return (
        <Surface tone={tone}>
            <div className="sg-clock-m">
                <span className="sg-cring">
                    <b>{big}</b>
                    {unit ? <small>{unit}</small> : null}
                </span>
                <div className="sg-clock-t">
                    {kicker ? <span className="sg-kick">{kicker}</span> : null}
                    <h3>{title}</h3>
                    {sub ? <p>{sub}</p> : null}
                </div>
            </div>
            {lines.length ? (
                <ul className="sg-clock-l">
                    {lines.map((l, i) => (
                        <li key={i}>
                            <Ring tone={l.state ?? 'none'} label={TONE_WORDS[l.state ?? 'none']} />
                            <span>{l.text}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
            {actions ? <div className="sg-actions">{actions}</div> : null}
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Queue                                                              */
/* ------------------------------------------------------------------ */

export interface QueueItem {
    key: string | number;
    title: string;
    sub?: ReactNode;
    age?: ReactNode;
    tone?: SgTone;
    badges?: ReactNode;
}

/** A queue in a split workspace: the items waiting, the selected one marked. */
export function Queue({ title, sub, items, selected, onSelect }: { title?: string; sub?: ReactNode; items: QueueItem[]; selected?: string | number | null; onSelect?: (key: string | number) => void }) {
    return (
        <Surface title={title} sub={sub}>
            <div className="sg-queue">
                {items.map((i) => (
                    <button key={i.key} type="button" className="sg-qi" aria-current={i.key === selected ? 'true' : undefined} onClick={() => onSelect?.(i.key)}>
                        <span className="sg-qi-h">
                            {i.tone ? <Ring tone={i.tone} /> : null}
                            <b>{i.title}</b>
                            {i.age ? <span>{i.age}</span> : null}
                        </span>
                        {i.sub ? <small>{i.sub}</small> : null}
                        {i.badges ? <span className="sg-qb">{i.badges}</span> : null}
                    </button>
                ))}
            </div>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Stage dots (a table cell)                                          */
/* ------------------------------------------------------------------ */

/**
 * Where a record is up to, in a table cell: its stages as dots on the line, the stage name, an optional note.
 * `stage` is 1-based; `tone` colours the current dot (success = finished, all filled).
 */
export function StageDots({ stage, of, label, note, tone }: { stage: number; of: number; label: string; note?: ReactNode; tone?: 'critical' | 'warning' | 'success' }) {
    return (
        <div className={cn('sg-stg', tone && `sg-stg--${tone}`)}>
            <span className="sg-dots" aria-hidden="true">
                {Array.from({ length: of }, (_, i) => (
                    <i key={i} className={cn(i + 1 < stage && 'sg-dot--done', i + 1 === stage && 'sg-dot--now')} />
                ))}
            </span>
            <small>
                {label}
                <span className="sr-only">
                    {' '}
                    (stage {stage} of {of})
                </span>
            </small>
            {note ? <small>{note}</small> : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Choices, routes, evidence windows (settings as pictures)           */
/* ------------------------------------------------------------------ */

export interface ChoiceOption {
    title: string;
    on?: boolean;
    badge?: string;
    text?: ReactNode;
    flow?: string[];
    canTitle?: string;
    can?: { label: string; state: SgTone }[];
    meta?: ReactNode;
}

/** Options side by side, the one in use marked; each shows what it does (a small flow, or who can do what). */
export function Choices({ title, sub, action, cols, options, actions }: Omit<SurfaceProps, 'children'> & { cols?: number; options: ChoiceOption[]; actions?: ReactNode }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            <ul className="sg-choices" style={{ '--cols': cols ?? (options.length || 2) } as CSSProperties}>
                {options.map((o) => (
                    <li key={o.title} className={cn('sg-ch', o.on && 'sg-ch--on')}>
                        <div className="sg-ch-h">
                            <Ring tone={o.on ? 'success' : 'none'} />
                            <h4>{o.title}</h4>
                            {o.badge ? <span className="sg-ch-badge">{o.badge}</span> : null}
                            {o.on ? <span className="sg-ch-on">In use</span> : null}
                        </div>
                        {o.text ? <p>{o.text}</p> : null}
                        {o.flow?.length ? (
                            <div className="sg-chflow">
                                {o.flow.map((f, i) => (
                                    <Fragment key={f}>
                                        {i ? (
                                            <span className="sg-arrow" aria-hidden="true">
                                                <ArrowRight className="size-3.5" />
                                            </span>
                                        ) : null}
                                        <span className="sg-pill">{f}</span>
                                    </Fragment>
                                ))}
                            </div>
                        ) : null}
                        {o.can?.length ? (
                            <div className="sg-can">
                                {o.canTitle ? <small>{o.canTitle}</small> : null}
                                {o.can.map((c) => (
                                    <span key={c.label}>
                                        <Ring tone={c.state} label={c.state === 'success' ? 'Can' : c.state === 'none' ? 'Cannot' : TONE_WORDS[c.state]} />
                                        {c.label}
                                    </span>
                                ))}
                            </div>
                        ) : null}
                        {o.meta ? <small className="sg-ch-meta">{o.meta}</small> : null}
                    </li>
                ))}
            </ul>
            {actions ? <div className="sg-actions">{actions}</div> : null}
        </Surface>
    );
}

export interface RouteRow {
    place: string;
    sub?: ReactNode;
    icon?: LucideIcon;
    lanes: { type: string; who: string; cover?: string; coverMeta?: string; tone?: 'warning' }[];
}

/** Where new incidents go: each Site, its kinds of incident and the person they go to (with cover), then the fallback. */
export function Routes({ title, sub, action, rows, fallback, actions }: Omit<SurfaceProps, 'children'> & { rows: RouteRow[]; fallback?: { title?: string; steps: { who?: string; label?: string; meta?: string }[] }; actions?: ReactNode }) {
    return (
        <Surface title={title} sub={sub} action={action}>
            <ul className="sg-routes">
                {rows.map((r) => (
                    <li key={r.place} className="sg-rt">
                        <div className="sg-rt-p">
                            {r.icon ? <r.icon aria-hidden="true" className="size-4 text-primary" /> : null}
                            <div>
                                <b>{r.place}</b>
                                {r.sub ? <small>{r.sub}</small> : null}
                            </div>
                        </div>
                        <span className="sg-arrow" aria-hidden="true">
                            <ArrowRight className="size-4" />
                        </span>
                        <div className="sg-lanes">
                            {r.lanes.map((l) => (
                                <div key={l.type} className={cn('sg-lane', l.tone && `sg-lane--${l.tone}`)}>
                                    <small>{l.type}</small>
                                    <PersonChip name={l.who} />
                                    {l.cover ? (
                                        <span className="sg-cover">
                                            <Repeat aria-hidden="true" className="size-3" />
                                            Cover: <b>{l.cover}</b>
                                            {l.coverMeta ? ` · ${l.coverMeta}` : ''}
                                        </span>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    </li>
                ))}
            </ul>
            {fallback ? (
                <div className="sg-fallback">
                    <strong>{fallback.title ?? 'If nobody can take it'}</strong>
                    {fallback.steps.map((s, i) => (
                        <Fragment key={i}>
                            {i ? (
                                <span className="sg-arrow" aria-hidden="true">
                                    <ArrowRight className="size-3.5" />
                                </span>
                            ) : null}
                            <span className="sg-fb-step">
                                {s.who ? <PersonChip name={s.who} /> : <b>{s.label}</b>}
                                {s.meta ? <small>{s.meta}</small> : null}
                            </span>
                        </Fragment>
                    ))}
                </div>
            ) : null}
            {actions ? <div className="sg-actions">{actions}</div> : null}
        </Surface>
    );
}

export interface EvidenceWindowRow {
    name: string;
    where: string;
    /** Minutes reviewed [before, after] the incident. */
    review: [number, number];
    /** Longest allowed [before, after]. */
    max: [number, number];
    sources?: { icon?: LucideIcon; label: string }[];
}

function minutes(m: number): string {
    if (m < 1) return `${Math.round(m * 60)} s`;
    if (m >= 60) return `${Math.round((m / 60) * 10) / 10} h`;
    return `${Math.round(m * 10) / 10} min`;
}

/** Evidence windows as time bars around the moment of the incident: what is reviewed, and the longest allowed. */
export function EvidenceWindows({ title, sub, action, before = 60, after = 120, rows, actions, ...rest }: Omit<SurfaceProps, 'children'> & { before?: number; after?: number; rows: EvidenceWindowRow[]; actions?: ReactNode }) {
    const total = before + after;
    const pc = (m: number) => `${((m / total) * 100).toFixed(2)}%`;
    return (
        <Surface title={title} sub={sub} action={action} aria-label={rest['aria-label']}>
            <div className="sg-windows">
                <div className="sg-wn-axis" aria-hidden="true">
                    <span />
                    <div className="sg-wn-scale">
                        <span style={{ left: 0 }}>{minutes(before)} before</span>
                        <span className="sg-wn-incident" style={{ left: pc(before) }}>
                            Incident
                        </span>
                        <span style={{ right: 0 }}>{minutes(after)} after</span>
                    </div>
                    <span />
                </div>
                {rows.map((r) => (
                    <div key={r.name} className="sg-wn-r">
                        <div className="sg-wn-n">
                            <b>{r.name}</b>
                            <small>{r.where}</small>
                        </div>
                        <div className="sg-wn-bar">
                            <i aria-hidden="true" className="sg-wn-track" />
                            <i aria-hidden="true" className="sg-wn-max" style={{ left: pc(before - r.max[0]), width: pc(r.max[0] + r.max[1]) }} />
                            <i aria-hidden="true" className="sg-wn-rev" style={{ left: pc(before - r.review[0]), width: pc(r.review[0] + r.review[1]) }} />
                            <i aria-hidden="true" className="sg-wn-mid" style={{ left: pc(before) }} />
                            <small className="sg-wn-lab">
                                Reviews {minutes(r.review[0])} before · {minutes(r.review[1])} after{' '}
                                <em>
                                    · up to {minutes(r.max[0])} before · {minutes(r.max[1])} after
                                </em>
                            </small>
                        </div>
                        <div className="sg-wn-s">
                            {(r.sources ?? []).map((s) => (
                                <span key={s.label} className="sg-pill">
                                    {s.icon ? <s.icon aria-hidden="true" className="size-3.5" /> : null}
                                    {s.label}
                                </span>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
            <div className="sg-wn-leg" aria-hidden="true">
                <span>
                    <i className="sg-wn-rev" />
                    Reviewed
                </span>
                <span>
                    <i className="sg-wn-max" />
                    Longest allowed
                </span>
            </div>
            {actions ? <div className="sg-actions">{actions}</div> : null}
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Row list, fact rows, note                                          */
/* ------------------------------------------------------------------ */

export interface RowItem {
    key?: string | number;
    title: ReactNode;
    sub?: ReactNode;
    /** Right side: a <StatusBadge> (one status per row). */
    badge?: ReactNode;
    /** Right side, after the badge: a short time ("2 days ago"). */
    time?: ReactNode;
    /** Left: an icon or avatar in the tint circle. */
    leading?: ReactNode;
    /** The whole row opens the record. */
    href?: string;
    /** The row's menu: a kebab at the end, and the same items on right-click (DESIGN.md entity rows). */
    menu?: MenuItem[];
    onContextMenu?: (event: MouseEvent<HTMLElement>) => void;
}

/** A list of records in a surface: title and sub-line, one status on the right; the whole row opens it. */
export function RowList({ title, sub, action, items, empty, limit }: Omit<SurfaceProps, 'children'> & { items: RowItem[]; empty?: ReactNode; limit?: number }) {
    const shown = limit ? items.slice(0, limit) : items;
    return (
        <Surface title={title} sub={sub} action={action}>
            {shown.length ? (
                <ul className={cn('sg-list', title && 'mt-2')}>
                    {shown.map((i, n) => {
                        const body = (
                            <>
                                {i.leading ? <span className="sg-li-lead">{i.leading}</span> : null}
                                <span className="sg-li-t">
                                    <b>{i.title}</b>
                                    {i.sub ? <small>{i.sub}</small> : null}
                                </span>
                                {i.badge ?? null}
                                {i.time ? <span className="sg-li-time">{i.time}</span> : null}
                            </>
                        );
                        return (
                            <li key={i.key ?? n} className="flex items-center" onContextMenu={i.onContextMenu}>
                                {i.href ? (
                                    <Link href={i.href} className="sg-li min-w-0 flex-1">
                                        {body}
                                    </Link>
                                ) : (
                                    <div className="sg-li min-w-0 flex-1">{body}</div>
                                )}
                                {i.menu?.length ? <EntityKebab actions={i.menu} className="mr-3" /> : null}
                            </li>
                        );
                    })}
                </ul>
            ) : empty ? (
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>{empty}</span>
                </p>
            ) : null}
        </Surface>
    );
}

/** Facts as label and value rows. */
export function FactRows({ title, sub, action, rows, ...rest }: Omit<SurfaceProps, 'children'> & { rows: [ReactNode, ReactNode][] }) {
    return (
        <Surface title={title} sub={sub} action={action} aria-label={rest['aria-label']}>
            <dl className={cn('sg-kv', title && 'mt-1')}>
                {rows.map(([k, v], i) => (
                    <Fragment key={i}>
                        <dt>{k}</dt>
                        <dd>{v}</dd>
                    </Fragment>
                ))}
            </dl>
        </Surface>
    );
}

/** A note in its place: one tinted line with its icon. Status tones for warnings; keep safety wording as it is. */
export function Note({ tone, icon: Icon, children }: { tone?: SgStatusTone; icon?: LucideIcon; children: ReactNode }) {
    return (
        <div role={tone === 'critical' ? 'alert' : 'note'} className={cn('sg-note', tone && `sg-note--${tone}`)}>
            {Icon ? <Icon aria-hidden="true" className="size-4" /> : null}
            <div className="min-w-0">{children}</div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  A chart with a point                                               */
/* ------------------------------------------------------------------ */

/**
 * Bars with their values written on: the latest in the brand, the rest quiet, and one sentence that says what
 * the chart shows (the drawings' "point" chart). For dense or multi-series data use the module's chart instead.
 */
export function PointBars({ title, sub, action, values, labels, note, height = 140 }: Omit<SurfaceProps, 'children'> & { values: number[]; labels: string[]; note?: ReactNode; height?: number }) {
    const max = Math.max(1, ...values);
    const summary = values.map((v, i) => `${labels[i] ?? ''} ${v}`).join(', ');
    return (
        <Surface title={title} sub={sub} action={action}>
            <div className="sg-chart">
                <div className="sg-bars" style={{ height }} role="img" aria-label={`${title ?? 'Chart'}: ${summary}`}>
                    {values.map((v, i) => (
                        <div key={i} className={cn(i === values.length - 1 && 'sg-bar--last')}>
                            <b aria-hidden="true">{v}</b>
                            <i aria-hidden="true" style={{ height: Math.max(4, (v / max) * (height - 36)) }} />
                        </div>
                    ))}
                </div>
                <div className="sg-xl" aria-hidden="true">
                    {labels.map((l, i) => (
                        <span key={i}>{l}</span>
                    ))}
                </div>
                {note ? <p className="sg-chart-note">{note}</p> : null}
            </div>
        </Surface>
    );
}
