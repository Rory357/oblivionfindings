import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { Button } from '@/components/ui/button';
import { useCompactDateTime } from '@/components/ui/date-time-presentation';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateOnly } from '@/lib/datetime';
import { CalendarDays, Check, ChevronDown } from 'lucide-react';
import { useRef, useState } from 'react';
import { usePickerPlacement } from './use-picker-placement';

// Single-date adapter around the same calendar used by Report and Appointment.
// No HR rules, ranges, timezone conversion or operational writes are introduced.
export function DatePicker({
    id,
    label,
    value,
    onChange,
    invalid,
    describedBy,
    allowClear = false,
    disabled = false,
    timeZone = 'Pacific/Auckland',
    trigger,
    compact: compactOverride,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    invalid?: boolean;
    describedBy?: string;
    allowClear?: boolean;
    disabled?: boolean;
    timeZone?: string;
    trigger?: React.ReactNode;
    /** A touch-sized single-line trigger for dense operational forms. */
    compact?: boolean;
}) {
    const compact = useCompactDateTime(compactOverride);
    const [open, setOpen] = useState(false);
    const pickerTrigger = useRef<HTMLButtonElement>(null);
    const pickerSide = usePickerPlacement(open, 390, pickerTrigger);
    const [draft, setDraft] = useState(value);
    const calendar = useRef<HTMLDivElement>(null);
    const changeOpen = (next: boolean) => {
        if (next) setDraft(value);
        setOpen(next);
    };
    return (
        <Popover open={open} onOpenChange={changeOpen}>
            <PopoverTrigger ref={pickerTrigger} asChild>
                {trigger ?? (
                    <Button
                        type="button"
                        id={id}
                        disabled={disabled}
                        variant="outline"
                        className="time-picker-trigger"
                        data-compact={compact || undefined}
                        aria-label={`${label}: ${value ? formatDateOnly(value) : 'Choose date'}`}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    >
                        <span className="time-picker-icon">
                            <CalendarDays className="size-4" />
                        </span>
                        <span>
                            <strong>
                                {value ? formatDateOnly(value) : 'Choose date'}
                            </strong>
                            {!compact && (
                                <small>Choose a day on the calendar</small>
                            )}
                        </span>
                        <ChevronDown className="size-4" />
                    </Button>
                )}
            </PopoverTrigger>
            <PopoverContent
                className="date-picker-popover"
                data-compact={compact || undefined}
                side={pickerSide}
                align={pickerSide === 'bottom' ? 'start' : 'center'}
                collisionPadding={16}
                sideOffset={8}
                aria-label={`${label} picker`}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (
                        calendar.current?.querySelector<HTMLButtonElement>(
                            'button[aria-pressed="true"]',
                        ) ||
                        calendar.current?.querySelector<HTMLButtonElement>(
                            'button[aria-pressed]',
                        )
                    )?.focus();
                }}
                onEscapeKeyDown={(event) => event.stopPropagation()}
            >
                <div className="time-picker-heading">
                    <div>
                        <strong>{label}</strong>
                        <small>{timeZone} · one date</small>
                    </div>
                    <CalendarDays className="size-4 text-primary" />
                </div>
                <div ref={calendar} className="date-time-calendar">
                    <LeaveCalendarRange
                        start={draft || null}
                        end={draft || null}
                        month={
                            draft ? new Date(`${draft}T12:00:00`) : new Date()
                        }
                        onChange={(next) => setDraft(next || '')}
                    />
                </div>
                <div className="date-picker-summary" role="status">
                    <CalendarDays className="size-5 text-primary" />
                    <div>
                        <strong>
                            {draft ? formatDateOnly(draft) : 'Pick your date'}
                        </strong>
                        <small>Select one day, then choose Use date.</small>
                    </div>
                </div>
                <div className="time-picker-footer date-picker-footer">
                    {allowClear && value && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                onChange('');
                                setOpen(false);
                            }}
                        >
                            Clear date
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        disabled={!draft}
                        onClick={() => {
                            onChange(draft);
                            setOpen(false);
                        }}
                    >
                        <Check className="size-4" />
                        Use date
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
