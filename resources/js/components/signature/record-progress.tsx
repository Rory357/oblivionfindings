/**
 * The Progress card (approved by Stephan 2026-11-01, H&S G3/G4 boards, decision 26; DESIGN.md "Records: status steps
 * in the header, the Progress card under it"). It sits directly under a record's header, whose round status steps
 * are the step picker:
 *   1. a fixed "Next step" bar holding the record's only filled button — it stays put whichever step is picked;
 *   2. the picked step's pane: what was added at that step (Edit where the server allows a correction), then
 *      "Activity at this step" with a link to the full log on the History tab, and an optional footnote.
 * A plain card: no coloured line on any edge (non-negotiable 8).
 */
import { ArrowRight, History, Info } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface RecordProgressNext {
    /** "Next step" (default), or e.g. "Closed". */
    kicker?: string;
    title: ReactNode;
    text?: ReactNode;
    /** The record's one filled button for this step (omit when this viewer has nothing to press). */
    action?: ReactNode;
    /** `done`: nothing left to do (the bar turns to the success tint). */
    tone?: 'done';
}

export interface RecordProgressItem {
    key: string;
    label: ReactNode;
    value: ReactNode;
    /** A quieter line under the value (who added it, when). */
    meta?: ReactNode;
    /** A text action at the end of the row ("Edit", "Add files", "Actions →"). */
    action?: ReactNode;
}

export interface RecordProgressActivity {
    key: string | number;
    time: ReactNode;
    who?: ReactNode;
    what: ReactNode;
    /** A correction (drawn with the brand dot instead of the done dot). */
    edit?: boolean;
}

export interface RecordProgressPane {
    title: ReactNode;
    meta?: ReactNode;
    /** Shown when the picked step isn't the one the record is on. */
    onBack?: () => void;
    items?: RecordProgressItem[];
    /** Anything a step needs beyond label/value rows (a list of actions, a pending correction). */
    children?: ReactNode;
    /** Empty-step sentence when nothing was added yet. */
    empty?: ReactNode;
    activity?: {
        items: RecordProgressActivity[];
        /** null while loading; a sentence when the log can't be read. */
        state?: 'loading' | 'error' | 'ready';
        onAll?: () => void;
    };
    note?: ReactNode;
}

export function RecordProgress({
    next,
    pane,
    label = 'Progress',
}: {
    next?: RecordProgressNext | null;
    pane?: RecordProgressPane | null;
    label?: string;
}) {
    return (
        <section aria-label={label} className="sg-surface sg-progress">
            {next ? (
                <div className={cn('sg-next', next.tone === 'done' && 'sg-next--done')}>
                    <span className="sg-next-k">{next.kicker ?? 'Next step'}</span>
                    <div className="sg-next-tx">
                        <b>{next.title}</b>
                        {next.text ? <span>{next.text}</span> : null}
                    </div>
                    {next.action ? <div className="sg-next-act">{next.action}</div> : null}
                </div>
            ) : null}
            {pane ? <ProgressPane pane={pane} /> : null}
        </section>
    );
}

function ProgressPane({ pane }: { pane: RecordProgressPane }) {
    const items = pane.items ?? [];
    const activity = pane.activity;
    return (
        <div className="sg-pane" aria-live="polite">
            <div className="sg-pane-t">
                <b>{pane.title}</b>
                {pane.meta ? <span>{pane.meta}</span> : null}
                {pane.onBack ? (
                    // eslint-disable-next-line no-restricted-syntax -- quiet text link back to the current step (.sg-pane-link).
                    <button type="button" className="sg-pane-link sg-pane-r" onClick={pane.onBack}>
                        Back to the current step
                        <ArrowRight aria-hidden="true" />
                    </button>
                ) : null}
            </div>
            {items.length ? (
                <dl className="sg-pane-items">
                    {items.map((item) => (
                        <div key={item.key}>
                            <dt>{item.label}</dt>
                            <dd>
                                {item.value}
                                {item.meta ? <small>{item.meta}</small> : null}
                            </dd>
                            <span>{item.action ?? null}</span>
                        </div>
                    ))}
                </dl>
            ) : null}
            {pane.children}
            {!items.length && !pane.children && pane.empty ? <p className="sg-pane-empty">{pane.empty}</p> : null}
            {activity ? (
                <div className="sg-alog">
                    <div className="sg-alog-h">
                        <History aria-hidden="true" />
                        Activity at this step
                        {activity.onAll ? (
                            // eslint-disable-next-line no-restricted-syntax -- quiet text link to the History tab (.sg-pane-link).
                            <button type="button" className="sg-pane-link sg-pane-r" onClick={activity.onAll}>
                                Full activity in History
                                <ArrowRight aria-hidden="true" />
                            </button>
                        ) : null}
                    </div>
                    {activity.state === 'loading' ? (
                        <p className="sg-alog-empty">Loading the activity…</p>
                    ) : activity.state === 'error' ? (
                        <p className="sg-alog-empty">The activity couldn’t be loaded. It is still kept in History.</p>
                    ) : activity.items.length ? (
                        <ol>
                            {activity.items.map((entry) => (
                                <li key={entry.key} className={cn('sg-ae', entry.edit && 'sg-ae--edit')}>
                                    <time>{entry.time}</time>
                                    <i aria-hidden="true" />
                                    <span>
                                        {entry.who ? <b>{entry.who}</b> : null} {entry.what}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="sg-alog-empty">Nothing recorded at this step yet.</p>
                    )}
                </div>
            ) : null}
            {pane.note ? (
                <p className="sg-pnote">
                    <Info aria-hidden="true" />
                    <span>{pane.note}</span>
                </p>
            ) : null}
        </div>
    );
}

/** A text action for a pane row ("Edit", "Add files"): brand text, no fill (the only filled button is the next step's). */
export function PaneLink({
    icon: Icon,
    children,
    onClick,
    href,
}: {
    icon?: typeof Info;
    children: ReactNode;
    onClick?: () => void;
    href?: string;
}) {
    const inner = (
        <>
            {Icon ? <Icon aria-hidden="true" /> : null}
            {children}
        </>
    );
    return href ? (
        <a className="sg-pane-link" href={href}>
            {inner}
        </a>
    ) : (
        // eslint-disable-next-line no-restricted-syntax -- the pane's quiet text action (.sg-pane-link): the only filled button is the next step's.
        <button type="button" className="sg-pane-link" onClick={onClick}>
            {inner}
        </button>
    );
}
