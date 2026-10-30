import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { CalendarClock, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ServiceStartDialog } from './service-start-dialog';
import type { StartOperation, StartPreview } from './service-start-types';
import { label, referralHref, type ReferralDetail } from './types';

export function ServiceStartPanel({ referral }: { referral: ReferralDetail }) {
    const [preview, setPreview] = useState<StartPreview | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [operation, setOperation] = useState<StartOperation | null>(null);
    const [dialogPreview, setDialogPreview] = useState<StartPreview | null>(
        null,
    );
    function openDecision(next: StartOperation) {
        if (!preview) return;
        setDialogPreview(preview);
        setOperation(next);
    }
    useEffect(() => {
        if (referral.stage !== 'converted') {
            setLoading(false);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        setPreview(null);
        setError('');
        axios
            .get<StartPreview>(
                `${referralHref(referral.reference)}/service-start`,
                { signal: controller.signal },
            )
            .then(({ data }) => {
                if (!controller.signal.aborted) setPreview(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'The start review could not be loaded. Current access to the referral, person, agreements and care plans is required.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [referral.reference, referral.stage, referral.version, retry]);
    if (referral.stage !== 'converted')
        return (
            <EmptyState
                icon={CalendarClock}
                title="Onboarding comes first"
                description="Prepare the onboarding handover first. Funding assistance can continue while onboarding is prepared; support starts after a separate readiness review."
            />
        );
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-section-title">Start support</h2>
                    <p className="text-subtle">
                        Review readiness, agree the date, then confirm the
                        actual start.
                    </p>
                </div>
                <Button
                    variant="outline"
                    disabled={loading}
                    onClick={() => setRetry((value) => value + 1)}
                >
                    <RefreshCw className="size-4" />
                    Refresh review
                </Button>
            </div>
            {loading && (
                <div role="status">
                    <LoadingState message="Loading the current service and care records…" />
                </div>
            )}
            {error && (
                <ErrorState
                    title="Start review unavailable"
                    message={error}
                    onRetry={() => setRetry((value) => value + 1)}
                />
            )}
            {preview && (
                <>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {preview.start?.commenced_at
                                    ? 'Support has started'
                                    : preview.start?.status === 'scheduled'
                                      ? 'Start date agreed'
                                      : 'Service readiness'}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Person
                                    </dt>
                                    <dd>{preview.person.name}</dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Onboarding
                                    </dt>
                                    <dd>
                                        {label(
                                            preview.workflow.status ??
                                                'not_recorded',
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-caption text-muted-foreground">
                                        Agreed start
                                    </dt>
                                    <dd>
                                        {preview.start?.agreed_date
                                            ? formatDateOnly(
                                                  preview.start.agreed_date,
                                              )
                                            : 'Not agreed'}
                                    </dd>
                                </div>
                            </dl>
                            {preview.start?.commenced_at && (
                                <p>
                                    Actual start:{' '}
                                    {formatDateTimeLong(
                                        preview.start.commenced_at,
                                    )}
                                </p>
                            )}
                            {preview.start?.review_required && (
                                <Alert variant="warning">
                                    <AlertDescription>
                                        The previous start decision needs
                                        review. Existing active support has been
                                        preserved.
                                    </AlertDescription>
                                </Alert>
                            )}
                            <div className="flex flex-wrap gap-3">
                                {preview.workflow.url && (
                                    <Button asChild variant="outline">
                                        <Link href={preview.workflow.url}>
                                            Open onboarding
                                        </Link>
                                    </Button>
                                )}
                                <Button asChild variant="outline">
                                    <Link
                                        href={`/operations/clients/${preview.person.id}`}
                                    >
                                        Open person
                                    </Link>
                                </Button>
                                {!preview.start?.commenced_at && (
                                    <Button
                                        disabled={!preview.proposal}
                                        onClick={() => openDecision('schedule')}
                                    >
                                        {preview.start
                                            ? 'Review start approval'
                                            : 'Review and agree start'}
                                    </Button>
                                )}
                                {preview.start?.status === 'scheduled' &&
                                    !preview.start.review_required && (
                                        <Button
                                            onClick={() =>
                                                openDecision('commence')
                                            }
                                        >
                                            Confirm actual start
                                        </Button>
                                    )}
                                {preview.start &&
                                    !preview.start.commenced_at &&
                                    preview.start.status !== 'cancelled' && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                openDecision('cancel')
                                            }
                                        >
                                            Cancel scheduled start
                                        </Button>
                                    )}
                            </div>
                            {!preview.proposal && (
                                <p className="text-subtle">
                                    Prepare a support proposal before reviewing
                                    a start.
                                </p>
                            )}
                            <p className="text-subtle">
                                Approval rechecks funding, current acceptance,
                                agreement terms, consent, care and staffing. It
                                does not record delivered support or create an
                                invoice. Funding follow-ups stay open.
                            </p>
                        </CardContent>
                    </Card>
                </>
            )}
            {operation && dialogPreview && (
                <ServiceStartDialog
                    reference={referral.reference}
                    preview={dialogPreview}
                    operation={operation}
                    onClose={() => {
                        setOperation(null);
                        setDialogPreview(null);
                        setRetry((value) => value + 1);
                    }}
                />
            )}
        </div>
    );
}
