import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Globe, Shield } from 'lucide-react';

export type SsoAvailability = { microsoft: boolean; google: boolean };

export function SsoSignInButtons({
    providers,
    audience = 'staff',
    errors = {},
}: {
    providers?: SsoAvailability;
    audience?: 'staff' | 'portal';
    errors?: { microsoft?: string; google?: string };
}) {
    if (
        !providers?.microsoft &&
        !providers?.google &&
        !errors.microsoft &&
        !errors.google
    )
        return null;
    const prefix = audience === 'portal' ? '/portal' : '';
    return (
        <div className="flex flex-col gap-3" aria-label="Single sign-on">
            {(errors.microsoft || errors.google) && (
                <Alert variant="destructive" role="alert">
                    <AlertDescription>
                        {errors.microsoft || errors.google}
                    </AlertDescription>
                </Alert>
            )}
            {providers?.microsoft && (
                <Button asChild variant="outline">
                    <a href={`${prefix}/auth/microsoft/redirect`}>
                        <Shield aria-hidden="true" />
                        Continue with Microsoft
                    </a>
                </Button>
            )}
            {providers?.google && (
                <Button asChild variant="outline">
                    <a href={`${prefix}/auth/google/redirect`}>
                        <Globe aria-hidden="true" />
                        Continue with Google
                    </a>
                </Button>
            )}
        </div>
    );
}
