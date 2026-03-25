import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { Monitor, Moon, Sun } from 'lucide-react';
import { useCallback, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

interface ThemeOption {
    value: Appearance;
    label: string;
    icon: typeof Sun;
    // Mini preview colors: [sidebar, content, accent]
    sidebarBg: string;
    contentBg: string;
    accentBg: string;
}

const themeOptions: ThemeOption[] = [
    {
        value: 'light',
        label: 'Light',
        icon: Sun,
        sidebarBg: 'bg-gray-100',
        contentBg: 'bg-white',
        accentBg: 'bg-violet-200',
    },
    {
        value: 'dark',
        label: 'Dark',
        icon: Moon,
        sidebarBg: 'bg-gray-800',
        contentBg: 'bg-gray-900',
        accentBg: 'bg-violet-600',
    },
    {
        value: 'system',
        label: 'System',
        icon: Monitor,
        sidebarBg: 'bg-gradient-to-r from-gray-100 to-gray-800',
        contentBg: 'bg-gradient-to-r from-white to-gray-900',
        accentBg: 'bg-violet-400',
    },
];

const dateFormats = [
    { value: 'DD/MM/YYYY', label: 'DD/MM/YYYY (NZ default)' },
    { value: 'MM/DD/YYYY', label: 'MM/DD/YYYY' },
    { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD' },
];

export default function Appearance() {
    const { appearance, updateAppearance } = useAppearance();
    const [dateFormat, setDateFormat] = useState(() => localStorage.getItem('dateFormat') || 'DD/MM/YYYY');
    const [timeFormat, setTimeFormat] = useState<'12' | '24'>(() => (localStorage.getItem('timeFormat') as '12' | '24') || '12');
    const [sidebarDensity, setSidebarDensity] = useState<'comfortable' | 'compact'>(() => (localStorage.getItem('sidebarDensity') as 'comfortable' | 'compact') || 'comfortable');

    const handleSavePreferences = useCallback(() => {
        localStorage.setItem('dateFormat', dateFormat);
        localStorage.setItem('timeFormat', timeFormat);
        localStorage.setItem('sidebarDensity', sidebarDensity);
    }, [dateFormat, timeFormat, sidebarDensity]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                {/* Theme Card */}
                <Card>
                    <CardHeader>
                        <CardTitle>Theme</CardTitle>
                        <CardDescription>Choose how the application looks</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {themeOptions.map((option) => {
                                const isActive = appearance === option.value;
                                const Icon = option.icon;
                                return (
                                    <button
                                        key={option.value}
                                        onClick={() => updateAppearance(option.value)}
                                        className={cn(
                                            'group flex flex-col items-center gap-3 rounded-lg border-2 p-4 transition-all',
                                            isActive
                                                ? 'border-violet-600 bg-violet-50/50 dark:bg-violet-950/20'
                                                : 'border-transparent bg-muted/30 hover:border-muted-foreground/20 hover:bg-muted/50',
                                        )}
                                    >
                                        {/* Mini preview */}
                                        <div className="flex w-full overflow-hidden rounded-md border">
                                            <div
                                                className={cn(
                                                    'flex w-8 flex-col gap-1.5 p-1.5',
                                                    option.sidebarBg,
                                                )}
                                            >
                                                <div className={cn('h-1.5 w-full rounded-sm', option.accentBg)} />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-3/4 rounded-sm bg-current opacity-10" />
                                            </div>
                                            <div className={cn('flex flex-1 flex-col gap-1.5 p-2', option.contentBg)}>
                                                <div className="h-1.5 w-3/4 rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-[0.06]" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-[0.06]" />
                                                <div className="h-1 w-2/3 rounded-sm bg-current opacity-[0.06]" />
                                            </div>
                                        </div>

                                        {/* Radio indicator + label */}
                                        <div className="flex items-center gap-2">
                                            <div
                                                className={cn(
                                                    'flex h-4 w-4 items-center justify-center rounded-full border-2 transition-colors',
                                                    isActive
                                                        ? 'border-violet-600'
                                                        : 'border-muted-foreground/30',
                                                )}
                                            >
                                                {isActive && (
                                                    <div className="h-2 w-2 rounded-full bg-violet-600" />
                                                )}
                                            </div>
                                            <span
                                                className={cn(
                                                    'text-sm font-medium',
                                                    isActive
                                                        ? 'text-violet-700 dark:text-violet-400'
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

                {/* Display Preferences Card */}
                <Card>
                    <CardHeader>
                        <CardTitle>Display Preferences</CardTitle>
                        <CardDescription>Customise your display settings</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {/* Date format */}
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

                        {/* Time format */}
                        <div className="grid gap-2">
                            <Label>Time format</Label>
                            <div className="flex gap-4">
                                {(['12', '24'] as const).map((fmt) => (
                                    <label
                                        key={fmt}
                                        className="flex cursor-pointer items-center gap-2"
                                    >
                                        <div
                                            className={cn(
                                                'flex h-4 w-4 items-center justify-center rounded-full border-2 transition-colors',
                                                timeFormat === fmt
                                                    ? 'border-violet-600'
                                                    : 'border-muted-foreground/30',
                                            )}
                                        >
                                            {timeFormat === fmt && (
                                                <div className="h-2 w-2 rounded-full bg-violet-600" />
                                            )}
                                        </div>
                                        <span className="text-sm">{fmt}-hour</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Sidebar density */}
                        <div className="grid gap-2">
                            <Label>Sidebar density</Label>
                            <div className="flex gap-4">
                                {(['comfortable', 'compact'] as const).map((density) => (
                                    <label
                                        key={density}
                                        className="flex cursor-pointer items-center gap-2"
                                    >
                                        <div
                                            className={cn(
                                                'flex h-4 w-4 items-center justify-center rounded-full border-2 transition-colors',
                                                sidebarDensity === density
                                                    ? 'border-violet-600'
                                                    : 'border-muted-foreground/30',
                                            )}
                                        >
                                            {sidebarDensity === density && (
                                                <div className="h-2 w-2 rounded-full bg-violet-600" />
                                            )}
                                        </div>
                                        <span className="text-sm capitalize">{density}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Language */}
                        <div className="grid gap-2">
                            <Label htmlFor="language">Language</Label>
                            <div className="flex items-center gap-3">
                                <select
                                    id="language"
                                    disabled
                                    className="flex h-9 max-w-xs rounded-md border border-input bg-muted/50 px-3 py-1 text-sm text-muted-foreground shadow-sm"
                                >
                                    <option>English (NZ)</option>
                                </select>
                                <span className="text-xs text-muted-foreground">
                                    More languages coming soon
                                </span>
                            </div>
                        </div>

                        {/* Save button */}
                        <Button
                            onClick={handleSavePreferences}
                            className="bg-violet-600 hover:bg-violet-700"
                        >
                            Save preferences
                        </Button>
                    </CardContent>
                </Card>
            </SettingsLayout>
        </AppLayout>
    );
}
