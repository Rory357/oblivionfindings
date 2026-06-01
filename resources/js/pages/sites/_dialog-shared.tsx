/**
 * Shared building blocks for the Vendors & Credentials popups.
 *
 * These are imported by both `vendors/_dialogs.tsx` and
 * `credentials/_dialogs.tsx` (and the global Vendors & Credentials page) so
 * every surface — the global directory, the site-profile tab, and the per-site
 * pages — renders the *same* tile pickers, locked-site cards, searchable site
 * picker and detail headers. Follows docs/POPUP_STYLE_GUIDE.md.
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import {
    Building2,
    Check,
    ChevronDown,
    ChevronsUpDown,
    Clock,
    FileBadge,
    FileKey,
    Fingerprint,
    Home,
    KeyRound,
    Link2,
    Lock,
    MapPin,
    Shield,
    Warehouse,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

// ── Site-type metadata (shared with the directory tables/filters) ──────────

export type SiteTypeKey = 'head_office' | 'house' | 'facility' | 'residential';

export const SITE_TYPE_META: Record<
    string,
    { label: string; icon: LucideIcon; badgeClass: string }
> = {
    head_office: {
        label: 'Head Office',
        icon: Building2,
        badgeClass: 'border-status-info/30 bg-status-info-bg text-status-info',
    },
    house: {
        label: 'House',
        icon: Home,
        badgeClass: 'border-status-success/30 bg-status-success-bg text-status-success',
    },
    facility: {
        label: 'Facility',
        icon: Warehouse,
        badgeClass: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    },
    residential: {
        label: 'Residential',
        icon: Home,
        badgeClass: 'border-primary/30 bg-primary/10 text-primary',
    },
};

export function SiteTypeBadge({ type }: { type?: string | null }) {
    if (!type) return <span className="text-muted-foreground">—</span>;
    const meta = SITE_TYPE_META[type] ?? SITE_TYPE_META.head_office;
    const Icon = meta.icon;
    return (
        <Badge variant="outline" className={cn('gap-1', meta.badgeClass)}>
            <Icon className="h-3 w-3" />
            {meta.label}
        </Badge>
    );
}

// ── Credential-type metadata (tile picker + table icons) ───────────────────

export const CREDENTIAL_TYPE_META: Record<
    string,
    { label: string; icon: LucideIcon; description: string }
> = {
    password: { label: 'Password', icon: Lock, description: 'Username + secret' },
    api_key: { label: 'API Key', icon: KeyRound, description: 'Machine token' },
    ssh_key: { label: 'SSH Key', icon: FileKey, description: 'Key pair' },
    pin: { label: 'PIN / Code', icon: Fingerprint, description: 'Door, alarm, panel' },
    certificate: { label: 'Certificate', icon: FileBadge, description: 'TLS / signing' },
    oauth: { label: 'OAuth', icon: Link2, description: 'Delegated access' },
    other: { label: 'Other', icon: Shield, description: 'Anything else' },
};

export function credentialTypeLabel(type: string): string {
    return CREDENTIAL_TYPE_META[type]?.label ?? type;
}

export function credentialTypeIcon(type: string): LucideIcon {
    return CREDENTIAL_TYPE_META[type]?.icon ?? Lock;
}

// ── Rotation health ────────────────────────────────────────────────────────
// Threshold is a placeholder per the redesign handoff — confirm the real
// rotation SLA with the team. 180d due, 240d overdue.

export const ROTATION_DUE_DAYS = 180;

export type RotationKey = 'ok' | 'due' | 'overdue' | 'unknown';
export type RotationTone = 'success' | 'warning' | 'critical';

export type RotationStatus = {
    key: RotationKey;
    label: string;
    tone: RotationTone;
    days: number | null;
};

export function daysSince(iso?: string | null): number | null {
    if (!iso) return null;
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return null;
    return Math.floor((Date.now() - then) / 86_400_000);
}

export function rotationStatus(lastRotatedAt?: string | null): RotationStatus {
    const days = daysSince(lastRotatedAt);
    if (days === null) {
        return { key: 'unknown', label: 'Never rotated', tone: 'warning', days: null };
    }
    if (days >= ROTATION_DUE_DAYS + 60) {
        return { key: 'overdue', label: 'Rotation overdue', tone: 'critical', days };
    }
    if (days >= ROTATION_DUE_DAYS) {
        return { key: 'due', label: 'Rotation due', tone: 'warning', days };
    }
    return { key: 'ok', label: 'Healthy', tone: 'success', days };
}

export const ROTATION_TONE_BADGE: Record<RotationTone, string> = {
    success: 'border-status-success/30 bg-status-success-bg text-status-success',
    warning: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    critical: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
};

export function relativeTime(iso?: string | null): string {
    const days = daysSince(iso);
    if (days === null) return 'never';
    if (days <= 0) return 'today';
    if (days === 1) return 'yesterday';
    if (days < 30) return `${days}d ago`;
    if (days < 365) return `${Math.round(days / 30)}mo ago`;
    return `${(days / 365).toFixed(1)}y ago`;
}

export function formatDate(iso?: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

// ── Tile picker (Send-Kudos style, per POPUP_STYLE_GUIDE.md) ───────────────

export type TileOption = {
    key: string;
    label: string;
    description?: string;
    icon: LucideIcon;
    accent?: string;
};

export function TilePicker({
    options,
    value,
    onChange,
    columns = 3,
}: {
    options: TileOption[];
    value: string;
    onChange: (key: string) => void;
    columns?: 2 | 3;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-2 gap-2',
                columns === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2',
            )}
        >
            {options.map((option) => {
                const Icon = option.icon;
                const active = value === option.key;
                return (
                    // eslint-disable-next-line no-restricted-syntax -- Send-Kudos-style selector tile, not a Button
                    <button
                        key={option.key}
                        type="button"
                        onClick={() => onChange(option.key)}
                        aria-pressed={active}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', active ? 'text-primary' : option.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">{option.label}</span>
                            {option.description ? (
                                <span className="block text-xs text-muted-foreground">
                                    {option.description}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

// ── Locked-site context card ───────────────────────────────────────────────

export type SiteOption = {
    id: number;
    name: string;
    type: string;
};

export function LockedSiteCard({
    site,
    note = 'Locked to the site you opened this from.',
}: {
    site: { name: string; type?: string | null };
    note?: string;
}) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
            <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                <MapPin className="h-4 w-4 text-primary" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">{site.name}</span>
                    {site.type ? <SiteTypeBadge type={site.type} /> : null}
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">{note}</p>
            </div>
        </div>
    );
}

// ── Searchable site picker (required when adding from the global view) ─────

export function SitePickerField({
    sites,
    value,
    onChange,
    error,
    hint = "Pick the site this belongs to. It will only be visible to that site's team.",
}: {
    sites: SiteOption[];
    value: number | '' | null;
    onChange: (id: number) => void;
    error?: string;
    hint?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = sites.find((s) => s.id === value);
    const SelectedIcon = selected ? SITE_TYPE_META[selected.type]?.icon ?? MapPin : MapPin;

    return (
        <div>
            <Label>
                Site <span className="text-status-critical">*</span>
            </Label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        className="w-full justify-between font-normal"
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <SelectedIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span className="truncate">
                                {selected ? selected.name : 'Select a site…'}
                            </span>
                        </span>
                        <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[var(--radix-popover-trigger-width)] p-0"
                    align="start"
                >
                    <Command>
                        <CommandInput placeholder="Search sites…" />
                        <CommandList>
                            <CommandEmpty>No sites found.</CommandEmpty>
                            <CommandGroup>
                                {sites.map((site) => {
                                    const Icon = SITE_TYPE_META[site.type]?.icon ?? MapPin;
                                    return (
                                        <CommandItem
                                            key={site.id}
                                            value={site.name}
                                            onSelect={() => {
                                                onChange(site.id);
                                                setOpen(false);
                                            }}
                                        >
                                            <Icon className="mr-2 h-4 w-4 text-muted-foreground" />
                                            <span className="flex-1 truncate">{site.name}</span>
                                            <Check
                                                className={cn(
                                                    'ml-2 h-4 w-4',
                                                    selected?.id === site.id
                                                        ? 'opacity-100'
                                                        : 'opacity-0',
                                                )}
                                            />
                                        </CommandItem>
                                    );
                                })}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {hint ? <p className="mt-1 text-xs text-muted-foreground">{hint}</p> : null}
            {error ? <p className="mt-1 text-xs text-status-critical">{error}</p> : null}
        </div>
    );
}

// ── Read-only detail header (icon tile + title + subline) ──────────────────

export function DetailIconHeader({
    icon: Icon,
    title,
    subtitle,
    tone = 'primary',
}: {
    icon: LucideIcon;
    title: ReactNode;
    subtitle?: ReactNode;
    tone?: 'primary' | 'critical';
}) {
    return (
        <div className="flex items-start gap-3">
            <span
                className={cn(
                    'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl',
                    tone === 'critical'
                        ? 'bg-status-critical-bg text-status-critical'
                        : 'bg-primary/10 text-primary',
                )}
            >
                <Icon className="h-5 w-5" />
            </span>
            <div className="min-w-0">
                <div className="text-base font-semibold leading-tight">{title}</div>
                {subtitle ? (
                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                        {subtitle}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

// ── Rotation-health badge ──────────────────────────────────────────────────

export function RotationBadge({
    lastRotatedAt,
    className,
}: {
    lastRotatedAt?: string | null;
    className?: string;
}) {
    const rot = rotationStatus(lastRotatedAt);
    const label = rot.key === 'ok' ? `Rotated ${relativeTime(lastRotatedAt)}` : rot.label;
    return (
        <Badge
            variant="outline"
            className={cn('gap-1', ROTATION_TONE_BADGE[rot.tone], className)}
        >
            <Clock className="h-3 w-3" />
            {label}
        </Badge>
    );
}

// ── Searchable filter dropdown (modern combobox; dark + light variants) ─────
// Replaces the dated native <select> on the hero filter strip and in the
// audit-log dialog. Searchable automatically when the option list is long.

export type FilterOption = {
    value: string;
    label: string;
    icon?: LucideIcon;
};

export function FilterSelect({
    value,
    onChange,
    options,
    variant = 'light',
    searchable,
    widthClass = 'w-44',
    'aria-label': ariaLabel,
}: {
    value: string;
    onChange: (value: string) => void;
    options: FilterOption[];
    variant?: 'dark' | 'light';
    searchable?: boolean;
    widthClass?: string;
    'aria-label'?: string;
}) {
    const [open, setOpen] = useState(false);
    const current = options.find((o) => o.value === value) ?? options[0];
    const isDefault = !current || current.value === options[0]?.value;
    const showSearch = searchable ?? options.length > 6;
    const CurrentIcon = current?.icon;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    role="combobox"
                    aria-label={ariaLabel}
                    aria-expanded={open}
                    className={cn(
                        'justify-between gap-1.5 font-normal',
                        widthClass,
                        variant === 'dark' &&
                            'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20 hover:text-primary-foreground',
                    )}
                >
                    <span className="flex min-w-0 items-center gap-1.5">
                        {!isDefault && variant === 'dark' ? (
                            <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-primary-foreground" />
                        ) : null}
                        {CurrentIcon ? <CurrentIcon className="h-3.5 w-3.5 shrink-0 opacity-80" /> : null}
                        <span className="truncate">{current?.label}</span>
                    </span>
                    <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-60" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-52 p-0">
                <Command>
                    {showSearch ? <CommandInput placeholder="Search…" /> : null}
                    <CommandList>
                        <CommandEmpty>No matches.</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => {
                                const Icon = option.icon;
                                return (
                                    <CommandItem
                                        key={option.value}
                                        value={option.label}
                                        onSelect={() => {
                                            onChange(option.value);
                                            setOpen(false);
                                        }}
                                    >
                                        {Icon ? (
                                            <Icon className="mr-2 h-4 w-4 text-muted-foreground" />
                                        ) : null}
                                        <span className="flex-1 truncate">{option.label}</span>
                                        <Check
                                            className={cn(
                                                'ml-2 h-4 w-4',
                                                option.value === value ? 'opacity-100' : 'opacity-0',
                                            )}
                                        />
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
