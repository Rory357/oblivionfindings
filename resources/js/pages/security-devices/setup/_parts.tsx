/** Small shared bits of the Setup screens. */
import { Ring, type SgTone } from '@/components/signature/blocks';
import { timedText } from '@/lib/security-devices/device-time';
import { Fragment, type ReactNode } from 'react';
import type { SetupLine, Tone } from './_types';

const RING: Record<Tone, SgTone> = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', neutral: 'none' };

/** A server line: "**bold**" becomes the figure, "{time}" / "{ago}" become NZ times. */
export function LineText({ line, now }: { line: Pick<SetupLine, 'text' | 'at'>; now: Date }): ReactNode {
    const text = timedText({ text: line.text, at: line.at ?? null }, now);
    const parts = text.split(/\*\*(.+?)\*\*/g);

    return (
        <>
            {parts.map((part, index) => (index % 2 === 1 ? <b key={index}>{part}</b> : <Fragment key={index}>{part}</Fragment>))}
        </>
    );
}

/** A line with its tone ring in front (tone is never colour alone: the ring says it to screen readers). */
export function ToneLine({ line, now }: { line: SetupLine; now: Date }) {
    return (
        <span className="flex items-start gap-2">
            <span className="mt-0.5 shrink-0">
                <Ring tone={RING[line.tone]} />
            </span>
            <span className="min-w-0 text-sm">
                <LineText line={line} now={now} />
            </span>
        </span>
    );
}

/** Lower-case match of every word in the query, for the header's "Search setup". */
export function matches(query: string, ...values: (string | null | undefined)[]): boolean {
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (words.length === 0) return true;
    const haystack = values.filter(Boolean).join(' ').toLowerCase();
    return words.every((word) => haystack.includes(word));
}
