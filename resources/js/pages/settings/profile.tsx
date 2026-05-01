import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useI18n } from '@/lib/i18n';
import profileRoutes, { edit } from '@/routes/profile';
import {
    Activity,
    AlertTriangle,
    Briefcase,
    Calendar,
    Camera,
    Clock,
    FileText,
    Globe,
    Key,
    Phone,
    Shield,
    Trash2,
    Upload,
    User,
} from 'lucide-react';
import { useCallback, useRef } from 'react';

const NZ_AU_TIMEZONES = [
    { value: 'Pacific/Auckland', label: 'Auckland (NZST/NZDT)' },
    { value: 'Pacific/Chatham', label: 'Chatham Islands' },
    { value: 'Australia/Sydney', label: 'Sydney (AEST/AEDT)' },
    { value: 'Australia/Melbourne', label: 'Melbourne (AEST/AEDT)' },
    { value: 'Australia/Brisbane', label: 'Brisbane (AEST)' },
    { value: 'Australia/Adelaide', label: 'Adelaide (ACST/ACDT)' },
    { value: 'Australia/Perth', label: 'Perth (AWST)' },
    { value: 'Australia/Darwin', label: 'Darwin (ACST)' },
    { value: 'Australia/Hobart', label: 'Hobart (AEST/AEDT)' },
    { value: 'Pacific/Fiji', label: 'Fiji' },
    { value: 'Pacific/Tongatapu', label: 'Tonga' },
    { value: 'Pacific/Apia', label: 'Samoa' },
];

const DATE_FORMATS = [
    { value: 'DD/MM/YYYY', label: 'DD/MM/YYYY' },
    { value: 'MM/DD/YYYY', label: 'MM/DD/YYYY' },
    { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD' },
];

type LandingOption = { key: string; label: string; role_label: string };

type ProfileData = {
    phone: string | null;
    jobTitle: string | null;
    timezone: string;
    locale: string;
    dateFormat: string;
    timeFormat: string;
    landingRoutePreference: string | null;
    landingOptions: LandingOption[];
    emailVerifiedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    lastLoginAt: string | null;
    passwordChangedAt: string | null;
    roles: string[];
    twoFactorEnabled: boolean;
    microsoftLinked: boolean;
    googleLinked: boolean;
    profilePhotoPath: string | null;
};

type ProfilePageProps = {
    mustVerifyEmail: boolean;
    status?: string;
    profile: ProfileData;
};

function formatRelativeTime(dateString: string | null | undefined): string {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 30) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function daysSince(dateString: string | null | undefined): number {
    if (!dateString) return 0;
    const date = new Date(dateString);
    const now = new Date();
    return Math.floor((now.getTime() - date.getTime()) / 86400000);
}

export default function Profile({
    mustVerifyEmail,
    status,
    profile: profileData,
}: ProfilePageProps) {
    const { auth } = usePage<SharedData>().props;
    const { availableLocales, t } = useI18n();
    const getInitials = useInitials();
    const photoForm = useForm<{ photo: File | null }>({ photo: null });
    const removePhotoForm = useForm({});
    const preferencesForm = useForm({
        timezone: profileData.timezone,
        locale: profileData.locale,
        date_format: profileData.dateFormat,
        time_format: profileData.timeFormat,
    });

    // Separate lightweight form for landing route preference. Changes save on
    // select change — no explicit save button for this one.
    const landingForm = useForm<{ landing_route_preference: string | null }>({
        landing_route_preference: profileData.landingRoutePreference,
    });

    const handleLandingChange = useCallback(
        (value: string) => {
            const next = value === '__system__' ? null : value;
            landingForm.setData('landing_route_preference', next);
            router.put(
                '/settings/profile/landing',
                { landing_route_preference: next },
                {
                    preserveScroll: true,
                },
            );
        },
        [landingForm],
    );
    const fileInputRef = useRef<HTMLInputElement>(null);
    const passwordInput = useRef<HTMLInputElement>(null);

    const hasPhoto = !!profileData.profilePhotoPath;
    const avatarSrc = auth.user.avatar;
    const memberSince = profileData.createdAt
        ? new Date(profileData.createdAt).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : null;

    const twoFactorEnabled = profileData.twoFactorEnabled;
    const passwordChangedDaysAgo = profileData.passwordChangedAt
        ? daysSince(profileData.passwordChangedAt)
        : null;
    const roles = profileData.roles;
    const title = t('app.profile.title', 'Profile settings');
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title,
            href: edit().url,
        },
    ];

    const handleFileSelect = useCallback(
        (file: File | null) => {
            if (!file) return;
            photoForm.setData('photo', file);
            setTimeout(() => {
                photoForm.post(profileRoutes.photo.update.url(), {
                    forceFormData: true,
                    preserveScroll: true,
                });
            }, 0);
        },
        [photoForm],
    );

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            const file = e.dataTransfer.files?.[0];
            if (
                file &&
                (file.type === 'image/png' || file.type === 'image/jpeg')
            ) {
                handleFileSelect(file);
            }
        },
        [handleFileSelect],
    );

    const submitPreferences = useCallback(
        (event: React.FormEvent<HTMLFormElement>) => {
            event.preventDefault();

            preferencesForm.patch(profileRoutes.update.url(), {
                preserveScroll: true,
            });
        },
        [preferencesForm],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <SettingsLayout>
                {/* ── Modern Profile Header ── */}
                <Card className="relative overflow-hidden bg-white dark:bg-muted">
                    {/* Accent bar */}
                    <div className="h-1.5 w-full bg-primary" />

                    <div className="px-6 py-6">
                        <div className="flex items-center gap-5">
                            {/* Avatar */}
                            <div className="group relative shrink-0">
                                <Avatar className="h-16 w-16 border-2 border-primary/30 shadow-md dark:border-primary/30">
                                    <AvatarImage
                                        src={avatarSrc}
                                        alt={auth.user.name}
                                    />
                                    <AvatarFallback className="bg-primary text-lg font-semibold text-white">
                                        {getInitials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    className="absolute inset-0 h-full w-full cursor-pointer rounded-full bg-black/40 p-0 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-black/40"
                                >
                                    <Camera className="h-4 w-4 text-white" />
                                </Button>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="hidden"
                                    onChange={(e) =>
                                        handleFileSelect(
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </div>

                            {/* Info */}
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-3">
                                    <h1 className="truncate text-xl font-semibold tracking-tight text-foreground dark:text-foreground">
                                        {auth.user.name}
                                    </h1>
                                    {roles.length > 0 ? (
                                        roles.map((role: string) => (
                                            <Badge
                                                key={role}
                                                variant="secondary"
                                                className="bg-primary/10 text-xs text-primary dark:bg-primary/50 dark:text-primary/70"
                                            >
                                                {role}
                                            </Badge>
                                        ))
                                    ) : (
                                        <Badge
                                            variant="secondary"
                                            className="bg-primary/10 text-xs text-primary dark:bg-primary/50 dark:text-primary/70"
                                        >
                                            Team Member
                                        </Badge>
                                    )}
                                </div>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {auth.user.email}
                                </p>
                                {memberSince && (
                                    <p className="mt-1 text-xs text-muted-foreground/70">
                                        Member since {memberSince}
                                    </p>
                                )}
                            </div>

                            {/* Actions */}
                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5"
                                    disabled={photoForm.processing}
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    onDragOver={(e) => e.preventDefault()}
                                    onDrop={handleDrop}
                                >
                                    <Upload className="h-3.5 w-3.5" />
                                    Upload photo
                                </Button>
                                {hasPhoto && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="gap-1.5 text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                        disabled={removePhotoForm.processing}
                                        onClick={() =>
                                            removePhotoForm.delete(
                                                profileRoutes.photo.destroy.url(),
                                                {
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                        Remove photo
                                    </Button>
                                )}
                            </div>
                        </div>
                        <InputError
                            className="mt-2"
                            message={(photoForm.errors as any).photo}
                        />
                    </div>
                </Card>

                {/* ── Two-Column Layout ── */}
                <div className="grid gap-6 lg:grid-cols-[1fr_0.67fr]">
                    {/* ── Left Column (60%) ── */}
                    <div className="space-y-6">
                        {/* Card 1: Personal Information */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <User className="h-4.5 w-4.5 text-primary" />
                                    {t(
                                        'app.profile.personal.title',
                                        'Personal information',
                                    )}
                                </CardTitle>
                                <CardDescription>
                                    {t(
                                        'app.profile.personal.description',
                                        'Update your personal details',
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Form
                                    action={profileRoutes.update()}
                                    options={{ preserveScroll: true }}
                                    className="space-y-5"
                                >
                                    {({
                                        processing,
                                        recentlySuccessful,
                                        errors,
                                    }) => (
                                        <>
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="name">
                                                        {t(
                                                            'app.auth.full_name',
                                                            'Full name',
                                                        )}{' '}
                                                        <span className="text-status-critical">
                                                            *
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id="name"
                                                        defaultValue={
                                                            auth.user.name
                                                        }
                                                        name="name"
                                                        required
                                                        autoComplete="name"
                                                        placeholder="Full name"
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="email">
                                                        {t(
                                                            'app.auth.email_address',
                                                            'Email address',
                                                        )}{' '}
                                                        <span className="text-status-critical">
                                                            *
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id="email"
                                                        type="email"
                                                        defaultValue={
                                                            auth.user.email
                                                        }
                                                        name="email"
                                                        required
                                                        autoComplete="username"
                                                        placeholder="Email address"
                                                    />
                                                    <InputError
                                                        message={errors.email}
                                                    />
                                                </div>
                                            </div>

                                            {mustVerifyEmail &&
                                                profileData.emailVerifiedAt ===
                                                    null && (
                                                    <div className="rounded-md border border-status-warning/30 bg-status-warning-bg px-4 py-3 dark:border-status-warning/50 dark:bg-status-warning">
                                                        <p className="text-sm text-status-warning dark:text-status-warning">
                                                            Your email address
                                                            is unverified.{' '}
                                                            <Link
                                                                href={send()}
                                                                as="button"
                                                                className="font-medium underline underline-offset-4 hover:text-status-warning"
                                                            >
                                                                Resend
                                                                verification
                                                                email.
                                                            </Link>
                                                        </p>
                                                        {status ===
                                                            'verification-link-sent' && (
                                                            <p className="mt-2 text-sm font-medium text-status-success">
                                                                A new
                                                                verification
                                                                link has been
                                                                sent to your
                                                                email.
                                                            </p>
                                                        )}
                                                    </div>
                                                )}

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="phone">
                                                        <span className="flex items-center gap-1.5">
                                                            <Phone className="h-3.5 w-3.5 text-muted-foreground" />
                                                            Phone number
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id="phone"
                                                        type="tel"
                                                        name="phone"
                                                        defaultValue={
                                                            profileData.phone ??
                                                            ''
                                                        }
                                                        autoComplete="tel"
                                                        placeholder="+64 21 234 5678"
                                                    />
                                                    <InputError
                                                        message={
                                                            (errors as any)
                                                                .phone
                                                        }
                                                    />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="job_title">
                                                        <span className="flex items-center gap-1.5">
                                                            <Briefcase className="h-3.5 w-3.5 text-muted-foreground" />
                                                            Job title / Position
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id="job_title"
                                                        name="job_title"
                                                        defaultValue={
                                                            profileData.jobTitle ??
                                                            ''
                                                        }
                                                        placeholder="e.g. Support Worker"
                                                    />
                                                    <InputError
                                                        message={
                                                            (errors as any)
                                                                .job_title
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-4 pt-2">
                                                <Button
                                                    disabled={processing}
                                                    className="bg-primary hover:bg-primary"
                                                    data-test="update-profile-button"
                                                >
                                                    {t(
                                                        'app.actions.save_changes',
                                                        'Save changes',
                                                    )}
                                                </Button>
                                                <Transition
                                                    show={recentlySuccessful}
                                                    enter="transition ease-in-out"
                                                    enterFrom="opacity-0"
                                                    leave="transition ease-in-out"
                                                    leaveTo="opacity-0"
                                                >
                                                    <p className="text-sm font-medium text-status-success">
                                                        {t(
                                                            'app.profile.personal.saved',
                                                            'Profile saved',
                                                        )}
                                                    </p>
                                                </Transition>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>

                        {/* Card 2: Preferences */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Globe className="h-4.5 w-4.5 text-primary" />
                                    {t(
                                        'app.profile.preferences.title',
                                        'Preferences',
                                    )}
                                </CardTitle>
                                <CardDescription>
                                    {t(
                                        'app.profile.preferences.description',
                                        'Customise your experience',
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    className="space-y-5"
                                    onSubmit={submitPreferences}
                                >
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="timezone">
                                                {t(
                                                    'app.profile.preferences.timezone',
                                                    'Timezone',
                                                )}
                                            </Label>
                                            <Select
                                                value={
                                                    preferencesForm.data
                                                        .timezone
                                                }
                                                onValueChange={(value) =>
                                                    preferencesForm.setData(
                                                        'timezone',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="timezone">
                                                    <SelectValue
                                                        placeholder={t(
                                                            'app.profile.preferences.select_timezone',
                                                            'Select timezone',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {NZ_AU_TIMEZONES.map(
                                                        (tz) => (
                                                            <SelectItem
                                                                key={tz.value}
                                                                value={tz.value}
                                                            >
                                                                {tz.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    preferencesForm.errors
                                                        .timezone
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="date_format">
                                                {t(
                                                    'app.appearance.regional.date_format',
                                                    'Date format',
                                                )}
                                            </Label>
                                            <Select
                                                value={
                                                    preferencesForm.data
                                                        .date_format
                                                }
                                                onValueChange={(value) =>
                                                    preferencesForm.setData(
                                                        'date_format',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger id="date_format">
                                                    <SelectValue
                                                        placeholder={t(
                                                            'app.profile.preferences.select_format',
                                                            'Select format',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {DATE_FORMATS.map((df) => (
                                                        <SelectItem
                                                            key={df.value}
                                                            value={df.value}
                                                        >
                                                            {df.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    preferencesForm.errors
                                                        .date_format
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>
                                            {t(
                                                'app.appearance.regional.time_format',
                                                'Time format',
                                            )}
                                        </Label>
                                        <RadioGroup
                                            value={
                                                preferencesForm.data.time_format
                                            }
                                            onValueChange={(value) =>
                                                preferencesForm.setData(
                                                    'time_format',
                                                    value,
                                                )
                                            }
                                            className="flex gap-6"
                                        >
                                            <div className="flex items-center gap-2">
                                                <RadioGroupItem
                                                    value="12"
                                                    id="time-12"
                                                />
                                                <Label
                                                    htmlFor="time-12"
                                                    className="cursor-pointer font-normal"
                                                >
                                                    {t(
                                                        'app.appearance.regional.time_format_12',
                                                        '12-hour',
                                                    )}
                                                </Label>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <RadioGroupItem
                                                    value="24"
                                                    id="time-24"
                                                />
                                                <Label
                                                    htmlFor="time-24"
                                                    className="cursor-pointer font-normal"
                                                >
                                                    {t(
                                                        'app.appearance.regional.time_format_24',
                                                        '24-hour',
                                                    )}
                                                </Label>
                                            </div>
                                        </RadioGroup>
                                        <InputError
                                            message={
                                                preferencesForm.errors
                                                    .time_format
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="language">
                                            {t(
                                                'app.settings.language',
                                                'Language',
                                            )}
                                        </Label>
                                        <Select
                                            value={preferencesForm.data.locale}
                                            onValueChange={(value) =>
                                                preferencesForm.setData(
                                                    'locale',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="language"
                                                className="max-w-xs"
                                            >
                                                <SelectValue placeholder="Select language" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    availableLocales,
                                                ).map(([value, meta]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {meta.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                preferencesForm.errors.locale
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="landing_route_preference">
                                            {t(
                                                'app.profile.preferences.default_landing_page',
                                                'Default landing page',
                                            )}
                                        </Label>
                                        <Select
                                            value={
                                                landingForm.data
                                                    .landing_route_preference ??
                                                '__system__'
                                            }
                                            onValueChange={handleLandingChange}
                                        >
                                            <SelectTrigger
                                                id="landing_route_preference"
                                                className="max-w-sm"
                                            >
                                                <SelectValue
                                                    placeholder={t(
                                                        'app.profile.preferences.system_default',
                                                        'System default',
                                                    )}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__system__">
                                                    {t(
                                                        'app.profile.preferences.system_default',
                                                        'System default',
                                                    )}
                                                </SelectItem>
                                                {profileData.landingOptions.map(
                                                    (opt) => (
                                                        <SelectItem
                                                            key={opt.key}
                                                            value={opt.key}
                                                        >
                                                            {opt.label}
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                (
                                                                {t(
                                                                    'app.profile.preferences.via',
                                                                    'via',
                                                                )}{' '}
                                                                {opt.role_label}
                                                                )
                                                            </span>
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            {profileData.landingOptions
                                                .length === 0
                                                ? t(
                                                      'app.profile.preferences.no_landing_options',
                                                      "None of your roles have a landing page configured. You'll be sent to the dashboard after login.",
                                                  )
                                                : t(
                                                      'app.profile.preferences.landing_help',
                                                      'Pick which of your roles decides where you land after login.',
                                                  )}
                                        </p>
                                    </div>

                                    <div className="pt-2">
                                        <Button
                                            type="submit"
                                            className="bg-primary hover:bg-primary"
                                            disabled={
                                                preferencesForm.processing
                                            }
                                            data-test="save-preferences-button"
                                        >
                                            {t(
                                                'app.settings.save_preferences',
                                                'Save preferences',
                                            )}
                                        </Button>
                                        <Transition
                                            show={
                                                preferencesForm.recentlySuccessful
                                            }
                                            enter="transition ease-in-out"
                                            enterFrom="opacity-0"
                                            leave="transition ease-in-out"
                                            leaveTo="opacity-0"
                                        >
                                            <p className="mt-3 text-sm font-medium text-status-success">
                                                {t(
                                                    'app.settings.preferences_saved',
                                                    'Preferences saved',
                                                )}
                                            </p>
                                        </Transition>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </div>

                    {/* ── Right Column (40%) ── */}
                    <div className="space-y-6">
                        {/* Card 3: Account Security */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Shield className="h-4.5 w-4.5 text-primary" />
                                    {t(
                                        'app.profile.security.title',
                                        'Account security',
                                    )}
                                </CardTitle>
                                <CardDescription>
                                    {t(
                                        'app.profile.security.description',
                                        'Manage your account security',
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/* Password */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                            <Key className="h-4 w-4 text-primary" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">
                                                {t(
                                                    'app.auth.password',
                                                    'Password',
                                                )}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {passwordChangedDaysAgo !== null
                                                    ? `Last changed ${passwordChangedDaysAgo} day${passwordChangedDaysAgo !== 1 ? 's' : ''} ago`
                                                    : t(
                                                          'app.profile.security.set_strong_password',
                                                          'Set a strong password',
                                                      )}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-primary hover:text-primary"
                                        asChild
                                    >
                                        <Link href="/settings/password">
                                            {t('app.actions.change', 'Change')}
                                        </Link>
                                    </Button>
                                </div>

                                <Separator />

                                {/* Two-Factor Auth */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                            <Shield className="h-4 w-4 text-primary" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">
                                                {t(
                                                    'app.profile.security.two_factor',
                                                    'Two-factor authentication',
                                                )}
                                            </p>
                                            {twoFactorEnabled ? (
                                                <Badge className="mt-1 bg-status-success-bg text-status-success hover:bg-status-success-bg dark:bg-status-success-bg dark:text-status-success">
                                                    {t(
                                                        'app.profile.security.enabled',
                                                        'Enabled',
                                                    )}
                                                </Badge>
                                            ) : (
                                                <Badge className="mt-1 bg-status-warning-bg text-status-warning hover:bg-status-warning-bg dark:bg-status-warning-bg dark:text-status-warning">
                                                    {t(
                                                        'app.profile.security.not_enabled',
                                                        'Not enabled',
                                                    )}
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-primary hover:text-primary"
                                        asChild
                                    >
                                        <Link href="/settings/two-factor">
                                            {t('app.actions.manage', 'Manage')}
                                        </Link>
                                    </Button>
                                </div>

                                <Separator />

                                {/* Active Sessions */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                            <Activity className="h-4 w-4 text-primary" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">
                                                {t(
                                                    'app.profile.security.active_sessions',
                                                    'Active sessions',
                                                )}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'app.profile.security.one_active_session',
                                                    '1 active session',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-primary hover:text-primary"
                                        disabled
                                    >
                                        {t('app.actions.view', 'View')}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Card 4: Quick Stats */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Activity className="h-4.5 w-4.5 text-primary" />
                                    Quick Stats
                                </CardTitle>
                                <CardDescription>
                                    Your activity summary
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <Calendar className="mb-2 h-5 w-5 text-primary" />
                                        <span className="text-2xl font-bold text-foreground dark:text-foreground">
                                            0
                                        </span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">
                                            Shifts this month
                                        </span>
                                    </div>
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <FileText className="mb-2 h-5 w-5 text-primary" />
                                        <span className="text-2xl font-bold text-foreground dark:text-foreground">
                                            0
                                        </span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">
                                            Notes written
                                        </span>
                                    </div>
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <Clock className="mb-2 h-5 w-5 text-primary" />
                                        <span className="text-2xl font-bold text-foreground dark:text-foreground">
                                            {formatRelativeTime(
                                                profileData.lastLoginAt ??
                                                    profileData.updatedAt,
                                            )}
                                        </span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">
                                            Last login
                                        </span>
                                    </div>
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <Activity className="mb-2 h-5 w-5 text-primary" />
                                        <span className="text-2xl font-bold text-foreground dark:text-foreground">
                                            {daysSince(profileData.createdAt)}
                                        </span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">
                                            Days active
                                        </span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Connected Accounts */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Shield className="h-5 w-5 text-primary" />{' '}
                                    Connected Accounts
                                </CardTitle>
                                <CardDescription>
                                    Link your Microsoft or Google account for
                                    single sign-on
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {/* Microsoft */}
                                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded bg-[#00a4ef]/10">
                                            <svg
                                                viewBox="0 0 23 23"
                                                className="h-4 w-4"
                                            >
                                                <path
                                                    fill="#f35325"
                                                    d="M1 1h10v10H1z"
                                                />
                                                <path
                                                    fill="#81bc06"
                                                    d="M12 1h10v10H12z"
                                                />
                                                <path
                                                    fill="#05a6f0"
                                                    d="M1 12h10v10H1z"
                                                />
                                                <path
                                                    fill="#ffba08"
                                                    d="M12 12h10v10H12z"
                                                />
                                            </svg>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">
                                                Microsoft
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {profileData.microsoftLinked
                                                    ? 'Connected'
                                                    : 'Not connected'}
                                            </p>
                                        </div>
                                    </div>
                                    {profileData.microsoftLinked ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-status-critical"
                                            onClick={() =>
                                                router.post(
                                                    '/auth/microsoft/disconnect',
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Disconnect
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a href="/auth/microsoft/redirect?link=1">
                                                Connect
                                            </a>
                                        </Button>
                                    )}
                                </div>
                                {/* Google */}
                                <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-8 w-8 items-center justify-center rounded bg-status-critical-bg">
                                            <svg
                                                viewBox="0 0 24 24"
                                                className="h-4 w-4"
                                            >
                                                <path
                                                    fill="#4285F4"
                                                    d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"
                                                />
                                                <path
                                                    fill="#34A853"
                                                    d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                                />
                                                <path
                                                    fill="#FBBC05"
                                                    d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                                                />
                                                <path
                                                    fill="#EA4335"
                                                    d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                                                />
                                            </svg>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">
                                                Google
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {profileData.googleLinked
                                                    ? 'Connected'
                                                    : 'Not connected'}
                                            </p>
                                        </div>
                                    </div>
                                    {profileData.googleLinked ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-status-critical"
                                            onClick={() =>
                                                router.post(
                                                    '/auth/google/disconnect',
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Disconnect
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a href="/auth/google/redirect?link=1">
                                                Connect
                                            </a>
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Card 5: Danger Zone */}
                        <Card className="border-status-critical/30 dark:border-status-critical/50">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-status-critical dark:text-status-critical">
                                    <AlertTriangle className="h-4.5 w-4.5" />
                                    Danger Zone
                                </CardTitle>
                                <CardDescription>
                                    Irreversible actions
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="mb-4 flex items-start gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 dark:border-status-critical/50 dark:bg-status-critical">
                                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-status-critical" />
                                    <div className="text-sm text-status-critical dark:text-status-critical">
                                        <span className="font-semibold">
                                            Warning
                                        </span>{' '}
                                        — Deleting your account removes all your
                                        data permanently. This action cannot be
                                        undone.
                                    </div>
                                </div>

                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="destructive"
                                            data-test="delete-user-button"
                                        >
                                            <Trash2 className="mr-1.5 h-4 w-4" />
                                            Delete account
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>
                                            Are you sure you want to delete your
                                            account?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Once your account is deleted, all of
                                            its resources and data will also be
                                            permanently deleted. Please enter
                                            your password to confirm you would
                                            like to permanently delete your
                                            account.
                                        </DialogDescription>

                                        <Form
                                            action={profileRoutes.destroy()}
                                            options={{ preserveScroll: true }}
                                            onError={() =>
                                                passwordInput.current?.focus()
                                            }
                                            resetOnSuccess
                                            className="space-y-6"
                                        >
                                            {({
                                                resetAndClearErrors,
                                                processing,
                                                errors,
                                            }) => (
                                                <>
                                                    <div className="grid gap-2">
                                                        <Label
                                                            htmlFor="password"
                                                            className="sr-only"
                                                        >
                                                            Password
                                                        </Label>
                                                        <Input
                                                            id="password"
                                                            type="password"
                                                            name="password"
                                                            ref={passwordInput}
                                                            placeholder="Password"
                                                            autoComplete="current-password"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.password
                                                            }
                                                        />
                                                    </div>

                                                    <DialogFooter className="gap-2">
                                                        <DialogClose asChild>
                                                            <Button
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    resetAndClearErrors()
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>

                                                        <Button
                                                            type="submit"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                            data-test="confirm-delete-user-button"
                                                        >
                                                            Delete account
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
