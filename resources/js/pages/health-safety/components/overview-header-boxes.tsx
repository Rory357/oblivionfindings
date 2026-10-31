/**
 * G2 (Stephan's approved Overview header, 31 Oct): the four count boxes on the header band. Each box is a link to its
 * list, shows the count now, how it moved since the trend date, and eight weekly bars. The server only sends a change
 * and bars it can prove from the records' dates; otherwise the box says "No trend yet" and draws no bars.
 * Built on the header's `.eh-meter` glass, tones and hover (DESIGN.md: the Event Horizon meter row).
 */
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCheck,
    ClipboardCheck,
    Clock,
    FileText,
    Flag,
    Flame,
    ShieldQuestion,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';

import { cn } from '@/lib/utils';

export interface HeaderBox {
    key: string;
    label: string;
    value: number;
    tone: 'alert' | null;
    href: string;
    trend: { delta: number; since: string } | null;
    weeks: Array<number | null>;
}

export interface HeaderBoxes {
    set: 'queue' | 'compliance' | 'incidents' | 'hazards';
    since: string;
    boxes: HeaderBox[];
}

const ICONS: Record<string, LucideIcon> = {
    need_owner: UserPlus,
    incidents_need_owner: UserPlus,
    worksafe: Flag,
    actions_overdue: Clock,
    actions_to_check: CheckCheck,
    open_reviews: FileText,
    open_incidents: FileText,
    close_anyway: ShieldQuestion,
    open_hazards: AlertTriangle,
    checks_due: ClipboardCheck,
    drills_due: Flame,
};

/** The labels each person's boxes carry, so the waiting state can name them before the numbers arrive. */
export const HEADER_BOX_LABELS: Record<HeaderBoxes['set'], string[]> = {
    queue: ['Need an owner', 'WorkSafe: decide or tell', 'Actions overdue', 'Actions to check'],
    compliance: ['Close-anyway requests', 'WorkSafe: decide or tell', 'Actions overdue', 'Open safety reviews'],
    incidents: ['Incidents need an owner', 'Open incidents', 'Actions overdue', 'Open hazards'],
    hazards: ['Open hazards', 'Checks due', 'Actions overdue', 'Drills due'],
};

function sinceLabel(date: string): string {
    return new Date(date + 'T00:00:00').toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

/** "↓ 2 since 1 Oct", "No change since 1 Oct", or "No trend yet" when the dates can't prove it. */
export function trendText(box: Pick<HeaderBox, 'trend'>): string {
    if (!box.trend) return 'No trend yet';
    const since = sinceLabel(box.trend.since);
    if (box.trend.delta === 0) return `No change since ${since}`;
    return `${box.trend.delta > 0 ? '↑' : '↓'} ${Math.abs(box.trend.delta)} since ${since}`;
}

function WeekBars({ weeks }: { weeks: Array<number | null> }) {
    const known = weeks.filter((w): w is number => w !== null);
    if (known.length === 0) return null;
    const max = Math.max(...known, 1);
    return (
        <span className="flex h-[18px] items-end gap-[3px]" aria-hidden="true">
            {weeks.map((w, i) => (
                <i
                    key={i}
                    className={cn(
                        'block w-[5px] rounded-t-[2px]',
                        w === null ? 'bg-transparent' : i === weeks.length - 1 ? 'bg-band-foreground' : 'bg-band-foreground/50',
                    )}
                    style={{ height: w === null ? 0 : `${Math.max(2, Math.round((w / max) * 18))}px` }}
                />
            ))}
        </span>
    );
}

export function OverviewHeaderBoxes({ data }: { data: HeaderBoxes }) {
    return (
        <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="hs-header-boxes">
            {data.boxes.map((box) => {
                const Icon = ICONS[box.key] ?? FileText;
                const trend = trendText(box);
                return (
                    <Link
                        key={box.key}
                        href={box.href}
                        aria-label={`${box.label}: ${box.value}. ${trend}. Open the list`}
                        className={cn(
                            'eh-meter min-h-[84px] outline-none focus-visible:ring-2 focus-visible:ring-band-foreground/70',
                            box.tone === 'alert' && 'eh-meter--critical',
                        )}
                    >
                        <span className="grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5">
                            <span className="eh-meter-big row-span-2 text-[34px] leading-none font-bold tracking-tight tabular-nums">
                                {box.value}
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[15px] leading-tight font-semibold text-band-foreground">
                                    {box.label}
                                </span>
                                <span
                                    className={cn(
                                        'mt-1 block text-xs leading-tight',
                                        box.trend ? 'text-band-foreground/80' : 'text-band-foreground/65 italic',
                                    )}
                                >
                                    {trend}
                                </span>
                            </span>
                            <span className="grid size-10 place-items-center rounded-full bg-band-foreground/10 text-band-foreground">
                                <Icon className="size-5" aria-hidden="true" />
                            </span>
                            <span className="col-span-2 col-start-2 justify-self-end">
                                <WeekBars weeks={box.weeks} />
                            </span>
                        </span>
                    </Link>
                );
            })}
        </div>
    );
}

/** While the numbers load: the person's four labels with "Counting…", never a 0 (B2.5). */
export function OverviewHeaderBoxesLoading({ set }: { set: HeaderBoxes['set'] }) {
    return (
        <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true">
            {HEADER_BOX_LABELS[set].map((label) => (
                <div key={label} className="eh-meter min-h-[84px]">
                    <span className="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-3">
                        <span className="h-8 w-9 animate-pulse rounded-md bg-band-foreground/15" />
                        <span className="min-w-0">
                            <span className="block text-[15px] leading-tight font-semibold text-band-foreground">{label}</span>
                            <span className="mt-1 block text-xs text-band-foreground/70">Counting…</span>
                        </span>
                    </span>
                </div>
            ))}
        </div>
    );
}
