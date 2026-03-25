import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    CircleHelp,
    Image as ImageIcon,
    Moon,
    Palette,
    RotateCcw,
    Sun,
    Upload,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Props = {
    allowedVars: string[];
    theme: {
        light: Record<string, string>;
        dark: Record<string, string>;
    };
    branding: {
        name: string | null;
        tagline: string | null;
        report_subtitle: string | null;
        logoUrl: string | null;
        faviconUrl: string | null;
        email_header_colour: string | null;
        email_footer_text: string | null;
        report_logo_position: string | null;
        report_font: string | null;
        report_include_company_details: boolean;
    };
    terminology: {
        defaults: Record<string, string>;
        overrides: Record<string, string | null>;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Branding', href: '/settings/branding' },
];

/* ------------------------------------------------------------------ */
/*  Terminology constants                                              */
/* ------------------------------------------------------------------ */

const TERM_ROWS = [
    { label: 'Client', singularKey: 'client.singular', pluralKey: 'client.plural' },
    { label: 'Site', singularKey: 'site.singular', pluralKey: 'site.plural' },
    { label: 'Asset', singularKey: 'asset.singular', pluralKey: 'asset.plural' },
    { label: 'Staff Member', singularKey: 'staff.singular', pluralKey: 'staff.plural' },
    { label: 'Support Worker', singularKey: 'worker.singular', pluralKey: 'worker.plural' },
    { label: 'Shift', singularKey: 'shift.singular', pluralKey: 'shift.plural' },
    { label: 'Timesheet', singularKey: 'timesheet.singular', pluralKey: 'timesheet.plural' },
    { label: 'Medication', singularKey: 'medication.singular', pluralKey: 'medication.plural' },
    { label: 'Incident', singularKey: 'incident.singular', pluralKey: 'incident.plural' },
    { label: 'Risk', singularKey: 'risk.singular', pluralKey: 'risk.plural' },
    { label: 'Note', singularKey: 'note.singular', pluralKey: 'note.plural' },
    { label: 'Document', singularKey: 'document.singular', pluralKey: 'document.plural' },
    { label: 'Report', singularKey: 'report.singular', pluralKey: 'report.plural' },
];

const ALL_KEYS = TERM_ROWS.flatMap((r) => [r.singularKey, r.pluralKey]);

const PRESETS: Record<string, Record<string, string>> = {
    'Disability Support': {
        'client.singular': 'Client', 'client.plural': 'Clients',
        'staff.singular': 'Staff Member', 'staff.plural': 'Staff Members',
        'worker.singular': 'Support Worker', 'worker.plural': 'Support Workers',
        'site.singular': 'Site', 'site.plural': 'Sites',
        'asset.singular': 'Asset', 'asset.plural': 'Assets',
        'shift.singular': 'Shift', 'shift.plural': 'Shifts',
        'timesheet.singular': 'Timesheet', 'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication', 'medication.plural': 'Medications',
        'incident.singular': 'Incident', 'incident.plural': 'Incidents',
        'risk.singular': 'Risk', 'risk.plural': 'Risks',
        'note.singular': 'Note', 'note.plural': 'Notes',
        'document.singular': 'Document', 'document.plural': 'Documents',
        'report.singular': 'Report', 'report.plural': 'Reports',
    },
    'Aged Care': {
        'client.singular': 'Resident', 'client.plural': 'Residents',
        'staff.singular': 'Carer', 'staff.plural': 'Carers',
        'worker.singular': 'Care Worker', 'worker.plural': 'Care Workers',
        'site.singular': 'Facility', 'site.plural': 'Facilities',
        'asset.singular': 'Asset', 'asset.plural': 'Assets',
        'shift.singular': 'Shift', 'shift.plural': 'Shifts',
        'timesheet.singular': 'Timesheet', 'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication', 'medication.plural': 'Medications',
        'incident.singular': 'Incident', 'incident.plural': 'Incidents',
        'risk.singular': 'Risk', 'risk.plural': 'Risks',
        'note.singular': 'Note', 'note.plural': 'Notes',
        'document.singular': 'Document', 'document.plural': 'Documents',
        'report.singular': 'Report', 'report.plural': 'Reports',
    },
    'Mental Health': {
        'client.singular': 'Consumer', 'client.plural': 'Consumers',
        'staff.singular': 'Clinician', 'staff.plural': 'Clinicians',
        'worker.singular': 'Peer Support Worker', 'worker.plural': 'Peer Support Workers',
        'site.singular': 'Service', 'site.plural': 'Services',
        'asset.singular': 'Asset', 'asset.plural': 'Assets',
        'shift.singular': 'Session', 'shift.plural': 'Sessions',
        'timesheet.singular': 'Timesheet', 'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication', 'medication.plural': 'Medications',
        'incident.singular': 'Incident', 'incident.plural': 'Incidents',
        'risk.singular': 'Risk', 'risk.plural': 'Risks',
        'note.singular': 'Note', 'note.plural': 'Notes',
        'document.singular': 'Document', 'document.plural': 'Documents',
        'report.singular': 'Report', 'report.plural': 'Reports',
    },
    'Generic': {
        'client.singular': 'Customer', 'client.plural': 'Customers',
        'staff.singular': 'Employee', 'staff.plural': 'Employees',
        'worker.singular': 'Worker', 'worker.plural': 'Workers',
        'site.singular': 'Location', 'site.plural': 'Locations',
        'asset.singular': 'Asset', 'asset.plural': 'Assets',
        'shift.singular': 'Shift', 'shift.plural': 'Shifts',
        'timesheet.singular': 'Timesheet', 'timesheet.plural': 'Timesheets',
        'medication.singular': 'Medication', 'medication.plural': 'Medications',
        'incident.singular': 'Incident', 'incident.plural': 'Incidents',
        'risk.singular': 'Risk', 'risk.plural': 'Risks',
        'note.singular': 'Note', 'note.plural': 'Notes',
        'document.singular': 'Document', 'document.plural': 'Documents',
        'report.singular': 'Report', 'report.plural': 'Reports',
    },
};

/* ------------------------------------------------------------------ */
/*  Theme presets                                                      */
/* ------------------------------------------------------------------ */

const THEME_PRESETS: {
    name: string;
    primary: string;
    primaryFg: string;
    accent: string;
}[] = [
    { name: 'Professional Violet', primary: '#7c3aed', primaryFg: '#ffffff', accent: '#8b5cf6' },
    { name: 'Ocean Blue', primary: '#2563eb', primaryFg: '#ffffff', accent: '#3b82f6' },
    { name: 'Forest Green', primary: '#059669', primaryFg: '#ffffff', accent: '#10b981' },
    { name: 'Warm Rose', primary: '#f43f5e', primaryFg: '#ffffff', accent: '#fb7185' },
];

/* ------------------------------------------------------------------ */
/*  Helper functions                                                   */
/* ------------------------------------------------------------------ */

function isHexColor(value: string) {
    return /^#[0-9a-fA-F]{6}$/.test(value);
}

function applyVar(name: string, value: string) {
    if (!name.startsWith('--')) name = `--${name}`;
    document.documentElement.style.setProperty(name, value);
}

/* ------------------------------------------------------------------ */
/*  Sub-components                                                     */
/* ------------------------------------------------------------------ */

function VarRow({
    cssVar,
    value,
    onChange,
    mode,
}: {
    cssVar: string;
    value: string;
    onChange: (next: string) => void;
    mode: 'light' | 'dark';
}) {
    const showColorPicker = isHexColor(value);

    function handle(next: string) {
        onChange(next);
        if (mode === 'light') {
            applyVar(cssVar, next);
        } else {
            if (document.documentElement.classList.contains('dark')) {
                applyVar(cssVar, next);
            }
        }
    }

    return (
        <div className="grid grid-cols-1 gap-2 md:grid-cols-12 md:items-center">
            <div className="text-sm font-medium md:col-span-5">
                <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                    {cssVar}
                </code>
            </div>
            <div className="flex items-center gap-2 md:col-span-7">
                <Input
                    value={value}
                    onChange={(e) => handle(e.target.value)}
                    placeholder="e.g. #4f46e5 or oklch(0.62 0.18 250)"
                />
                <Input
                    type="color"
                    className="h-9 w-12 p-1"
                    value={showColorPicker ? value : '#000000'}
                    onChange={(e) => handle(e.target.value)}
                    title="Pick a hex colour"
                />
            </div>
        </div>
    );
}

function ColorSwatch({
    label,
    cssVar,
    value,
    onChange,
    mode,
}: {
    label: string;
    cssVar: string;
    value: string;
    onChange: (next: string) => void;
    mode: 'light' | 'dark';
}) {
    const showColorPicker = isHexColor(value);

    function handle(next: string) {
        onChange(next);
        if (mode === 'light') {
            applyVar(cssVar, next);
        } else if (document.documentElement.classList.contains('dark')) {
            applyVar(cssVar, next);
        }
    }

    return (
        <div className="flex flex-col items-center gap-3">
            <div className="relative">
                <div
                    className="h-12 w-12 rounded-full border-2 border-border shadow-sm transition-shadow hover:shadow-md"
                    style={{ backgroundColor: showColorPicker ? value : '#cbd5e1' }}
                />
                <input
                    type="color"
                    className="absolute inset-0 h-12 w-12 cursor-pointer rounded-full opacity-0"
                    value={showColorPicker ? value : '#000000'}
                    onChange={(e) => handle(e.target.value)}
                />
            </div>
            <Label className="text-sm font-medium">{label}</Label>
            <Input
                value={value}
                onChange={(e) => handle(e.target.value)}
                placeholder="#4f46e5"
                className="max-w-[140px] text-center font-mono text-xs"
            />
            <code className="text-[10px] text-muted-foreground">{cssVar}</code>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Main page                                                          */
/* ------------------------------------------------------------------ */

export default function BrandingPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [previewDark, setPreviewDark] = useState(
        document.documentElement.classList.contains('dark'),
    );
    const [dragOver, setDragOver] = useState(false);
    const [faviconDragOver, setFaviconDragOver] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const faviconInputRef = useRef<HTMLInputElement>(null);

    const initialTheme = useMemo(
        () => ({
            light: props.theme?.light ?? {},
            dark: props.theme?.dark ?? {},
        }),
        [props.theme],
    );

    /* --- Branding form (posts to /settings/branding) --- */
    const form = useForm<{
        branding: {
            name: string;
            tagline: string;
            report_subtitle: string;
            email_header_colour: string;
            email_footer_text: string;
            report_logo_position: string;
            report_font: string;
            report_include_company_details: boolean;
        };
        theme: { light: Record<string, string>; dark: Record<string, string> };
        logo: File | null;
        remove_logo: boolean;
        favicon: File | null;
        remove_favicon: boolean;
    }>({
        branding: {
            name: (props.branding?.name ?? '').toString(),
            tagline: (props.branding?.tagline ?? '').toString(),
            report_subtitle: (props.branding?.report_subtitle ?? '').toString(),
            email_header_colour: (props.branding?.email_header_colour ?? '').toString(),
            email_footer_text: (props.branding?.email_footer_text ?? '').toString(),
            report_logo_position: (props.branding?.report_logo_position ?? 'left').toString(),
            report_font: (props.branding?.report_font ?? 'default').toString(),
            report_include_company_details: props.branding?.report_include_company_details ?? true,
        },
        theme: {
            light: { ...initialTheme.light },
            dark: { ...initialTheme.dark },
        },
        logo: null,
        remove_logo: false,
        favicon: null,
        remove_favicon: false,
    });

    /* --- Terminology form (PUT to /settings/terminology) --- */
    const terminologyInitial = useMemo(() => {
        return Object.fromEntries(
            ALL_KEYS.map((k) => [
                k,
                (props.terminology?.overrides?.[k] ?? props.terminology?.defaults?.[k] ?? '').toString(),
            ]),
        );
    }, [props.terminology]);

    const termForm = useForm<{ labels: Record<string, string> }>({
        labels: terminologyInitial,
    });

    const handleFile = useCallback(
        (file: File | null) => {
            if (file && file.size > 2 * 1024 * 1024) return;
            form.setData('logo', file);
        },
        [form],
    );

    const handleFavicon = useCallback(
        (file: File | null) => {
            if (file && file.size > 512 * 1024) return;
            form.setData('favicon', file);
        },
        [form],
    );

    if (!can?.settings?.manageBranding) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Branding" />
                <SettingsLayout>
                    <HeadingSmall title="Branding" description="" />
                    <div className="rounded-md border p-4 text-sm">
                        You don't have permission to manage branding.
                    </div>
                </SettingsLayout>
            </AppLayout>
        );
    }

    const primaryVars = [
        { label: 'Primary', cssVar: '--primary' },
        { label: 'Primary Foreground', cssVar: '--primary-foreground' },
        { label: 'Accent', cssVar: '--accent' },
    ];

    const remainingVars = props.allowedVars.filter(
        (v) => !['--primary', '--primary-foreground', '--accent'].includes(v),
    );

    const currentMode = previewDark ? 'dark' : 'light';

    const applyPreset = (presetName: string) => {
        const preset = PRESETS[presetName];
        if (!preset) return;
        termForm.setData('labels', { ...termForm.data.labels, ...preset });
    };

    const applyThemePreset = (preset: typeof THEME_PRESETS[0]) => {
        const updated = {
            ...form.data.theme,
            [currentMode]: {
                ...form.data.theme[currentMode],
                '--primary': preset.primary,
                '--primary-foreground': preset.primaryFg,
                '--accent': preset.accent,
            },
        };
        form.setData('theme', updated);
        applyVar('--primary', preset.primary);
        applyVar('--primary-foreground', preset.primaryFg);
        applyVar('--accent', preset.accent);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Branding" />
            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall
                        title="Branding & Customisation"
                        description="Manage your organisation's identity, terminology, colours, and document branding from one place."
                    />

                    {/* ============================================================ */}
                    {/*  BRANDING FORM — posts to /settings/branding                  */}
                    {/* ============================================================ */}
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/settings/branding', {
                                forceFormData: true,
                            });
                        }}
                        className="space-y-8"
                    >
                        {/* -------------------------------------------------------- */}
                        {/*  Section 1: Company Identity                              */}
                        {/* -------------------------------------------------------- */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <ImageIcon className="h-5 w-5 text-violet-600" />
                                    Company Identity
                                </CardTitle>
                                <CardDescription>
                                    Your organisation's name, logo, and tagline
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-6 lg:grid-cols-2">
                                    {/* Left column — text inputs */}
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="branding_name">Company Name</Label>
                                            <Input
                                                id="branding_name"
                                                value={form.data.branding.name}
                                                onChange={(e) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        name: e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. My Organisation"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="branding_tagline">Tagline</Label>
                                            <Input
                                                id="branding_tagline"
                                                value={form.data.branding.tagline}
                                                onChange={(e) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        tagline: e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. Supported Living Provider"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Displayed under the company name in the sidebar.
                                            </p>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="branding_report_subtitle">Report Subtitle</Label>
                                            <Input
                                                id="branding_report_subtitle"
                                                value={form.data.branding.report_subtitle}
                                                onChange={(e) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        report_subtitle: e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. Managed Services Platform"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Default subtitle used in exported PDF reports.
                                            </p>
                                        </div>
                                    </div>

                                    {/* Right column — logo upload */}
                                    <div className="space-y-4">
                                        <Label>Logo</Label>
                                        <div
                                            className={`relative flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-10 transition-colors ${
                                                dragOver
                                                    ? 'border-violet-500 bg-violet-50 dark:bg-violet-500/10'
                                                    : 'border-border hover:border-violet-400 hover:bg-muted/50'
                                            }`}
                                            onClick={() => fileInputRef.current?.click()}
                                            onDragOver={(e) => {
                                                e.preventDefault();
                                                setDragOver(true);
                                            }}
                                            onDragLeave={() => setDragOver(false)}
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                setDragOver(false);
                                                const file = e.dataTransfer.files?.[0] ?? null;
                                                handleFile(file);
                                            }}
                                        >
                                            {props.branding?.logoUrl && !form.data.remove_logo ? (
                                                <img
                                                    src={props.branding.logoUrl}
                                                    alt="Current logo"
                                                    className="mb-3 h-16 w-auto object-contain"
                                                />
                                            ) : (
                                                <div className="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                                                    <Upload className="h-6 w-6 text-muted-foreground" />
                                                </div>
                                            )}
                                            <p className="text-sm font-medium">Click to upload or drag and drop</p>
                                            <p className="mt-1 text-xs text-muted-foreground">PNG, JPG up to 2MB</p>
                                            {form.data.logo && (
                                                <p className="mt-2 text-xs font-medium text-violet-600">
                                                    Selected: {form.data.logo.name}
                                                </p>
                                            )}
                                            <input
                                                ref={fileInputRef}
                                                type="file"
                                                accept="image/png,image/jpeg,image/jpg"
                                                className="hidden"
                                                onChange={(e) => {
                                                    const file = e.target.files?.[0] ?? null;
                                                    handleFile(file);
                                                }}
                                            />
                                        </div>

                                        {props.branding?.logoUrl && (
                                            <div className="flex items-center gap-2">
                                                <Checkbox
                                                    id="remove_logo"
                                                    checked={form.data.remove_logo}
                                                    onCheckedChange={(v) =>
                                                        form.setData('remove_logo', Boolean(v))
                                                    }
                                                />
                                                <Label htmlFor="remove_logo" className="text-sm text-muted-foreground">
                                                    Remove current logo
                                                </Label>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Favicon upload — smaller, below the two columns */}
                                <Separator />
                                <div className="space-y-3">
                                    <Label>Favicon</Label>
                                    <div className="flex items-center gap-4">
                                        <div
                                            className={`relative flex h-16 w-16 cursor-pointer items-center justify-center rounded-lg border-2 border-dashed transition-colors ${
                                                faviconDragOver
                                                    ? 'border-violet-500 bg-violet-50 dark:bg-violet-500/10'
                                                    : 'border-border hover:border-violet-400'
                                            }`}
                                            onClick={() => faviconInputRef.current?.click()}
                                            onDragOver={(e) => {
                                                e.preventDefault();
                                                setFaviconDragOver(true);
                                            }}
                                            onDragLeave={() => setFaviconDragOver(false)}
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                setFaviconDragOver(false);
                                                const file = e.dataTransfer.files?.[0] ?? null;
                                                handleFavicon(file);
                                            }}
                                        >
                                            {props.branding?.faviconUrl && !form.data.remove_favicon ? (
                                                <img
                                                    src={props.branding.faviconUrl}
                                                    alt="Favicon"
                                                    className="h-8 w-8 object-contain"
                                                />
                                            ) : (
                                                <CircleHelp className="h-5 w-5 text-muted-foreground/50" />
                                            )}
                                            <input
                                                ref={faviconInputRef}
                                                type="file"
                                                accept="image/png,image/x-icon,image/svg+xml"
                                                className="hidden"
                                                onChange={(e) => {
                                                    const file = e.target.files?.[0] ?? null;
                                                    handleFavicon(file);
                                                }}
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <p className="text-sm text-muted-foreground">
                                                32x32px recommended. PNG, ICO, or SVG.
                                            </p>
                                            {form.data.favicon && (
                                                <p className="text-xs font-medium text-violet-600">
                                                    Selected: {form.data.favicon.name}
                                                </p>
                                            )}
                                            {props.branding?.faviconUrl && (
                                                <button
                                                    type="button"
                                                    className="text-xs text-destructive hover:underline"
                                                    onClick={() => form.setData('remove_favicon', true)}
                                                >
                                                    Remove favicon
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* -------------------------------------------------------- */}
                        {/*  Section 3: Brand Colours                                 */}
                        {/* -------------------------------------------------------- */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <Palette className="h-5 w-5 text-violet-600" />
                                            Brand Colours
                                        </CardTitle>
                                        <CardDescription>
                                            Set your primary brand colours applied across the application
                                        </CardDescription>
                                    </div>
                                    <button
                                        type="button"
                                        className="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition-colors hover:bg-muted"
                                        onClick={() => {
                                            const on = !previewDark;
                                            setPreviewDark(on);
                                            document.documentElement.classList.toggle('dark', on);
                                            if (on) {
                                                Object.entries(form.data.theme.dark ?? {}).forEach(([k, val]) => {
                                                    applyVar(k, val);
                                                });
                                            } else {
                                                Object.entries(form.data.theme.light ?? {}).forEach(([k, val]) => {
                                                    applyVar(k, val);
                                                });
                                            }
                                        }}
                                    >
                                        {previewDark ? (
                                            <Moon className="h-4 w-4" />
                                        ) : (
                                            <Sun className="h-4 w-4" />
                                        )}
                                        {previewDark ? 'Dark' : 'Light'}
                                    </button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {/* Primary colour swatches */}
                                <div className="flex flex-wrap justify-center gap-8 py-2 sm:justify-start">
                                    {primaryVars.map(({ label, cssVar }) => (
                                        <ColorSwatch
                                            key={`${currentMode}-${cssVar}`}
                                            label={label}
                                            cssVar={cssVar}
                                            mode={currentMode}
                                            value={form.data.theme[currentMode][cssVar] ?? ''}
                                            onChange={(next) =>
                                                form.setData('theme', {
                                                    ...form.data.theme,
                                                    [currentMode]: {
                                                        ...form.data.theme[currentMode],
                                                        [cssVar]: next,
                                                    },
                                                })
                                            }
                                        />
                                    ))}
                                </div>

                                <Separator />

                                {/* Theme presets */}
                                <div className="space-y-3">
                                    <p className="text-sm font-medium text-muted-foreground">Theme Presets</p>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                        {THEME_PRESETS.map((preset) => (
                                            <button
                                                key={preset.name}
                                                type="button"
                                                onClick={() => applyThemePreset(preset)}
                                                className="flex flex-col items-center gap-2 rounded-lg border p-3 transition-all hover:border-violet-400 hover:shadow-sm"
                                            >
                                                <div className="flex gap-1">
                                                    <div
                                                        className="h-5 w-5 rounded-full border"
                                                        style={{ backgroundColor: preset.primary }}
                                                    />
                                                    <div
                                                        className="h-5 w-5 rounded-full border"
                                                        style={{ backgroundColor: preset.primaryFg }}
                                                    />
                                                    <div
                                                        className="h-5 w-5 rounded-full border"
                                                        style={{ backgroundColor: preset.accent }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium">{preset.name}</span>
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <Separator />

                                {/* Advanced Colours toggle */}
                                <div className="space-y-3">
                                    <button
                                        type="button"
                                        className="flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                        onClick={() => setAdvancedOpen((s) => !s)}
                                    >
                                        {advancedOpen ? (
                                            <ChevronDown className="h-4 w-4" />
                                        ) : (
                                            <ChevronRight className="h-4 w-4" />
                                        )}
                                        Advanced Colours ({remainingVars.length} variables)
                                    </button>

                                    {advancedOpen && (
                                        <div className="space-y-6 rounded-lg border bg-muted/30 p-4">
                                            <div className="space-y-3">
                                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                    {previewDark ? 'Dark' : 'Light'} mode variables
                                                </div>
                                                <div className="space-y-3">
                                                    {remainingVars.map((cssVar) => (
                                                        <VarRow
                                                            key={`${currentMode}-adv-${cssVar}`}
                                                            mode={currentMode}
                                                            cssVar={cssVar}
                                                            value={form.data.theme[currentMode][cssVar] ?? ''}
                                                            onChange={(next) =>
                                                                form.setData('theme', {
                                                                    ...form.data.theme,
                                                                    [currentMode]: {
                                                                        ...form.data.theme[currentMode],
                                                                        [cssVar]: next,
                                                                    },
                                                                })
                                                            }
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* -------------------------------------------------------- */}
                        {/*  Section 4: Email & Report Branding                       */}
                        {/* -------------------------------------------------------- */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Email & Report Branding</CardTitle>
                                <CardDescription>
                                    Customise how your organisation appears in emails and exported documents
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-6 lg:grid-cols-2">
                                    {/* Email settings */}
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="email_header_colour">Email Header Colour</Label>
                                            <div className="flex items-center gap-3">
                                                <div className="relative">
                                                    <div
                                                        className="h-10 w-10 rounded-lg border-2 border-border shadow-sm"
                                                        style={{
                                                            backgroundColor: isHexColor(form.data.branding.email_header_colour)
                                                                ? form.data.branding.email_header_colour
                                                                : '#7c3aed',
                                                        }}
                                                    />
                                                    <input
                                                        type="color"
                                                        className="absolute inset-0 h-10 w-10 cursor-pointer opacity-0"
                                                        value={
                                                            isHexColor(form.data.branding.email_header_colour)
                                                                ? form.data.branding.email_header_colour
                                                                : '#7c3aed'
                                                        }
                                                        onChange={(e) =>
                                                            form.setData('branding', {
                                                                ...form.data.branding,
                                                                email_header_colour: e.target.value,
                                                            })
                                                        }
                                                    />
                                                </div>
                                                <Input
                                                    id="email_header_colour"
                                                    value={form.data.branding.email_header_colour}
                                                    onChange={(e) =>
                                                        form.setData('branding', {
                                                            ...form.data.branding,
                                                            email_header_colour: e.target.value,
                                                        })
                                                    }
                                                    placeholder="#7c3aed"
                                                    className="max-w-[160px] font-mono text-sm"
                                                />
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="email_footer_text">Email Footer Text</Label>
                                            <Textarea
                                                id="email_footer_text"
                                                value={form.data.branding.email_footer_text}
                                                onChange={(e) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        email_footer_text: e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. My Organisation Ltd. | 123 Main Street, Auckland"
                                                rows={3}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Shown at the bottom of all outgoing emails.
                                            </p>
                                        </div>
                                    </div>

                                    {/* Report settings */}
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label>Report Header Logo Position</Label>
                                            <RadioGroup
                                                value={form.data.branding.report_logo_position}
                                                onValueChange={(v) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        report_logo_position: v,
                                                    })
                                                }
                                                className="flex gap-4"
                                            >
                                                {['left', 'centre', 'right'].map((pos) => (
                                                    <div key={pos} className="flex items-center gap-2">
                                                        <RadioGroupItem value={pos} id={`logo_pos_${pos}`} />
                                                        <Label htmlFor={`logo_pos_${pos}`} className="text-sm capitalize">
                                                            {pos}
                                                        </Label>
                                                    </div>
                                                ))}
                                            </RadioGroup>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Report Font</Label>
                                            <RadioGroup
                                                value={form.data.branding.report_font}
                                                onValueChange={(v) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        report_font: v,
                                                    })
                                                }
                                                className="flex gap-4"
                                            >
                                                {[
                                                    { value: 'default', label: 'Default' },
                                                    { value: 'serif', label: 'Serif' },
                                                    { value: 'sans-serif', label: 'Sans-serif' },
                                                ].map((opt) => (
                                                    <div key={opt.value} className="flex items-center gap-2">
                                                        <RadioGroupItem value={opt.value} id={`font_${opt.value}`} />
                                                        <Label htmlFor={`font_${opt.value}`} className="text-sm">
                                                            {opt.label}
                                                        </Label>
                                                    </div>
                                                ))}
                                            </RadioGroup>
                                        </div>
                                        <div className="flex items-center gap-3 pt-2">
                                            <Switch
                                                id="report_company_details"
                                                checked={form.data.branding.report_include_company_details}
                                                onCheckedChange={(v) =>
                                                    form.setData('branding', {
                                                        ...form.data.branding,
                                                        report_include_company_details: v,
                                                    })
                                                }
                                            />
                                            <Label htmlFor="report_company_details" className="text-sm">
                                                Include company details in report footer
                                            </Label>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Save branding */}
                        <div className="flex items-center gap-2">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="bg-violet-600 hover:bg-violet-700"
                            >
                                Save Branding
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    form.setData('theme', { light: {}, dark: {} });
                                    form.setData('branding', {
                                        name: '',
                                        tagline: '',
                                        report_subtitle: '',
                                        email_header_colour: '',
                                        email_footer_text: '',
                                        report_logo_position: 'left',
                                        report_font: 'default',
                                        report_include_company_details: true,
                                    });
                                    form.setData('logo', null);
                                    form.setData('remove_logo', false);
                                    form.setData('favicon', null);
                                    form.setData('remove_favicon', false);
                                    props.allowedVars.forEach((v) => applyVar(v, ''));
                                }}
                            >
                                Reset
                            </Button>
                        </div>
                    </form>

                    {/* ============================================================ */}
                    {/*  TERMINOLOGY FORM — PUT to /settings/terminology               */}
                    {/* ============================================================ */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Terminology</CardTitle>
                            <CardDescription>
                                Customise the language used across the application to match your organisation
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    termForm.put('/settings/terminology');
                                }}
                                className="space-y-6"
                            >
                                {/* Preset buttons */}
                                <div className="space-y-2">
                                    <p className="text-sm font-medium text-muted-foreground">Template presets</p>
                                    <div className="flex flex-wrap gap-2">
                                        {Object.keys(PRESETS).map((name) => (
                                            <Button
                                                key={name}
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => applyPreset(name)}
                                                className="hover:border-violet-400 hover:text-violet-600"
                                            >
                                                {name}
                                                {name === 'Disability Support' && (
                                                    <Badge variant="secondary" className="ml-1.5 text-[10px]">
                                                        Default
                                                    </Badge>
                                                )}
                                            </Button>
                                        ))}
                                    </div>
                                </div>

                                {/* Terminology table */}
                                <div className="rounded-lg border">
                                    <div className="grid grid-cols-4 gap-4 border-b bg-muted/50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        <div>Label</div>
                                        <div>Singular</div>
                                        <div>Plural</div>
                                        <div>Default</div>
                                    </div>
                                    <div className="divide-y">
                                        {TERM_ROWS.map((row) => (
                                            <div
                                                key={row.label}
                                                className="grid grid-cols-4 items-center gap-4 px-4 py-3"
                                            >
                                                <div className="text-sm font-medium">{row.label}</div>
                                                <div>
                                                    <Input
                                                        value={termForm.data.labels[row.singularKey] ?? ''}
                                                        placeholder={props.terminology?.defaults?.[row.singularKey] ?? ''}
                                                        onChange={(e) =>
                                                            termForm.setData('labels', {
                                                                ...termForm.data.labels,
                                                                [row.singularKey]: e.target.value,
                                                            })
                                                        }
                                                        className="h-8 text-sm"
                                                    />
                                                </div>
                                                <div>
                                                    <Input
                                                        value={termForm.data.labels[row.pluralKey] ?? ''}
                                                        placeholder={props.terminology?.defaults?.[row.pluralKey] ?? ''}
                                                        onChange={(e) =>
                                                            termForm.setData('labels', {
                                                                ...termForm.data.labels,
                                                                [row.pluralKey]: e.target.value,
                                                            })
                                                        }
                                                        className="h-8 text-sm"
                                                    />
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {props.terminology?.defaults?.[row.singularKey] ?? ''} / {props.terminology?.defaults?.[row.pluralKey] ?? ''}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="flex items-center justify-between">
                                    <button
                                        type="button"
                                        className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                                        onClick={() => {
                                            const reset = Object.fromEntries(
                                                ALL_KEYS.map((k) => [k, '']),
                                            );
                                            termForm.setData('labels', reset);
                                        }}
                                    >
                                        <RotateCcw className="h-3 w-3" />
                                        Reset all
                                    </button>
                                    <Button
                                        type="submit"
                                        disabled={termForm.processing}
                                        className="bg-violet-600 hover:bg-violet-700"
                                    >
                                        Save Terminology
                                    </Button>
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    Changes will be reflected across all modules immediately.
                                </p>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
