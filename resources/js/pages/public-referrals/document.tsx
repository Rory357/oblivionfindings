import { phoneUrl } from '@/components/public-provider';
import { NoteLine, PageIntro } from '@/components/public-site/primitives';
import { siteLinks, usePublicSite } from '@/components/public-site/site';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import PublicSiteLayout from '@/layouts/marketing-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { FileUp, LockKeyhole, Phone, ShieldCheck } from 'lucide-react';
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
    const { provider } = usePublicSite();
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
    const phone = provider.phone?.trim();

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
    const choose = (next: File | null) => {
        setFile(next);
        setConfirmed(false);
        setError('');
    };

    return (
        <PublicSiteLayout
            title="Secure upload"
            current="upload"
            intro={
                <PageIntro
                    eyebrow="Secure upload"
                    title="Share a requested document"
                    lede={
                        details
                            ? `Your intake coordinator asked for this document. This link expires on ${formatDateTimeLong(details.expires_at)}.`
                            : 'Your intake coordinator asked for a document. Enter the code they sent you separately to open the request. You don’t need an account.'
                    }
                />
            }
        >
            {leave.confirmation}
            <Head>
                <meta name="robots" content="noindex,nofollow" />
                <meta name="referrer" content="no-referrer" />
            </Head>
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.5fr_1fr]">
                <section
                    aria-labelledby="upload-heading"
                    className="space-y-6 rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    <div className="space-y-1.5">
                        <p className="journey-site-kicker">
                            {received
                                ? 'Received'
                                : details
                                  ? 'Requested by our intake team'
                                  : 'Your request'}
                        </p>
                        <h2
                            id="upload-heading"
                            ref={heading}
                            tabIndex={-1}
                            className="text-section-title focus-visible:outline-none"
                        >
                            {received
                                ? 'Document received'
                                : details
                                  ? 'Share your document'
                                  : 'Open your document request'}
                        </h2>
                    </div>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    {received ? (
                        <div className="space-y-5">
                            <div
                                role="status"
                                className="flex items-center gap-4 rounded-2xl bg-muted p-5"
                            >
                                {/* Signature: success is the brand ring closing, not a green tick. */}
                                <span
                                    className="journey-site-ring-done"
                                    aria-hidden
                                />
                                <span>
                                    <span className="block font-semibold">
                                        Received — thank you
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        We check every file for viruses before
                                        your coordinator opens it. This receipt
                                        does not confirm funding, eligibility or
                                        the start of support.
                                    </span>
                                </span>
                            </div>
                            <dl className="grid gap-1 text-sm">
                                <div className="flex flex-wrap gap-x-2">
                                    <dt className="text-muted-foreground">
                                        Receipt
                                    </dt>
                                    <dd className="font-medium break-all">
                                        {details.reference}
                                    </dd>
                                </div>
                                <div className="flex flex-wrap gap-x-2">
                                    <dt className="text-muted-foreground">
                                        Received
                                    </dt>
                                    <dd>
                                        {formatDateTimeLong(
                                            details.received_at!,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                            <Button asChild className="frontline-tap">
                                <a href={siteLinks.home}>
                                    Return to the website
                                </a>
                            </Button>
                        </div>
                    ) : (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void submit();
                            }}
                            className="space-y-6"
                            aria-busy={busy}
                        >
                            {details ? (
                                <>
                                    <div className="space-y-2 rounded-2xl bg-muted p-5">
                                        <h3 className="font-semibold break-words">
                                            {details.title}
                                        </h3>
                                        <p className="text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                            {details.instructions}
                                        </p>
                                        <p className="text-caption text-muted-foreground">
                                            Link expires{' '}
                                            {formatDateTimeLong(
                                                details.expires_at,
                                            )}
                                        </p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label id="upload-document-label">
                                            Choose a document
                                        </Label>
                                        <p
                                            id="upload-document-hint"
                                            className="text-caption text-muted-foreground"
                                        >
                                            One PDF, JPEG or PNG, up to 10 MB.
                                            Check that every page is readable.
                                        </p>
                                        {file && (
                                            <StagedFileCard
                                                file={file}
                                                onRemove={() => choose(null)}
                                            />
                                        )}
                                        <FileDropzone
                                            aria-labelledby="upload-document-label"
                                            aria-describedby="upload-document-hint"
                                            onFiles={(list) =>
                                                choose(list[0] ?? null)
                                            }
                                            multiple={false}
                                            disabled={busy}
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            title={
                                                file
                                                    ? 'Choose a different file'
                                                    : 'Drag your document here, or browse'
                                            }
                                            hint="PDF, JPG or PNG · up to 10 MB"
                                        />
                                    </div>
                                    <label className="flex items-start gap-3 rounded-2xl bg-muted p-4">
                                        <Checkbox
                                            className="frontline-hit mt-0.5"
                                            checked={confirmed}
                                            disabled={busy}
                                            onCheckedChange={(value) =>
                                                setConfirmed(value === true)
                                            }
                                        />
                                        <span className="text-sm">
                                            I have checked this file and want to
                                            share it with the team for this
                                            request.
                                        </span>
                                    </label>
                                </>
                            ) : (
                                <div className="space-y-1.5">
                                    <Label htmlFor="upload-code">
                                        10-digit code
                                    </Label>
                                    <Input
                                        id="upload-code"
                                        className="frontline-tap max-w-xs"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        type="password"
                                        value={passcode}
                                        maxLength={10}
                                        disabled={busy}
                                        aria-describedby="upload-code-hint"
                                        onChange={(event) =>
                                            setPasscode(
                                                event.target.value.replace(
                                                    /\D/g,
                                                    '',
                                                ),
                                            )
                                        }
                                    />
                                    <p
                                        id="upload-code-hint"
                                        className="text-caption text-muted-foreground"
                                    >
                                        Your coordinator sent the code
                                        separately from this link, so only you
                                        can use it.
                                    </p>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-5">
                                <p
                                    className="text-sm text-muted-foreground"
                                    role={busy ? 'status' : undefined}
                                >
                                    {busy
                                        ? 'Please keep this page open while we confirm the result.'
                                        : !details
                                          ? 'Enter your 10-digit code to open the request.'
                                          : !file
                                            ? 'Choose a file to send.'
                                            : 'Ready to send.'}
                                </p>
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="frontline-tap"
                                    disabled={busy}
                                >
                                    {details ? (
                                        <FileUp
                                            className="size-4"
                                            aria-hidden
                                        />
                                    ) : (
                                        <LockKeyhole
                                            className="size-4"
                                            aria-hidden
                                        />
                                    )}
                                    {busy
                                        ? details
                                            ? 'Sending document…'
                                            : 'Checking code…'
                                        : details
                                          ? 'Send document'
                                          : 'Open request'}
                                </Button>
                            </div>
                        </form>
                    )}
                </section>
                <aside className="space-y-3 rounded-3xl border border-border bg-card p-6">
                    <NoteLine icon={ShieldCheck}>
                        Files are checked for viruses and stored privately. Only
                        the staff working on your referral can open them.
                    </NoteLine>
                    <NoteLine icon={LockKeyhole}>
                        This link only accepts the document that was requested,
                        and stops working when it expires.
                    </NoteLine>
                    <NoteLine icon={Phone}>
                        Didn’t expect this request?{' '}
                        {phone ? (
                            <>
                                Call us on{' '}
                                <a
                                    href={phoneUrl(phone)}
                                    className="font-semibold text-primary hover:underline"
                                >
                                    {phone}
                                </a>{' '}
                                before uploading anything.
                            </>
                        ) : (
                            'Contact us before uploading anything.'
                        )}
                    </NoteLine>
                </aside>
            </div>
        </PublicSiteLayout>
    );
}
