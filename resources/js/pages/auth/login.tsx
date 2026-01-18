import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/react';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: LoginProps) {
    return (
        <AuthLayout
            title="Log in to your account"
            description="Enter your email and password below to log in"
        >
            <Head title="Log in" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Forgot password?
                                        </TextLink>
                                    )}
                                </div>
                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        <div className="flex flex-col gap-3">
                            {/* Microsoft SSO */}
                            <Button
                                asChild
                                variant="outline"
                                className="flex w-full items-center justify-center gap-3"
                            >
                                <a href="/auth/microsoft/redirect">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 23 23"
                                        aria-hidden
                                    >
                                        <rect
                                            x="1"
                                            y="1"
                                            width="10"
                                            height="10"
                                            fill="#F25022"
                                        />
                                        <rect
                                            x="12"
                                            y="1"
                                            width="10"
                                            height="10"
                                            fill="#7FBA00"
                                        />
                                        <rect
                                            x="1"
                                            y="12"
                                            width="10"
                                            height="10"
                                            fill="#00A4EF"
                                        />
                                        <rect
                                            x="12"
                                            y="12"
                                            width="10"
                                            height="10"
                                            fill="#FFB900"
                                        />
                                    </svg>
                                    Continue with Microsoft (Organization)
                                </a>
                            </Button>

                            {/* Google signup */}
                            <Button
                                asChild
                                variant="outline"
                                className="flex w-full items-center justify-center gap-3"
                            >
                                <a href="/auth/google/redirect">
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 48 48"
                                        aria-hidden
                                    >
                                        <path
                                            fill="#FFC107"
                                            d="M43.6 20.4H42V20H24v8h11.3C33.7 32.6 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.3 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.2-.4-3.6z"
                                        />
                                        <path
                                            fill="#FF3D00"
                                            d="M6.3 14.7l6.6 4.8C14.6 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.3 29.6 4 24 4c-7.7 0-14.4 4.3-17.7 10.7z"
                                        />
                                        <path
                                            fill="#4CAF50"
                                            d="M24 44c5.1 0 9.9-2 13.4-5.3l-6.2-5.2C29.1 35.6 26.7 36 24 36c-5.2 0-9.6-3.4-11.2-8.1l-6.6 5.1C9.4 39.7 16.2 44 24 44z"
                                        />
                                        <path
                                            fill="#1976D2"
                                            d="M43.6 20.4H42V20H24v8h11.3c-1.3 3.6-4.5 6.4-8.3 7.5l.1.1 6.2 5.2c-.4.4 7.4-5.4 7.4-16.8z"
                                        />
                                    </svg>
                                    Continue with Google
                                </a>
                            </Button>

                            <Separator />
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                Don't have an account?{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    Sign up
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
