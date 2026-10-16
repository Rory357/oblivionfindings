import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Megaphone } from 'lucide-react';
import type { HubProps } from './_types';

/** Only an authoritative, unfiltered count can say all required reading is done. */
export function ReadingAttention({
    data,
    home,
}: {
    data: HubProps;
    home: boolean;
}) {
    if (data.demo) return null;
    const attention = data.readingAttention;
    const pending = data.announcements.find(
        (item) => item.requires_acknowledgement && !item.viewer_acknowledged,
    );
    const fallback = pending ?? data.announcements[0];
    const notice = home && attention ? attention.first : fallback;
    const caughtUp = home && attention?.pending_count === 0;
    if (!caughtUp && !notice) return null;
    const href =
        caughtUp || !notice
            ? '/company-hub?view=updates'
            : `/company-hub?view=updates&announcement=${notice.id}`;
    const required =
        !caughtUp &&
        (home && attention
            ? attention.pending_count > 0
            : fallback?.requires_acknowledgement &&
              !fallback.viewer_acknowledged);
    return (
        <section className="hub-notice-strip" aria-label="Company notices">
            {caughtUp ? (
                <CheckCircle2 className="size-4 shrink-0 text-status-success" />
            ) : (
                <Megaphone className="size-4 shrink-0 text-primary" />
            )}
            <span className="text-xs font-semibold text-primary">
                {caughtUp
                    ? 'UP TO DATE'
                    : required
                      ? 'REQUIRED READING'
                      : 'NOTICE'}
            </span>
            <Link
                className="min-w-0 flex-1 font-medium hover:underline"
                href={href}
            >
                {caughtUp
                    ? 'You’re up to date with required reading.'
                    : notice?.title}
            </Link>
            {home && attention && attention.pending_count > 0 && (
                <span className="shrink-0 text-xs text-muted-foreground">
                    {attention.pending_count} to read
                </span>
            )}
            <Link className="shrink-0 text-sm text-primary" href={href}>
                {caughtUp
                    ? 'View updates'
                    : required
                      ? 'Read and acknowledge'
                      : 'Read notice'}
                <ArrowRight className="ml-2 inline size-4" />
            </Link>
        </section>
    );
}
