import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head } from '@inertiajs/react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { useI18n } from '@/lib/i18n';

export default function Register() {
    const { t } = useI18n();

    return (
        <AuthLayout
            title={t('app.auth.register.title', 'Create an account')}
            description={t(
                'app.auth.register.description',
                'Enter your details below to create your account',
            )}
        >
            <Head title={t('app.auth.register.head', 'Register')} />
            <Form
                action={store()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="name"
                                    className="text-foreground"
                                >
                                    {t('app.auth.register.name', 'Name')}
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t(
                                        'app.auth.full_name',
                                        'Full name',
                                    )}
                                    className="bg-background/80"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

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
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                    className="bg-background/80"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label
                                    htmlFor="password"
                                    className="text-foreground"
                                >
                                    {t('app.auth.password', 'Password')}
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={t(
                                        'app.auth.password',
                                        'Password',
                                    )}
                                    className="bg-background/80"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label
                                    htmlFor="password_confirmation"
                                    className="text-foreground"
                                >
                                    {t(
                                        'app.auth.confirm_password',
                                        'Confirm password',
                                    )}
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={t(
                                        'app.auth.confirm_password',
                                        'Confirm password',
                                    )}
                                    className="bg-background/80"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {t(
                                    'app.auth.register.submit',
                                    'Create account',
                                )}
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {t(
                                'app.auth.register.has_account',
                                'Already have an account?',
                            )}{' '}
                            <TextLink href={login()} tabIndex={6}>
                                {t('app.auth.login.submit', 'Log in')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
