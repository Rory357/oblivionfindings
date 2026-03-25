import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Form, Head, Link, useForm, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
import { useInitials } from '@/hooks/use-initials';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit } from '@/routes/profile';
import { AlertTriangle, Upload, Trash2 } from 'lucide-react';
import { useCallback, useRef } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

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

    const hasPhoto = !!(auth.user as any).profile_photo_path;
    const avatarSrc = (auth.user as any).avatar ?? (auth.user as any).profile_photo_url;
    const memberSince = (auth.user as any).created_at
        ? new Date((auth.user as any).created_at).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : null;

    const handleFileSelect = useCallback(
        (file: File | null) => {
            if (!file) return;
            photoForm.setData('photo', file);
            // Auto-submit on file select
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
                {/* Profile Photo */}
                <Card>
                    <CardHeader>
                        <CardTitle>Profile Photo</CardTitle>
                        <CardDescription>
                            Upload a photo to display across the app. PNG or JPG, max 2MB.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
                            <div className="flex flex-col items-center gap-2">
                                <Avatar className="h-20 w-20">
                                    <AvatarImage src={avatarSrc} alt={auth.user.name} />
                                    <AvatarFallback className="text-lg">
                                        {getInitials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                {hasPhoto && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                        disabled={removePhotoForm.processing}
                                        onClick={() =>
                                            removePhotoForm.delete('/settings/profile/photo', {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        <Trash2 className="mr-1 h-3.5 w-3.5" />
                                        Remove
                                    </Button>
                                )}
                            </div>

                            <div
                                className="flex flex-1 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-muted-foreground/25 bg-muted/30 px-6 py-8 transition-colors hover:border-violet-400 hover:bg-violet-50/50 dark:hover:bg-violet-950/20"
                                onClick={() => fileInputRef.current?.click()}
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={handleDrop}
                            >
                                <Upload className="mb-2 h-8 w-8 text-muted-foreground/60" />
                                <p className="text-sm font-medium text-foreground">
                                    Click to upload or drag and drop
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    PNG or JPG up to 2MB
                                </p>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="hidden"
                                    onChange={(e) => handleFileSelect(e.target.files?.[0] ?? null)}
                                />
                            </div>
                        </div>
                        <InputError className="mt-3" message={(photoForm.errors as any).photo} />
                    </CardContent>
                </Card>

                {/* Profile Information */}
                <Card>
                    <CardHeader>
                        <CardTitle>Profile Information</CardTitle>
                        <CardDescription>Update your name and email address</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...ProfileController.update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            className="space-y-5"
                        >
                            {({ processing, recentlySuccessful, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            className="max-w-md"
                                            defaultValue={auth.user.name}
                                            name="name"
                                            required
                                            autoComplete="name"
                                            placeholder="Full name"
                                        />
                                        <InputError className="mt-1" message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email address</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            className="max-w-md"
                                            defaultValue={auth.user.email}
                                            name="email"
                                            required
                                            autoComplete="username"
                                            placeholder="Email address"
                                        />
                                        <InputError className="mt-1" message={errors.email} />
                                    </div>

                                    {mustVerifyEmail && auth.user.email_verified_at === null && (
                                        <div>
                                            <p className="text-sm text-muted-foreground">
                                                Your email address is unverified.{' '}
                                                <Link
                                                    href={send()}
                                                    as="button"
                                                    className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                                >
                                                    Click here to resend the verification email.
                                                </Link>
                                            </p>

                                            {status === 'verification-link-sent' && (
                                                <div className="mt-2 text-sm font-medium text-green-600">
                                                    A new verification link has been sent to your
                                                    email address.
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="flex items-center gap-4">
                                        <Button
                                            disabled={processing}
                                            className="bg-violet-600 hover:bg-violet-700"
                                            data-test="update-profile-button"
                                        >
                                            Save
                                        </Button>

                                        <Transition
                                            show={recentlySuccessful}
                                            enter="transition ease-in-out"
                                            enterFrom="opacity-0"
                                            leave="transition ease-in-out"
                                            leaveTo="opacity-0"
                                        >
                                            <p className="text-sm text-green-600">Saved</p>
                                        </Transition>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                {/* Delete Account */}
                <Card className="border-red-200 dark:border-red-900/50">
                    <CardHeader>
                        <CardTitle>Delete Account</CardTitle>
                        <CardDescription>
                            Permanently delete your account and all data
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="mb-4 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-950/30">
                            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                            <div className="text-sm text-red-700 dark:text-red-400">
                                <span className="font-semibold">Warning</span> — This action cannot
                                be undone. All your data will be permanently removed.
                            </div>
                        </div>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="destructive" data-test="delete-user-button">
                                    Delete account
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    Are you sure you want to delete your account?
                                </DialogTitle>
                                <DialogDescription>
                                    Once your account is deleted, all of its resources and data will
                                    also be permanently deleted. Please enter your password to
                                    confirm you would like to permanently delete your account.
                                </DialogDescription>

                                <Form
                                    {...ProfileController.destroy.form()}
                                    options={{
                                        preserveScroll: true,
                                    }}
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

                {/* Member since */}
                {memberSince && (
                    <p className="text-center text-sm text-muted-foreground">
                        Member since {memberSince}
                    </p>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
