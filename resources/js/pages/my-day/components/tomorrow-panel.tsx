import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import HandoverReadCard from '@/components/handover-read-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { formatDate, formatTime } from '@/lib/datetime';

import { residentHue, residentInitials } from '../lib/resident-hue';
import type { MyDayPreShiftBriefing } from '../lib/types';

interface TomorrowPanelProps {
    briefing?: MyDayPreShiftBriefing | null;
    /** Heading override. Defaults to "Tomorrow" but pages can pass "Next shift" etc. */
    heading?: string;
}

export function TomorrowPanel({ briefing, heading }: TomorrowPanelProps) {
    const t = useMyDayLabels();
    if (!briefing) return null;
    const resolvedHeading = heading ?? t('tomorrow_title');

    const start = formatTime(briefing.starts_at);
    const end = formatTime(briefing.ends_at);
    const dayLabel = formatDate(briefing.starts_at);
    const client = briefing.client;

    // The MyShiftResource client payload only carries `name` + `photo_url`. We
    // derive initials + hue client-side so the avatar matches the deterministic
    // colours used everywhere else (see lib/resident-hue.ts).
    const fullName = (client?.name ?? '').trim();
    const [firstName = '', ...rest] = fullName.split(/\s+/);
    const lastName = rest.join(' ');
    const initials = residentInitials(firstName, lastName) || '—';
    const hue = client?.id ? residentHue(client.id) : 277;

    const briefingLines = collectBriefingLines(briefing);

    return (
        <div
            data-test="my-day-tomorrow"
            className="rounded-2xl border border-border bg-card p-5 shadow-sm"
        >
            <div className="mb-2.5 flex items-center gap-2">
                <div className="text-section-title">{resolvedHeading}</div>
                <span className="text-subtle">
                    {dayLabel} · {start} start
                </span>
            </div>
            <div className="mb-2.5 flex items-center gap-2.5">
                <Avatar className="h-9 w-9">
                    {client?.photo_url ? (
                        <AvatarImage src={client.photo_url} alt={fullName} />
                    ) : null}
                    <AvatarFallback
                        className="text-sm font-semibold"
                        style={{
                            background: `oklch(0.85 0.10 ${hue})`,
                            color: `oklch(0.28 0.16 ${hue})`,
                        }}
                    >
                        {initials}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <div className="truncate text-sm font-semibold">
                        {fullName || t('upcoming_shift')}
                    </div>
                    <div className="truncate text-xs text-muted-foreground">
                        {start} – {end}
                        {briefing.location ? ` · ${briefing.location}` : ''}
                    </div>
                </div>
            </div>
            {briefingLines.length > 0 ? (
                <ul className="ml-4 list-disc text-sm leading-relaxed text-foreground marker:text-muted-foreground">
                    {briefingLines.map((line, i) => (
                        <li key={i}>{line}</li>
                    ))}
                </ul>
            ) : null}
            {briefing.incoming_handover ? (
                <div className="mt-3">
                    <HandoverReadCard handover={briefing.incoming_handover} />
                </div>
            ) : null}
            <Button asChild variant="outline" className="frontline-tap mt-4">
                <Link href="/my-roster">
                    {t('read_full_briefing')}
                    <ArrowRight className="ml-1 h-3 w-3" />
                </Link>
            </Button>
        </div>
    );
}

/**
 * The server returns `what_to_know` as a single string (the shift notes) and
 * may also expose `bullets`. We normalise both into a flat list so the panel
 * always shows readable briefing points.
 */
function collectBriefingLines(briefing: MyDayPreShiftBriefing): string[] {
    const lines: string[] = [];
    if (briefing.bullets && briefing.bullets.length > 0) {
        for (const b of briefing.bullets) {
            const trimmed = b?.trim();
            if (trimmed) lines.push(trimmed);
        }
    }
    if (briefing.what_to_know) {
        const text = briefing.what_to_know.trim();
        if (text) {
            // Split on newlines or sentence boundaries so a multi-line note
            // becomes a real bullet list rather than one long blob.
            const parts = text
                .split(/\r?\n+|(?<=[.!?])\s+(?=[A-Z])/)
                .map((s) => s.trim())
                .filter(Boolean);
            for (const p of parts) {
                if (!lines.includes(p)) lines.push(p);
            }
        }
    }
    return lines;
}

export default TomorrowPanel;
