import {
    SsoSignInButtons,
    type SsoAvailability,
} from '@/components/auth/sso-sign-in-buttons';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { useI18n } from '@/lib/i18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/react';

interface LoginProps {
    status?: string;
    ssoProviders?: SsoAvailability;
    canResetPassword: boolean;
    canRegister: boolean;
}

export default function Login({
    status,
    ssoProviders,
    canResetPassword,
    canRegister,
}: LoginProps) {
    const { t } = useI18n();

    return (
        <AuthLayout
            title={t('app.auth.login.title', 'Log in to your account')}
            description={t(
                'app.auth.login.description',
                'Enter your email and password below to log in',
            )}
        >
            <Head title={t('app.auth.login.head', 'Log in')} />

            <Form
                action={store()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="email"
                                    className="text-foreground"
                                >
                                    {t(
                                        'app.auth.email_address',
                                        'Email address',
                                    )}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    className="bg-background/80"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label
                                        htmlFor="password"
                                        className="text-foreground"
                                    >
                                        {t('app.auth.password', 'Password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            {t(
                                                'app.auth.forgot_password_link',
                                                'Forgot password?',
                                            )}
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
                                    placeholder={t(
                                        'app.auth.password',
                                        'Password',
                                    )}
                                    className="bg-background/80"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label
                                    htmlFor="remember"
                                    className="text-foreground"
                                >
                                    {t(
                                        'app.auth.login.remember',
                                        'Remember me',
                                    )}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('app.auth.login.submit', 'Log in')}
                            </Button>
                        </div>

                        <SsoSignInButtons
                            providers={ssoProviders}
                            errors={errors}
                        />

                        {canRegister && (
                            <div className="text-center text-sm text-foreground">
                                {t(
                                    'app.auth.login.no_account',
                                    "Don't have an account?",
                                )}{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    {t('app.auth.login.sign_up', 'Sign up')}
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-status-success">
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
