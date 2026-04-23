import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useAppearance, type Appearance as AppearanceType } from '@/hooks/use-appearance';
import { DEFAULT_BRAND_HEX } from '@/lib/derive-palette';
import { cn } from '@/lib/utils';
import { HexColorPicker } from 'react-colorful';
import { Check, Monitor, Moon, RotateCcw, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

interface ThemeOption {
    value: AppearanceType;
    label: string;
    icon: typeof Sun;
    sidebarBg: string;
    contentBg: string;
    headerBg: string;
    textLine: string;
}

const themeOptions: ThemeOption[] = [
    {
        value: 'light',
        label: 'Light',
        icon: Sun,
        sidebarBg: 'bg-muted',
        contentBg: 'bg-card',
        headerBg: 'bg-muted/70',
        textLine: 'bg-muted-foreground/30',
    },
    {
        value: 'dark',
        label: 'Dark',
        icon: Moon,
        sidebarBg: 'bg-neutral-800',
        contentBg: 'bg-neutral-900',
        headerBg: 'bg-neutral-700',
        textLine: 'bg-neutral-600',
    },
    {
        value: 'system',
        label: 'System',
        icon: Monitor,
        sidebarBg: 'bg-gradient-to-b from-muted to-neutral-800',
        contentBg: 'bg-gradient-to-b from-card to-neutral-900',
        headerBg: 'bg-gradient-to-b from-muted/70 to-neutral-700',
        textLine: 'bg-gradient-to-b from-muted-foreground/30 to-neutral-600',
    },
];

interface FontSizeOption {
    label: string;
    value: string;
    px: number;
}

const fontSizes: FontSizeOption[] = [
    { label: 'Small', value: '13', px: 13 },
    { label: 'Default', value: '14', px: 14 },
    { label: 'Large', value: '16', px: 16 },
];

const dateFormats = [
    { value: 'DD/MM/YYYY', label: 'DD/MM/YYYY (NZ default)' },
    { value: 'MM/DD/YYYY', label: 'MM/DD/YYYY' },
    { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD' },
];

function getLS(key: string, fallback: string): string {
    if (typeof window === 'undefined') return fallback;
    return localStorage.getItem(key) || fallback;
}

function getLSBool(key: string, fallback: boolean): boolean {
    if (typeof window === 'undefined') return fallback;
    const v = localStorage.getItem(key);
    if (v === null) return fallback;
    return v === 'true';
}

export default function Appearance() {
    const {
        appearance,
        updateAppearance,
        updateAccent,
        updateFontSize,
        updateSidebarDensity,
        updateReduceMotion,
        resetAccent,
    } = useAppearance();

    const [accentColour, setAccentColour] = useState(() =>
        getLS('accentColour', DEFAULT_BRAND_HEX),
    );
    const [accentOpen, setAccentOpen] = useState(false);
    const pickerRef = useRef<HTMLDivElement | null>(null);

    const [fontSize, setFontSize] = useState(() => getLS('fontSize', '14'));
    const [sidebarDensity, setSidebarDensity] = useState<'comfortable' | 'compact'>(
        () => getLS('sidebarDensity', 'comfortable') as 'comfortable' | 'compact',
    );
    const [reduceMotion, setReduceMotion] = useState(() =>
        getLSBool('reduceMotion', false),
    );

    const [dateFormat, setDateFormat] = useState(() =>
        getLS('dateFormat', 'DD/MM/YYYY'),
    );
    const [timeFormat, setTimeFormat] = useState<'12' | '24'>(
        () => getLS('timeFormat', '12') as '12' | '24',
    );
    const [firstDay, setFirstDay] = useState<'monday' | 'sunday'>(
        () => getLS('firstDayOfWeek', 'monday') as 'monday' | 'sunday',
    );

    const [saved, setSaved] = useState(false);

    // Live-apply callbacks wrap the hook setters so changes are visible
    // immediately across the whole app (not just this page).
    const handleAccent = useCallback(
        (hex: string) => {
            setAccentColour(hex);
            updateAccent(hex);
        },
        [updateAccent],
    );

    const handleResetAccent = useCallback(() => {
        resetAccent();
        setAccentColour(DEFAULT_BRAND_HEX);
    }, [resetAccent]);

    const handleFontSize = useCallback(
        (value: string) => {
            setFontSize(value);
            updateFontSize(parseInt(value, 10));
        },
        [updateFontSize],
    );

    const handleDensity = useCallback(
        (density: 'comfortable' | 'compact') => {
            setSidebarDensity(density);
            updateSidebarDensity(density);
        },
        [updateSidebarDensity],
    );

    const handleReduceMotion = useCallback(
        (on: boolean) => {
            setReduceMotion(on);
            updateReduceMotion(on);
        },
        [updateReduceMotion],
    );

    // Close the accent popover on outside click.
    useEffect(() => {
        if (!accentOpen) return;
        const onClick = (e: MouseEvent) => {
            if (
                pickerRef.current &&
                !pickerRef.current.contains(e.target as Node)
            ) {
                setAccentOpen(false);
            }
        };
        window.addEventListener('mousedown', onClick);
        return () => window.removeEventListener('mousedown', onClick);
    }, [accentOpen]);

    // Save action — writes non-live settings (regional) to localStorage.
    // Phase 2 wires this to a real PUT /settings/appearance.
    const handleSave = useCallback(() => {
        localStorage.setItem('dateFormat', dateFormat);
        localStorage.setItem('timeFormat', timeFormat);
        localStorage.setItem('firstDayOfWeek', firstDay);
        setSaved(true);
    }, [dateFormat, timeFormat, firstDay]);

    useEffect(() => {
        if (saved) {
            const t = setTimeout(() => setSaved(false), 2500);
            return () => clearTimeout(t);
        }
    }, [saved]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                {/* Theme */}
                <Card>
                    <CardHeader>
                        <CardTitle>Theme</CardTitle>
                        <CardDescription>
                            Choose how the application looks
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {themeOptions.map((option) => {
                                const isActive = appearance === option.value;
                                const Icon = option.icon;
                                return (
                                    <button
                                        key={option.value}
                                        onClick={() =>
                                            updateAppearance(option.value)
                                        }
                                        className={cn(
                                            'group relative flex flex-col items-center gap-3 rounded-xl border-2 p-4 transition-all',
                                            isActive
                                                ? 'border-primary bg-primary/5 shadow-sm'
                                                : 'border-transparent bg-muted/30 hover:border-muted-foreground/20 hover:bg-muted/50',
                                        )}
                                    >
                                        <div className="flex h-28 w-full overflow-hidden rounded-lg border shadow-sm">
                                            <div
                                                className={cn(
                                                    'flex w-10 flex-col gap-1.5 p-1.5',
                                                    option.sidebarBg,
                                                )}
                                            >
                                                <div className="h-2 w-full rounded-sm bg-primary" />
                                                <div className="mt-1 h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-3/4 rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-2/3 rounded-sm bg-current opacity-10" />
                                            </div>
                                            <div
                                                className={cn(
                                                    'flex flex-1 flex-col gap-2 p-2',
                                                    option.contentBg,
                                                )}
                                            >
                                                <div
                                                    className={cn(
                                                        'h-2 w-2/3 rounded-sm',
                                                        option.headerBg,
                                                    )}
                                                />
                                                <div className="flex flex-1 gap-1.5">
                                                    <div
                                                        className={cn(
                                                            'flex-1 rounded-sm opacity-40',
                                                            option.headerBg,
                                                        )}
                                                    />
                                                    <div
                                                        className={cn(
                                                            'flex-1 rounded-sm opacity-40',
                                                            option.headerBg,
                                                        )}
                                                    />
                                                </div>
                                                <div className="flex gap-1.5">
                                                    <div
                                                        className={cn(
                                                            'h-3 flex-1 rounded-sm opacity-30',
                                                            option.textLine,
                                                        )}
                                                    />
                                                    <div className="h-3 w-8 rounded-sm bg-primary opacity-70" />
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2.5">
                                            <div
                                                className={cn(
                                                    'flex h-4 w-4 items-center justify-center rounded-full border-2 transition-colors',
                                                    isActive
                                                        ? 'border-primary'
                                                        : 'border-muted-foreground/30',
                                                )}
                                            >
                                                {isActive && (
                                                    <div className="h-2 w-2 rounded-full bg-primary" />
                                                )}
                                            </div>
                                            <Icon
                                                className={cn(
                                                    'h-4 w-4',
                                                    isActive
                                                        ? 'text-primary'
                                                        : 'text-muted-foreground',
                                                )}
                                            />
                                            <span
                                                className={cn(
                                                    'text-sm font-medium',
                                                    isActive
                                                        ? 'text-primary'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {option.label}
                                            </span>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Accent colour */}
                <Card>
                    <CardHeader>
                        <CardTitle>Accent Colour</CardTitle>
                        <CardDescription>
                            Pick any colour &mdash; the whole app re-tints live.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center gap-4">
                            <div className="relative" ref={pickerRef}>
                                <button
                                    type="button"
                                    onClick={() => setAccentOpen((v) => !v)}
                                    className="flex items-center gap-3 rounded-lg border border-input bg-card px-3 py-2 text-sm shadow-sm transition-colors hover:bg-muted/50"
                                    aria-haspopup="dialog"
                                    aria-expanded={accentOpen}
                                >
                                    <span
                                        className="h-6 w-6 rounded-md border"
                                        style={{ backgroundColor: accentColour }}
                                    />
                                    <span className="font-mono uppercase">
                                        {accentColour}
                                    </span>
                                </button>
                                {accentOpen && (
                                    <div className="absolute left-0 z-50 mt-2 rounded-lg border bg-popover p-3 shadow-lg">
                                        <HexColorPicker
                                            color={accentColour}
                                            onChange={handleAccent}
                                        />
                                        <input
                                            type="text"
                                            value={accentColour}
                                            onChange={(e) =>
                                                handleAccent(e.target.value)
                                            }
                                            className="mt-2 w-full rounded-md border border-input bg-transparent px-2 py-1 text-sm font-mono uppercase"
                                        />
                                    </div>
                                )}
                            </div>

                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleResetAccent}
                                className="gap-2"
                            >
                                <RotateCcw className="h-4 w-4" />
                                Reset to brand default
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Display */}
                <Card>
                    <CardHeader>
                        <CardTitle>Display</CardTitle>
                        <CardDescription>
                            Customise text size and visual density
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-8">
                        {/* Font size */}
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">Font Size</Label>
                            <div className="grid grid-cols-3 gap-3">
                                {fontSizes.map((opt) => {
                                    const isActive = fontSize === opt.value;
                                    return (
                                        <button
                                            key={opt.value}
                                            onClick={() => handleFontSize(opt.value)}
                                            className={cn(
                                                'flex flex-col items-center gap-2 rounded-lg border-2 px-4 py-4 transition-all',
                                                isActive
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-transparent bg-muted/30 hover:border-muted-foreground/20',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'font-semibold leading-none',
                                                    isActive
                                                        ? 'text-primary'
                                                        : 'text-foreground',
                                                )}
                                                style={{ fontSize: `${opt.px}px` }}
                                            >
                                                Aa
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {opt.label} ({opt.px}px)
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Sidebar density */}
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">
                                Sidebar Density
                            </Label>
                            <div className="grid grid-cols-2 gap-3">
                                {(['comfortable', 'compact'] as const).map((density) => {
                                    const isActive = sidebarDensity === density;
                                    const gap =
                                        density === 'comfortable' ? 'gap-2' : 'gap-0.5';
                                    const pad =
                                        density === 'comfortable' ? 'p-2' : 'p-1.5';
                                    return (
                                        <button
                                            key={density}
                                            onClick={() => handleDensity(density)}
                                            className={cn(
                                                'flex flex-col items-center gap-2 rounded-lg border-2 p-4 transition-all',
                                                isActive
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-transparent bg-muted/30 hover:border-muted-foreground/20',
                                            )}
                                        >
                                            <div
                                                className={cn(
                                                    'flex w-20 flex-col rounded-md border bg-muted/40',
                                                    pad,
                                                    gap,
                                                )}
                                            >
                                                <div className="h-1.5 w-full rounded-sm bg-primary" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-3/4 rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-2/3 rounded-sm bg-current opacity-10" />
                                            </div>
                                            <span className="text-xs capitalize text-muted-foreground">
                                                {density}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Reduce motion */}
                        <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                            <div className="space-y-0.5">
                                <Label
                                    htmlFor="reduce-motion"
                                    className="text-sm font-medium"
                                >
                                    Reduce motion
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Disable animations for accessibility
                                </p>
                            </div>
                            <Switch
                                id="reduce-motion"
                                checked={reduceMotion}
                                onCheckedChange={handleReduceMotion}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Regional */}
                <Card>
                    <CardHeader>
                        <CardTitle>Regional</CardTitle>
                        <CardDescription>
                            Date, time, and language preferences
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="dateFormat">Date format</Label>
                            <select
                                id="dateFormat"
                                value={dateFormat}
                                onChange={(e) => setDateFormat(e.target.value)}
                                className="flex h-9 max-w-xs rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {dateFormats.map((f) => (
                                    <option key={f.value} value={f.value}>
                                        {f.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid gap-2">
                            <Label>Time format</Label>
                            <div className="flex gap-2">
                                {(['12', '24'] as const).map((fmt) => (
                                    <button
                                        key={fmt}
                                        onClick={() => setTimeFormat(fmt)}
                                        className={cn(
                                            'rounded-md border px-4 py-2 text-sm font-medium transition-all',
                                            timeFormat === fmt
                                                ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                                : 'border-input bg-transparent text-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {fmt}-hour
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label>First day of week</Label>
                            <div className="flex gap-2">
                                {(
                                    [
                                        { value: 'monday' as const, label: 'Monday' },
                                        { value: 'sunday' as const, label: 'Sunday' },
                                    ] as const
                                ).map((opt) => (
                                    <button
                                        key={opt.value}
                                        onClick={() => setFirstDay(opt.value)}
                                        className={cn(
                                            'rounded-md border px-4 py-2 text-sm font-medium transition-all',
                                            firstDay === opt.value
                                                ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                                : 'border-input bg-transparent text-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {opt.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="language">Language</Label>
                            <div className="flex items-center gap-3">
                                <input
                                    id="language"
                                    disabled
                                    value="English (NZ)"
                                    className="flex h-9 max-w-xs rounded-md border border-input bg-muted/50 px-3 py-1 text-sm text-muted-foreground shadow-sm"
                                />
                                <span className="text-xs text-muted-foreground">
                                    More languages coming soon
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Save */}
                <div className="flex items-center gap-4">
                    <Button onClick={handleSave}>Save preferences</Button>
                    {saved && (
                        <span className="flex items-center gap-1.5 text-sm font-medium text-status-success">
                            <Check className="h-4 w-4" />
                            Preferences saved
                        </span>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
