import React, { useRef, useState } from "react";
import { CalendarDays, Check, ChevronDown } from "lucide-react";
import { LeaveCalendarRange } from "@/components/hr/leave-calendar-range";
import { formatDateOnly } from "@/lib/datetime";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";

// Single-date adapter around the same calendar used by Report and Appointment.
// No HR rules, ranges, timezone conversion or operational writes are introduced.
export function DatePicker({
  id,
  label,
  value,
  onChange,
  invalid,
  describedBy,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  invalid?: boolean;
  describedBy?: string;
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(value);
  const calendar = useRef<HTMLDivElement>(null);
  const changeOpen = (next: boolean) => {
    if (next) setDraft(value);
    setOpen(next);
  };
  return (
    <Popover open={open} onOpenChange={changeOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          variant="outline"
          className="time-picker-trigger"
          aria-label={`${label}: ${value ? formatDateOnly(value) : "Choose date"}`}
          aria-invalid={invalid}
          aria-describedby={describedBy}
        >
          <span className="time-picker-icon">
            <CalendarDays className="size-4" />
          </span>
          <span>
            <strong>{value ? formatDateOnly(value) : "Choose date"}</strong>
            <small>Choose a day on the calendar</small>
          </span>
          <ChevronDown className="size-4" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="date-picker-popover"
        side="right"
        align="center"
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
              "button[aria-pressed]",
            )
          )?.focus();
        }}
        onEscapeKeyDown={(event) => event.stopPropagation()}
      >
        <div className="time-picker-heading">
          <div>
            <strong>{label}</strong>
            <small>Pacific/Auckland · one date</small>
          </div>
          <CalendarDays className="size-4 text-primary" />
        </div>
        <div ref={calendar} className="date-time-calendar">
          <LeaveCalendarRange
            start={draft || null}
            end={draft || null}
            month={draft ? new Date(`${draft}T12:00:00`) : new Date()}
            onChange={(next) => setDraft(next || "")}
          />
        </div>
        <div className="date-picker-summary" role="status">
          <CalendarDays className="size-5 text-primary" />
          <div>
            <strong>{draft ? formatDateOnly(draft) : "Pick your date"}</strong>
            <small>Select one day, then choose Use date.</small>
          </div>
        </div>
        <div className="time-picker-footer date-picker-footer">
          <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
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
