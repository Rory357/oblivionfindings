import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    allowedVars: string[];
    theme: {
        light: Record<string, string>;
        dark: Record<string, string>;
    };
    branding: {
        name: string | null;
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

        // Live preview:
        // - Light mode: always apply to :root
        // - Dark mode: only apply if .dark is currently enabled
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
                    placeholder="e.g. #4f46e5 or 1 0 0 or oklch(0.62 0.18 250)"
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

export default function BrandingPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [advancedOpen, setAdvancedOpen] = useState(false);

    // Dark-mode preview toggle must be INSIDE the component
    const [previewDark, setPreviewDark] = useState(
        document.documentElement.classList.contains('dark'),
    );

    const initialTheme = useMemo(
        () => ({
            light: props.theme?.light ?? {},
            dark: props.theme?.dark ?? {},
        }),
        [props.theme],
    );

    const form = useForm<{
        branding: { name: string };
        theme: { light: Record<string, string>; dark: Record<string, string> };
        logo: File | null;
        remove_logo: boolean;
    }>({
        branding: {
            name: (props.branding?.name ?? '').toString(),
        },
        theme: {
            light: { ...initialTheme.light },
            dark: { ...initialTheme.dark },
        },
        logo: null,
        remove_logo: false,
    });

    if (!can?.settings?.manageBranding) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Branding" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don’t have permission to manage branding.
                </div>
            </SettingsLayout>
        );
    }

    const quickVars = [
        '--primary',
        '--primary-foreground',
        '--background',
        '--foreground',
        '--accent',
        '--accent-foreground',
        '--sidebar',
        '--sidebar-primary',
        '--sidebar-foreground',
    ];

    const remainingVars = props.allowedVars.filter(
        (v) => !quickVars.includes(v),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Branding" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Branding"
                        description="Customize your organisation’s colors and logo (applies to everyone)."
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
                        <Card>
                            <CardHeader>
                                <CardTitle>Organisation</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="branding_name">
                                        Display name
                                    </Label>
                                    <Input
                                        id="branding_name"
                                        value={form.data.branding.name}
                                        onChange={(e) =>
                                            form.setData('branding', {
                                                ...form.data.branding,
                                                name: e.target.value,
                                            })
                                        }
                                        placeholder="e.g. Acme Supported Living"
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="logo">Logo</Label>
                                    <Input
                                        id="logo"
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => {
                                            const file =
                                                e.target.files?.[0] ?? null;
                                            form.setData('logo', file);
                                        }}
                                    />
                                    {props.branding?.logoUrl && (
                                        <div className="flex items-center gap-3">
                                            <img
                                                src={props.branding.logoUrl}
                                                alt="Current logo"
                                                className="h-10 w-10 rounded-md border object-cover"
                                            />
                                            <div className="flex items-center gap-2">
                                                <Checkbox
                                                    id="remove_logo"
                                                    checked={
                                                        form.data.remove_logo
                                                    }
                                                    onCheckedChange={(v) =>
                                                        form.setData(
                                                            'remove_logo',
                                                            Boolean(v),
                                                        )
                                                    }
                                                />
                                                <Label htmlFor="remove_logo">
                                                    Remove current logo
                                                </Label>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="space-y-2">
                                <CardTitle>Colors</CardTitle>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="preview_dark"
                                        checked={previewDark}
                                        onCheckedChange={(v) => {
                                            const on = Boolean(v);
                                            setPreviewDark(on);
                                            document.documentElement.classList.toggle(
                                                'dark',
                                                on,
                                            );

                                            // If switching to dark preview, apply current dark vars to preview immediately
                                            if (on) {
                                                Object.entries(
                                                    form.data.theme.dark ?? {},
                                                ).forEach(([k, val]) => {
                                                    applyVar(k, val);
                                                });
                                            } else {
                                                // Switching back to light preview: re-apply current light vars
                                                Object.entries(
                                                    form.data.theme.light ?? {},
                                                ).forEach(([k, val]) => {
                                                    applyVar(k, val);
                                                });
                                            }
                                        }}
                                    />
                                    <Label htmlFor="preview_dark">
                                        Preview dark mode
                                    </Label>
                                </div>
                            </CardHeader>

                            <CardContent className="space-y-6">
                                <div className="space-y-3">
                                    <div className="text-sm font-medium">
                                        Light mode
                                    </div>
                                    <div className="space-y-3">
                                        {quickVars.map((cssVar) => (
                                            <VarRow
                                                key={`light-${cssVar}`}
                                                mode="light"
                                                cssVar={cssVar}
                                                value={
                                                    form.data.theme.light[
                                                        cssVar
                                                    ] ?? ''
                                                }
                                                onChange={(next) =>
                                                    form.setData('theme', {
                                                        ...form.data.theme,
                                                        light: {
                                                            ...form.data.theme
                                                                .light,
                                                            [cssVar]: next,
                                                        },
                                                    })
                                                }
                                            />
                                        ))}
                                    </div>
                                </div>

                                <Separator />

                                <div className="space-y-3">
                                    <div className="text-sm font-medium">
                                        Dark mode
                                    </div>
                                    <div className="space-y-3">
                                        {quickVars.map((cssVar) => (
                                            <VarRow
                                                key={`dark-${cssVar}`}
                                                mode="dark"
                                                cssVar={cssVar}
                                                value={
                                                    form.data.theme.dark[
                                                        cssVar
                                                    ] ?? ''
                                                }
                                                onChange={(next) =>
                                                    form.setData('theme', {
                                                        ...form.data.theme,
                                                        dark: {
                                                            ...form.data.theme
                                                                .dark,
                                                            [cssVar]: next,
                                                        },
                                                    })
                                                }
                                            />
                                        ))}
                                    </div>
                                </div>

                                <Separator />

                                <div className="space-y-3">
                                    <button
                                        type="button"
                                        className="text-sm font-medium underline-offset-4 hover:underline"
                                        onClick={() =>
                                            setAdvancedOpen((s) => !s)
                                        }
                                    >
                                        {advancedOpen
                                            ? 'Hide advanced tokens'
                                            : 'Show advanced tokens'}
                                    </button>

                                    {advancedOpen && (
                                        <div className="space-y-6">
                                            <div className="space-y-3">
                                                <div className="text-xs font-medium text-muted-foreground">
                                                    Light mode advanced
                                                </div>
                                                <div className="space-y-3">
                                                    {remainingVars.map(
                                                        (cssVar) => (
                                                            <VarRow
                                                                key={`light-adv-${cssVar}`}
                                                                mode="light"
                                                                cssVar={cssVar}
                                                                value={
                                                                    form.data
                                                                        .theme
                                                                        .light[
                                                                        cssVar
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    next,
                                                                ) =>
                                                                    form.setData(
                                                                        'theme',
                                                                        {
                                                                            ...form
                                                                                .data
                                                                                .theme,
                                                                            light: {
                                                                                ...form
                                                                                    .data
                                                                                    .theme
                                                                                    .light,
                                                                                [cssVar]:
                                                                                    next,
                                                                            },
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                            </div>

                                            <div className="space-y-3">
                                                <div className="text-xs font-medium text-muted-foreground">
                                                    Dark mode advanced
                                                </div>
                                                <div className="space-y-3">
                                                    {remainingVars.map(
                                                        (cssVar) => (
                                                            <VarRow
                                                                key={`dark-adv-${cssVar}`}
                                                                mode="dark"
                                                                cssVar={cssVar}
                                                                value={
                                                                    form.data
                                                                        .theme
                                                                        .dark[
                                                                        cssVar
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    next,
                                                                ) =>
                                                                    form.setData(
                                                                        'theme',
                                                                        {
                                                                            ...form
                                                                                .data
                                                                                .theme,
                                                                            dark: {
                                                                                ...form
                                                                                    .data
                                                                                    .theme
                                                                                    .dark,
                                                                                [cssVar]:
                                                                                    next,
                                                                            },
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <div className="flex items-center gap-2">
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    form.setData('theme', {
                                        light: {},
                                        dark: {},
                                    });
                                    form.setData('branding', { name: '' });
                                    form.setData('logo', null);
                                    form.setData('remove_logo', false);

                                    // Remove inline preview overrides so defaults show
                                    props.allowedVars.forEach((v) =>
                                        applyVar(v, ''),
                                    );
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
