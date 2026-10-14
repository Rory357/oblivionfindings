import { AuthStatusPanel } from '@/components/auth/auth-status-panel';
import { PasswordField } from '@/components/auth/password-field';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthJourneyLayout from '@/layouts/auth/auth-journey-layout';
import { useI18n } from '@/lib/i18n';
import { redirect as googleRedirect } from '@/routes/auth/google';
import { redirect as microsoftRedirect } from '@/routes/auth/microsoft';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { login as portalLogin } from '@/routes/portal';
import { Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowUp,
    Check,
    CircleAlert,
    Clock,
    Hourglass,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

type Provider = 'microsoft' | 'google';

interface LoginProps {
    status?: string | null;
    canResetPassword: boolean;
    ssoProviders?: Record<Provider, boolean>;
    ssoRouting?: Array<{ domain: string; provider: Provider }>;
    retryAfter?: number | null;
    notStaffEmail?: string | null;
    awaitingApproval?: boolean;
    passwordChanged?: boolean;
}

const PROVIDER_LABEL: Record<Provider, string> = {
    microsoft: 'Microsoft',
    google: 'Google',
};

/** Fortify's failed-credentials message, shown in plainer words. */
const CREDENTIALS_FAILED = 'These credentials do not match our records.';

function providerUrl(provider: Provider, email?: string): string {
    const route = provider === 'microsoft' ? microsoftRedirect : googleRedirect;

    return route(email ? { query: { login_hint: email } } : undefined).url;
}

function formatWait(seconds: number): string {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/**
 * Staff sign-in (approved half-and-half design, 13 Oct 2026). Email first:
 * an organisation address goes straight to its Microsoft/Google sign-in,
 * anything else continues to a password.
 */
export default function Login({
    status,
    canResetPassword,
    ssoProviders,
    ssoRouting = [],
    retryAfter,
    notStaffEmail,
    awaitingApproval = false,
    passwordChanged = false,
}: LoginProps) {
    const { t } = useI18n();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const form = useForm({ email: '', password: '', remember: false });
    const [step, setStep] = useState<'email' | 'password' | 'sso'>('email');
    const [ssoProvider, setSsoProvider] = useState<Provider | null>(null);
    const [capsLock, setCapsLock] = useState(false);
    const [wait, setWait] = useState(retryAfter ?? 0);
    const [dismissed, setDismissed] = useState(false);
    // An error that arrived by redirect (e.g. access revoked) hides once the
    // person starts again.
    const [redirectErrorSeen, setRedirectErrorSeen] = useState(false);
    const passwordRef = useRef<HTMLInputElement>(null);
    const handoffTimer = useRef<number | null>(null);

    useEffect(() => setRedirectErrorSeen(false), [errors.email]);
    useEffect(
        () => () => {
            if (handoffTimer.current) window.clearTimeout(handoffTimer.current);
        },
        [],
    );

    useEffect(() => setWait(retryAfter ?? 0), [retryAfter]);
    useEffect(() => {
        if (wait <= 0) return;
        const timer = window.setTimeout(
            () => setWait((value) => value - 1),
            1000,
        );

        return () => window.clearTimeout(timer);
    }, [wait]);
    useEffect(
        () => setDismissed(false),
        [awaitingApproval, notStaffEmail, passwordChanged],
    );
    useEffect(() => {
        if (step === 'password') passwordRef.current?.focus();
    }, [step]);

    const panel = dismissed
        ? null
        : notStaffEmail
          ? 'not-staff'
          : awaitingApproval
            ? 'approval'
            : passwordChanged
              ? 'changed'
              : null;

    const otherProviders = (['microsoft', 'google'] as const).filter(
        (provider) => ssoProviders?.[provider],
    );
    const providerError = errors.microsoft || errors.google;
    const signing = form.processing || step === 'sso';

    const backToEmail = () => {
        if (handoffTimer.current) {
            // "Change" during the provider handoff cancels it.
            window.clearTimeout(handoffTimer.current);
            handoffTimer.current = null;
        }
        setDismissed(true);
        setRedirectErrorSeen(true);
        setStep('email');
        setSsoProvider(null);
        form.reset('password');
        form.clearErrors();
    };

    const onContinue = (event: FormEvent) => {
        event.preventDefault();
        const email = form.data.email.trim().toLowerCase();
        if (!/^\S+@\S+\.\S+$/.test(email)) {
            form.setError('email', 'Enter your email address.');

            return;
        }
        form.clearErrors();
        form.setData('email', email);
        const route = ssoRouting.find(
            (candidate) => candidate.domain === email.split('@')[1],
        );
        if (route) {
            setSsoProvider(route.provider);
            setStep('sso');
            // Let the journey start before the provider page takes over.
            handoffTimer.current = window.setTimeout(
                () =>
                    window.location.assign(providerUrl(route.provider, email)),
                900,
            );

            return;
        }
        setStep('password');
    };

    const onSignIn = (event: FormEvent) => {
        event.preventDefault();
        form.post(store().url, { onFinish: () => form.reset('password') });
    };

    // The password step shows this form's own errors (cleared by "Change").
    const passwordError =
        form.errors.email === CREDENTIALS_FAILED
            ? "That email and password don't match. Try again, or reset your password."
            : form.errors.email;

    return (
        <AuthJourneyLayout
            title={t('app.auth.login.head', 'Sign in')}
            lede="Sign in to open My Day: your roster, the people you support, medication rounds and handovers."
            signing={signing}
            footer={
                <>
                    <span>
                        Family or whānau?{' '}
                        <Link
                            href={portalLogin()}
                            className="font-semibold text-primary hover:underline"
                        >
                            Use the family portal
                        </Link>
                    </span>
                    <span className="text-[13px]">
                        Can't sign in? Your manager or IT support can reset your
                        access.
                    </span>
                </>
            }
        >
            {panel === 'not-staff' && (
                <AuthStatusPanel
                    icon={Users}
                    title="No staff account for this email"
                    actions={
                        <>
                            <Button asChild className="journey-control w-full">
                                <Link href={portalLogin()}>
                                    Go to the family portal
                                </Link>
                            </Button>
                            <Button
                                type="button"
                                variant="link"
                                onClick={backToEmail}
                            >
                                Use a different account
                            </Button>
                        </>
                    }
                >
                    <p>
                        {notStaffEmail} isn't a staff account. If you're family
                        or whānau, sign in through the family portal.
                    </p>
                </AuthStatusPanel>
            )}

            {panel === 'approval' && (
                <AuthStatusPanel
                    icon={Hourglass}
                    title="Your account is waiting for approval"
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            className="journey-control w-full"
                            onClick={backToEmail}
                        >
                            Back to sign in
                        </Button>
                    }
                >
                    <p>
                        A manager approves new accounts before the first
                        sign-in. If it's taking a while, check with your
                        manager.
                    </p>
                </AuthStatusPanel>
            )}

            {panel === 'changed' && (
                <AuthStatusPanel
                    icon={Check}
                    title="Password changed"
                    actions={
                        <Button
                            type="button"
                            className="journey-control w-full"
                            onClick={backToEmail}
                        >
                            {t('app.auth.login.submit', 'Sign in')}
                        </Button>
                    }
                >
                    <p>Your new password is saved. Sign in with it now.</p>
                </AuthStatusPanel>
            )}

            {panel === null && (
                <>
                    <div className="flex flex-col gap-1.5">
                        <h1 className="journey-title">
                            {t('app.auth.login.title', 'Sign in')}
                        </h1>
                        {step === 'email' && (
                            <p className="journey-subtitle">
                                {t(
                                    'app.auth.login.description',
                                    'Start with your work email.',
                                )}
                            </p>
                        )}
                    </div>

                    {status && !passwordChanged && (
                        <Alert variant="success">
                            <Check />
                            <AlertDescription>{status}</AlertDescription>
                        </Alert>
                    )}
                    {step === 'email' && errors.email && !redirectErrorSeen && (
                        // Arrives by redirect (e.g. access revoked); the
                        // password step shows its own errors below.
                        <Alert variant="critical">
                            <CircleAlert />
                            <AlertDescription>{errors.email}</AlertDescription>
                        </Alert>
                    )}
                    {errors.session && (
                        <Alert variant="info">
                            <Clock />
                            <AlertDescription>
                                {errors.session}
                            </AlertDescription>
                        </Alert>
                    )}
                    {providerError && (
                        <Alert variant="critical">
                            <CircleAlert />
                            <AlertDescription>{providerError}</AlertDescription>
                        </Alert>
                    )}

                    {step === 'email' && (
                        <form
                            onSubmit={onContinue}
                            className="flex flex-col gap-4"
                            noValidate
                        >
                            <div className="flex flex-col gap-2">
                                <Label
                                    htmlFor="email"
                                    className="journey-label"
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
                                    autoComplete="username"
                                    autoFocus
                                    required
                                    placeholder="you@oblivioncare.co.nz"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        Boolean(form.errors.email) || undefined
                                    }
                                    className="journey-control"
                                />
                                <InputError message={form.errors.email} />
                            </div>
                            <Button
                                type="submit"
                                className="journey-control w-full"
                                data-test="login-continue"
                            >
                                {t('app.auth.login.continue', 'Continue')}
                            </Button>
                            {otherProviders.length > 0 && (
                                <p className="journey-hint text-center">
                                    Other ways to sign in:{' '}
                                    {otherProviders.map((provider, index) => (
                                        <span key={provider}>
                                            {index > 0 && ' · '}
                                            <a
                                                href={providerUrl(provider)}
                                                className="font-semibold text-primary hover:underline"
                                            >
                                                {PROVIDER_LABEL[provider]}
                                            </a>
                                        </span>
                                    ))}
                                </p>
                            )}
                        </form>
                    )}

                    {step === 'password' && (
                        <form
                            onSubmit={onSignIn}
                            className="flex flex-col gap-4"
                        >
                            {passwordError && (
                                <Alert
                                    variant={wait > 0 ? 'warning' : 'critical'}
                                >
                                    {wait > 0 ? <Clock /> : <CircleAlert />}
                                    <AlertDescription>
                                        {passwordError}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="journey-who">
                                <span className="min-w-0 truncate">
                                    {form.data.email}
                                </span>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="h-auto p-0"
                                    onClick={backToEmail}
                                >
                                    Change
                                </Button>
                            </div>

                            <div className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between gap-3">
                                    <Label
                                        htmlFor="password"
                                        className="journey-label"
                                    >
                                        {t('app.auth.password', 'Password')}
                                    </Label>
                                    {canResetPassword && (
                                        <Link
                                            href={request()}
                                            className="text-[13.5px] font-semibold text-primary hover:underline"
                                        >
                                            {t(
                                                'app.auth.forgot_password_link',
                                                'Forgot password?',
                                            )}
                                        </Link>
                                    )}
                                </div>
                                <PasswordField
                                    ref={passwordRef}
                                    id="password"
                                    name="password"
                                    autoComplete="current-password"
                                    required
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                    onKeyUp={(event) =>
                                        setCapsLock(
                                            event.getModifierState('CapsLock'),
                                        )
                                    }
                                    aria-invalid={
                                        Boolean(passwordError && wait <= 0) ||
                                        undefined
                                    }
                                    aria-describedby={
                                        capsLock
                                            ? 'caps-lock-warning'
                                            : undefined
                                    }
                                    disabled={wait > 0}
                                />
                                {capsLock && (
                                    <span
                                        id="caps-lock-warning"
                                        className="flex items-center gap-1.5 text-[12.5px] font-semibold text-status-warning"
                                    >
                                        <ArrowUp
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Caps Lock is on
                                    </span>
                                )}
                            </div>

                            <div className="flex items-start gap-2.5">
                                <Checkbox
                                    id="remember"
                                    checked={form.data.remember}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'remember',
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <div className="flex flex-col gap-0.5">
                                    <Label
                                        htmlFor="remember"
                                        className="text-sm font-medium"
                                    >
                                        {t(
                                            'app.auth.login.remember',
                                            'Keep me signed in',
                                        )}
                                    </Label>
                                    <span className="journey-hint">
                                        Leave this off on a shared house
                                        computer.
                                    </span>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="journey-control w-full"
                                disabled={form.processing || wait > 0}
                                data-test="login-button"
                            >
                                {wait > 0
                                    ? `Try again in ${formatWait(wait)}`
                                    : form.processing
                                      ? 'Signing in…'
                                      : t('app.auth.login.submit', 'Sign in')}
                            </Button>
                            {wait > 0 && canResetPassword && (
                                <p className="journey-hint text-center">
                                    Forgotten it?{' '}
                                    <Link
                                        href={request()}
                                        className="font-semibold text-primary hover:underline"
                                    >
                                        Reset it by email
                                    </Link>
                                </p>
                            )}
                        </form>
                    )}

                    {step === 'sso' && ssoProvider && (
                        <div role="status" className="flex flex-col gap-4">
                            <div className="journey-who">
                                <span className="min-w-0 truncate">
                                    {form.data.email}
                                </span>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="h-auto p-0"
                                    onClick={backToEmail}
                                >
                                    Change
                                </Button>
                            </div>
                            <p className="flex items-center gap-2.5 text-[15px] leading-normal">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-[17px] shrink-0 text-primary"
                                />
                                Your organisation signs in with{' '}
                                {PROVIDER_LABEL[ssoProvider]}. Taking you there
                                now…
                            </p>
                            <Button asChild className="journey-control w-full">
                                <a
                                    href={providerUrl(
                                        ssoProvider,
                                        form.data.email,
                                    )}
                                >
                                    Continue to {PROVIDER_LABEL[ssoProvider]}
                                </a>
                            </Button>
                        </div>
                    )}
                </>
            )}
        </AuthJourneyLayout>
    );
}
