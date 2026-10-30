import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    useEffect,
    useRef,
    useState,
    type Dispatch,
    type SetStateAction,
} from 'react';
import { label, referralHref } from './types';

export type DuplicateOriginal = {
    reference: string;
    version: number;
    name: string;
    stage: string;
    owner_name: string | null;
};
export type DuplicateReview = {
    original: DuplicateOriginal | null;
    basis: string;
    confirmed: boolean;
};
export type DuplicateClosureHistory = {
    closed_at: string;
    reopened_at: string | null;
    accessible: boolean;
    target?: DuplicateOriginal;
    basis?: string;
};

export function DuplicateClosureReview({
    source,
    value,
    onChange,
}: {
    source: string;
    value: DuplicateReview;
    onChange: Dispatch<SetStateAction<DuplicateReview>>;
}) {
    const [reference, setReference] = useState(value.original?.reference ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef<AbortController | null>(null);
    useEffect(() => () => pending.current?.abort(), []);
    async function inspect() {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setBusy(true);
        setError('');
        onChange((v) => ({ ...v, original: null, confirmed: false }));
        try {
            const { data } = await axios.get<{ original: DuplicateOriginal }>(
                `${referralHref(source)}/duplicate-preview`,
                {
                    params: {
                        original_reference: reference.trim().toUpperCase(),
                    },
                    signal: controller.signal,
                },
            );
            if (!controller.signal.aborted)
                onChange((v) => ({ ...v, original: data.original }));
        } catch (error) {
            if (!controller.signal.aborted)
                setError(
                    axios.isAxiosError(error)
                        ? (error.response?.data?.message ??
                              'Could not review the original referral. Try again.')
                        : 'Could not review the original referral. Try again.',
                );
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }
    return (
        <div className="space-y-4">
            <p className="text-subtle">
                Confirm this is the same enquiry, not another person with a
                shared name or contact, or a new request for support. The
                original keeps its owner and workflow. No person records,
                documents or follow-ups are merged.
            </p>
            <Field label="Original referral reference">
                <Input
                    value={reference}
                    maxLength={26}
                    onChange={(e) => {
                        pending.current?.abort();
                        setBusy(false);
                        setReference(e.target.value);
                        setError('');
                        onChange((v) => ({
                            ...v,
                            original: null,
                            confirmed: false,
                        }));
                    }}
                />
            </Field>
            <Button
                type="button"
                variant="outline"
                disabled={busy || reference.trim().length !== 26}
                onClick={inspect}
            >
                {busy ? 'Checking original…' : 'Review original referral'}
            </Button>
            {error && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            {value.original && (
                <div className="space-y-2 rounded-lg border p-4">
                    <p className="text-subtle font-semibold">
                        {value.original.name}
                    </p>
                    <p className="text-caption">
                        {label(value.original.stage)} ·{' '}
                        {value.original.owner_name ?? 'Owner unavailable'}
                    </p>
                    <a
                        className="text-subtle text-primary underline"
                        href={referralHref(value.original.reference)}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open original referral to compare (new tab)
                    </a>
                    <Field label="Why this is the same enquiry">
                        <Textarea
                            value={value.basis}
                            maxLength={2000}
                            onChange={(e) =>
                                onChange((v) => ({
                                    ...v,
                                    basis: e.target.value,
                                    confirmed: false,
                                }))
                            }
                        />
                    </Field>
                    <label className="text-subtle flex items-start gap-3">
                        <Checkbox
                            checked={value.confirmed}
                            onCheckedChange={(checked) =>
                                onChange((v) => ({
                                    ...v,
                                    confirmed: checked === true,
                                }))
                            }
                        />
                        I reviewed both records and confirmed the same person or
                        contact and the same enquiry.
                    </label>
                </div>
            )}
        </div>
    );
}

export function DuplicateClosureHistoryPanel({
    history,
}: {
    history: DuplicateClosureHistory[];
}) {
    if (!history.length) return null;
    return (
        <section className="space-y-3" aria-label="Duplicate closure reviews">
            <h3 className="text-subtle font-semibold">
                Duplicate closure reviews
            </h3>
            {history.map((entry, index) => (
                <div
                    key={`${entry.closed_at}-${index}`}
                    className="space-y-2 rounded-lg border p-3"
                >
                    <p className="text-caption">
                        Closed {formatDateTime(entry.closed_at)}
                        {entry.reopened_at
                            ? ` · Reopened ${formatDateTime(entry.reopened_at)}`
                            : ''}
                    </p>
                    {entry.accessible && entry.target ? (
                        <>
                            <Link
                                className="text-subtle text-primary underline"
                                href={referralHref(entry.target.reference)}
                            >
                                Original: {entry.target.name}
                            </Link>
                            <p className="text-subtle whitespace-pre-wrap">
                                {entry.basis}
                            </p>
                        </>
                    ) : (
                        <p className="text-subtle">
                            Original referral details require current
                            duplicate-review access to both records.
                        </p>
                    )}
                </div>
            ))}
        </section>
    );
}
