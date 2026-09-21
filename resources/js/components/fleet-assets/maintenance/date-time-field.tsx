import React from "react";
import { formatDateOnly } from "@/lib/datetime";
import { Button } from "@/components/ui/button";
import { TimePicker, displayTime } from "./time-picker";
import { DatePicker } from "./date-picker";

// Preserve local date/time parts without converting through the browser timezone.
// Partial values are form drafts only; the parent rejects them before recording.
export const validLocalDateTime = (value: string) =>
  /^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/.test(value);

export function localDateTimeLabel(value?: string) {
  if (!value) return "Not provided";
  if (!validLocalDateTime(value)) return "Incomplete — choose a date and time";
  const [date, time] = value.split("T");
  return `${formatDateOnly(date)} · ${displayTime(time)} · Pacific/Auckland`;
}

export function DateTimeField({
  id,
  label,
  value,
  onChange,
  error,
  hint,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  hint?: string;
}) {
  const [date = "", time = ""] = value.split("T");
  const update = (nextDate: string, nextTime: string) =>
    onChange(nextDate || nextTime ? `${nextDate}T${nextTime}` : "");
  const description =
    [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(" ") ||
    undefined;
  return (
    <fieldset className="date-time-field">
      <legend>
        {label} <span>Pacific/Auckland</span>
      </legend>
      <div className="inline-fields">
        <div className="field">
          <label htmlFor={`${id}-date`}>{label} date</label>
          <DatePicker
            id={`${id}-date`}
            label={`${label} date`}
            value={date}
            invalid={!!error && !date}
            describedBy={description}
            onChange={(nextDate) => update(nextDate, time)}
          />
        </div>
        <div className="field">
          <label htmlFor={`${id}-time`}>{label} time</label>
          <TimePicker
            id={`${id}-time`}
            label={`${label} time`}
            value={time}
            invalid={!!error && !time}
            describedBy={description}
            onChange={(nextTime) => update(date, nextTime)}
          />
        </div>
      </div>
      {value && (
        <Button
          variant="ghost"
          size="sm"
          className="mt-2"
          aria-label={`${label}: Clear date and time`}
          onClick={() => onChange("")}
        >
          Clear date and time
        </Button>
      )}
      {hint && (
        <p id={`${id}-hint`} className="muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="upload-error" role="alert">
          {error}
        </p>
      )}
    </fieldset>
  );
}
