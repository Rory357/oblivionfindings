// Components
import { login } from '@/routes';
import { email } from '@/routes/password';
import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { useI18n } from '@/lib/i18n';

export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useI18n();

    return (
        <AuthLayout
            title={t('app.auth.forgot.title', 'Forgot password')}
            description={t(
                'app.auth.forgot.description',
                'Enter your email to receive a password reset link',
            )}
        >
            <Head title={t('app.auth.forgot_password', 'Forgot password')} />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-status-success">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form action={email()}>
                    {({ processing, errors }) => (
                        <>
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
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="email@example.com"
                                    className="bg-background/80"
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    )}
                                    {t(
                                        'app.auth.forgot.submit',
                                        'Email password reset link',
                                    )}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="space-x-1 text-center text-sm text-muted-foreground">
                    <span>
                        {t('app.auth.forgot.return_prefix', 'Or, return to')}
                    </span>
                    <TextLink href={login()}>
                        {t('app.auth.forgot.return_link', 'log in')}
                    </TextLink>
                </div>
            </div>
        </AuthLayout>
    );
}
