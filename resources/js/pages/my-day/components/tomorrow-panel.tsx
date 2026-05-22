import { ArrowRight } from 'lucide-react';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';

import type { MyDayPreShiftBriefing } from '../lib/types';

interface TomorrowPanelProps {
    briefing?: MyDayPreShiftBriefing | null;
}

export function TomorrowPanel({ briefing }: TomorrowPanelProps) {
    if (!briefing) return null;
    const start = formatTime(briefing.starts_at);
    const end = formatTime(briefing.ends_at);
    const dayLabel = new Date(briefing.starts_at).toLocaleDateString([], { weekday: 'short' });
    const client = briefing.client;

    return (
        <div
            data-test="my-day-tomorrow"
            className="rounded-2xl border border-border bg-gradient-to-b from-card to-background p-4"
        >
            <div className="mb-2.5 flex items-center gap-2">
                <div className="text-[10.5px] font-bold uppercase tracking-[0.12em] text-text-faint">
                    Tomorrow
                </div>
                <span className="text-[11px] text-muted-foreground">
                    {dayLabel} · {start} start
                </span>
            </div>
            <div className="mb-2.5 flex items-center gap-2.5">
                <Avatar className="h-9 w-9">
                    <AvatarFallback
                        className="text-sm font-semibold"
                        style={{
                            background: `oklch(0.85 0.10 ${client.hue})`,
                            color: `oklch(0.28 0.16 ${client.hue})`,
                        }}
                    >
                        {client.initials}
                    </AvatarFallback>
                </Avatar>
                <div>
                    <div className="text-sm font-semibold">
                        {client.first_name} {client.last_name}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {start} – {end}
                        {briefing.location ? ` · ${briefing.location}` : ''}
                    </div>
                </div>
            </div>
            {briefing.bullets && briefing.bullets.length > 0 ? (
                <ul className="ml-4 list-disc text-[12.5px] leading-[1.6] text-foreground marker:text-muted-foreground">
                    {briefing.bullets.map((b, i) => (
                        <li key={i}>{b}</li>
                    ))}
                </ul>
            ) : null}
            <Button variant="ghost" size="sm" className="mt-2">
                Read full briefing
                <ArrowRight className="ml-1 h-3 w-3" />
            </Button>
        </div>
    );
}

function formatTime(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
}

export default TomorrowPanel;
