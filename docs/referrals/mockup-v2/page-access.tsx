// Sign in (the REAL app pages, rendered with fabricated props through the
// Inertia shim) and Secure upload.
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import Login from '@/pages/auth/login';
import PortalLogin from '@/pages/portal/login';
import {
    setMockProps,
    setMockSubmitHandler,
    type MockRequest,
} from '@inertiajs/react';
import { FileUp, LockKeyhole, Phone, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { provider } from './data';
import { showReviewNotice } from './review';
import { NoteLine, PageIntro, SitePage } from './site';

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/* ── Sign in ─────────────────────────────────────────────────────────── */

export function SignInPage({ audience }: { audience: 'staff' | 'family' }) {
    const [linkSentTo, setLinkSentTo] = useState<string | null>(null);
    // Each emailed link remounts the page so the "Check your email" panel
    // shows again, as a fresh server response would.
    const [sends, setSends] = useState(0);

    useEffect(() => {
        setMockSubmitHandler(async ({ url, data }: MockRequest) => {
            if (url.includes('/locale')) {
                setMockProps({ locale: String(data.locale) });
                return;
            }
            // The family portal's emailed link (`/portal/login/link`).
            if (url.includes('/portal/login/link')) {
                await wait(900);
                setLinkSentTo(String(data.email));
                setSends((count) => count + 1);
                return;
            }
            // The journey animation plays once while the sign-in is sent.
            await wait(2300);
            showReviewNotice(
                audience === 'family'
                    ? 'Signed in (demo): a family member would now be in the family portal, seeing only the people they’re linked to.'
                    : 'Signed in (demo): a staff member would now be in their workspace, on My Day.',
            );
        });
        return () => setMockSubmitHandler(() => wait(900));
    }, [audience]);

    return audience === 'family' ? (
        <PortalLogin key={sends} linkSentTo={linkSentTo} />
    ) : (
        <Login canResetPassword status={null} />
    );
}

/* ── Secure upload ───────────────────────────────────────────────────── */

export function UploadPage() {
    const [files, setFiles] = useState<File[]>([]);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | undefined>();
    const [state, setState] = useState<'editing' | 'sending' | 'sent'>(
        'editing',
    );

    const send = () => {
        setCodeError(undefined);
        setState('sending');
        window.setTimeout(() => {
            if (code === '000000') {
                setState('editing');
                setCodeError(
                    'That code doesn’t match. Check the code your coordinator sent you, or call us.',
                );
                return;
            }
            setState('sent');
        }, 900);
    };

    return (
        <SitePage
            current="upload"
            intro={
                <PageIntro
                    eyebrow="Secure upload"
                    title="Share your funding plan"
                    lede="Your intake coordinator asked for this document for your referral. This link expires on 26 October 2026."
                />
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.5fr_1fr]">
                <section
                    aria-labelledby="upload-heading"
                    className="space-y-6 rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    <div className="space-y-1.5">
                        <p className="journey-site-kicker">
                            Requested by Sam, intake coordinator
                        </p>
                        <h2 id="upload-heading" className="text-section-title">
                            Upload one document
                        </h2>
                    </div>
                    {state === 'sent' ? (
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
                                    We check every file for viruses before your
                                    coordinator opens it. They’ll let you know
                                    if anything else is needed. You can close
                                    this page.
                                </span>
                            </span>
                        </div>
                    ) : (
                        <>
                            <div className="space-y-1.5">
                                <Label htmlFor="upload-code">Access code</Label>
                                <Input
                                    id="upload-code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    value={code}
                                    onChange={(event) => {
                                        setCode(
                                            event.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                        );
                                        setCodeError(undefined);
                                    }}
                                    placeholder="6 digits"
                                    aria-invalid={codeError ? true : undefined}
                                    aria-describedby="upload-code-hint"
                                    className="max-w-xs"
                                />
                                <p
                                    id="upload-code-hint"
                                    className="text-caption text-muted-foreground"
                                >
                                    Your coordinator sent the code separately
                                    from this link, so only you can use it.
                                </p>
                                <InputError message={codeError} />
                            </div>
                            <div className="space-y-2">
                                <Label id="upload-document-label">
                                    Document
                                </Label>
                                {files[0] ? (
                                    <StagedFileCard
                                        file={files[0]}
                                        onRemove={() => setFiles([])}
                                    />
                                ) : (
                                    <FileDropzone
                                        aria-labelledby="upload-document-label"
                                        onFiles={(list) =>
                                            setFiles(list.slice(0, 1))
                                        }
                                        multiple={false}
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        title="Drag your document here, or browse"
                                        hint="PDF, JPG or PNG · up to 10 MB"
                                    />
                                )}
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-5">
                                <p className="text-sm text-muted-foreground">
                                    {!files[0]
                                        ? 'Choose a file to send.'
                                        : code.length < 6
                                          ? 'Enter your 6-digit access code.'
                                          : 'Ready to send.'}
                                </p>
                                <Button
                                    size="lg"
                                    disabled={
                                        !files[0] ||
                                        code.length < 6 ||
                                        state === 'sending'
                                    }
                                    onClick={send}
                                >
                                    {state === 'sending' ? (
                                        <Spinner aria-hidden />
                                    ) : (
                                        <FileUp
                                            className="size-4"
                                            aria-hidden
                                        />
                                    )}
                                    {state === 'sending'
                                        ? 'Sending…'
                                        : 'Send document'}
                                </Button>
                            </div>
                        </>
                    )}
                </section>
                <aside className="space-y-3 rounded-3xl border border-border bg-card p-6">
                    <NoteLine icon={ShieldCheck}>
                        Files are checked for viruses and stored privately. Only
                        the staff working on your referral can open them.
                    </NoteLine>
                    <NoteLine icon={LockKeyhole}>
                        This link only accepts one document, and stops working
                        on 26 October 2026.
                    </NoteLine>
                    <NoteLine icon={Phone}>
                        Didn’t expect this request? Call us on {provider.phone}{' '}
                        before uploading anything.
                    </NoteLine>
                </aside>
            </div>
        </SitePage>
    );
}
