import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    useAppearance,
    type Appearance as AppearanceType,
} from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { DEFAULT_BRAND_HEX } from '@/lib/derive-palette';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, Monitor, Moon, RotateCcw, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { HexColorPicker } from 'react-colorful';

interface AppearancePageProps extends Record<string, unknown> {
    appearance: {
        theme: AppearanceType;
        accent_colour: string | null;
        font_size: number;
        sidebar_density: 'comfortable' | 'compact';
        reduce_motion: boolean;
        first_day_of_week: 'monday' | 'sunday';
        date_format: string;
        time_format: '12' | '24';
        locale: string;
    };
    orgDefaults: {
        first_day_of_week: string;
        date_format: string;
        time_format: string;
    };
}

interface ThemeOption {
    value: AppearanceType;
    label: string;
    labelKey: string;
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
        labelKey: 'app.appearance.theme.light',
        icon: Sun,
        sidebarBg: 'bg-muted',
        contentBg: 'bg-card',
        headerBg: 'bg-muted/70',
        textLine: 'bg-muted-foreground/30',
    },
    {
        value: 'dark',
        label: 'Dark',
        labelKey: 'app.appearance.theme.dark',
        icon: Moon,
        sidebarBg: 'bg-muted',
        contentBg: 'bg-muted',
        headerBg: 'bg-muted',
        textLine: 'bg-muted-foreground/80',
    },
    {
        value: 'system',
        label: 'System',
        labelKey: 'app.appearance.theme.system',
        icon: Monitor,
        sidebarBg: 'bg-gradient-to-b from-muted to-muted',
        contentBg: 'bg-gradient-to-b from-card to-muted',
        headerBg: 'bg-gradient-to-b from-muted/70 to-muted',
        textLine: 'bg-gradient-to-b from-muted-foreground/30 to-muted',
    },
];

interface FontSizeOption {
    label: string;
    labelKey: string;
    value: string;
    px: number;
}

const fontSizes: FontSizeOption[] = [
    {
        label: 'Small',
        labelKey: 'app.appearance.display.font_size_small',
        value: '13',
        px: 13,
    },
    {
        label: 'Default',
        labelKey: 'app.appearance.display.font_size_default',
        value: '14',
        px: 14,
    },
    {
        label: 'Large',
        labelKey: 'app.appearance.display.font_size_large',
        value: '16',
        px: 16,
    },
];

const dateFormats = [
    {
        value: 'DD/MM/YYYY',
        label: 'DD/MM/YYYY (NZ default)',
        labelKey: 'app.appearance.regional.date_format_nz',
    },
    {
        value: 'MM/DD/YYYY',
        label: 'MM/DD/YYYY',
        labelKey: 'app.appearance.regional.date_format_us',
    },
    {
        value: 'YYYY-MM-DD',
        label: 'YYYY-MM-DD',
        labelKey: 'app.appearance.regional.date_format_iso',
    },
];

export default function Appearance() {
    const page = usePage<AppearancePageProps>();
    const { availableLocales, t } = useI18n();
    const {
        appearance: themeSetting,
        updateAppearance,
        updateAccent,
        updateFontSize,
        updateSidebarDensity,
        updateReduceMotion,
        resetAccent,
    } = useAppearance();

    const server = page.props.appearance;

    const form = useForm<{
        theme: AppearanceType;
        accent_colour: string | null;
        font_size: number;
        sidebar_density: 'comfortable' | 'compact';
        reduce_motion: boolean;
        first_day_of_week: 'monday' | 'sunday';
        date_format: string;
        time_format: '12' | '24';
        locale: string;
    }>({
        theme: server.theme,
        accent_colour: server.accent_colour,
        font_size: server.font_size,
        sidebar_density: server.sidebar_density,
        reduce_motion: server.reduce_motion,
        first_day_of_week: server.first_day_of_week,
        date_format: server.date_format,
        time_format: server.time_format,
        locale: server.locale,
    });

    const [accentOpen, setAccentOpen] = useState(false);
    const pickerRef = useRef<HTMLDivElement | null>(null);
    const [saved, setSaved] = useState(false);

    // Hydrate live-apply from server on first mount so hard-refresh matches
    // persisted state even if localStorage is cleared.
    useEffect(() => {
        updateAppearance(server.theme);
        if (server.accent_colour) updateAccent(server.accent_colour);
        updateFontSize(server.font_size);
        updateSidebarDensity(server.sidebar_density);
        updateReduceMotion(server.reduce_motion);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Live-apply callbacks — update form data AND apply to the DOM so the
    // change is visible immediately across the whole app. Persistence happens
    // on Save.
    const handleTheme = useCallback(
        (mode: AppearanceType) => {
            form.setData('theme', mode);
            updateAppearance(mode);
        },
        [form, updateAppearance],
    );

    const handleAccent = useCallback(
        (hex: string) => {
            form.setData('accent_colour', hex);
            updateAccent(hex);
        },
        [form, updateAccent],
    );

    const handleResetAccent = useCallback(() => {
        form.setData('accent_colour', null);
        resetAccent();
    }, [form, resetAccent]);

    const handleFontSize = useCallback(
        (value: number) => {
            form.setData('font_size', value);
            updateFontSize(value);
        },
        [form, updateFontSize],
    );

    const handleDensity = useCallback(
        (density: 'comfortable' | 'compact') => {
            form.setData('sidebar_density', density);
            updateSidebarDensity(density);
        },
        [form, updateSidebarDensity],
    );

    const handleReduceMotion = useCallback(
        (on: boolean) => {
            form.setData('reduce_motion', on);
            updateReduceMotion(on);
        },
        [form, updateReduceMotion],
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

    const handleSave = useCallback(() => {
        form.put('/settings/appearance', {
            preserveScroll: true,
            onSuccess: () => setSaved(true),
        });
    }, [form]);

    useEffect(() => {
        if (saved) {
            const t = setTimeout(() => setSaved(false), 2500);
            return () => clearTimeout(t);
        }
    }, [saved]);

    const title = t('app.appearance.title', 'Appearance settings');
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title,
            href: editAppearance().url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <SettingsLayout>
                {/* Theme */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('app.appearance.theme.title', 'Theme')}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'app.appearance.theme.description',
                                'Choose how the application looks',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {themeOptions.map((option) => {
                                const isActive = themeSetting === option.value;
                                const Icon = option.icon;
                                return (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        key={option.value}
                                        onClick={() =>
                                            handleTheme(option.value)
                                        }
                                        className={cn(
                                            'group relative h-auto flex-col gap-3 rounded-xl border-2 p-4',
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
                                                {t(
                                                    option.labelKey,
                                                    option.label,
                                                )}
                                            </span>
                                        </div>
                                    </Button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Accent colour */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t(
                                'app.appearance.accent.title',
                                'Accent colour',
                            )}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'app.appearance.accent.description',
                                'Pick any colour - the whole app re-tints live.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center gap-4">
                            <div className="relative" ref={pickerRef}>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setAccentOpen((v) => !v)}
                                    className="h-auto gap-3 rounded-lg border-input bg-card px-3 py-2 text-sm hover:bg-muted/50"
                                    aria-haspopup="dialog"
                                    aria-expanded={accentOpen}
                                >
                                    <span
                                        className="h-6 w-6 rounded-md border"
                                        style={{
                                            backgroundColor:
                                                form.data.accent_colour ??
                                                DEFAULT_BRAND_HEX,
                                        }}
                                    />
                                    <span className="font-mono uppercase">
                                        {form.data.accent_colour ??
                                            t(
                                                'app.appearance.accent.brand_default',
                                                'Brand default',
                                            )}
                                    </span>
                                </Button>
                                {accentOpen && (
                                    <div className="absolute left-0 z-50 mt-2 rounded-lg border bg-popover p-3 shadow-lg">
                                        <HexColorPicker
                                            color={
                                                form.data.accent_colour ??
                                                DEFAULT_BRAND_HEX
                                            }
                                            onChange={handleAccent}
                                        />
                                        <input
                                            type="text"
                                            value={
                                                form.data.accent_colour ?? ''
                                            }
                                            onChange={(e) =>
                                                handleAccent(e.target.value)
                                            }
                                            className="mt-2 w-full rounded-md border border-input bg-transparent px-2 py-1 font-mono text-sm uppercase"
                                            placeholder="#7c3aed"
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
                                {t(
                                    'app.appearance.accent.reset',
                                    'Reset to brand default',
                                )}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Display */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('app.appearance.display.title', 'Display')}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'app.appearance.display.description',
                                'Customise text size and visual density',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-8">
                        {/* Font size */}
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">
                                {t(
                                    'app.appearance.display.font_size',
                                    'Font size',
                                )}
                            </Label>
                            <div className="grid grid-cols-3 gap-3">
                                {fontSizes.map((opt) => {
                                    const isActive =
                                        form.data.font_size === opt.px;
                                    return (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            key={opt.value}
                                            onClick={() =>
                                                handleFontSize(opt.px)
                                            }
                                            className={cn(
                                                'h-auto flex-col gap-2 rounded-lg border-2 px-4 py-4',
                                                isActive
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-transparent bg-muted/30 hover:border-muted-foreground/20',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'leading-none font-semibold',
                                                    isActive
                                                        ? 'text-primary'
                                                        : 'text-foreground',
                                                )}
                                                style={{
                                                    fontSize: `${opt.px}px`,
                                                }}
                                            >
                                                Aa
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(opt.labelKey, opt.label)} (
                                                {opt.px}px)
                                            </span>
                                        </Button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Sidebar density */}
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">
                                {t(
                                    'app.appearance.display.sidebar_density',
                                    'Sidebar density',
                                )}
                            </Label>
                            <div className="grid grid-cols-2 gap-3">
                                {(['comfortable', 'compact'] as const).map(
                                    (density) => {
                                        const isActive =
                                            form.data.sidebar_density ===
                                            density;
                                        const gap =
                                            density === 'comfortable'
                                                ? 'gap-2'
                                                : 'gap-0.5';
                                        const pad =
                                            density === 'comfortable'
                                                ? 'p-2'
                                                : 'p-1.5';
                                        return (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                key={density}
                                                onClick={() =>
                                                    handleDensity(density)
                                                }
                                                className={cn(
                                                    'h-auto flex-col gap-2 rounded-lg border-2 p-4',
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
                                                <span className="text-xs text-muted-foreground capitalize">
                                                    {t(
                                                        `app.appearance.display.${density}`,
                                                        density,
                                                    )}
                                                </span>
                                            </Button>
                                        );
                                    },
                                )}
                            </div>
                        </div>

                        {/* Reduce motion */}
                        <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                            <div className="space-y-0.5">
                                <Label
                                    htmlFor="reduce-motion"
                                    className="text-sm font-medium"
                                >
                                    {t(
                                        'app.appearance.display.reduce_motion',
                                        'Reduce motion',
                                    )}
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'app.appearance.display.reduce_motion_description',
                                        'Disable animations for accessibility',
                                    )}
                                </p>
                            </div>
                            <Switch
                                id="reduce-motion"
                                checked={form.data.reduce_motion}
                                onCheckedChange={handleReduceMotion}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Regional */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('app.appearance.regional.title', 'Regional')}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'app.appearance.regional.description',
                                'Date, time, and language preferences',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="dateFormat">
                                {t(
                                    'app.appearance.regional.date_format',
                                    'Date format',
                                )}
                            </Label>
                            <select
                                id="dateFormat"
                                value={form.data.date_format}
                                onChange={(e) =>
                                    form.setData('date_format', e.target.value)
                                }
                                className="flex h-9 max-w-xs rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {dateFormats.map((f) => (
                                    <option key={f.value} value={f.value}>
                                        {t(f.labelKey, f.label)}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid gap-2">
                            <Label>
                                {t(
                                    'app.appearance.regional.time_format',
                                    'Time format',
                                )}
                            </Label>
                            <div className="flex gap-2">
                                {(['12', '24'] as const).map((fmt) => (
                                    <Button
                                        type="button"
                                        variant={
                                            form.data.time_format === fmt
                                                ? 'default'
                                                : 'outline'
                                        }
                                        key={fmt}
                                        onClick={() =>
                                            form.setData('time_format', fmt)
                                        }
                                        className={cn(
                                            'px-4 py-2 text-sm font-medium',
                                            form.data.time_format === fmt
                                                ? 'shadow-sm'
                                                : 'bg-transparent text-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {t(
                                            `app.appearance.regional.time_format_${fmt}`,
                                            `${fmt}-hour`,
                                        )}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label>
                                {t(
                                    'app.appearance.regional.first_day_of_week',
                                    'First day of week',
                                )}
                            </Label>
                            <div className="flex gap-2">
                                {(
                                    [
                                        {
                                            value: 'monday' as const,
                                            label: 'Monday',
                                            labelKey:
                                                'app.appearance.regional.monday',
                                        },
                                        {
                                            value: 'sunday' as const,
                                            label: 'Sunday',
                                            labelKey:
                                                'app.appearance.regional.sunday',
                                        },
                                    ] as const
                                ).map((opt) => (
                                    <Button
                                        type="button"
                                        variant={
                                            form.data.first_day_of_week ===
                                            opt.value
                                                ? 'default'
                                                : 'outline'
                                        }
                                        key={opt.value}
                                        onClick={() =>
                                            form.setData(
                                                'first_day_of_week',
                                                opt.value,
                                            )
                                        }
                                        className={cn(
                                            'px-4 py-2 text-sm font-medium',
                                            form.data.first_day_of_week ===
                                                opt.value
                                                ? 'shadow-sm'
                                                : 'bg-transparent text-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {t(opt.labelKey, opt.label)}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="language">
                                {t('app.settings.language', 'Language')}
                            </Label>
                            <Select
                                value={form.data.locale}
                                onValueChange={(value) =>
                                    form.setData('locale', value)
                                }
                            >
                                <SelectTrigger
                                    id="language"
                                    className="max-w-xs"
                                >
                                    <SelectValue
                                        placeholder={t(
                                            'app.appearance.regional.select_language',
                                            'Select language',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(availableLocales).map(
                                        ([value, meta]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {meta.label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Save */}
                <div className="flex items-center gap-4">
                    <Button onClick={handleSave} disabled={form.processing}>
                        {form.processing
                            ? t('app.actions.saving', 'Saving...')
                            : t(
                                  'app.settings.save_preferences',
                                  'Save preferences',
                              )}
                    </Button>
                    {saved && (
                        <span className="flex items-center gap-1.5 text-sm font-medium text-status-success">
                            <Check className="h-4 w-4" />
                            {t(
                                'app.settings.preferences_saved',
                                'Preferences saved',
                            )}
                        </span>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
