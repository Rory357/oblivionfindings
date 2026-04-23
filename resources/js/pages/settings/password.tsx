import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit, update } from '@/routes/user-password';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Form, Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Check, X } from 'lucide-react';
import { cn } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: edit().url,
    },
];

interface PasswordRequirement {
    label: string;
    test: (password: string) => boolean;
}

const requirements: PasswordRequirement[] = [
    { label: 'At least 8 characters', test: (p) => p.length >= 8 },
    { label: 'One uppercase letter', test: (p) => /[A-Z]/.test(p) },
    { label: 'One lowercase letter', test: (p) => /[a-z]/.test(p) },
    { label: 'One number', test: (p) => /[0-9]/.test(p) },
    { label: 'One special character', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

function getStrength(password: string): { level: number; label: string; color: string; bgColor: string } {
    if (!password) return { level: 0, label: '', color: '', bgColor: '' };
    const passed = requirements.filter((r) => r.test(password)).length;
    if (passed <= 1) return { level: 1, label: 'Weak', color: 'bg-red-500', bgColor: 'text-red-600' };
    if (passed <= 2) return { level: 2, label: 'Fair', color: 'bg-amber-500', bgColor: 'text-amber-600' };
    if (passed <= 4) return { level: 3, label: 'Strong', color: 'bg-green-500', bgColor: 'text-green-600' };
    return { level: 4, label: 'Excellent', color: 'bg-emerald-500', bgColor: 'text-emerald-600' };
}

export default function Password() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const [newPassword, setNewPassword] = useState('');

    const strength = useMemo(() => getStrength(newPassword), [newPassword]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Password settings" />

            <SettingsLayout>
                <Card>
                    <CardHeader>
                        <CardTitle>Update Password</CardTitle>
                        <CardDescription>
                            Ensure your account stays secure with a strong password
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            resetOnError={[
                                'password',
                                'password_confirmation',
                                'current_password',
                            ]}
                            resetOnSuccess
                            onError={(errors) => {
                                if (errors.password) {
                                    passwordInput.current?.focus();
                                }
                                if (errors.current_password) {
                                    currentPasswordInput.current?.focus();
                                }
                            }}
                            onSuccess={() => setNewPassword('')}
                            className="space-y-5"
                        >
                            {({ errors, processing, recentlySuccessful }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="current_password">Current password</Label>
                                        <Input
                                            id="current_password"
                                            ref={currentPasswordInput}
                                            name="current_password"
                                            type="password"
                                            className="max-w-md"
                                            autoComplete="current-password"
                                            placeholder="Current password"
                                        />
                                        <InputError message={errors.current_password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">New password</Label>
                                        <Input
                                            id="password"
                                            ref={passwordInput}
                                            name="password"
                                            type="password"
                                            className="max-w-md"
                                            autoComplete="new-password"
                                            placeholder="New password"
                                            onChange={(e) => setNewPassword(e.target.value)}
                                        />

                                        {/* Strength meter */}
                                        {newPassword && (
                                            <div className="max-w-md space-y-2">
                                                <div className="flex items-center gap-2">
                                                    <div className="flex flex-1 gap-1">
                                                        {[1, 2, 3, 4].map((i) => (
                                                            <div
                                                                key={i}
                                                                className={cn(
                                                                    'h-1.5 flex-1 rounded-full transition-colors',
                                                                    i <= strength.level
                                                                        ? strength.color
                                                                        : 'bg-muted',
                                                                )}
                                                            />
                                                        ))}
                                                    </div>
                                                    <span
                                                        className={cn(
                                                            'text-xs font-medium',
                                                            strength.bgColor,
                                                        )}
                                                    >
                                                        {strength.label}
                                                    </span>
                                                </div>
                                            </div>
                                        )}

                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            Confirm password
                                        </Label>
                                        <Input
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            type="password"
                                            className="max-w-md"
                                            autoComplete="new-password"
                                            placeholder="Confirm password"
                                        />
                                        <InputError message={errors.password_confirmation} />
                                    </div>

                                    {/* Requirements checklist */}
                                    {newPassword && (
                                        <div className="max-w-md rounded-lg border bg-muted/30 p-4">
                                            <p className="mb-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                Password requirements
                                            </p>
                                            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                                {requirements.map((req) => {
                                                    const met = req.test(newPassword);
                                                    return (
                                                        <div
                                                            key={req.label}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            {met ? (
                                                                <Check className="h-3.5 w-3.5 text-green-600" />
                                                            ) : (
                                                                <X className="h-3.5 w-3.5 text-muted-foreground/50" />
                                                            )}
                                                            <span
                                                                className={cn(
                                                                    met
                                                                        ? 'text-foreground'
                                                                        : 'text-muted-foreground',
                                                                )}
                                                            >
                                                                {req.label}
                                                            </span>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}

                                    <div className="flex items-center gap-4">
                                        <Button
                                            disabled={processing}
                                            className="bg-violet-600 hover:bg-violet-700"
                                            data-test="update-password-button"
                                        >
                                            Update password
                                        </Button>

                                        <Transition
                                            show={recentlySuccessful}
                                            enter="transition ease-in-out"
                                            enterFrom="opacity-0"
                                            leave="transition ease-in-out"
                                            leaveTo="opacity-0"
                                        >
                                            <p className="text-sm text-green-600">
                                                Password updated
                                            </p>
                                        </Transition>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </SettingsLayout>
        </AppLayout>
    );
}
