import { AuthStatusPanel } from '@/components/auth/auth-status-panel';
import { Button } from '@/components/ui/button';
import AuthJourneyLayout, {
    type AuthAudience,
} from '@/layouts/auth/auth-journey-layout';
import { login } from '@/routes';
import { login as portalLogin } from '@/routes/portal';
import { Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';

/** After signing out (LogoutResponse sends staff here, family with ?for=family). */
export default function SignedOut({
    audience = 'staff',
}: {
    audience?: AuthAudience;
}) {
    const family = audience === 'family';

    return (
        <AuthJourneyLayout audience={audience} title="Signed out">
            <AuthStatusPanel
                icon={LogOut}
                title="You've signed out"
                actions={
                    <Button asChild className="journey-control w-full">
                        <Link href={family ? portalLogin() : login()}>
                            Sign in again
                        </Link>
                    </Button>
                }
            >
                <p>
                    {family
                        ? 'On a shared computer, close the browser as well.'
                        : 'On a shared house computer, close the browser as well so the next person starts fresh.'}
                </p>
            </AuthStatusPanel>
        </AuthJourneyLayout>
    );
}
