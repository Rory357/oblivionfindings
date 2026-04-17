import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    FileText,
    Pill,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  Handover read card — shown at clock-in                                    */
/* -------------------------------------------------------------------------- */
/*
 * PR 11 — Structured handover-read prompt surfaced on `/my-day` whenever the
 * arriving worker has a submitted (unacknowledged) handover waiting from the
 * previous shift. Sits directly above the clock-in card so the worker sees
 * "what they need to know" before starting.
 *
 * Stays deliberately small:
 *   - three short sections (Meds / Incidents / Notes)
 *   - one acknowledge action ("I've read this")
 *   - optional collapse once sections get long
 *
 * If no handover is pending, the parent passes `handover={null}` and this
 * component renders nothing — never noise.
 */

export type HandoverReadItem = {
    label?: string | null;
    severity?: string | null;
    priority?: string | null;
    type?: string | null;
    status?: string | null;
    [key: string]: unknown;
};

export type HandoverReadPayload = {
    id: number;
    handover_notes: string | null;
    client_mood: string | null;
    medications_due: HandoverReadItem[];
    incidents_to_note: HandoverReadItem[];
    follow_up_items: HandoverReadItem[];
    submitted_at: string | null;
    outgoing_staff_name: string | null;
    outgoing_shift_ends_at: string | null;
    client_name: string | null;
};

export type HandoverReadCardProps = {
    handover: HandoverReadPayload | null;
};

function formatWhen(iso: string | null): string | null {
    if (!iso) return null;
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const mins = Math.floor(diffMs / 60000);
    const hrs = Math.floor(mins / 60);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    if (hrs < 24) return `${hrs}h ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function moodLabel(mood: string | null): string | null {
    if (!mood) return null;
    const map: Record<string, string> = {
        calm: 'Calm shift',
        mixed: 'Mixed shift',
        challenging: 'Challenging shift',
    };
    return map[mood] ?? mood;
}

function itemLabel(item: HandoverReadItem, fallback: string): string {
    if (typeof item.label === 'string' && item.label.trim() !== '') {
        return item.label;
    }
    if (typeof item.type === 'string' && item.type.trim() !== '') {
        return item.type;
    }
    return fallback;
}

export default function HandoverReadCard({ handover }: HandoverReadCardProps) {
    const [submitting, setSubmitting] = useState(false);
    const [collapsed, setCollapsed] = useState(false);

    if (!handover) return null;

    const meds = handover.medications_due ?? [];
    const incidents = handover.incidents_to_note ?? [];
    const followUps = handover.follow_up_items ?? [];
    const hasMeds = meds.length > 0;
    const hasIncidents = incidents.length > 0;
    const hasFollowUps = followUps.length > 0;
    const notes = (handover.handover_notes ?? '').trim();
    const hasNotes = notes !== '';

    const acknowledge = () => {
        setSubmitting(true);
        router.patch(
            `/attendance/handover/${handover.id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const submittedWhen = formatWhen(handover.submitted_at);
    const mood = moodLabel(handover.client_mood);

    return (
        <section
            aria-label="Handover from last shift"
            className={cn(
                'scroll-mt-20 rounded-xl border border-sky-300 bg-sky-50/70 p-4 shadow-sm',
                'dark:border-sky-500/40 dark:bg-sky-950/30',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-base font-semibold text-sky-900 dark:text-sky-100">
                        What you need to know before starting
                    </h2>
                    <p className="mt-0.5 text-xs text-sky-900/80 dark:text-sky-100/80">
                        Handover
                        {handover.outgoing_staff_name
                            ? ` from ${handover.outgoing_staff_name}`
                            : ''}
                        {submittedWhen ? ` · ${submittedWhen}` : ''}
                        {mood ? ` · ${mood}` : ''}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setCollapsed((v) => !v)}
                    aria-label={collapsed ? 'Expand handover' : 'Collapse handover'}
                    className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-sky-900/80 hover:bg-sky-100/70 dark:text-sky-100/80 dark:hover:bg-sky-900/40"
                >
                    {collapsed ? (
                        <ChevronDown className="h-4 w-4" />
                    ) : (
                        <ChevronUp className="h-4 w-4" />
                    )}
                </button>
            </div>

            {!collapsed && (
                <div className="mt-3 space-y-3">
                    {/* Meds */}
                    <div className="rounded-lg border border-sky-200 bg-background/70 p-3 dark:border-sky-900/50">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <Pill className="h-4 w-4 text-sky-700 dark:text-sky-300" />
                            Meds
                        </div>
                        {hasMeds ? (
                            <ul className="mt-1.5 space-y-1 text-sm">
                                {meds.map((m, i) => (
                                    <li
                                        key={`med-${i}`}
                                        className="flex items-start gap-2 text-foreground"
                                    >
                                        <span
                                            aria-hidden
                                            className="mt-1.5 block h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                        />
                                        <span>{itemLabel(m, 'Outstanding medication')}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-1 text-sm text-muted-foreground">
                                All scheduled medication completed on the last shift.
                            </p>
                        )}
                    </div>

                    {/* Incidents / alerts */}
                    <div className="rounded-lg border border-sky-200 bg-background/70 p-3 dark:border-sky-900/50">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <AlertTriangle className="h-4 w-4 text-sky-700 dark:text-sky-300" />
                            Incidents / alerts
                        </div>
                        {hasIncidents || hasFollowUps ? (
                            <ul className="mt-1.5 space-y-1 text-sm">
                                {incidents.map((inc, i) => (
                                    <li
                                        key={`inc-${i}`}
                                        className="flex items-start gap-2 text-foreground"
                                    >
                                        <span
                                            aria-hidden
                                            className="mt-1.5 block h-1.5 w-1.5 shrink-0 rounded-full bg-red-500"
                                        />
                                        <span>{itemLabel(inc, 'Incident noted last shift')}</span>
                                    </li>
                                ))}
                                {followUps.map((f, i) => (
                                    <li
                                        key={`fu-${i}`}
                                        className="flex items-start gap-2 text-foreground"
                                    >
                                        <span
                                            aria-hidden
                                            className="mt-1.5 block h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                        />
                                        <span>{itemLabel(f, 'Follow-up needed')}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Nothing urgent flagged.
                            </p>
                        )}
                    </div>

                    {/* Notes */}
                    {hasNotes && (
                        <div className="rounded-lg border border-sky-200 bg-background/70 p-3 dark:border-sky-900/50">
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <FileText className="h-4 w-4 text-sky-700 dark:text-sky-300" />
                                Notes from last shift
                            </div>
                            <p className="mt-1.5 whitespace-pre-wrap text-sm text-foreground">
                                {notes}
                            </p>
                        </div>
                    )}
                </div>
            )}

            <div className="mt-3 flex items-center justify-end gap-2">
                <Button
                    size="sm"
                    onClick={acknowledge}
                    disabled={submitting}
                    className="h-10"
                >
                    <CheckCircle2 className="mr-2 h-4 w-4" />
                    {submitting ? 'Saving…' : "I've read this"}
                </Button>
            </div>
        </section>
    );
}
