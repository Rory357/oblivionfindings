import { AuthStatusPanel } from '@/components/auth/auth-status-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthJourneyLayout, {
    type AuthAudience,
} from '@/layouts/auth/auth-journey-layout';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { Form, Link } from '@inertiajs/react';
import { Check, Mail } from 'lucide-react';

/** "Confirm your email address" before first use. */
export default function VerifyEmail({
    status,
    email,
    audience = 'staff',
}: {
    status?: string | null;
    email?: string | null;
    audience?: AuthAudience;
}) {
    return (
        <AuthJourneyLayout
            audience={audience}
            title="Confirm your email address"
        >
            {status === 'verification-link-sent' && (
                <Alert variant="success">
                    <Check />
                    <AlertDescription>
                        We've sent a new link. Check your email.
                    </AlertDescription>
                </Alert>
            )}
            <AuthStatusPanel
                icon={Mail}
                title="Confirm your email address"
                actions={
                    <Form action={send()} className="flex flex-col gap-3">
                        {({ processing }) => (
                            <>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="journey-control w-full"
                                    disabled={processing}
                                >
                                    Send the link again
                                </Button>
                                <Link
                                    href={logout()}
                                    method="post"
                                    as="button"
                                    className="self-center text-[14px] font-semibold text-primary hover:underline"
                                >
                                    Sign out
                                </Link>
                            </>
                        )}
                    </Form>
                }
            >
                <p>
                    Open the link we sent to{' '}
                    {email ? (
                        <span className="font-semibold text-foreground">
                            {email}
                        </span>
                    ) : (
                        'your email'
                    )}{' '}
                    to finish setting up your account.
                </p>
            </AuthStatusPanel>
        </AuthJourneyLayout>
    );
}
