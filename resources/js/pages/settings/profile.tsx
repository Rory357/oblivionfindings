import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Form, Head, Link, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { edit } from '@/routes/profile';
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
    Mail,
    Phone,
    Shield,
    Trash2,
    Upload,
    User,
} from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

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
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();
    const photoForm = useForm<{ photo: File | null }>({ photo: null });
    const removePhotoForm = useForm({});
    const fileInputRef = useRef<HTMLInputElement>(null);
    const passwordInput = useRef<HTMLInputElement>(null);

    const user = auth.user as any;
    const hasPhoto = !!user.profile_photo_path;
    const avatarSrc = user.avatar ?? user.profile_photo_url;
    const memberSince = user.created_at
        ? new Date(user.created_at).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : null;

    const twoFactorEnabled = !!user.two_factor_enabled;
    const passwordChangedDaysAgo = user.password_changed_at ? daysSince(user.password_changed_at) : null;
    const roles: string[] = user.roles ?? (user.role ? [user.role] : []);

    // Preferences state (local only until a preferences endpoint exists)
    const [timezone, setTimezone] = useState('Pacific/Auckland');
    const [dateFormat, setDateFormat] = useState('DD/MM/YYYY');
    const [timeFormat, setTimeFormat] = useState('24');

    const handleFileSelect = useCallback(
        (file: File | null) => {
            if (!file) return;
            photoForm.setData('photo', file);
            setTimeout(() => {
                photoForm.post('/settings/profile/photo', {
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
            if (file && (file.type === 'image/png' || file.type === 'image/jpeg')) {
                handleFileSelect(file);
            }
        },
        [handleFileSelect],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />

            <SettingsLayout>
                {/* ── Hero Profile Card ── */}
                <Card className="overflow-hidden border-0 shadow-sm">
                    <div className="bg-gradient-to-br from-violet-50 via-violet-50/60 to-white px-6 pb-6 pt-8 dark:from-violet-950/30 dark:via-violet-950/10 dark:to-transparent">
                        <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                            {/* Avatar with hover overlay */}
                            <div className="group relative shrink-0">
                                <Avatar className="h-[120px] w-[120px] ring-4 ring-white shadow-lg dark:ring-gray-800">
                                    <AvatarImage src={avatarSrc} alt={auth.user.name} />
                                    <AvatarFallback className="bg-violet-100 text-2xl font-semibold text-violet-700 dark:bg-violet-900 dark:text-violet-300">
                                        {getInitials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <button
                                    type="button"
                                    onClick={() => fileInputRef.current?.click()}
                                    className="absolute inset-0 flex cursor-pointer flex-col items-center justify-center rounded-full bg-black/50 opacity-0 transition-opacity group-hover:opacity-100"
                                >
                                    <Camera className="mb-1 h-5 w-5 text-white" />
                                    <span className="text-xs font-medium text-white">Change photo</span>
                                </button>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="hidden"
                                    onChange={(e) => handleFileSelect(e.target.files?.[0] ?? null)}
                                />
                            </div>

                            {/* Name, email, role, member since */}
                            <div className="flex flex-1 flex-col items-center gap-2 sm:items-start">
                                <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                                    {auth.user.name}
                                </h1>
                                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                    <Mail className="h-3.5 w-3.5" />
                                    {auth.user.email}
                                </p>

                                <div className="mt-1 flex flex-wrap items-center gap-2">
                                    {roles.length > 0
                                        ? roles.map((role: string) => (
                                              <Badge
                                                  key={role}
                                                  className="bg-violet-100 text-violet-700 hover:bg-violet-100 dark:bg-violet-900/50 dark:text-violet-300"
                                              >
                                                  {role}
                                              </Badge>
                                          ))
                                        : (
                                              <Badge className="bg-violet-100 text-violet-700 hover:bg-violet-100 dark:bg-violet-900/50 dark:text-violet-300">
                                                  Team Member
                                              </Badge>
                                          )}
                                    {memberSince && (
                                        <span className="text-xs text-muted-foreground">
                                            Member since {memberSince}
                                        </span>
                                    )}
                                </div>

                                {/* Photo action buttons */}
                                <div className="mt-3 flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="gap-1.5"
                                        disabled={photoForm.processing}
                                        onClick={() => fileInputRef.current?.click()}
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
                                            className="gap-1.5 text-red-600 hover:bg-red-50 hover:text-red-700"
                                            disabled={removePhotoForm.processing}
                                            onClick={() =>
                                                removePhotoForm.delete('/settings/profile/photo', {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                            Remove photo
                                        </Button>
                                    )}
                                </div>
                                <InputError className="mt-1" message={(photoForm.errors as any).photo} />
                            </div>
                        </div>
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
                                    <User className="h-4.5 w-4.5 text-violet-600" />
                                    Personal Information
                                </CardTitle>
                                <CardDescription>Update your personal details</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Form
                                    {...ProfileController.update.form()}
                                    options={{ preserveScroll: true }}
                                    className="space-y-5"
                                >
                                    {({ processing, recentlySuccessful, errors }) => (
                                        <>
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="name">
                                                        Full name <span className="text-red-500">*</span>
                                                    </Label>
                                                    <Input
                                                        id="name"
                                                        defaultValue={auth.user.name}
                                                        name="name"
                                                        required
                                                        autoComplete="name"
                                                        placeholder="Full name"
                                                    />
                                                    <InputError message={errors.name} />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="email">
                                                        Email address <span className="text-red-500">*</span>
                                                    </Label>
                                                    <Input
                                                        id="email"
                                                        type="email"
                                                        defaultValue={auth.user.email}
                                                        name="email"
                                                        required
                                                        autoComplete="username"
                                                        placeholder="Email address"
                                                    />
                                                    <InputError message={errors.email} />
                                                </div>
                                            </div>

                                            {mustVerifyEmail && auth.user.email_verified_at === null && (
                                                <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                                                    <p className="text-sm text-amber-700 dark:text-amber-400">
                                                        Your email address is unverified.{' '}
                                                        <Link
                                                            href={send()}
                                                            as="button"
                                                            className="font-medium underline underline-offset-4 hover:text-amber-900"
                                                        >
                                                            Resend verification email.
                                                        </Link>
                                                    </p>
                                                    {status === 'verification-link-sent' && (
                                                        <p className="mt-2 text-sm font-medium text-green-600">
                                                            A new verification link has been sent to your email.
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
                                                        defaultValue={user.phone ?? ''}
                                                        autoComplete="tel"
                                                        placeholder="+64 21 234 5678"
                                                    />
                                                    <InputError message={(errors as any).phone} />
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
                                                        defaultValue={user.job_title ?? ''}
                                                        placeholder="e.g. Support Worker"
                                                    />
                                                    <InputError message={(errors as any).job_title} />
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-4 pt-2">
                                                <Button
                                                    disabled={processing}
                                                    className="bg-violet-600 hover:bg-violet-700"
                                                    data-test="update-profile-button"
                                                >
                                                    Save changes
                                                </Button>
                                                <Transition
                                                    show={recentlySuccessful}
                                                    enter="transition ease-in-out"
                                                    enterFrom="opacity-0"
                                                    leave="transition ease-in-out"
                                                    leaveTo="opacity-0"
                                                >
                                                    <p className="text-sm font-medium text-green-600">Saved</p>
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
                                    <Globe className="h-4.5 w-4.5 text-violet-600" />
                                    Preferences
                                </CardTitle>
                                <CardDescription>Customise your experience</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="timezone">Timezone</Label>
                                        <Select value={timezone} onValueChange={setTimezone}>
                                            <SelectTrigger id="timezone">
                                                <SelectValue placeholder="Select timezone" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {NZ_AU_TIMEZONES.map((tz) => (
                                                    <SelectItem key={tz.value} value={tz.value}>
                                                        {tz.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="date_format">Date format</Label>
                                        <Select value={dateFormat} onValueChange={setDateFormat}>
                                            <SelectTrigger id="date_format">
                                                <SelectValue placeholder="Select format" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {DATE_FORMATS.map((df) => (
                                                    <SelectItem key={df.value} value={df.value}>
                                                        {df.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label>Time format</Label>
                                    <RadioGroup
                                        value={timeFormat}
                                        onValueChange={setTimeFormat}
                                        className="flex gap-6"
                                    >
                                        <div className="flex items-center gap-2">
                                            <RadioGroupItem value="12" id="time-12" />
                                            <Label htmlFor="time-12" className="cursor-pointer font-normal">
                                                12-hour
                                            </Label>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <RadioGroupItem value="24" id="time-24" />
                                            <Label htmlFor="time-24" className="cursor-pointer font-normal">
                                                24-hour
                                            </Label>
                                        </div>
                                    </RadioGroup>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="language">Language</Label>
                                    <div className="flex items-center gap-3">
                                        <Input
                                            id="language"
                                            value="English (NZ)"
                                            disabled
                                            className="max-w-xs bg-muted"
                                        />
                                        <span className="text-xs text-muted-foreground">More coming soon</span>
                                    </div>
                                </div>

                                <div className="pt-2">
                                    <Button className="bg-violet-600 hover:bg-violet-700">
                                        Save preferences
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* ── Right Column (40%) ── */}
                    <div className="space-y-6">
                        {/* Card 3: Account Security */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Shield className="h-4.5 w-4.5 text-violet-600" />
                                    Account Security
                                </CardTitle>
                                <CardDescription>Manage your account security</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/* Password */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 dark:bg-violet-950/30">
                                            <Key className="h-4 w-4 text-violet-600" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">Password</p>
                                            <p className="text-xs text-muted-foreground">
                                                {passwordChangedDaysAgo !== null
                                                    ? `Last changed ${passwordChangedDaysAgo} day${passwordChangedDaysAgo !== 1 ? 's' : ''} ago`
                                                    : 'Set a strong password'}
                                            </p>
                                        </div>
                                    </div>
                                    <Button variant="ghost" size="sm" className="text-violet-600 hover:text-violet-700" asChild>
                                        <Link href="/settings/password">Change</Link>
                                    </Button>
                                </div>

                                <Separator />

                                {/* Two-Factor Auth */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 dark:bg-violet-950/30">
                                            <Shield className="h-4 w-4 text-violet-600" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">Two-factor authentication</p>
                                            {twoFactorEnabled ? (
                                                <Badge className="mt-1 bg-green-100 text-green-700 hover:bg-green-100 dark:bg-green-900/40 dark:text-green-400">
                                                    Enabled
                                                </Badge>
                                            ) : (
                                                <Badge className="mt-1 bg-amber-100 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/40 dark:text-amber-400">
                                                    Not enabled
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                    <Button variant="ghost" size="sm" className="text-violet-600 hover:text-violet-700" asChild>
                                        <Link href="/settings/two-factor">Manage</Link>
                                    </Button>
                                </div>

                                <Separator />

                                {/* Active Sessions */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 dark:bg-violet-950/30">
                                            <Activity className="h-4 w-4 text-violet-600" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium">Active sessions</p>
                                            <p className="text-xs text-muted-foreground">1 active session</p>
                                        </div>
                                    </div>
                                    <Button variant="ghost" size="sm" className="text-violet-600 hover:text-violet-700" disabled>
                                        View
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Card 4: Quick Stats */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Activity className="h-4.5 w-4.5 text-violet-600" />
                                    Quick Stats
                                </CardTitle>
                                <CardDescription>Your activity summary</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <Calendar className="mb-2 h-5 w-5 text-violet-500" />
                                        <span className="text-2xl font-bold text-gray-900 dark:text-gray-100">0</span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">Shifts this month</span>
                                    </div>
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <FileText className="mb-2 h-5 w-5 text-violet-500" />
                                        <span className="text-2xl font-bold text-gray-900 dark:text-gray-100">0</span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">Notes written</span>
                                    </div>
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <Clock className="mb-2 h-5 w-5 text-violet-500" />
                                        <span className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {formatRelativeTime(user.last_login_at ?? user.updated_at)}
                                        </span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">Last login</span>
                                    </div>
                                    <div className="flex flex-col items-center rounded-lg border bg-muted/30 p-4 text-center">
                                        <Activity className="mb-2 h-5 w-5 text-violet-500" />
                                        <span className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                            {daysSince(user.created_at)}
                                        </span>
                                        <span className="mt-0.5 text-xs text-muted-foreground">Days active</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Card 5: Danger Zone */}
                        <Card className="border-red-200 dark:border-red-900/50">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-red-700 dark:text-red-400">
                                    <AlertTriangle className="h-4.5 w-4.5" />
                                    Danger Zone
                                </CardTitle>
                                <CardDescription>Irreversible actions</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="mb-4 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-950/30">
                                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                                    <div className="text-sm text-red-700 dark:text-red-400">
                                        <span className="font-semibold">Warning</span> — Deleting your
                                        account removes all your data permanently. This action cannot be
                                        undone.
                                    </div>
                                </div>

                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="destructive" data-test="delete-user-button">
                                            <Trash2 className="mr-1.5 h-4 w-4" />
                                            Delete account
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>
                                            Are you sure you want to delete your account?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Once your account is deleted, all of its resources and data
                                            will also be permanently deleted. Please enter your password
                                            to confirm you would like to permanently delete your account.
                                        </DialogDescription>

                                        <Form
                                            {...ProfileController.destroy.form()}
                                            options={{ preserveScroll: true }}
                                            onError={() => passwordInput.current?.focus()}
                                            resetOnSuccess
                                            className="space-y-6"
                                        >
                                            {({ resetAndClearErrors, processing, errors }) => (
                                                <>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="password" className="sr-only">
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
                                                        <InputError message={errors.password} />
                                                    </div>

                                                    <DialogFooter className="gap-2">
                                                        <DialogClose asChild>
                                                            <Button
                                                                variant="secondary"
                                                                onClick={() => resetAndClearErrors()}
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>

                                                        <Button
                                                            variant="destructive"
                                                            disabled={processing}
                                                            asChild
                                                        >
                                                            <button
                                                                type="submit"
                                                                data-test="confirm-delete-user-button"
                                                            >
                                                                Delete account
                                                            </button>
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
