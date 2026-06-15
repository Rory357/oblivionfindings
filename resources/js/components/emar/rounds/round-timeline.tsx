/* eslint-disable no-restricted-syntax -- timeline nodes are custom absolutely-
   positioned buttons with a conic-gradient progress donut; not a <Button>/Card.
   All colours are semantic tokens (var(--…) / token classes). */
import { Check, Clock, Pill, Play } from 'lucide-react';
import { useMemo, type KeyboardEvent as ReactKeyboardEvent, type MouseEvent } from 'react';
import { RoundStatusBadge, roundArcColor } from './round-bits';
import { roundCounts, roundStatusMeta, type RoundSummary } from './types';

type Props = {
    rounds: RoundSummary[];
    dateTitle: string;
    onOpen: (id: number) => void;
    onContext: (e: MouseEvent, round: RoundSummary) => void;
};

const DEFAULT_START = 6 * 60;
const DEFAULT_END = 22 * 60;
// Minimum horizontal separation between node centres (% of the min-width band) so
// near-identical scheduled times stay legible and individually clickable.
const MIN_GAP_PCT = 11;

function toMinutes(hhmm: string): number {
    const [h, m] = hhmm.split(':').map(Number);
    return (h || 0) * 60 + (m || 0);
}

function shortName(name: string): string {
    return name.replace(/\s*round$/i, '').trim() || name;
}

export default function RoundTimeline({ rounds, dateTitle, onOpen, onContext }: Props) {
    // The axis defaults to 06:00–22:00 but extends outward (snapped to even hours)
    // to cover any round scheduled outside it, so every round sits at its true
    // proportional position rather than being clamped onto the edge tick.
    const layout = useMemo(() => {
        let lo = DEFAULT_START;
        let hi = DEFAULT_END;
        for (const r of rounds) {
            const m = toMinutes(r.scheduled_time);
            if (m < lo) lo = m;
            if (m > hi) hi = m;
        }
        lo = Math.floor(lo / 120) * 120;
        hi = Math.ceil(hi / 120) * 120;
        const span = Math.max(120, hi - lo);
        const pct = (mins: number) => Math.min(100, Math.max(0, ((mins - lo) / span) * 100));

        const ticks: number[] = [];
        for (let h = lo / 60; h <= hi / 60; h += 2) ticks.push(h);

        // De-collide nodes: each donut sits on its true time, but when two rounds
        // fall within MIN_GAP_PCT of each other the later one is nudged right so
        // labels stay legible and every node remains clickable (live rounds aren't
        // hand-spaced like the prototype's demo catalog).
        const sorted = [...rounds].sort((a, b) => toMinutes(a.scheduled_time) - toMinutes(b.scheduled_time));
        const nodeLeft = new Map<number, number>();
        let prev = -Infinity;
        for (const r of sorted) {
            let l = pct(toMinutes(r.scheduled_time));
            if (l < prev + MIN_GAP_PCT) l = Math.min(100, prev + MIN_GAP_PCT);
            nodeLeft.set(r.id, l);
            prev = l;
        }

        return { ticks, tickLeft: (h: number) => pct(h * 60), nodeLeft };
    }, [rounds]);

    // Keyboard parity for the right-click quick-actions menu (ContextMenu key / Shift+F10)
    // — synthesizes a position from the focused node so keyboard users reach the same menu.
    const openMenuFromKey = (e: ReactKeyboardEvent, r: RoundSummary) => {
        if (e.key !== 'ContextMenu' && !(e.shiftKey && e.key === 'F10')) return;
        e.preventDefault();
        const rect = e.currentTarget.getBoundingClientRect();
        onContext({ preventDefault: () => {}, clientX: rect.left + rect.width / 2, clientY: rect.bottom } as unknown as MouseEvent, r);
    };

    return (
        <section className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="mb-1 flex items-center gap-2">
                <Clock className="h-4 w-4 text-muted-foreground" />
                <h2 className="text-sm font-semibold">Round timeline</h2>
                <span className="text-xs text-muted-foreground">— {dateTitle} · tap a round to walk it</span>
            </div>

            {rounds.length === 0 ? (
                <div className="py-8 text-center text-sm text-muted-foreground">No rounds match the current filters.</div>
            ) : (
                <div className="overflow-x-auto">
                    <div className="relative mx-1 mt-2 mb-7 h-32 min-w-[820px]">
                        {/* axis */}
                        <div className="absolute top-[23px] right-0 left-0 h-0.5 bg-border" />
                        {layout.ticks.map((h) => {
                            const left = layout.tickLeft(h);
                            return (
                                <div key={h} className="absolute top-0 bottom-0 -translate-x-1/2" style={{ left: `${left}%` }}>
                                    <div className="h-full w-px bg-border" />
                                    <div className="absolute bottom-[-18px] left-1/2 -translate-x-1/2 text-[10px] whitespace-nowrap text-muted-foreground">
                                        {String(h).padStart(2, '0')}:00
                                    </div>
                                </div>
                            );
                        })}
                        {/* nodes */}
                        {rounds.map((r) => {
                            const counts = roundCounts(r.cells);
                            const left = layout.nodeLeft.get(r.id) ?? 0;
                            const col = roundArcColor(r.status);
                            const Glyph = r.status === 'completed' ? Check : r.status === 'in_progress' ? Play : Pill;
                            return (
                                <button
                                    key={r.id}
                                    type="button"
                                    title={`${r.name} · ${r.scheduled_time}`}
                                    aria-label={`${r.name}, ${r.scheduled_time}, ${roundStatusMeta(r.status).label}, ${counts.pct}% recorded — open guided round`}
                                    onClick={() => onOpen(r.id)}
                                    onContextMenu={(e) => onContext(e, r)}
                                    onKeyDown={(e) => openMenuFromKey(e, r)}
                                    className="absolute top-1 flex -translate-x-1/2 flex-col items-center gap-1 bg-transparent"
                                    style={{ left: `${left}%` }}
                                >
                                    <span
                                        aria-hidden
                                        className="grid h-10 w-10 place-items-center rounded-full"
                                        style={{ background: `conic-gradient(${col} ${counts.pct * 3.6}deg, var(--muted) 0deg)` }}
                                    >
                                        <span className="grid h-[30px] w-[30px] place-items-center rounded-full bg-card" style={{ color: col }}>
                                            <Glyph className="h-[15px] w-[15px]" />
                                        </span>
                                    </span>
                                    <span className="text-[11px] font-bold whitespace-nowrap text-foreground">{r.scheduled_time}</span>
                                    <span className="text-[10px] whitespace-nowrap text-muted-foreground">{shortName(r.name)}</span>
                                    <RoundStatusBadge status={r.status} showIcon={false} />
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </section>
    );
}
