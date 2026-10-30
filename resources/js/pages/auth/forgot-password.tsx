import { AuthStatusPanel } from '@/components/auth/auth-status-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthJourneyLayout, {
    type AuthAudience,
} from '@/layouts/auth/auth-journey-layout';
import { useI18n } from '@/lib/i18n';
import { login } from '@/routes';
import { email as sendResetLink } from '@/routes/password';
import { login as portalLogin } from '@/routes/portal';
import { store as requestSignInLink } from '@/routes/portal/sign-in-link';
import { Link, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { type FormEvent } from 'react';

interface ForgotPasswordProps {
    status?: string | null;
    /** The address just submitted ("check your email" never says whether it has an account). */
    sentTo?: string | null;
    audience?: AuthAudience;
    ssoRouting?: Array<{ domain: string; provider: 'microsoft' | 'google' }>;
}

/** Forgot password, staff or family (?for=family). Steps 1 and 2 of the reset flow. */
export default function ForgotPassword({
    sentTo,
    audience = 'staff',
    ssoRouting = [],
}: ForgotPasswordProps) {
    const { t } = useI18n();
    const family = audience === 'family';
    const form = useForm({ email: '' });
    const backToSignIn = family ? portalLogin() : login();
    const ssoProvider =
        ssoRouting[0]?.provider === 'google' ? 'Google' : 'Microsoft';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => data);
        form.post(sendResetLink().url, { preserveScroll: true });
    };

    return (
        <AuthJourneyLayout
            audience={audience}
            title={t('app.auth.forgot.title', 'Reset your password')}
        >
            {sentTo ? (
                <AuthStatusPanel
                    icon={Mail}
                    title="Check your email"
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                className="journey-control w-full"
                                disabled={form.processing}
                                onClick={() => {
                                    form.transform(() => ({ email: sentTo }));
                                    form.post(sendResetLink().url, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                Send it again
                            </Button>
                            <Link
                                href={backToSignIn}
                                className="self-center text-[14px] font-semibold text-primary hover:underline"
                            >
                                {t(
                                    'app.auth.forgot.return_link',
                                    'Back to sign in',
                                )}
                            </Link>
                        </>
                    }
                >
                    <p>
                        If{' '}
                        <span className="font-semibold text-foreground">
                            {sentTo}
                        </span>{' '}
                        has an account, we've sent a link to reset your
                        password. It works for 60 minutes.
                    </p>
                </AuthStatusPanel>
            ) : (
                <>
                    <div className="flex flex-col gap-1.5">
                        <h1 className="journey-title">
                            {t('app.auth.forgot.title', 'Reset your password')}
                        </h1>
                        <p className="journey-subtitle">
                            {family
                                ? "Enter the email you use for the family portal. We'll send you a link to choose a new password."
                                : t(
                                      'app.auth.forgot.description',
                                      "Enter the email you sign in with. We'll send you a link to choose a new password.",
                                  )}
                        </p>
                    </div>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="email" className="journey-label">
                                {t('app.auth.email_address', 'Email address')}
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="username"
                                autoFocus
                                required
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                className="journey-control"
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        <Button
                            type="submit"
                            className="journey-control w-full"
                            disabled={form.processing}
                            data-test="email-password-reset-link-button"
                        >
                            {t(
                                'app.auth.forgot.submit',
                                'Email me a reset link',
                            )}
                        </Button>
                        {family ? (
                            <Button
                                type="button"
                                variant="link"
                                className="self-center text-[15px]"
                                onClick={() => {
                                    form.transform((data) => ({
                                        email: data.email,
                                    }));
                                    form.post(requestSignInLink().url);
                                }}
                            >
                                Email me a sign-in link instead
                            </Button>
                        ) : (
                            ssoRouting.length > 0 && (
                                <p className="journey-hint">
                                    Sign in with {ssoProvider}? Reset your
                                    password with {ssoProvider} instead.
                                </p>
                            )
                        )}
                    </form>
                    <Link
                        href={backToSignIn}
                        className="self-center text-[14px] font-semibold text-primary hover:underline"
                    >
                        {t('app.auth.forgot.return_link', 'Back to sign in')}
                    </Link>
                </>
            )}
        </AuthJourneyLayout>
    );
}
