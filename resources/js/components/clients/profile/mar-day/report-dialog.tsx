import {
    CalendarDays,
    CalendarRange,
    Clock,
    FileDown,
    FileText,
    Sun,
} from 'lucide-react';
import { useState } from 'react';

import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { TilePicker } from '@/components/wizard/primitives';

import { shiftDay } from './use-medication-day';

type RangeKey = 'today' | 'week' | 'four_weeks' | 'month' | 'custom';

const RANGES: {
    key: RangeKey;
    label: string;
    description: string;
    icon: typeof Sun;
}[] = [
    { key: 'today', label: 'Today', description: 'This NZ day', icon: Sun },
    {
        key: 'week',
        label: 'Last 7 days',
        description: 'Today and the 6 days before',
        icon: Clock,
    },
    {
        key: 'four_weeks',
        label: 'Last 28 days',
        description: 'Four weeks to today',
        icon: CalendarDays,
    },
    {
        key: 'month',
        label: 'This month',
        description: 'From the 1st to today',
        icon: CalendarDays,
    },
    {
        key: 'custom',
        label: 'Custom',
        description: 'Choose the days',
        icon: CalendarRange,
    },
];

/** The NZ days a range covers, both ends included; null until a custom range is whole. */
export function reportRange(
    key: RangeKey,
    today: string,
    custom: { start: string | null; end: string | null },
): { from: string; to: string } | null {
    switch (key) {
        case 'today':
            return { from: today, to: today };
        case 'week':
            return { from: shiftDay(today, -6), to: today };
        case 'four_weeks':
            return { from: shiftDay(today, -27), to: today };
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today };
        case 'custom':
            return custom.start && custom.end
                ? { from: custom.start, to: custom.end }
                : null;
    }
}

/** The existing exports' URLs for one person and range (no new report engine — P09 owns that). */
export function reportUrls(
    clientId: number,
    range: { from: string; to: string },
    includePrn: boolean,
): { pdf: string; csv: string } {
    const query = new URLSearchParams({
        client_id: String(clientId),
        date_from: range.from,
        date_to: range.to,
        include_prn: includePrn ? '1' : '0',
    }).toString();
    return {
        pdf: `/emar/pdf/mar-chart?${query}`,
        csv: `/emar/reports/export-mar?${query}`,
    };
}

/**
 * Report for this person from the profile's MAR tab (P02-1b): the MAR chart
 * PDF and the dose history CSV, for a range, through the exports that exist.
 */
export function MarReportDialog({
    open,
    onClose,
    clientId,
    personName,
    today,
    controlledLeftOut,
}: {
    open: boolean;
    onClose: () => void;
    clientId: number;
    personName: string;
    today: string;
    /** The reader can't see controlled medicines, so the exports leave them out. */
    controlledLeftOut: boolean;
}) {
    const [range, setRange] = useState<RangeKey>('week');
    const [custom, setCustom] = useState<{
        start: string | null;
        end: string | null;
    }>({
        start: null,
        end: null,
    });
    const [includePrn, setIncludePrn] = useState(true);
    const chosen = reportRange(range, today, custom);
    const tooLate = chosen !== null && chosen.to > today;
    const urls =
        chosen && !tooLate ? reportUrls(clientId, chosen, includePrn) : null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-x-hidden overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        Medication report for {personName}
                    </DialogTitle>
                    <DialogDescription>
                        The MAR chart and dose history for the days you choose,
                        in NZ time.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>Days</Label>
                        <TilePicker
                            value={range}
                            onChange={(next) => setRange(next as RangeKey)}
                            options={RANGES}
                        />
                    </div>

                    {range === 'custom' ? (
                        <div className="space-y-2">
                            <LeaveCalendarRange
                                start={custom.start}
                                end={custom.end}
                                onChange={(start, end) =>
                                    setCustom({ start, end })
                                }
                            />
                            {tooLate ? (
                                <p
                                    className="text-caption text-status-critical"
                                    role="alert"
                                >
                                    A report can't include days after today.
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
                        <div>
                            <Label htmlFor="report-include-prn">
                                Include as-needed medicines
                            </Label>
                            <p className="text-caption text-muted-foreground">
                                As-needed doses given in the range, with the
                                scheduled ones.
                            </p>
                        </div>
                        <Switch
                            id="report-include-prn"
                            checked={includePrn}
                            onCheckedChange={setIncludePrn}
                        />
                    </div>

                    {controlledLeftOut ? (
                        <p className="text-caption text-muted-foreground">
                            Controlled medicines are left out — they need
                            controlled-medicine access.
                        </p>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="outline"
                        disabled={!urls}
                        onClick={() => urls && window.location.assign(urls.csv)}
                    >
                        <FileDown className="size-4" />
                        Dose history (CSV)
                    </Button>
                    <Button
                        disabled={!urls}
                        onClick={() =>
                            urls && window.open(urls.pdf, '_blank', 'noopener')
                        }
                    >
                        <FileText className="size-4" />
                        MAR chart (PDF)
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
