import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, CircleHelp, Moon, Sun, Upload } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';

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
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Branding', href: '/settings/branding' },
];

function isHexColor(value: string) {
    return /^#[0-9a-fA-F]{6}$/.test(value);
}

function applyVar(name: string, value: string) {
    if (!name.startsWith('--')) name = `--${name}`;
    document.documentElement.style.setProperty(name, value);
}

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
                    title="Pick a hex color"
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
        <div className="space-y-2">
            <Label className="text-sm font-medium">{label}</Label>
            <div className="flex items-center gap-3">
                <div className="relative">
                    <div
                        className="h-10 w-10 rounded-full border-2 border-border shadow-sm"
                        style={{ backgroundColor: showColorPicker ? value : '#cbd5e1' }}
                    />
                    <input
                        type="color"
                        className="absolute inset-0 h-10 w-10 cursor-pointer opacity-0"
                        value={showColorPicker ? value : '#000000'}
                        onChange={(e) => handle(e.target.value)}
                    />
                </div>
                <Input
                    value={value}
                    onChange={(e) => handle(e.target.value)}
                    placeholder="#4f46e5"
                    className="max-w-[160px] font-mono text-sm"
                />
            </div>
            <code className="text-xs text-muted-foreground">{cssVar}</code>
        </div>
    );
}

export default function BrandingPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [previewDark, setPreviewDark] = useState(
        document.documentElement.classList.contains('dark'),
    );
    const [dragOver, setDragOver] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const initialTheme = useMemo(
        () => ({
            light: props.theme?.light ?? {},
            dark: props.theme?.dark ?? {},
        }),
        [props.theme],
    );

    const form = useForm<{
        branding: { name: string; tagline: string; report_subtitle: string };
        theme: { light: Record<string, string>; dark: Record<string, string> };
        logo: File | null;
        remove_logo: boolean;
    }>({
        branding: {
            name: (props.branding?.name ?? '').toString(),
            tagline: (props.branding?.tagline ?? '').toString(),
            report_subtitle: (props.branding?.report_subtitle ?? '').toString(),
        },
        theme: {
            light: { ...initialTheme.light },
            dark: { ...initialTheme.dark },
        },
        logo: null,
        remove_logo: false,
    });

    const handleFile = useCallback(
        (file: File | null) => {
            if (file && file.size > 2 * 1024 * 1024) return;
            form.setData('logo', file);
        },
        [form],
    );

    if (!can?.settings?.manageBranding) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Branding" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don't have permission to manage branding.
                </div>
            </SettingsLayout>
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Branding" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Branding"
                        description="Customise your organisation's identity, colours, and logo."
                    />

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/settings/branding', {
                                forceFormData: true,
                            });
                        }}
                        className="space-y-6"
                    >
                        {/* Company Logo */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Company Logo</CardTitle>
                                <CardDescription>
                                    Upload your company logo for PDF reports and the sidebar. PNG or JPG, max 2MB.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-start gap-6">
                                    {/* Current logo preview */}
                                    <div className="flex h-20 w-20 flex-shrink-0 items-center justify-center rounded-lg border-2 border-dashed border-border bg-muted/50">
                                        {props.branding?.logoUrl && !form.data.remove_logo ? (
                                            <img
                                                src={props.branding.logoUrl}
                                                alt="Current logo"
                                                className="h-full w-full rounded-lg object-contain p-1"
                                            />
                                        ) : (
                                            <CircleHelp className="h-8 w-8 text-muted-foreground/50" />
                                        )}
                                    </div>

                                    {/* Upload zone */}
                                    <div className="flex-1">
                                        <div
                                            className={`relative flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-8 transition-colors ${
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
                                            <Upload className="mb-2 h-8 w-8 text-muted-foreground" />
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
                                            <div className="mt-3 flex items-center gap-2">
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
                            </CardContent>
                        </Card>

                        {/* Company Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Company Details</CardTitle>
                                <CardDescription>
                                    Customise the company name and tagline shown in the sidebar and PDF reports.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
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
                                        placeholder="e.g. CodeBlue 365"
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
                            </CardContent>
                        </Card>

                        {/* Brand Colors */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle>Brand Colours</CardTitle>
                                        <CardDescription>
                                            Set your primary brand colours. These are applied across the application.
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
                                {/* Primary color swatches - 3 column grid */}
                                <div className="grid gap-6 sm:grid-cols-3">
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

                                {/* Advanced Colors toggle */}
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

                        {/* Save / Reset */}
                        <div className="flex items-center gap-2">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="bg-violet-600 hover:bg-violet-700"
                            >
                                Save Changes
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    form.setData('theme', { light: {}, dark: {} });
                                    form.setData('branding', { name: '', tagline: '', report_subtitle: '' });
                                    form.setData('logo', null);
                                    form.setData('remove_logo', false);
                                    props.allowedVars.forEach((v) => applyVar(v, ''));
                                }}
                            >
                                Reset
                            </Button>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
