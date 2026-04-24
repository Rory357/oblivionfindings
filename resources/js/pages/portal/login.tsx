import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function PortalLogin() {
    const { flash } = usePage().props as any;

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
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">Oblivion Findings</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Client & Family Portal</p>
                    </div>

                    <Card>
                        <CardHeader className="text-center">
                            <CardTitle>Sign in to your account</CardTitle>
                            <CardDescription>
                                Access your care information and communicate with your support team
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
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        placeholder="you@example.com"
                                        autoComplete="email"
                                    />
                                    {form.errors.email && (
                                        <p className="text-sm text-status-critical">{form.errors.email}</p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                        placeholder="Your password"
                                        autoComplete="current-password"
                                    />
                                    {form.errors.password && (
                                        <p className="text-sm text-status-critical">{form.errors.password}</p>
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

                            {/* Divider */}
                            <div className="relative">
                                <div className="absolute inset-0 flex items-center">
                                    <div className="w-full border-t" />
                                </div>
                                <div className="relative flex justify-center text-xs uppercase">
                                    <span className="bg-white px-2 text-muted-foreground">or continue with</span>
                                </div>
                            </div>

                            {/* SSO Buttons */}
                            <div className="grid gap-3">
                                <Button
                                    variant="outline"
                                    className="w-full border-status-info/30 bg-status-info-bg text-status-info hover:bg-status-info-bg"
                                    asChild
                                >
                                    <a href="/portal/auth/microsoft/redirect">
                                        <svg className="mr-2 h-4 w-4" viewBox="0 0 21 21" fill="none">
                                            <rect x="1" y="1" width="9" height="9" fill="#F25022" />
                                            <rect x="11" y="1" width="9" height="9" fill="#7FBA00" />
                                            <rect x="1" y="11" width="9" height="9" fill="#00A4EF" />
                                            <rect x="11" y="11" width="9" height="9" fill="#FFB900" />
                                        </svg>
                                        Sign in with Microsoft
                                    </a>
                                </Button>
                                <Button
                                    variant="outline"
                                    className="w-full"
                                    asChild
                                >
                                    <a href="/portal/auth/google/redirect">
                                        <svg className="mr-2 h-4 w-4" viewBox="0 0 24 24">
                                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4" />
                                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                                        </svg>
                                        Sign in with Google
                                    </a>
                                </Button>
                            </div>

                            {/* No registration notice */}
                            <p className="text-muted-foreground text-center text-xs">
                                Don't have an account? Contact your support provider to get access.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
