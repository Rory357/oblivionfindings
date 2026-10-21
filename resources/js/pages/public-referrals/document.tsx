import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/wizard/primitives';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import MarketingLayout from '@/layouts/marketing-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { CheckCircle2, FileUp, LockKeyhole } from 'lucide-react';
import { useRef, useState } from 'react';

type RequestDetails = {
    reference: string;
    title: string;
    instructions: string;
    state: 'pending' | 'received';
    expires_at: string;
    received_at: string | null;
};

export default function ReferralDocumentPage({ token }: { token: string }) {
    const [passcode, setPasscode] = useState('');
    const [details, setDetails] = useState<RequestDetails | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    const [error, setError] = useState('');
    const heading = useRef<HTMLHeadingElement>(null);
    const base = `/referrals/documents/${token}`;
    const leave = useSettingsLeaveConfirmation(
        !!file || busy,
        'Leave this document upload?',
        'The selected file will not be kept on this page. A submission already sent may still finish; reopen the request with your code to check its receipt.',
        undefined,
        busy,
    );

    async function submit() {
        if (pending.current) return;
        if (!details && !/^\d{10}$/.test(passcode)) {
            setError('Enter the 10-digit code the team shared separately.');
            return;
        }
        if (details && (!file || !confirmed)) {
            setError(
                'Choose your document and confirm it is the file you want to share.',
            );
            return;
        }
        if (
            file &&
            (file.size > 10 * 1024 * 1024 ||
                !['application/pdf', 'image/jpeg', 'image/png'].includes(
                    file.type,
                ))
        ) {
            setError('Choose a PDF, JPEG or PNG file no larger than 10 MB.');
            return;
        }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            let response;
            if (details) {
                const form = new FormData();
                form.append('passcode', passcode);
                form.append('file', file!);
                response = await axios.post<RequestDetails>(base, form);
            } else
                response = await axios.post<RequestDetails>(`${base}/check`, {
                    passcode,
                });
            if (
                !response.data ||
                !['pending', 'received'].includes(response.data.state) ||
                typeof response.data.reference !== 'string' ||
                (response.data.state === 'received' &&
                    !response.data.received_at)
            ) {
                throw new Error('Request outcome not confirmed');
            }
            setDetails(response.data);
            if (response.data.state === 'received') {
                setFile(null);
                setConfirmed(false);
            }
            requestAnimationFrame(() => heading.current?.focus());
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            if (response?.status === 403 || response?.status === 404) {
                setDetails(null);
                setFile(null);
                setConfirmed(false);
                setPasscode('');
                requestAnimationFrame(() => heading.current?.focus());
            }
            setError(
                response?.status === 429
                    ? 'Too many attempts. Please wait a minute before trying again.'
                    : response?.status === 404
                      ? 'The link or code could not be verified. Check the code or contact the team for a new request.'
                      : (response?.data?.message ??
                        'We could not confirm the result. Keep this page open and try again with the same file.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }

    const received = details?.state === 'received';
    return (
        <MarketingLayout title="Share a requested document">
            {leave.confirmation}
            <Head>
                <meta name="robots" content="noindex,nofollow" />
                <meta name="referrer" content="no-referrer" />
            </Head>
            <div className="mx-auto max-w-2xl space-y-5">
                <Card>
                    <CardHeader className="space-y-3">
                        {received ? (
                            <CheckCircle2
                                className="size-6 text-status-success"
                                aria-hidden
                            />
                        ) : details ? (
                            <FileUp
                                className="size-6 text-primary"
                                aria-hidden
                            />
                        ) : (
                            <LockKeyhole
                                className="size-6 text-primary"
                                aria-hidden
                            />
                        )}
                        <h1
                            ref={heading}
                            tabIndex={-1}
                            className="text-page-title"
                        >
                            {received
                                ? 'Document received'
                                : details
                                  ? 'Share your document'
                                  : 'Open your document request'}
                        </h1>
                        <p className="text-subtle">
                            {received
                                ? 'The team will check the file and review the information. This receipt does not confirm funding, eligibility or the start of support.'
                                : details
                                  ? 'Send only the document requested below. You can contact the team if you need help or another way to provide it.'
                                  : 'Enter the code the team gave you separately. You do not need an account.'}
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        {error && (
                            <Alert variant="warning">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}
                        {received ? (
                            <div className="space-y-4">
                                <p className="text-subtle">
                                    Receipt:{' '}
                                    <span className="font-medium break-all text-foreground">
                                        {details.reference}
                                    </span>
                                </p>
                                <p className="text-subtle">
                                    Received{' '}
                                    {formatDateTimeLong(details.received_at!)}
                                </p>
                                <Button asChild className="frontline-tap">
                                    <a href="/">Return to the website</a>
                                </Button>
                            </div>
                        ) : (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void submit();
                                }}
                                className="space-y-5"
                                aria-busy={busy}
                            >
                                {details ? (
                                    <>
                                        <div className="space-y-2 rounded-lg border bg-muted/30 p-4">
                                            <h2 className="text-section-title break-words">
                                                {details.title}
                                            </h2>
                                            <p className="text-subtle break-words whitespace-pre-wrap">
                                                {details.instructions}
                                            </p>
                                            <p className="text-caption text-muted-foreground">
                                                Link expires{' '}
                                                {formatDateTimeLong(
                                                    details.expires_at,
                                                )}
                                            </p>
                                        </div>
                                        <Field
                                            label="Choose a document"
                                            hint="One PDF, JPEG or PNG, up to 10 MB. Check that every page is readable."
                                        >
                                            <Input
                                                className="min-h-[44px] w-full min-w-0"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                disabled={busy}
                                                onChange={(event) => {
                                                    setFile(
                                                        event.target
                                                            .files?.[0] ?? null,
                                                    );
                                                    setConfirmed(false);
                                                    setError('');
                                                }}
                                            />
                                        </Field>
                                        {file && (
                                            <p className="text-subtle break-all">
                                                {file.name} ·{' '}
                                                {(file.size / 1024).toFixed(0)}{' '}
                                                KB
                                            </p>
                                        )}
                                        <label className="flex min-h-[44px] items-start gap-3">
                                            <Checkbox
                                                className="frontline-hit mt-1"
                                                checked={confirmed}
                                                disabled={busy}
                                                onCheckedChange={(value) =>
                                                    setConfirmed(value === true)
                                                }
                                            />
                                            <span className="text-subtle">
                                                I have checked this file and
                                                want to share it with the team
                                                for this request.
                                            </span>
                                        </label>
                                    </>
                                ) : (
                                    <Field
                                        label="10-digit code"
                                        hint="If you do not have a code, contact the team using the details you already know."
                                    >
                                        <Input
                                            className="min-h-[44px]"
                                            inputMode="numeric"
                                            autoComplete="one-time-code"
                                            type="password"
                                            value={passcode}
                                            maxLength={10}
                                            disabled={busy}
                                            onChange={(event) =>
                                                setPasscode(
                                                    event.target.value.replace(
                                                        /\D/g,
                                                        '',
                                                    ),
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                <Button
                                    type="submit"
                                    className="frontline-tap w-full sm:w-auto"
                                    disabled={busy}
                                >
                                    {busy
                                        ? details
                                            ? 'Sending document…'
                                            : 'Checking code…'
                                        : details
                                          ? 'Send document'
                                          : 'Open request'}
                                </Button>
                                {busy && (
                                    <p role="status" className="text-subtle">
                                        Please keep this page open while we
                                        confirm the result.
                                    </p>
                                )}
                            </form>
                        )}
                    </CardContent>
                </Card>
            </div>
        </MarketingLayout>
    );
}
