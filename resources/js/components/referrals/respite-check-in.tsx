import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { InternalSupportPreparation } from './internal-support-preparation';

export type RespitePreparation = {
    person_id: number;
    site_id: number;
    service_context_id: number;
};

export function RespiteCheckIn({
    stayId,
    reviewUrl,
    preparation,
}: {
    stayId: number;
    reviewUrl?: string | null;
    preparation?: RespitePreparation | null;
}) {
    const submitting = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const checkIn = () => {
        if (submitting.current) return;
        submitting.current = true;
        setBusy(true);
        setError(null);
        router.post(
            `/respite/stays/${stayId}/check-in`,
            {},
            {
                preserveScroll: true,
                errorBag: 'respiteCheckIn',
                onError: (errors) =>
                    setError(
                        Object.values(errors).join(' ') ||
                            'Check-in could not be completed. Review the current stay before trying again.',
                    ),
                onFinish: () => {
                    submitting.current = false;
                    setBusy(false);
                },
            },
        );
    };

    return (
        <div className="w-full space-y-3">
            {error && (
                <Alert variant="warning">
                    <AlertTitle>Check-in not completed</AlertTitle>
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            <p className="text-subtle">
                Check in when the person arrives and support begins. Current
                service-start and admission checks must be satisfied.
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    className="frontline-tap"
                    disabled={busy}
                    onClick={checkIn}
                >
                    {busy ? 'Checking in…' : 'Check in'}
                </Button>
                {reviewUrl && (
                    <Button
                        size="sm"
                        variant="outline"
                        className="frontline-tap"
                        asChild
                    >
                        <Link href={reviewUrl}>Review service start</Link>
                    </Button>
                )}
            </div>
            {!reviewUrl && preparation && (
                <InternalSupportPreparation
                    personId={preparation.person_id}
                    defaultSiteId={preparation.site_id}
                    defaultContextId={preparation.service_context_id}
                />
            )}
        </div>
    );
}
