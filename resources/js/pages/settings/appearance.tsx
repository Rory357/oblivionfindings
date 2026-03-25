import { Head } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAppearance } from '@/routes/appearance';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { type Appearance as AppearanceType, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import {
    Bell,
    BellRing,
    Briefcase,
    Calendar,
    Check,
    LayoutDashboard,
    Monitor,
    Moon,
    Sun,
    Users,
    Volume2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: editAppearance().url,
    },
];

// ---------------------------------------------------------------------------
// Theme options
// ---------------------------------------------------------------------------
interface ThemeOption {
    value: AppearanceType;
    label: string;
    icon: typeof Sun;
    sidebarBg: string;
    contentBg: string;
    accentBg: string;
    headerBg: string;
    textLine: string;
}

const themeOptions: ThemeOption[] = [
    {
        value: 'light',
        label: 'Light',
        icon: Sun,
        sidebarBg: 'bg-gray-100',
        contentBg: 'bg-white',
        accentBg: 'bg-violet-500',
        headerBg: 'bg-gray-200/70',
        textLine: 'bg-gray-300',
    },
    {
        value: 'dark',
        label: 'Dark',
        icon: Moon,
        sidebarBg: 'bg-gray-800',
        contentBg: 'bg-gray-900',
        accentBg: 'bg-violet-500',
        headerBg: 'bg-gray-700',
        textLine: 'bg-gray-600',
    },
    {
        value: 'system',
        label: 'System',
        icon: Monitor,
        sidebarBg: 'bg-gradient-to-b from-gray-100 to-gray-800',
        contentBg: 'bg-gradient-to-b from-white to-gray-900',
        accentBg: 'bg-violet-400',
        headerBg: 'bg-gradient-to-b from-gray-200/70 to-gray-700',
        textLine: 'bg-gradient-to-b from-gray-300 to-gray-600',
    },
];

// ---------------------------------------------------------------------------
// Accent colours
// ---------------------------------------------------------------------------
interface AccentColour {
    name: string;
    value: string;
    bg: string;
    ring: string;
}

const accentColours: AccentColour[] = [
    { name: 'Violet', value: 'violet', bg: 'bg-violet-600', ring: 'ring-violet-600' },
    { name: 'Indigo', value: 'indigo', bg: 'bg-indigo-600', ring: 'ring-indigo-600' },
    { name: 'Blue', value: 'blue', bg: 'bg-blue-600', ring: 'ring-blue-600' },
    { name: 'Emerald', value: 'emerald', bg: 'bg-emerald-600', ring: 'ring-emerald-600' },
    { name: 'Rose', value: 'rose', bg: 'bg-rose-500', ring: 'ring-rose-500' },
    { name: 'Amber', value: 'amber', bg: 'bg-amber-500', ring: 'ring-amber-500' },
    { name: 'Slate', value: 'slate', bg: 'bg-slate-600', ring: 'ring-slate-600' },
    { name: 'Teal', value: 'teal', bg: 'bg-teal-600', ring: 'ring-teal-600' },
];

// ---------------------------------------------------------------------------
// Font sizes
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// Date formats
// ---------------------------------------------------------------------------
const dateFormats = [
    { value: 'DD/MM/YYYY', label: 'DD/MM/YYYY (NZ default)' },
    { value: 'MM/DD/YYYY', label: 'MM/DD/YYYY' },
    { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD' },
];

// ---------------------------------------------------------------------------
// Landing pages
// ---------------------------------------------------------------------------
interface LandingPageOption {
    value: string;
    label: string;
    icon: typeof LayoutDashboard;
}

const landingPages: LandingPageOption[] = [
    { value: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { value: 'operations', label: 'Operations', icon: Users },
    { value: 'schedule', label: 'My Schedule', icon: Calendar },
    { value: 'hr', label: 'HR Portal', icon: Briefcase },
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------
export default function Appearance() {
    const { appearance, updateAppearance } = useAppearance();

    // Card 2 - Accent Colour
    const [accentColour, setAccentColour] = useState(() => getLS('accentColour', 'violet'));

    // Card 3 - Display
    const [fontSize, setFontSize] = useState(() => getLS('fontSize', '14'));
    const [sidebarDensity, setSidebarDensity] = useState<'comfortable' | 'compact'>(
        () => getLS('sidebarDensity', 'comfortable') as 'comfortable' | 'compact',
    );
    const [reduceMotion, setReduceMotion] = useState(() => getLSBool('reduceMotion', false));

    // Card 4 - Regional
    const [dateFormat, setDateFormat] = useState(() => getLS('dateFormat', 'DD/MM/YYYY'));
    const [timeFormat, setTimeFormat] = useState<'12' | '24'>(
        () => getLS('timeFormat', '12') as '12' | '24',
    );
    const [firstDay, setFirstDay] = useState<'monday' | 'sunday'>(
        () => getLS('firstDayOfWeek', 'monday') as 'monday' | 'sunday',
    );

    // Card 5 - Notifications
    const [desktopNotifications, setDesktopNotifications] = useState(() =>
        getLSBool('desktopNotifications', false),
    );
    const [notificationSounds, setNotificationSounds] = useState(() =>
        getLSBool('notificationSounds', true),
    );
    const [emailDigest, setEmailDigest] = useState(() => getLS('emailDigest', 'instant'));

    // Card 6 - Landing page
    const [landingPage, setLandingPage] = useState(() => getLS('landingPage', 'dashboard'));

    // Save flash
    const [saved, setSaved] = useState(false);

    const handleSave = useCallback(() => {
        localStorage.setItem('accentColour', accentColour);
        localStorage.setItem('fontSize', fontSize);
        localStorage.setItem('sidebarDensity', sidebarDensity);
        localStorage.setItem('reduceMotion', String(reduceMotion));
        localStorage.setItem('dateFormat', dateFormat);
        localStorage.setItem('timeFormat', timeFormat);
        localStorage.setItem('firstDayOfWeek', firstDay);
        localStorage.setItem('desktopNotifications', String(desktopNotifications));
        localStorage.setItem('notificationSounds', String(notificationSounds));
        localStorage.setItem('emailDigest', emailDigest);
        localStorage.setItem('landingPage', landingPage);
        setSaved(true);
    }, [
        accentColour,
        fontSize,
        sidebarDensity,
        reduceMotion,
        dateFormat,
        timeFormat,
        firstDay,
        desktopNotifications,
        notificationSounds,
        emailDigest,
        landingPage,
    ]);

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
                {/* --------------------------------------------------------- */}
                {/* Card 1: Theme                                             */}
                {/* --------------------------------------------------------- */}
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
                                            'group relative flex flex-col items-center gap-3 rounded-xl border-2 p-4 transition-all',
                                            isActive
                                                ? 'border-violet-600 bg-violet-50/60 shadow-sm dark:bg-violet-950/20'
                                                : 'border-transparent bg-muted/30 hover:border-muted-foreground/20 hover:bg-muted/50',
                                        )}
                                    >
                                        {/* Mini UI mockup preview */}
                                        <div className="flex h-28 w-full overflow-hidden rounded-lg border shadow-sm">
                                            {/* Sidebar */}
                                            <div
                                                className={cn(
                                                    'flex w-10 flex-col gap-1.5 p-1.5',
                                                    option.sidebarBg,
                                                )}
                                            >
                                                <div className={cn('h-2 w-full rounded-sm', option.accentBg)} />
                                                <div className="mt-1 h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-3/4 rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-full rounded-sm bg-current opacity-10" />
                                                <div className="h-1 w-2/3 rounded-sm bg-current opacity-10" />
                                            </div>
                                            {/* Content area */}
                                            <div className={cn('flex flex-1 flex-col gap-2 p-2', option.contentBg)}>
                                                {/* Top bar */}
                                                <div className={cn('h-2 w-2/3 rounded-sm', option.headerBg)} />
                                                {/* Content cards */}
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
                                                    <div
                                                        className={cn(
                                                            'h-3 w-8 rounded-sm opacity-60',
                                                            option.accentBg,
                                                        )}
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        {/* Icon + label + radio */}
                                        <div className="flex items-center gap-2.5">
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
                                            <Icon
                                                className={cn(
                                                    'h-4 w-4',
                                                    isActive
                                                        ? 'text-violet-600'
                                                        : 'text-muted-foreground',
                                                )}
                                            />
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

                {/* --------------------------------------------------------- */}
                {/* Card 2: Accent Colour                                     */}
                {/* --------------------------------------------------------- */}
                <Card>
                    <CardHeader>
                        <CardTitle>Accent Colour</CardTitle>
                        <CardDescription>
                            Choose your preferred accent colour throughout the app
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-3 gap-5 sm:grid-cols-4">
                            {accentColours.map((colour) => {
                                const isActive = accentColour === colour.value;
                                return (
                                    <button
                                        key={colour.value}
                                        onClick={() => setAccentColour(colour.value)}
                                        className="group flex flex-col items-center gap-2"
                                    >
                                        <div
                                            className={cn(
                                                'relative flex h-12 w-12 items-center justify-center rounded-full transition-all',
                                                colour.bg,
                                                isActive && ['ring-2 ring-offset-2', colour.ring],
                                                !isActive && 'hover:scale-110',
                                            )}
                                        >
                                            {isActive && (
                                                <Check className="h-5 w-5 text-white drop-shadow" />
                                            )}
                                        </div>
                                        <span
                                            className={cn(
                                                'text-xs font-medium',
                                                isActive
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {colour.name}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* --------------------------------------------------------- */}
                {/* Card 3: Display                                           */}
                {/* --------------------------------------------------------- */}
                <Card>
                    <CardHeader>
                        <CardTitle>Display</CardTitle>
                        <CardDescription>Customise text size and visual density</CardDescription>
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
                                            onClick={() => setFontSize(opt.value)}
                                            className={cn(
                                                'flex flex-col items-center gap-2 rounded-lg border-2 px-4 py-4 transition-all',
                                                isActive
                                                    ? 'border-violet-600 bg-violet-50/60 dark:bg-violet-950/20'
                                                    : 'border-transparent bg-muted/30 hover:border-muted-foreground/20',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'font-semibold leading-none',
                                                    isActive
                                                        ? 'text-violet-700 dark:text-violet-400'
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
                            <Label className="text-sm font-medium">Sidebar Density</Label>
                            <div className="grid grid-cols-2 gap-3">
                                {(['comfortable', 'compact'] as const).map((density) => {
                                    const isActive = sidebarDensity === density;
                                    const gap = density === 'comfortable' ? 'gap-2' : 'gap-0.5';
                                    const pad = density === 'comfortable' ? 'p-2' : 'p-1.5';
                                    return (
                                        <button
                                            key={density}
                                            onClick={() => setSidebarDensity(density)}
                                            className={cn(
                                                'flex flex-col items-center gap-2 rounded-lg border-2 p-4 transition-all',
                                                isActive
                                                    ? 'border-violet-600 bg-violet-50/60 dark:bg-violet-950/20'
                                                    : 'border-transparent bg-muted/30 hover:border-muted-foreground/20',
                                            )}
                                        >
                                            {/* Mini sidebar mockup */}
                                            <div
                                                className={cn(
                                                    'flex w-20 flex-col rounded-md border bg-muted/40',
                                                    pad,
                                                    gap,
                                                )}
                                            >
                                                <div className="h-1.5 w-full rounded-sm bg-violet-400" />
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
                                <Label htmlFor="reduce-motion" className="text-sm font-medium">
                                    Reduce motion
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Disable animations for accessibility
                                </p>
                            </div>
                            <Switch
                                id="reduce-motion"
                                checked={reduceMotion}
                                onCheckedChange={setReduceMotion}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* --------------------------------------------------------- */}
                {/* Card 4: Regional                                          */}
                {/* --------------------------------------------------------- */}
                <Card>
                    <CardHeader>
                        <CardTitle>Regional</CardTitle>
                        <CardDescription>Date, time, and language preferences</CardDescription>
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
                            <div className="flex gap-2">
                                {(['12', '24'] as const).map((fmt) => (
                                    <button
                                        key={fmt}
                                        onClick={() => setTimeFormat(fmt)}
                                        className={cn(
                                            'rounded-md border px-4 py-2 text-sm font-medium transition-all',
                                            timeFormat === fmt
                                                ? 'border-violet-600 bg-violet-600 text-white shadow-sm'
                                                : 'border-input bg-transparent text-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {fmt}-hour
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* First day of week */}
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
                                                ? 'border-violet-600 bg-violet-600 text-white shadow-sm'
                                                : 'border-input bg-transparent text-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {opt.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Language */}
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

                {/* --------------------------------------------------------- */}
                {/* Card 5: Notifications & Sounds                            */}
                {/* --------------------------------------------------------- */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BellRing className="h-5 w-5 text-violet-600" />
                            Notifications & Sounds
                        </CardTitle>
                        <CardDescription>Control notification behaviour</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* Desktop notifications */}
                        <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                            <div className="flex items-center gap-3">
                                <Bell className="h-4 w-4 text-muted-foreground" />
                                <div className="space-y-0.5">
                                    <Label htmlFor="desktop-notifs" className="text-sm font-medium">
                                        Desktop notifications
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Allow browser notifications
                                    </p>
                                </div>
                            </div>
                            <Switch
                                id="desktop-notifs"
                                checked={desktopNotifications}
                                onCheckedChange={setDesktopNotifications}
                            />
                        </div>

                        {/* Notification sounds */}
                        <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                            <div className="flex items-center gap-3">
                                <Volume2 className="h-4 w-4 text-muted-foreground" />
                                <div className="space-y-0.5">
                                    <Label htmlFor="notif-sounds" className="text-sm font-medium">
                                        Notification sounds
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Play sounds for new notifications
                                    </p>
                                </div>
                            </div>
                            <Switch
                                id="notif-sounds"
                                checked={notificationSounds}
                                onCheckedChange={setNotificationSounds}
                            />
                        </div>

                        {/* Email digest */}
                        <div className="grid gap-2 pt-2">
                            <Label htmlFor="emailDigest">Email digest</Label>
                            <select
                                id="emailDigest"
                                value={emailDigest}
                                onChange={(e) => setEmailDigest(e.target.value)}
                                className="flex h-9 max-w-xs rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="instant">Instant</option>
                                <option value="daily">Daily Summary</option>
                                <option value="weekly">Weekly Summary</option>
                                <option value="off">Off</option>
                            </select>
                        </div>
                    </CardContent>
                </Card>

                {/* --------------------------------------------------------- */}
                {/* Card 6: Default Landing Page                              */}
                {/* --------------------------------------------------------- */}
                <Card>
                    <CardHeader>
                        <CardTitle>Default Landing Page</CardTitle>
                        <CardDescription>
                            Choose which page loads when you sign in
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-2">
                            {landingPages.map((page) => {
                                const isActive = landingPage === page.value;
                                const Icon = page.icon;
                                return (
                                    <button
                                        key={page.value}
                                        onClick={() => setLandingPage(page.value)}
                                        className={cn(
                                            'flex items-center gap-3 rounded-lg border px-4 py-3 text-left transition-all',
                                            isActive
                                                ? 'border-violet-600 bg-violet-50/60 dark:bg-violet-950/20'
                                                : 'border-transparent bg-muted/20 hover:bg-muted/40',
                                        )}
                                    >
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
                                        <Icon
                                            className={cn(
                                                'h-4 w-4',
                                                isActive
                                                    ? 'text-violet-600'
                                                    : 'text-muted-foreground',
                                            )}
                                        />
                                        <span
                                            className={cn(
                                                'text-sm font-medium',
                                                isActive
                                                    ? 'text-violet-700 dark:text-violet-400'
                                                    : 'text-foreground',
                                            )}
                                        >
                                            {page.label}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* --------------------------------------------------------- */}
                {/* Save                                                      */}
                {/* --------------------------------------------------------- */}
                <div className="flex items-center gap-4">
                    <Button
                        onClick={handleSave}
                        className="bg-violet-600 hover:bg-violet-700"
                    >
                        Save preferences
                    </Button>
                    {saved && (
                        <span className="flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                            <Check className="h-4 w-4" />
                            Preferences saved
                        </span>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
