/**
 * Network gear's History tab (S&D v13, pg-sdr-3e): one logbook, newest first, grouped Today ·
 * Earlier this week · each month. Filter by kind from one "What" dropdown (never pill chips) and
 * pick a date and time with the approved pickers. Terminal sessions show only to IT admins, and
 * every other kind keeps the permission of the tab it comes from (the server leaves out the rest).
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { FactRows, Log, QuietLine, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { groupLines, logbookItems, whoDidWhat } from './_device-logbook';
import { MainSide } from './_device-page-parts';
import type { HistoryTab } from './_device-page-types';

export type HistoryFilter = { what?: string; from?: string | null };

export function HistoryTabView({
    history,
    now,
    onFilter,
    onCompare,
    onTerminal,
}: {
    history: HistoryTab;
    now: Date;
    onFilter: (filter: HistoryFilter) => void;
    onCompare: (snapshotId: number) => void;
    /** Opens the Terminal tab; null when the viewer has no Terminal tab. */
    onTerminal: (() => void) | null;
}) {
    const noun = history.noun;
    const groups = groupLines(history.lines, now);
    const filtered = history.what !== 'all' || history.from !== null;
    const date = history.from?.slice(0, 10) ?? '';
    const time = history.from?.slice(11, 16) ?? '';
    const who = whoDidWhat(history.lines, now);
    const showsTerminal = history.kinds.some((kind) => kind.value === 'terminal');

    return (
        <MainSide
            main={
                groups.length === 0 ? (
                    <QuietLine
                        title="History"
                        text={filtered ? 'Nothing matches. Choose Everything or another date.' : `Nothing is recorded for this ${noun} at your Sites yet.`}
                        action={filtered ? { label: 'Show everything', onClick: () => onFilter({ what: 'all', from: null }) } : null}
                    />
                ) : (
                    <>
                        {groups.map((group, index) => (
                            <Log
                                key={group.key}
                                title={group.title}
                                sub={index === 0 ? `Newest first · everything about this ${noun}, with who did it` : undefined}
                                limit={group.lines.length}
                                items={logbookItems(group.lines, now, { onCompare, onTerminal })}
                            />
                        ))}
                        {history.lines.length >= 150 ? (
                            <p className="text-subtle">Showing the newest 150. Choose one kind in What to see more of it.</p>
                        ) : null}
                    </>
                )
            }
            side={
                <>
                    <Surface title="Show" sub={showsTerminal ? 'Terminal sessions show only to IT admins' : undefined}>
                        <div className="mt-3 grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="sd-history-what">What</Label>
                                <Select value={history.what} onValueChange={(value) => onFilter({ what: value })}>
                                    <SelectTrigger id="sd-history-what" className="w-56" aria-label="What">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {history.kinds.map((kind) => (
                                            <SelectItem key={kind.value} value={kind.value}>
                                                {kind.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="sd-history-from">From</Label>
                                <div className="flex flex-wrap items-center gap-2">
                                    <DatePicker
                                        id="sd-history-from"
                                        label="From date"
                                        value={date}
                                        allowClear
                                        onChange={(next) => onFilter({ from: next ? `${next}T${time || '00:00'}` : null })}
                                    />
                                    <TimePicker
                                        id="sd-history-from-time"
                                        label="From time"
                                        value={time}
                                        onChange={(next) => onFilter({ from: `${date || now.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE })}T${next}` })}
                                    />
                                </div>
                                <p className="text-caption">Shows what happened from then until now</p>
                            </div>
                            {filtered ? (
                                <Button type="button" variant="outline" size="sm" className="justify-self-start" onClick={() => onFilter({ what: 'all', from: null })}>
                                    Show everything
                                </Button>
                            ) : null}
                        </div>
                    </Surface>
                    {who.length > 0 ? <FactRows title="Who did what this month" rows={who} /> : null}
                    <QuietLine
                        title={`Kept with the ${noun}`}
                        text={`The logbook stays if the ${noun} is removed. Graphs keep every reading for 14 days, then daily summaries for 5 years.`}
                    />
                </>
            }
        />
    );
}
