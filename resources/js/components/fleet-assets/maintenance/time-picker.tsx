import React, { useRef, useState } from "react";
import { Clock3, Keyboard, Check, ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";

const pad = (n: number | string) => String(n).padStart(2, "0");
export const displayTime = (value: string) => {
  if (!/^\d{2}:\d{2}$/.test(value)) return "Choose time";
  const [hour, minute] = value.split(":").map(Number);
  return `${pad(hour % 12 || 12)}:${pad(minute)} ${hour >= 12 ? "PM" : "AM"}`;
};

// One isolated design composition for every editable time; values remain HH:mm.
export function TimePicker({
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
  const [hour, setHour] = useState("09");
  const [minute, setMinute] = useState("00");
  const [period, setPeriod] = useState("AM");
  const [face, setFace] = useState<"hour" | "minute">("hour");
  const [manual, setManual] = useState(false);
  const [error, setError] = useState("");
  const hourInput = useRef<HTMLInputElement>(null);
  const minuteInput = useRef<HTMLInputElement>(null);
  const changeOpen = (next: boolean) => {
    if (next) {
      const [h, m] = (value || "09:00").split(":").map(Number);
      setHour(pad(h % 12 || 12));
      setMinute(pad(m));
      setPeriod(h >= 12 ? "PM" : "AM");
      setFace("hour");
      setManual(false);
      setError("");
    }
    setOpen(next);
  };
  const validHour =
    /^\d{1,2}$/.test(hour) && Number(hour) >= 1 && Number(hour) <= 12;
  const validMinute =
    /^\d{1,2}$/.test(minute) && Number(minute) >= 0 && Number(minute) <= 59;
  const apply = () => {
    if (!validHour || !validMinute) {
      setError(
        !validHour
          ? "Enter an hour from 1 to 12."
          : "Enter minutes from 00 to 59.",
      );
      (!validHour ? hourInput : minuteInput).current?.focus();
      return;
    }
    onChange(
      `${pad((Number(hour) % 12) + (period === "PM" ? 12 : 0))}:${pad(Number(minute))}`,
    );
    setOpen(false);
  };
  const adjust = (
    event: React.KeyboardEvent<HTMLInputElement>,
    unit: "hour" | "minute",
  ) => {
    if (event.key === "Enter") {
      event.preventDefault();
      apply();
    }
    if (!["ArrowUp", "ArrowDown"].includes(event.key)) return;
    event.preventDefault();
    const delta = event.key === "ArrowUp" ? 1 : -1;
    if (unit === "hour")
      setHour(pad(((Number(hour) - 1 + delta + 12) % 12) + 1));
    else setMinute(pad((Number(minute) + delta + 60) % 60));
    setError("");
  };
  const selected = face === "hour" ? Number(hour) % 12 : Number(minute) / 5;
  const angle = (selected * Math.PI) / 6;
  const handX = 128 + Math.sin(angle) * 96;
  const handY = 128 - Math.cos(angle) * 96;
  return (
    <Popover open={open} onOpenChange={changeOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          variant="outline"
          className="time-picker-trigger"
          aria-label={`${label}: ${displayTime(value)}`}
          aria-invalid={invalid}
          aria-describedby={describedBy}
        >
          <span className="time-picker-icon">
            <Clock3 className="size-4" />
          </span>
          <span>
            <strong>{displayTime(value)}</strong>
            <small>Choose on the clock or type a time</small>
          </span>
          <ChevronDown className="size-4" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="time-picker-popover"
        side="right"
        align="center"
        collisionPadding={16}
        sideOffset={8}
        aria-label={`${label} picker`}
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          hourInput.current?.focus();
          hourInput.current?.select();
        }}
        onEscapeKeyDown={(event) => event.stopPropagation()}
      >
        <div className="time-picker-heading">
          <div>
            <strong>{label}</strong>
            <small>Pacific/Auckland</small>
          </div>
          <span className="time-picker-mode-label">
            {manual ? "Type a time" : "Select a time"}
          </span>
        </div>
        <div className="time-picker-digits">
          <div>
            <label htmlFor={`${id}-hour`}>Hour</label>
            <input
              ref={hourInput}
              id={`${id}-hour`}
              aria-label={`${label} hour`}
              inputMode="numeric"
              autoComplete="off"
              maxLength={2}
              value={hour}
              data-active={face === "hour"}
              aria-invalid={!!error && !validHour}
              aria-describedby={error ? `${id}-error` : `${id}-help`}
              onFocus={() => setFace("hour")}
              onChange={(event) => {
                setHour(event.target.value);
                setError("");
              }}
              onKeyDown={(event) => adjust(event, "hour")}
            />
          </div>
          <span className="time-picker-colon">:</span>
          <div>
            <label htmlFor={`${id}-minute`}>Minute</label>
            <input
              ref={minuteInput}
              id={`${id}-minute`}
              aria-label={`${label} minute`}
              inputMode="numeric"
              autoComplete="off"
              maxLength={2}
              value={minute}
              data-active={face === "minute"}
              aria-invalid={!!error && !validMinute}
              aria-describedby={error ? `${id}-error` : `${id}-help`}
              onFocus={() => setFace("minute")}
              onChange={(event) => {
                setMinute(event.target.value);
                setError("");
              }}
              onKeyDown={(event) => adjust(event, "minute")}
            />
          </div>
          <div
            className="time-picker-period"
            role="group"
            aria-label={`${label} AM or PM`}
          >
            {["AM", "PM"].map((item) => (
              <Button
                key={item}
                size="sm"
                variant={period === item ? "default" : "outline"}
                aria-pressed={period === item}
                onClick={() => setPeriod(item)}
              >
                {item}
              </Button>
            ))}
          </div>
        </div>
        {!manual && (
          <>
            <div className="time-picker-face-tabs">
              <Button
                size="sm"
                variant={face === "hour" ? "secondary" : "ghost"}
                aria-pressed={face === "hour"}
                onClick={() => setFace("hour")}
              >
                Hours
              </Button>
              <Button
                size="sm"
                variant={face === "minute" ? "secondary" : "ghost"}
                aria-pressed={face === "minute"}
                onClick={() => setFace("minute")}
              >
                Minutes
              </Button>
            </div>
            <div
              className="time-picker-dial"
              role="group"
              aria-label={
                face === "hour"
                  ? "Choose hour on clock"
                  : "Choose minutes on clock"
              }
            >
              <svg viewBox="0 0 256 256" aria-hidden="true">
                <line
                  x1="128"
                  y1="128"
                  x2={Number.isFinite(handX) ? handX : 128}
                  y2={Number.isFinite(handY) ? handY : 32}
                />
                <circle cx="128" cy="128" r="4" />
              </svg>
              {Array.from({ length: 12 }, (_, index) => {
                const number = face === "hour" ? index || 12 : index * 5;
                const active =
                  face === "hour"
                    ? Number(hour) === number
                    : Number(minute) === number;
                return (
                  // The clock-face mark needs native button positioning inside the circular dial.
                  // eslint-disable-next-line no-restricted-syntax
                  <button
                    type="button"
                    key={`${face}-${index}`}
                    className="time-picker-mark"
                    style={{
                      left: `${50 + Math.sin((index * Math.PI) / 6) * 37.5}%`,
                      top: `${50 - Math.cos((index * Math.PI) / 6) * 37.5}%`,
                    }}
                    aria-label={`${face === "hour" ? "Hour" : "Minute"} ${face === "hour" ? number : pad(number)}`}
                    aria-pressed={active}
                    onClick={() => {
                      if (face === "hour") {
                        setHour(pad(number));
                        setFace("minute");
                      } else setMinute(pad(number));
                      setError("");
                    }}
                  >
                    {face === "hour" ? number : pad(number)}
                  </button>
                );
              })}
            </div>
          </>
        )}
        <p className="time-picker-help" id={`${id}-help`}>
          {manual
            ? "Type any minute. Use the arrow keys to adjust."
            : face === "hour"
              ? "Choose an hour, then minutes. You can also type above."
              : "Choose a 5-minute mark, or type any minute above."}
        </p>
        <p className="sr-only" role="status">
          {validHour && validMinute
            ? `Selected ${pad(Number(hour))}:${pad(Number(minute))} ${period}`
            : "Enter a valid time"}
        </p>
        {error && (
          <p className="upload-error" id={`${id}-error`} role="alert">
            {error}
          </p>
        )}
        <div className="time-picker-footer">
          <Button variant="ghost" size="sm" onClick={() => setManual(!manual)}>
            {manual ? (
              <Clock3 className="size-4" />
            ) : (
              <Keyboard className="size-4" />
            )}
            {manual ? "Clock" : "Type time"}
          </Button>
          <div>
            <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button size="sm" onClick={apply}>
              <Check className="size-4" />
              Use time
            </Button>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  );
}
