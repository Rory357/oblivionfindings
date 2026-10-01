/* Medication › Settings page parts (eMAR P11 v5 `ui.tsx`), composed only
 * from the app's primitives: Fleet Settings' Card + Label + Switch rows with
 * the On/Off word, Segmented for three or more real options, the sticky Card
 * save bar, titled setting groups, and the settings notice. */
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { FieldErr, Segmented } from '@/components/wizard/primitives';
import { ReviewCard } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    ArrowUpRight,
    Check,
    ChevronDown,
    Search,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

export const DefaultNotReviewed = () => (
    <StatusBadge variant="warning" size="sm">
        Default — not yet reviewed
    </StatusBadge>
);
export const NotConfigured = () => (
    <StatusBadge variant="neutral" size="sm">
        Not configured
    </StatusBadge>
);
export const Changed = () => (
    <StatusBadge variant="info" size="sm">
        Changed — not saved
    </StatusBadge>
);

/** On/off with the state spelled out (colour is never the only signal). */
export function OnOff({
    id,
    checked,
    onChange,
    disabled,
    label,
}: {
    id: string;
    checked: boolean;
    onChange: (v: boolean) => void;
    disabled?: boolean;
    label?: string;
}) {
    return (
        <span className="inline-flex items-center gap-3">
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
                aria-label={label}
            />
            <span className="text-subtle w-7" aria-hidden="true">
                {checked ? 'On' : 'Off'}
            </span>
        </span>
    );
}

export function Choice<T extends string>({
    value,
    onChange,
    options,
    disabled,
}: {
    value: T;
    onChange: (v: T) => void;
    options: [T, string][];
    disabled?: boolean;
}) {
    return (
        <Segmented
            value={value}
            onChange={onChange}
            options={options.map(([v, l]) => ({
                value: v,
                label: l,
                disabled,
            }))}
        />
    );
}

/** Section: list caption, then content. */
export function Section({
    title,
    caption,
    right,
    children,
    id,
}: {
    title: ReactNode;
    caption?: ReactNode;
    right?: ReactNode;
    children: ReactNode;
    id?: string;
}) {
    return (
        <section className="space-y-3" aria-labelledby={id}>
            <div id={id}>
                <ListCaption title={title} caption={caption} right={right} />
            </div>
            {children}
        </section>
    );
}

/** The alert description is a grid, so mixed text and <b> are wrapped in one span. */
export const Note = ({ children }: { children: ReactNode }) => (
    <SettingsNotice role="note">
        <span>{children}</span>
    </SettingsNotice>
);

/** Fleet's in-page status message after a save; focus moves here so it is announced. */
export function StatusMessage({ message }: { message: string | null }) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (message) ref.current?.focus();
    }, [message]);
    return message ? (
        <div role="status" tabIndex={-1} ref={ref} className="outline-none">
            <SettingsNotice role="note">
                <span>{message}</span>
            </SettingsNotice>
        </div>
    ) : null;
}

/** Sticky save bar (Fleet `_notifications.tsx`): what changed, then Discard / Review changes. */
export function SaveBar({
    count,
    onDiscard,
    onReview,
    readOnly,
}: {
    count: number;
    onDiscard: () => void;
    onReview: () => void;
    readOnly?: string;
}) {
    return (
        <Card
            className="sticky bottom-0 z-10 flex flex-row flex-wrap items-center justify-between gap-3 p-4"
            role="region"
            aria-label="Save changes"
        >
            {readOnly ? (
                <p className="text-subtle">{readOnly}</p>
            ) : (
                <>
                    <div>
                        <p className="text-sm font-semibold">
                            {count
                                ? `${count} ${count === 1 ? 'change' : 'changes'} to review`
                                : 'No unsaved changes'}
                        </p>
                        <p className="text-caption mt-1">
                            {count
                                ? 'Nothing applies until you review and save. Your changes stay while you move between tabs and views.'
                                : 'Changes apply only after you review and save them.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!count}
                            onClick={onDiscard}
                        >
                            Discard changes
                        </Button>
                        <Button size="sm" disabled={!count} onClick={onReview}>
                            Review changes
                        </Button>
                    </div>
                </>
            )}
        </Card>
    );
}

/** One place per table for the right-click menu (the same items as the kebab). */
export function RowMenu<T>({
    ctx,
    close,
    title,
    icon,
    items,
}: {
    ctx: { x: number; y: number; record: T } | null;
    close: () => void;
    title: (r: T) => string;
    icon?: LucideIcon;
    items: (r: T) => MenuItem[];
}) {
    if (!ctx) return null;
    return (
        <EntityContextMenu
            x={ctx.x}
            y={ctx.y}
            icon={icon}
            title={title(ctx.record)}
            items={items(ctx.record)}
            onClose={close}
        />
    );
}

/** Internal decision and package codes stay out of product copy. */
export const plain = (t?: string) =>
    (t ?? '').replace(/\s*\((?:D\d+|NF-\d+|EM-\d+|P\d{2}[a-z]?)\)/g, '');

/** Settings sit in titled groups (icon header + divided rows), two per line where they fit. */
export function SettingGroup({
    icon: Icon,
    title,
    caption,
    children,
    wide,
    id,
}: {
    icon: LucideIcon;
    title: string;
    caption?: string;
    children: ReactNode;
    wide?: boolean;
    id?: string;
}) {
    return (
        <Card
            className={cn(
                'gap-0 overflow-hidden p-0 [&:not(:has([data-setting]))]:hidden',
                wide && 'lg:col-span-2',
            )}
            data-group={id}
        >
            <div className="flex items-start gap-3 border-b border-border p-4">
                <span className="shrink-0 rounded-lg bg-primary/10 p-2 text-primary">
                    <Icon className="size-4" />
                </span>
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    {caption ? (
                        <p className="text-caption mt-0.5">{caption}</p>
                    ) : null}
                </div>
            </div>
            <div className="divide-y divide-border">{children}</div>
        </Card>
    );
}

export type RowState = 'default' | 'nc' | 'changed' | null;

/** One setting inside a group: label and a one-line hint left, control right; a choice below. */
export function GroupRow({
    id,
    label,
    hint,
    control,
    children,
    state,
    error,
    errorId,
    hidden,
}: {
    id?: string;
    label: string;
    hint?: string;
    control?: ReactNode;
    children?: ReactNode;
    state?: RowState;
    error?: string;
    errorId?: string;
    hidden?: boolean;
}) {
    if (hidden) return null;
    return (
        <div className="space-y-2 px-4 py-3" data-setting={id}>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Label
                            htmlFor={id}
                            className="text-[13px] leading-snug"
                        >
                            {label}
                        </Label>
                        {state === 'changed' ? (
                            <Changed />
                        ) : state === 'nc' ? (
                            <NotConfigured />
                        ) : state === 'default' ? (
                            <DefaultNotReviewed />
                        ) : null}
                    </div>
                    {hint ? (
                        <p className="text-caption mt-0.5">{plain(hint)}</p>
                    ) : null}
                </div>
                {control ? <div className="shrink-0">{control}</div> : null}
            </div>
            {children ? <div className="space-y-2">{children}</div> : null}
            <FieldErr id={errorId}>{error}</FieldErr>
        </div>
    );
}

/** Two groups per line where they fit. `empty` shows only when search or the filter hides every setting. */
export const GroupGrid = ({
    children,
    empty,
}: {
    children: ReactNode;
    empty?: ReactNode;
}) => (
    <>
        <div className="peer grid items-start gap-5 lg:grid-cols-2">
            {children}
        </div>
        {empty ? (
            <div className="hidden peer-[:not(:has([data-setting]))]:block">
                {empty}
            </div>
        ) : null}
    </>
);

/** Overview (Fleet Tracking): caption + a two-column grid of ReviewCards, each with its state and a way in. */
export type OverviewCard = {
    icon: LucideIcon;
    title: string;
    lines: ReactNode[];
    badge?: ReactNode;
    cta: string;
    onClick: () => void;
};
export function Overview({
    title,
    caption,
    cards: all,
    note,
    q = '',
}: {
    title: string;
    caption: string;
    cards: OverviewCard[];
    note?: ReactNode;
    q?: string;
}) {
    const cards = all.filter(
        (c) =>
            !q ||
            [c.title, ...c.lines.map((l) => (typeof l === 'string' ? l : ''))]
                .join(' ')
                .toLowerCase()
                .includes(q.toLowerCase()),
    );
    return (
        <div className="space-y-5">
            <ListCaption title={title} caption={caption} />
            {cards.length ? null : (
                <p className="text-subtle">Nothing here matches “{q}”.</p>
            )}
            <div className="grid gap-5 lg:grid-cols-2">
                {cards.map((c) => (
                    <ReviewCard key={c.title} icon={c.icon} title={c.title}>
                        <div className="space-y-2">
                            {c.lines.map((l, i) => (
                                <p key={i} className="text-subtle">
                                    {l}
                                </p>
                            ))}
                            {c.badge ? <div>{c.badge}</div> : null}
                            <Button variant="link" onClick={c.onClick}>
                                {c.cta} <ArrowUpRight className="size-4" />
                            </Button>
                        </div>
                    </ReviewCard>
                ))}
            </div>
            {note ? <Note>{note}</Note> : null}
        </div>
    );
}

/** Searchable record selector (POPUP guide: Popover + Command over a small,
 * complete authorised list). Items that can't be chosen stay listed with why. */
export type PickItem = {
    id: string;
    name: string;
    sub: string;
    ok: boolean;
    why?: string;
};
export function RecordPicker({
    id,
    label,
    value,
    items,
    onChange,
    error,
    required,
    foot,
    placeholder = 'Search and choose a person',
    search = 'Search by name…',
    disabled,
}: {
    id: string;
    label: string;
    value: string;
    items: PickItem[];
    onChange: (id: string) => void;
    error?: string;
    required?: boolean;
    foot?: string;
    placeholder?: string;
    search?: string;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const sel = items.find((x) => x.id === value);
    return (
        <div className="min-w-0">
            <Label
                id={`${id}-label`}
                htmlFor={id}
                className="mb-1.5 flex items-center gap-1.5"
            >
                {label}
                {required ? (
                    <span className="text-status-critical">*</span>
                ) : null}
            </Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={!!error || undefined}
                        aria-describedby={error ? `${id}-error` : undefined}
                        disabled={disabled}
                        className={cn(
                            'h-auto min-h-10 w-full justify-between py-2 text-left font-normal',
                            !sel && 'text-muted-foreground',
                        )}
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <Search className="size-4 shrink-0" />
                            {sel ? (
                                <span className="min-w-0">
                                    <span className="block truncate font-semibold text-foreground">
                                        {sel.name}
                                    </span>
                                    <span className="text-caption block truncate">
                                        {sel.sub}
                                    </span>
                                </span>
                            ) : (
                                placeholder
                            )}
                        </span>
                        <ChevronDown className="size-4 shrink-0 opacity-60" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[--radix-popover-trigger-width] min-w-[360px] p-0"
                    align="start"
                >
                    <Command>
                        <CommandInput placeholder={search} />
                        <CommandList>
                            <CommandEmpty>
                                No one matches. Check the spelling, or clear the
                                search.
                            </CommandEmpty>
                            {items.map((x) => (
                                <CommandItem
                                    key={x.id}
                                    value={`${x.name} ${x.sub}`}
                                    disabled={!x.ok}
                                    onSelect={() => {
                                        onChange(x.id);
                                        setOpen(false);
                                    }}
                                >
                                    <Check
                                        className={cn(
                                            'size-4',
                                            value === x.id
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="min-w-0">
                                        <span className="block font-medium">
                                            {x.name}
                                        </span>
                                        <span className="text-caption block">
                                            {x.sub}
                                            {x.ok
                                                ? ''
                                                : ` · can’t be chosen: ${x.why}`}
                                        </span>
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                    {foot ? (
                        <p className="text-caption border-t p-2">{foot}</p>
                    ) : null}
                </PopoverContent>
            </Popover>
            <FieldErr id={`${id}-error`}>{error}</FieldErr>
        </div>
    );
}
