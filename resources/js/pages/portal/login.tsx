import { AuthStatusPanel } from '@/components/auth/auth-status-panel';
import { PasswordField } from '@/components/auth/password-field';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthJourneyLayout from '@/layouts/auth/auth-journey-layout';
import { login as staffLogin } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { google, microsoft } from '@/routes/portal/auth';
import { store as requestSignInLink } from '@/routes/portal/sign-in-link';
import { Link, useForm, usePage } from '@inertiajs/react';
import {
    Calendar,
    Check,
    CircleAlert,
    Clock,
    FileCheck,
    Globe,
    Image,
    Info,
    Mail,
    MessageCircle,
    Shield,
} from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

interface PortalLoginProps {
    ssoProviders?: { microsoft: boolean; google: boolean };
    status?: string | null;
    linkSentTo?: string | null;
    notice?: string | null;
    passwordChanged?: boolean;
    retryAfter?: number | null;
}

const CREDENTIALS_FAILED = 'These credentials do not match our records.';

const PORTAL_FEATURES = [
    { icon: Image, label: 'Updates and photos' },
    { icon: Calendar, label: 'Visits' },
    { icon: MessageCircle, label: 'Messages' },
    { icon: FileCheck, label: 'Documents and consent' },
] as const;

function formatWait(seconds: number): string {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/**
 * Family portal sign-in (approved half-and-half design, 13 Oct 2026). An
 * emailed sign-in link comes first (families sign in rarely and forget
 * passwords); a password is the second choice.
 */
export default function PortalLogin({
    ssoProviders,
    linkSentTo,
    notice,
    passwordChanged = false,
    retryAfter,
}: PortalLoginProps) {
    const { errors, flash } = usePage<{
        errors: Record<string, string>;
        flash?: { success?: string | null };
    }>().props;
    const form = useForm({ email: '', password: '', remember: false });
    const [mode, setMode] = useState<'start' | 'password'>('start');
    const [dismissed, setDismissed] = useState(false);
    // An error that arrived by redirect hides once the person starts again.
    const [redirectErrorSeen, setRedirectErrorSeen] = useState(false);
    const [capsLock, setCapsLock] = useState(false);
    const [wait, setWait] = useState(retryAfter ?? 0);
    const passwordRef = useRef<HTMLInputElement>(null);

    useEffect(() => setDismissed(false), [linkSentTo, passwordChanged]);
    useEffect(() => setRedirectErrorSeen(false), [errors.email]);
    useEffect(() => setWait(retryAfter ?? 0), [retryAfter]);
    useEffect(() => {
        if (wait <= 0) return;
        const timer = window.setTimeout(
            () => setWait((value) => value - 1),
            1000,
        );

        return () => window.clearTimeout(timer);
    }, [wait]);
    useEffect(() => {
        if (mode === 'password') passwordRef.current?.focus();
    }, [mode]);

    const panel = dismissed
        ? null
        : linkSentTo
          ? 'sent'
          : passwordChanged
            ? 'changed'
            : null;
    const providers = (['google', 'microsoft'] as const).filter(
        (provider) => ssoProviders?.[provider],
    );
    const providerError = errors.google || errors.microsoft;

    const sendLink = (email: string) => {
        form.clearErrors();
        form.transform(() => ({ email }));
        form.post(requestSignInLink().url, { preserveScroll: true });
    };

    const onSendLink = (event: FormEvent) => {
        event.preventDefault();
        const email = form.data.email.trim().toLowerCase();
        if (!/^\S+@\S+\.\S+$/.test(email)) {
            form.setError('email', 'Enter your email address.');

            return;
        }
        sendLink(email);
    };

    const usePassword = () => {
        const email = form.data.email.trim().toLowerCase();
        if (!/^\S+@\S+\.\S+$/.test(email)) {
            form.setError('email', 'Enter your email address first.');

            return;
        }
        form.clearErrors();
        form.setData('email', email);
        setMode('password');
    };

    const onSignIn = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => data);
        form.post(store().url, { onFinish: () => form.reset('password') });
    };

    const startOver = () => {
        setDismissed(true);
        setRedirectErrorSeen(true);
        setMode('start');
        form.reset('password');
        form.clearErrors();
    };

    // The password step shows this form's own errors (cleared by "Change").
    const passwordError =
        form.errors.email === CREDENTIALS_FAILED
            ? "That email and password don't match. Try again, or ask for a sign-in link instead."
            : form.errors.email;

    return (
        <AuthJourneyLayout
            audience="family"
            title="Family portal sign in"
            lede="See how your family member is doing and keep in touch with their support team, wherever you are."
            signing={form.processing && mode === 'password'}
            brandFooter={
                <ul
                    aria-label="In the family portal"
                    className="flex max-w-[260px] flex-col items-start gap-2"
                >
                    {PORTAL_FEATURES.map(({ icon: Icon, label }) => (
                        <li key={label} className="journey-chip">
                            <Icon aria-hidden="true" className="size-[17px]" />
                            {label}
                        </li>
                    ))}
                </ul>
            }
            footer={
                <span>
                    Staff member?{' '}
                    <Link
                        href={staffLogin()}
                        className="font-semibold text-primary hover:underline"
                    >
                        Staff sign in
                    </Link>
                </span>
            }
        >
            {panel === 'sent' && (
                <AuthStatusPanel
                    icon={Mail}
                    title="Check your email"
                    actions={
                        <div className="flex flex-wrap gap-5">
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0 text-[15px]"
                                disabled={form.processing}
                                onClick={() => sendLink(linkSentTo ?? '')}
                            >
                                Send it again
                            </Button>
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0 text-[15px]"
                                onClick={startOver}
                            >
                                Use a different email
                            </Button>
                        </div>
                    }
                >
                    <p>
                        If{' '}
                        <span className="font-semibold text-foreground">
                            {linkSentTo}
                        </span>{' '}
                        has a family portal account, we've sent a link to sign
                        in. It works for 15 minutes, on this device.
                    </p>
                    <p className="text-[15px]">
                        Can't see it? Check your junk or spam folder.
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
                            onClick={startOver}
                        >
                            Sign in
                        </Button>
                    }
                >
                    <p>Your new password is saved. Sign in with it now.</p>
                </AuthStatusPanel>
            )}

            {panel === null && (
                <>
                    <div className="flex flex-col gap-1.5">
                        <h1 className="journey-title">Sign in</h1>
                        <p className="journey-subtitle">
                            For whānau, family and the people we support.
                        </p>
                    </div>

                    {(notice || flash?.success) && (
                        // e.g. "Your account is awaiting approval by staff."
                        // after a first Google/Microsoft sign-in.
                        <Alert variant="info">
                            <Info />
                            <AlertDescription>
                                {notice || flash?.success}
                            </AlertDescription>
                        </Alert>
                    )}
                    {mode === 'start' &&
                        errors.email &&
                        !form.errors.email &&
                        !redirectErrorSeen && (
                            <Alert variant="critical">
                                <CircleAlert />
                                <AlertDescription>
                                    {errors.email}
                                </AlertDescription>
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
                    {(errors.link || providerError) && (
                        <Alert variant="critical">
                            <CircleAlert />
                            <AlertDescription>
                                {errors.link || providerError}
                            </AlertDescription>
                        </Alert>
                    )}

                    {mode === 'start' && (
                        <form
                            onSubmit={onSendLink}
                            className="flex flex-col gap-3.5"
                            noValidate
                        >
                            <div className="flex flex-col gap-2">
                                <Label
                                    htmlFor="email"
                                    className="journey-label"
                                >
                                    Email address
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                    placeholder="name@example.com"
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
                                disabled={form.processing}
                            >
                                <Mail aria-hidden="true" />
                                Email me a sign-in link
                            </Button>
                            <p className="journey-hint">
                                No password to remember: we email you a link
                                that signs you in on this device.
                            </p>
                            <Button
                                type="button"
                                variant="link"
                                className="self-center text-[15px]"
                                onClick={usePassword}
                            >
                                Use my password instead
                            </Button>
                        </form>
                    )}

                    {mode === 'password' && (
                        <form
                            onSubmit={onSignIn}
                            className="flex flex-col gap-4"
                        >
                            <div className="journey-who">
                                <span className="min-w-0 truncate">
                                    {form.data.email}
                                </span>
                                <Button
                                    type="button"
                                    variant="link"
                                    className="h-auto p-0"
                                    onClick={startOver}
                                >
                                    Change
                                </Button>
                            </div>
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
                            <div className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between gap-3">
                                    <Label
                                        htmlFor="password"
                                        className="journey-label"
                                    >
                                        Password
                                    </Label>
                                    <Link
                                        href={request({
                                            query: { for: 'family' },
                                        })}
                                        className="text-[14.5px] font-semibold text-primary hover:underline"
                                    >
                                        Forgot your password?
                                    </Link>
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
                                    disabled={wait > 0}
                                />
                                {capsLock && (
                                    <span className="text-[13px] font-semibold text-status-warning">
                                        Caps Lock is on
                                    </span>
                                )}
                            </div>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="remember"
                                    checked={form.data.remember}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'remember',
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5 size-5"
                                />
                                <div className="flex flex-col gap-0.5">
                                    <Label
                                        htmlFor="remember"
                                        className="text-[15px] font-medium"
                                    >
                                        Keep me signed in
                                    </Label>
                                    <span className="journey-hint">
                                        Only on your own phone or computer.
                                    </span>
                                </div>
                            </div>
                            <Button
                                type="submit"
                                className="journey-control w-full"
                                disabled={form.processing || wait > 0}
                            >
                                {wait > 0
                                    ? `Try again in ${formatWait(wait)}`
                                    : form.processing
                                      ? 'Signing in…'
                                      : 'Sign in'}
                            </Button>
                            <Button
                                type="button"
                                variant="link"
                                className="self-center text-[15px]"
                                onClick={() => sendLink(form.data.email)}
                            >
                                Email me a sign-in link instead
                            </Button>
                        </form>
                    )}

                    {providers.length > 0 && (
                        <div className="flex flex-col gap-3">
                            <div className="journey-divider">
                                or continue with
                            </div>
                            <div className="grid grid-cols-2 gap-2.5">
                                {providers.map((provider) => (
                                    <Button
                                        key={provider}
                                        asChild
                                        variant="outline"
                                        className="journey-control"
                                    >
                                        <a
                                            href={
                                                (provider === 'google'
                                                    ? google()
                                                    : microsoft()
                                                ).url
                                            }
                                        >
                                            {provider === 'google' ? (
                                                <Globe aria-hidden="true" />
                                            ) : (
                                                <Shield aria-hidden="true" />
                                            )}
                                            {provider === 'google'
                                                ? 'Google'
                                                : 'Microsoft'}
                                        </a>
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}

                    <p className="journey-note">
                        <span className="font-semibold text-foreground">
                            First time here?
                        </span>{' '}
                        Open the invitation email we sent you. No invitation
                        yet? Ask your family member's coordinator.
                    </p>
                </>
            )}
        </AuthJourneyLayout>
    );
}
