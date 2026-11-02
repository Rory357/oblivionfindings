/** Shared bits of the Sites screens: fact text and the Site's flag. */
import { StatusBadge } from '@/components/ui/status-badge';
import { timedText } from '@/lib/security-devices/device-time';
import { Fragment, type ReactNode } from 'react';
import type { SiteFact, SiteFlag } from './_types';

/** A fact line from the server: "**bold**" becomes the figure, "{time}" / "{ago}" become NZ times. */
export function FactText({ fact, now }: { fact: SiteFact; now: Date }): ReactNode {
    const text = timedText({ text: fact.text, at: fact.at ?? null }, now);
    const parts = text.split(/\*\*(.+?)\*\*/g);

    return (
        <>
            {parts.map((part, index) => (index % 2 === 1 ? <b key={index}>{part}</b> : <Fragment key={index}>{part}</Fragment>))}
        </>
    );
}

export function SiteFlagBadge({ flag }: { flag: SiteFlag }) {
    return <StatusBadge variant={flag.tone}>{flag.text}</StatusBadge>;
}
