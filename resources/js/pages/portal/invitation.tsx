import { AuthStatusPanel } from '@/components/auth/auth-status-panel';
import { JourneySteps } from '@/components/auth/journey-steps';
import { PasswordField } from '@/components/auth/password-field';
import {
    PasswordRules,
    type PasswordRequirements,
} from '@/components/auth/password-rules';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthJourneyLayout from '@/layouts/auth/auth-journey-layout';
import { login as portalLogin } from '@/routes/portal';
import { accept, resend, sso } from '@/routes/portal/invitations';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Calendar,
    Check,
    CircleAlert,
    FileCheck,
    Globe,
    Image,
    Mail,
    MessageCircle,
    Shield,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

type InvitationProps =
    | { state: 'invalid'; token: string }
    | { state: 'expired'; token: string; maskedEmail: string; resent: boolean }
    | {
          state: 'active';
          token: string;
          email: string;
          inviterName: string | null;
          clientFirstName: string | null;
          expiresOn: string;
          ssoProviders: { microsoft: boolean; google: boolean };
          passwordRules: PasswordRequirements;
      };

const PORTAL_FEATURES = [
    { icon: Image, label: 'Updates and photos' },
    { icon: Calendar, label: 'Visits' },
    { icon: MessageCircle, label: 'Messages' },
    { icon: FileCheck, label: 'Documents and consent' },
] as const;

const portalChips = (
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
);

/** The family invitation page (7-day link): choose a password or link Google/Microsoft. */
export default function PortalInvitation(props: InvitationProps) {
    if (props.state === 'invalid') {
        return (
            <AuthJourneyLayout
                audience="family"
                title="Invitation"
                brandFooter={portalChips}
            >
                <AuthStatusPanel
                    icon={CircleAlert}
                    title="This invitation link doesn't work"
                    actions={
                        <Button asChild className="journey-control w-full">
                            <Link href={portalLogin()}>Go to sign in</Link>
                        </Button>
                    }
                >
                    <p>
                        It may have been replaced by a newer invitation. Use the
                        link in the most recent email, or ask your family
                        member's coordinator to send a new one.
                    </p>
                </AuthStatusPanel>
            </AuthJourneyLayout>
        );
    }

    if (props.state === 'expired') {
        return <ExpiredInvitation {...props} />;
    }

    return <ActiveInvitation {...props} />;
}

function ExpiredInvitation({
    token,
    maskedEmail,
    resent,
}: {
    token: string;
    maskedEmail: string;
    resent: boolean;
}) {
    const [sending, setSending] = useState(false);

    return (
        <AuthJourneyLayout
            audience="family"
            title="Invitation expired"
            brandFooter={portalChips}
            footer={
                <span>
                    Or ask your family member's coordinator for a new
                    invitation.
                </span>
            }
        >
            <JourneySteps
                steps={['Invitation', 'Your sign-in', 'Family portal']}
                current={0}
                expired
                label="Invitation expired"
            />
            {resent && (
                <Alert variant="success">
                    <Check />
                    <AlertDescription>
                        We've sent a new invitation. Check your email.
                    </AlertDescription>
                </Alert>
            )}
            <AuthStatusPanel
                icon={Mail}
                title="This invitation has expired"
                actions={
                    <Button
                        type="button"
                        className="journey-control w-full"
                        disabled={sending || resent}
                        onClick={() =>
                            router.post(
                                resend(token).url,
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setSending(true),
                                    onFinish: () => setSending(false),
                                },
                            )
                        }
                    >
                        Email me a new link
                    </Button>
                }
            >
                <p>
                    Invitations last 7 days. We can email a new link to{' '}
                    {maskedEmail}.
                </p>
            </AuthStatusPanel>
        </AuthJourneyLayout>
    );
}

function ActiveInvitation({
    token,
    email,
    inviterName,
    clientFirstName,
    expiresOn,
    ssoProviders,
    passwordRules,
}: Extract<InvitationProps, { state: 'active' }>) {
    const form = useForm({ password: '', password_confirmation: '' });
    const providers = (['google', 'microsoft'] as const).filter(
        (provider) => ssoProviders[provider],
    );
    const pageName = clientFirstName
        ? `${clientFirstName}'s page`
        : 'Family portal';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(accept(token).url, {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthJourneyLayout
            audience="family"
            title="Set up your account"
            lede={`Your family portal account is nearly ready. Choose how you'll sign in, then you'll go straight to ${clientFirstName ? `${clientFirstName}'s page` : 'the family portal'}.`}
            brandFooter={portalChips}
            signing={form.processing}
            footer={
                <span>
                    This invitation works until {expiresOn}. Not expecting it?
                    Don't use the link, and let us know.
                </span>
            }
        >
            <JourneySteps
                steps={['Invitation', 'Your sign-in', pageName]}
                current={1}
                label="Step 2 of 3: choose how you sign in"
            />
            <div className="flex flex-col gap-1.5">
                <h1 className="journey-title">Set up your account</h1>
                <p className="journey-subtitle">
                    {inviterName
                        ? `${inviterName} invited you`
                        : 'You have been invited'}
                    {clientFirstName
                        ? ` to follow ${clientFirstName}'s support`
                        : ''}
                    . You'll sign in as{' '}
                    <span className="font-semibold text-foreground">
                        {email}
                    </span>
                    .
                </p>
            </div>

            <form onSubmit={submit} className="flex flex-col gap-3.5">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="password" className="journey-label">
                        Choose a password
                    </Label>
                    <PasswordField
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        aria-describedby="invitation-password-rules"
                        aria-invalid={
                            Boolean(form.errors.password) || undefined
                        }
                    />
                    <PasswordRules
                        id="invitation-password-rules"
                        rules={passwordRules}
                        value={form.data.password}
                    />
                    <InputError message={form.errors.password} />
                </div>
                <div className="flex flex-col gap-2">
                    <Label
                        htmlFor="password_confirmation"
                        className="journey-label"
                    >
                        Type it again
                    </Label>
                    <PasswordField
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'password_confirmation',
                                event.target.value,
                            )
                        }
                    />
                </div>
                <Button
                    type="submit"
                    className="journey-control w-full"
                    disabled={form.processing}
                >
                    {form.processing
                        ? 'Setting up your account…'
                        : 'Set password and continue'}
                </Button>
            </form>

            {providers.length > 0 && (
                <div className="flex flex-col gap-2.5">
                    <div className="journey-divider">or skip the password</div>
                    <div className="grid grid-cols-2 gap-2.5">
                        {providers.map((provider) => (
                            <Button
                                key={provider}
                                asChild
                                variant="outline"
                                className="journey-control"
                            >
                                <a href={sso({ token, provider }).url}>
                                    {provider === 'google' ? (
                                        <Globe aria-hidden="true" />
                                    ) : (
                                        <Shield aria-hidden="true" />
                                    )}
                                    Use{' '}
                                    {provider === 'google'
                                        ? 'Google'
                                        : 'Microsoft'}
                                </a>
                            </Button>
                        ))}
                    </div>
                    <p className="journey-hint">
                        Use the{' '}
                        {providers
                            .map((provider) =>
                                provider === 'google' ? 'Google' : 'Microsoft',
                            )
                            .join(' or ')}{' '}
                        account for {email}.
                    </p>
                </div>
            )}
        </AuthJourneyLayout>
    );
}
