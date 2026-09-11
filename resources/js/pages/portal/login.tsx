import {
    SsoSignInButtons,
    type SsoAvailability,
} from '@/components/auth/sso-sign-in-buttons';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function PortalLogin({
    ssoProviders,
}: {
    ssoProviders?: SsoAvailability;
}) {
    const { flash, errors } = usePage().props as any;

    const form = useForm({
        email: '',
        password: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/login');
    }

    return (
        <>
            <Head title="Portal Login" />
            <div className="flex min-h-screen items-center justify-center bg-muted px-4">
                <div className="w-full max-w-md">
                    {/* Branding */}
                    <div className="mb-8 text-center">
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Oblivion Findings
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Client & Family Portal
                        </p>
                    </div>

                    <Card>
                        <CardHeader className="text-center">
                            <CardTitle>Sign in to your account</CardTitle>
                            <CardDescription>
                                Access your care information and communicate
                                with your support team
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Flash messages */}
                            {flash?.success && (
                                <div className="rounded-md bg-status-success-bg p-3 text-sm text-status-success">
                                    {flash.success}
                                </div>
                            )}
                            {flash?.error && (
                                <div className="rounded-md bg-status-critical-bg p-3 text-sm text-status-critical">
                                    {flash.error}
                                </div>
                            )}

                            {/* Traditional login form */}
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="you@example.com"
                                        autoComplete="email"
                                    />
                                    {form.errors.email && (
                                        <p className="text-sm text-status-critical">
                                            {form.errors.email}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={form.data.password}
                                        onChange={(e) =>
                                            form.setData(
                                                'password',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Your password"
                                        autoComplete="current-password"
                                    />
                                    {form.errors.password && (
                                        <p className="text-sm text-status-critical">
                                            {form.errors.password}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    className="w-full bg-primary hover:bg-primary"
                                    disabled={form.processing}
                                >
                                    Sign in
                                </Button>
                            </form>

                            <SsoSignInButtons
                                providers={ssoProviders}
                                audience="portal"
                                errors={errors}
                            />

                            {/* No registration notice */}
                            <p className="text-center text-xs text-muted-foreground">
                                Don't have an account? Contact your support
                                provider to get access.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
