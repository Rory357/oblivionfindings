import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import {
    configurationChanges,
    type ReferralConfiguration,
} from './settings-types';
import { referralHref } from './types';

type Preview = {
    version: number;
    from: { id: number; version: number };
    target: { id: number; version: number; reason: string };
    configuration_before: ReferralConfiguration;
    configuration_after: ReferralConfiguration;
    funding: {
        id: number;
        pathway: string;
        next_label: string;
        changes: boolean;
        previous_checklist: string[];
        next_checklist: string[];
    }[];
    ready: boolean;
    issues: string[];
    preview_token: string;
};

type Props = { reference: string; version: number };
type Decision = {
    version: number;
    target_id: number;
    preview_token: string;
    reason: string;
    acknowledge_reviews: boolean;
    submission_key: string;
};

export function SettingsMigrationPanel(props: Props) {
    // A retained request must never cross into another person's referral.
    return <MigrationEditor key={props.reference} {...props} />;
}

function MigrationEditor({ reference, version }: Props) {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(true);
    const [retry, setRetry] = useState(0);
    const [reason, setReason] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);
    const [review, setReview] = useState(false);
    const [reviewedPreview, setReviewedPreview] = useState<Preview | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [conflict, setConflict] = useState(false);
    const issued = useRef<Decision | null>(null);
    const sending = useRef(false);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setPreview(null);
        if (!issued.current) {
            setAcknowledged(false);
            setReview(false);
            setReviewedPreview(null);
        }
        axios
            .get(`${referralHref(reference)}/settings-migration`, {
                signal: controller.signal,
            })
            .then(({ data }) => {
                if (!controller.signal.aborted) setPreview(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'The migration preview could not be loaded. Current referral, assessment, funding and settings governance access are required.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [reference, version, retry]);
    async function save() {
        if (sending.current || conflict) return;
        if (!issued.current) {
            if (
                !reviewedPreview?.ready ||
                reviewedPreview !== preview ||
                reason.trim().length < 10 ||
                !acknowledged
            )
                return;
            issued.current = {
                version: reviewedPreview.version,
                target_id: reviewedPreview.target.id,
                preview_token: reviewedPreview.preview_token,
                reason,
                acknowledge_reviews: true,
                submission_key: crypto.randomUUID(),
            };
        }
        sending.current = true;
        setBusy(true);
        setError('');
        try {
            const { data } = await axios.post(
                `${referralHref(reference)}/settings-migration`,
                issued.current,
            );
            if (!data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            setReview(false);
            setUncertain(false);
            router.reload({
                only: ['referral', 'permissions'],
                preserveScroll: true,
            });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setUncertain(!response || response.status >= 500);
            setConflict(
                !!response && [403, 404, 409].includes(response.status),
            );
            if (response && [403, 404, 409].includes(response.status))
                setReview(false);
            if (response && response.status < 500) issued.current = null;
            setError(
                response?.data?.errors
                    ? Object.values(response.data.errors).flat().join(' ')
                    : (response?.data?.message ??
                          'Migration was not confirmed. Retry the same decision to recover its result.'),
            );
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }
    const changes = preview
        ? configurationChanges(
              preview.configuration_before,
              preview.configuration_after,
          )
        : [];
    return (
        <Card>
            <CardHeader>
                <h2 className="text-section-title">
                    Review configuration for this referral
                </h2>
                <p className="text-subtle">
                    Adopt the current published settings with a recorded reason.
                    This does not reroute the referral, change recorded consent,
                    move due dates or replace original funding decisions.
                </p>
            </CardHeader>
            <CardContent className="space-y-5">
                {saved && (
                    <Alert variant="info">
                        <AlertDescription>
                            Configuration updated. Review affected funding
                            verification and service readiness before relying on
                            previous approvals. Existing active support remains
                            active.
                        </AlertDescription>
                    </Alert>
                )}
                {loading && (
                    <p role="status">
                        Loading current configuration and affected work…
                    </p>
                )}
                {error && (
                    <Alert variant="warning">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {!busy && !uncertain && (
                    <Button
                        variant="outline"
                        onClick={() => {
                            setConflict(false);
                            setError('');
                            setReview(false);
                            setSaved(false);
                            issued.current = null;
                            setRetry((count) => count + 1);
                        }}
                    >
                        Refresh preview
                    </Button>
                )}
                {uncertain && issued.current && !saved && (
                    <Button
                        disabled={busy || conflict}
                        onClick={() => setReview(true)}
                    >
                        Recover migration result
                    </Button>
                )}
                {preview && !saved && (
                    <>
                        <p className="font-medium">
                            Recorded version {preview.from.version} → Current
                            version {preview.target.version}
                        </p>
                        <p className="text-subtle">{preview.target.reason}</p>
                        <section className="space-y-3">
                            <h3 className="text-section-title">
                                Configuration changes
                            </h3>
                            <dl className="max-h-80 space-y-3 overflow-y-auto rounded-lg border border-border p-4">
                                {changes.map((change) => (
                                    <div key={change.label}>
                                        <dt className="text-caption font-medium capitalize">
                                            {change.label}
                                        </dt>
                                        <dd className="text-subtle break-words">
                                            {change.before} → {change.after}
                                        </dd>
                                    </div>
                                ))}
                                {!changes.length && (
                                    <p className="text-subtle">
                                        No referral configuration values differ.
                                        Open funding guides may still need
                                        updating.
                                    </p>
                                )}
                            </dl>
                        </section>
                        <section className="space-y-3">
                            <h3 className="text-section-title">
                                Open funding guides
                            </h3>
                            {preview.funding.length ? (
                                preview.funding.map((item) => (
                                    <div
                                        key={item.id}
                                        className="rounded-lg border border-border p-3"
                                    >
                                        <p className="font-medium">
                                            {item.pathway}
                                            {item.pathway !== item.next_label
                                                ? ` → ${item.next_label}`
                                                : ''}
                                        </p>
                                        <p className="text-subtle">
                                            {item.changes
                                                ? 'Guide will update; existing verification will require review.'
                                                : 'Already uses the current guide.'}
                                        </p>
                                        <p className="text-subtle">
                                            Required evidence:{' '}
                                            {item.next_checklist.join('; ') ||
                                                'No configured checklist items'}
                                        </p>
                                    </div>
                                ))
                            ) : (
                                <p className="text-subtle">
                                    No open funding cases.
                                </p>
                            )}
                        </section>
                        {!preview.ready && (
                            <Alert variant="warning">
                                <AlertDescription>
                                    <ul className="list-disc pl-5">
                                        {preview.issues.map((issue) => (
                                            <li key={issue}>{issue}</li>
                                        ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        )}
                        {preview.ready && (
                            <fieldset
                                disabled={busy || uncertain || conflict}
                                className="space-y-4"
                            >
                                <Field
                                    label="Reason for adopting these settings"
                                    required
                                >
                                    <Textarea
                                        value={reason}
                                        maxLength={2000}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                </Field>
                                <label className="flex items-start gap-3">
                                    <Checkbox
                                        checked={acknowledged}
                                        onCheckedChange={(checked) =>
                                            setAcknowledged(checked === true)
                                        }
                                    />
                                    I reviewed the changes and will arrange the
                                    required funding and service-readiness
                                    reviews. Existing evidence and due dates are
                                    preserved.
                                </label>
                            </fieldset>
                        )}
                        {!uncertain && (
                            <Button
                                disabled={
                                    busy ||
                                    conflict ||
                                    !preview.ready ||
                                    reason.trim().length < 10 ||
                                    !acknowledged
                                }
                                onClick={() => {
                                    setReviewedPreview(preview);
                                    setReview(true);
                                }}
                            >
                                Review migration
                            </Button>
                        )}
                    </>
                )}
                <ConfirmDialog
                    open={review}
                    onClose={() => !busy && setReview(false)}
                    onConfirm={() => void save()}
                    processing={busy}
                    variant="default"
                    title={`Adopt settings version ${reviewedPreview?.target.version}?`}
                    confirmText={
                        uncertain
                            ? 'Retry same migration'
                            : 'Apply reviewed migration'
                    }
                    description={
                        <div className="space-y-3">
                            <p>{issued.current?.reason ?? reason}</p>
                            <p>
                                Open funding guides update and affected
                                verification needs review. Existing start
                                approval is flagged for review; an active person
                                is not stopped. No ownership, due date, consent
                                or original decision is rewritten.
                            </p>
                            {error && <p role="alert">{error}</p>}
                        </div>
                    }
                />
            </CardContent>
        </Card>
    );
}
