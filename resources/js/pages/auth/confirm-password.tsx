import { PasswordField } from '@/components/auth/password-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthJourneyLayout, {
    type AuthAudience,
} from '@/layouts/auth/auth-journey-layout';
import { useI18n } from '@/lib/i18n';
import { request } from '@/routes/password';
import { store } from '@/routes/password/confirm';
import { Form, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

/** "Confirm it's you" before sensitive settings (Fortify password confirmation). */
export default function ConfirmPassword({
    audience = 'staff',
}: {
    audience?: AuthAudience;
}) {
    const { t } = useI18n();

    return (
        <AuthJourneyLayout
            audience={audience}
            title={t('app.auth.confirm.head', "Confirm it's you")}
        >
            <div className="flex flex-col gap-4">
                <span className="journey-badge" aria-hidden="true">
                    <ShieldCheck className="size-5" />
                </span>
                <h1 className="journey-title">
                    {t('app.auth.confirm.title', "Confirm it's you")}
                </h1>
                <p className="journey-subtitle">
                    {t(
                        'app.auth.confirm.description',
                        'Enter your password again before you change your security settings.',
                    )}
                </p>
            </div>
            <Form
                action={store()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password" className="journey-label">
                                {t('app.auth.password', 'Password')}
                            </Label>
                            <PasswordField
                                id="password"
                                name="password"
                                autoComplete="current-password"
                                autoFocus
                                required
                                aria-invalid={
                                    Boolean(errors.password) || undefined
                                }
                            />
                            <InputError message={errors.password} />
                        </div>
                        <Button
                            type="submit"
                            className="journey-control w-full"
                            disabled={processing}
                            data-test="confirm-password-button"
                        >
                            {t('app.auth.confirm.submit', 'Confirm')}
                        </Button>
                        <Link
                            href={request()}
                            className="self-center text-[14px] font-semibold text-primary hover:underline"
                        >
                            {t(
                                'app.auth.forgot_password_link',
                                'Forgot password?',
                            )}
                        </Link>
                    </>
                )}
            </Form>
        </AuthJourneyLayout>
    );
}
