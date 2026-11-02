/**
 * Site › Logbook (S&D drawing pg-sdr-12i): what happened at the Site in plain sentences, newest
 * first, a day at a time: since the night ended, the night itself, and the day before. One "What"
 * dropdown narrows it; "Go to" picks another day with the approved date picker.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { KnowList, Log, QuietLine, SideBySide, SideColumn, Surface, type SgAction } from '@/components/signature/blocks';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import type { LogbookWhat, SiteLogbook, StreamLine } from './_types';

export const LOGBOOK_WHAT: { value: LogbookWhat; label: string }[] = [
    { value: 'everything', label: 'Everything' },
    { value: 'alerts', label: 'Alerts' },
    { value: 'alarm', label: 'Alarm' },
    { value: 'modes', label: 'Modes' },
    { value: 'devices', label: 'Devices' },
    { value: 'people', label: 'Who did what' },
];

/** The line's own action, or "Open" for what it's about. */
export function lineAction(line: StreamLine): SgAction | null {
    if (line.action) return { label: line.action.label, href: line.action.href, ariaLabel: `${line.action.label}: ${line.title}` };
    return line.href ? { label: 'Open', href: line.href, ariaLabel: `Open: ${line.title}` } : null;
}

export function LogbookTab({ logbook, base, siteName }: { logbook: SiteLogbook; base: string; siteName: string }) {
    const go = (changes: { what?: LogbookWhat; day?: string }) => {
        const what = changes.what ?? logbook.what;
        // Today is the default, so it isn't put in the address; a cleared date goes back to today.
        const day = changes.day !== undefined ? changes.day : logbook.today ? '' : logbook.day;
        router.get(base, { tab: 'logbook', ...(what !== 'everything' ? { what } : {}), ...(day ? { day } : {}) }, { preserveScroll: true, preserveState: true });
    };
    const nothing = logbook.sections.every((section) => section.items.length === 0);

    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                {nothing ? (
                    <QuietLine
                        title="Logbook"
                        text={logbook.what === 'everything' ? `Nothing was recorded at ${siteName} on these days.` : 'Nothing of that kind was recorded on these days. Try Everything.'}
                    />
                ) : (
                    logbook.sections
                        .filter((section) => section.items.length > 0)
                        .map((section, index) => (
                            <Log
                                key={section.key}
                                title={section.title}
                                sub={index === 0 ? `Newest first · everything that happened at ${siteName}, with who did it` : undefined}
                                limit={section.items.length}
                                items={section.items.map((line) => ({
                                    key: line.key,
                                    time: formatTime(line.at),
                                    tone: line.tone,
                                    title: line.title,
                                    sub: line.sub ?? undefined,
                                    action: lineAction(line),
                                }))}
                            />
                        ))
                )}
            </div>
            <SideColumn>
                <Surface title="Show">
                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="logbook-what">What</Label>
                            <Select value={logbook.what} onValueChange={(value) => go({ what: value as LogbookWhat })}>
                                <SelectTrigger id="logbook-what" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {LOGBOOK_WHAT.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="logbook-day">Go to</Label>
                            <DatePicker id="logbook-day" label="Go to" value={logbook.today ? '' : logbook.day} allowClear onChange={(day) => go({ day })} />
                        </div>
                    </div>
                </Surface>
                <KnowList
                    title="What the logbook keeps"
                    items={[
                        { tone: 'info', text: 'The alarm, house modes, alerts and how they ended, scans, settings changes and planned quiet times, each with who did it' },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b>Sensors linked to someone’s care</b> show here only when their care rule acts, never minute by minute. Someone you can’t look up is “a person”
                                </>
                            ),
                        },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b>Camera viewing</b> is in Overview › Reports, with each reason
                                </>
                            ),
                        },
                    ]}
                />
            </SideColumn>
        </SideBySide>
    );
}
