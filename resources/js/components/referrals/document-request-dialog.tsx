import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, Copy, FileText, Send } from 'lucide-react';
import { useRef, useState } from 'react';
import { label, referralHref, type ReferralDetail } from './types';

export type DocumentRequestRow = {
    id: number;
    reference: string;
    title: string;
    recipient: string;
    state: string;
    audience: string;
    expires_at: string;
    received_at: string | null;
    revoked_at: string | null;
    evidence_id: number | null;
};
type Issued = DocumentRequestRow & { token: string; passcode: string };

export function DocumentRequestDialog({
    referral,
    audience,
    maximumDays,
    onClose,
}: {
    referral: ReferralDetail;
    audience: 'assessment' | 'funding';
    maximumDays: number;
    onClose: () => void;
}) {
    const [form, setForm] = useState({
        title: '',
        instructions: '',
        category: 'other',
        recipient: '',
        recipient_verification: '',
        reason: '',
        days: Math.min(7, maximumDays),
        separate_channel_confirmed: false,
    });
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState<Issued | null>(null);
    const [discard, setDiscard] = useState(false);
    const pending = useRef(false);
    const submitted = useRef<
        | (typeof form & {
              version: number;
              request_key: string;
              audience: string;
          })
        | null
    >(null);
    const key = useRef(crypto.randomUUID());
    const body = useRef<HTMLDivElement>(null);
    const [copied, copy] = useClipboard();
    const [copyError, setCopyError] = useState('');
    const steps = [
        {
            key: 'details',
            label: 'Request details',
            blurb: 'Document and recipient',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review and create',
            blurb: 'Safe delivery and expiry',
            icon: ClipboardCheck,
        },
    ];
    const valid =
        form.title.trim().length >= 3 &&
        form.instructions.trim().length >= 5 &&
        form.recipient.trim().length >= 3 &&
        form.recipient_verification.trim().length >= 10 &&
        form.reason.trim().length >= 5 &&
        Number.isInteger(form.days) &&
        form.days >= 1 &&
        form.days <= maximumDays &&
        form.separate_channel_confirmed;
    const change = (patch: Partial<typeof form>) =>
        setForm((current) => ({ ...current, ...patch }));
    function go(index: number) {
        if (busy || saved || submitted.current) return;
        if (index === 1 && !valid) {
            setError(
                'Complete the document, recipient, verification, reason and safe delivery confirmation before continuing.',
            );
            return;
        }
        setError('');
        setStep(index);
        body.current?.scrollTo({ top: 0 });
    }
    async function save() {
        if (pending.current || !valid) return;
        pending.current = true;
        setBusy(true);
        setError('');
        submitted.current ??= {
            version: referral.version,
            request_key: key.current,
            audience,
            ...form,
        };
        try {
            const response = await axios.post<{
                saved: boolean;
                request: Issued;
            }>(
                `${referralHref(referral.reference)}/document-requests`,
                submitted.current,
            );
            if (!response.data.saved) throw new Error('Request not confirmed');
            setSaved(response.data.request);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            if (response && response.status >= 400 && response.status < 500) {
                submitted.current = null;
                if (response.status === 409)
                    router.reload({ only: ['referral'], preserveScroll: true });
            }
            setError(
                response?.data?.errors
                    ? Object.values(
                          response.data.errors as Record<string, string[]>,
                      )
                          .flat()
                          .join(' ')
                    : (response?.data?.message ??
                          'Creation was not confirmed. Keep these details unchanged and try again to recover the same request.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    async function copyValue(value: string) {
        setCopyError(
            (await copy(value))
                ? ''
                : 'Copy was unavailable. Select and copy the value from the field.',
        );
    }
    const link = saved
        ? `${window.location.origin}/referrals/documents/${saved.token}`
        : '';
    const close = () => {
        if (busy) return;
        if (saved) onClose();
        else setDiscard(true);
    };
    return (
        <>
            <WizardShell
                open
                title="Request a document"
                description={`Private evidence for ${referral.name}`}
                railTitle="Secure collection"
                railIcon={Send}
                railSub="Create a time-limited request. Arrange delivery of the link and code separately using agreed safe contact methods."
                steps={steps}
                stepIndex={step}
                onStepClick={go}
                bodyRef={body}
                onClose={close}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? go(0) : close())}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={busy}
                        onClick={() => (step ? void save() : go(1))}
                    >
                        {busy
                            ? 'Creating request…'
                            : step
                              ? 'Create request'
                              : 'Continue'}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Request ready to share"
                            blurb="Nothing has been sent automatically. Share the link and code separately with the verified recipient. Save them now; they are not shown in request history."
                            actions={
                                <div className="w-full max-w-md space-y-4 text-left">
                                    <Field label="Secure request link">
                                        <Input
                                            className="min-h-[44px]"
                                            readOnly
                                            value={link}
                                            onFocus={(event) =>
                                                event.target.select()
                                            }
                                        />
                                    </Field>
                                    <Button
                                        variant="outline"
                                        onClick={() => void copyValue(link)}
                                    >
                                        <Copy className="size-4" />
                                        {copied === link
                                            ? 'Link copied'
                                            : 'Copy link'}
                                    </Button>
                                    <Field label="Code — share separately">
                                        <Input
                                            className="min-h-[44px]"
                                            readOnly
                                            value={saved.passcode}
                                            onFocus={(event) =>
                                                event.target.select()
                                            }
                                        />
                                    </Field>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            void copyValue(saved.passcode)
                                        }
                                    >
                                        <Copy className="size-4" />
                                        {copied === saved.passcode
                                            ? 'Code copied'
                                            : 'Copy code'}
                                    </Button>
                                    <p className="text-subtle">
                                        Expires{' '}
                                        {formatDateTimeLong(saved.expires_at)}.
                                        Record delivery in the referral’s
                                        contact history. If details are lost,
                                        revoke this request and create a
                                        replacement.
                                    </p>
                                    {copyError && (
                                        <p
                                            role="status"
                                            className="text-subtle"
                                        >
                                            {copyError}
                                        </p>
                                    )}
                                    <Button
                                        className="w-full"
                                        onClick={onClose}
                                    >
                                        View document requests
                                    </Button>
                                </div>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2 className="text-section-title">{steps[step].label}</h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    {step === 0 ? (
                        <>
                            <Field label="Document title">
                                <Input
                                    className="min-h-[44px] sm:min-h-0"
                                    value={form.title}
                                    maxLength={200}
                                    onChange={(event) =>
                                        change({ title: event.target.value })
                                    }
                                />
                            </Field>
                            <Field
                                label="Instructions for the recipient"
                                hint="Say exactly what is needed. These instructions appear only after code verification."
                            >
                                <Textarea
                                    value={form.instructions}
                                    maxLength={1000}
                                    onChange={(event) =>
                                        change({
                                            instructions: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Document category">
                                <SelectInput
                                    placeholder="Choose document category"
                                    className="min-h-[44px] sm:min-h-0"
                                    value={form.category}
                                    onChange={(category) =>
                                        change({ category })
                                    }
                                    options={[
                                        'authority',
                                        'assessment',
                                        'application',
                                        'external_decision',
                                        'agreement',
                                        'identity',
                                        'other',
                                    ].map((value) => ({
                                        value,
                                        label: label(value),
                                    }))}
                                />
                            </Field>
                            <Field label="Verified recipient">
                                <Input
                                    className="min-h-[44px] sm:min-h-0"
                                    value={form.recipient}
                                    maxLength={200}
                                    onChange={(event) =>
                                        change({
                                            recipient: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field
                                label="Recipient and safe contact checks"
                                hint="Record how identity, authority to provide this document, and separate safe delivery methods were checked."
                            >
                                <Textarea
                                    value={form.recipient_verification}
                                    maxLength={1000}
                                    onChange={(event) =>
                                        change({
                                            recipient_verification:
                                                event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field
                                label="Link duration (days)"
                                hint={`Up to ${maximumDays} days under this referral’s settings.`}
                            >
                                <Input
                                    className="min-h-[44px] sm:min-h-0"
                                    type="number"
                                    min={1}
                                    max={maximumDays}
                                    value={form.days}
                                    onChange={(event) =>
                                        change({
                                            days: Number(event.target.value),
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Reason for requesting this document">
                                <Textarea
                                    value={form.reason}
                                    maxLength={2000}
                                    onChange={(event) =>
                                        change({ reason: event.target.value })
                                    }
                                />
                            </Field>
                            <label className="flex min-h-[44px] items-start gap-3">
                                <Checkbox
                                    className="frontline-hit mt-1"
                                    checked={form.separate_channel_confirmed}
                                    onCheckedChange={(value) =>
                                        change({
                                            separate_channel_confirmed:
                                                value === true,
                                        })
                                    }
                                />
                                <span className="text-subtle">
                                    I have verified the recipient and agreed
                                    safe ways to share the link and code
                                    separately.
                                </span>
                            </label>
                        </>
                    ) : (
                        <>
                            <ReviewCard
                                title="Document request"
                                icon={FileText}
                                onEdit={() => go(0)}
                            >
                                <ReviewRow
                                    label="Document"
                                    value={form.title}
                                />
                                <ReviewRow
                                    label="Instructions"
                                    value={form.instructions}
                                />
                                <ReviewRow
                                    label="Access"
                                    value={label(audience)}
                                />
                                <ReviewRow
                                    label="Category"
                                    value={label(form.category)}
                                />
                                <ReviewRow
                                    label="Recipient"
                                    value={form.recipient}
                                />
                                <ReviewRow
                                    label="Verification"
                                    value={form.recipient_verification}
                                />
                                <ReviewRow
                                    label="Expires in"
                                    value={`${form.days} days`}
                                />
                                <ReviewRow label="Reason" value={form.reason} />
                            </ReviewCard>
                            <Alert variant="info">
                                <AlertDescription>
                                    One file per request. Five incorrect codes
                                    lock the request. Changes to collection
                                    authority, identity or the issuing
                                    colleague’s permissions can make it
                                    unavailable. A received file still needs
                                    scanning and staff review.
                                </AlertDescription>
                            </Alert>
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                title="Discard this document request?"
                description="The unsaved details will be discarded. If creation was interrupted, check request history before creating another."
                confirmText="Discard details"
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
            />
        </>
    );
}
