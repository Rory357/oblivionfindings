import React, { useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { StatusBadge, type StatusVariant } from "@/components/ui/status-badge";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Command,
  CommandInput,
  CommandList,
  CommandItem,
  CommandEmpty,
} from "@/components/ui/command";
import {
  Check,
  ChevronDown,
  Search,
  AlertCircle,
  ArrowUpRight,
  FileText,
  type LucideIcon,
} from "lucide-react";
export const Badge = ({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: StatusVariant;
}) => <StatusBadge variant={tone}>{children}</StatusBadge>;
export function Notice({
  title,
  children,
  tone = "info",
}: {
  title: string;
  children?: ReactNode;
  tone?: string;
}) {
  return (
    <div
      className={`notice ${tone}`}
      role={tone === "critical" ? "alert" : "status"}
    >
      <AlertCircle size={19} />
      <div>
        <strong>{title}</strong>
        {children && <p>{children}</p>}
      </div>
    </div>
  );
}
export function Panel({
  title,
  sub,
  action,
  children,
}: {
  title: string;
  sub?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2 className="text-section-title">{title}</h2>
          {sub && <p className="muted">{sub}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}
export function Row({
  title,
  sub,
  value,
  badge,
  action,
}: {
  title: string;
  sub?: string;
  value?: ReactNode;
  badge?: ReactNode;
  action?: () => void;
}) {
  return (
    <div className="evidence-row">
      <div className="row-label">
        <strong>{title}</strong>
        {sub && <small>{sub}</small>}
      </div>
      <div className="row-value">
        {value}
        {badge}
      </div>
      {action && (
        <Button
          variant="ghost"
          size="sm"
          onClick={action}
          aria-label={`View ${title}`}
        >
          <ArrowUpRight size={16} />
        </Button>
      )}
    </div>
  );
}
export function Modal({
  title,
  description,
  onClose,
  children,
  footer,
  wide = false,
  icon: Icon = FileText,
  size = "detail",
}: {
  title: string;
  description: string;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
  wide?: boolean;
  icon?: LucideIcon;
  size?: "detail" | "standard";
}) {
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent
        className="preview-dialog overflow-hidden p-0 gap-0 bg-card"
        style={{
          width: wide
            ? "min(92vw, 900px)"
            : size === "standard"
              ? "min(92vw, 720px)"
              : "min(92vw, 480px)",
          maxWidth: wide
            ? "min(92vw, 900px)"
            : size === "standard"
              ? "min(92vw, 720px)"
              : "min(92vw, 480px)",
          maxHeight: "90vh",
          display: "flex",
          flexDirection: "column",
        }}
      >
        <DialogHeader className="modal-header">
          <div className="modal-icon">
            <Icon size={21} />
          </div>
          <div>
            <DialogTitle className="text-section-title">{title}</DialogTitle>
            <DialogDescription className="mt-1.5">
              {description}
            </DialogDescription>
          </div>
        </DialogHeader>
        <div className="dialog-body">{children}</div>
        <DialogFooter className="modal-footer">
          {footer ?? (
            <Button variant="outline" onClick={onClose}>
              Close
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
export function Picker({
  label,
  value,
  onChange,
  options,
  fail = false,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: { id: string; name: string; detail: string }[];
  fail?: boolean;
}) {
  const [open, setOpen] = useState(false),
    [query, setQuery] = useState(""),
    [recovered, setRecovered] = useState(false);
  const selected = options.find((o) => o.id === value);
  const error = fail && !recovered;
  return (
    <div className="field">
      <label>{label}</label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            role="combobox"
            aria-label={label}
            aria-expanded={open}
            className="picker-button"
          >
            <Search size={16} />
            <span>{selected?.name ?? `Choose ${label.toLowerCase()}`}</span>
            <ChevronDown size={15} />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="p-0"
          style={{ width: "min(80vw, 410px)" }}
          align="start"
        >
          <Command>
            <CommandInput
              aria-label={`Search ${label.toLowerCase()}`}
              placeholder="Search name or reference…"
              value={query}
              onValueChange={setQuery}
            />
            <CommandList>
              {error ? (
                <div className="p-4">
                  <Notice title="Search unavailable" tone="critical">
                    Your selection has been kept.
                  </Notice>
                  <Button
                    variant="outline"
                    onKeyDown={(event) => event.stopPropagation()}
                    onClick={() => setRecovered(true)}
                  >
                    Retry search
                  </Button>
                </div>
              ) : (
                <>
                  <CommandEmpty>
                    No matching records. Try a different name or reference.
                  </CommandEmpty>
                  {options.map((o) => (
                    <CommandItem
                      key={o.id}
                      value={`${o.name} ${o.detail}`}
                      onSelect={() => {
                        onChange(o.id);
                        setOpen(false);
                      }}
                    >
                      <div>
                        <strong>{o.name}</strong>
                        <small className="block text-muted-foreground">
                          {o.detail}
                        </small>
                      </div>
                      {value === o.id && (
                        <Check className="ml-auto" size={16} />
                      )}
                    </CommandItem>
                  ))}
                </>
              )}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      {selected && <small className="muted">{selected.detail}</small>}
    </div>
  );
}
