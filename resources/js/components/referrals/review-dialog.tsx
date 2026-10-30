import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    Plus,
    ShieldCheck,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import { label, referralHref, type ReferralDetail } from './types';

type Sharing = {
    recipient: string;
    purpose: string;
    information: string;
    expires_at: string;
};
type Question = {
    key: string;
    title: string;
    options?: string[];
    short?: boolean;
};
const authorityScopeLabels: Record<string, string> = {
    contact: 'Future contact',
    collect: 'Collect assessment information',
    assist: 'Assist with funding applications',
    service: 'Consent for the proposed service',
};
const authorityQuestions: Question[][] = [
    [
        {
            key: 'state',
            title: 'Authority state',
            options: ['pending', 'current', 'withdrawn', 'disputed'],
        },
        {
            key: 'basis',
            title: 'Basis',
            options: [
                'self',
                'supported_decision',
                'representative',
                'other_lawful_basis',
                'to_clarify',
            ],
        },
        { key: 'basis_note', title: 'Basis, scope and evidence' },
        {
            key: 'representative',
            title: 'Representative, if applicable',
            short: true,
        },
        {
            key: 'participation',
            title: 'How the person participates and is supported',
        },
    ],
    [
        {
            key: 'notice',
            title: 'Collection notice',
            options: [
                'notice_given',
                'direct_collection',
                'pending_review',
                'exception_reviewed',
            ],
        },
        {
            key: 'notice_note',
            title: 'Notice delivery or reviewed exception details',
        },
    ],
];
const assessmentQuestions: Question[][] = [
    [
        {
            key: 'identity_status',
            title: 'Identity review',
            options: ['pending', 'verified', 'disputed'],
        },
        {
            key: 'identity_note',
            title: 'How identity was checked / what remains unknown',
        },
        {
            key: 'intention',
            title: 'The person’s intention',
            options: [
                'exploring',
                'proceed',
                'other_provider',
                'declined',
                'to_clarify',
            ],
        },
        { key: 'intention_note', title: 'Person’s wishes and provider choice' },
        { key: 'goals', title: 'Goals and what matters to the person' },
        {
            key: 'participation',
            title: 'Participation, communication and chosen supporters',
        },
    ],
    [
        {
            key: 'suitability',
            title: 'Provider suitability',
            options: [
                'pending',
                'suitable',
                'not_suitable',
                'more_information',
            ],
        },
        { key: 'suitability_note', title: 'Suitability review and evidence' },
        {
            key: 'capacity',
            title: 'Service capacity',
            options: ['unknown', 'available', 'waitlist', 'unavailable'],
        },
        { key: 'capacity_note', title: 'Capacity basis and remaining actions' },
        {
            key: 'safety_needs',
            title: 'Known safety needs and information still required',
        },
    ],
];
const localInstant = (value: string) =>
    toDatetimeLocal(value) + workerTimeOffset(value);

export function ReferralReviewDialog({
    mode,
    referral,
    onClose,
}: {
    mode: 'authority' | 'assessment';
    referral: ReferralDetail;
    onClose: () => void;
}) {
    const previous = (
        mode === 'authority' ? referral.authority?.review : referral.assessment
    ) as Record<string, unknown> | undefined;
    const questions =
        mode === 'authority' ? authorityQuestions : assessmentQuestions;
    const [values, setValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            questions
                .flat()
                .map((question) => [
                    question.key,
                    typeof previous?.[question.key] === 'string'
                        ? String(previous[question.key])
                        : (question.options?.[0] ?? ''),
                ]),
        ),
    );
    const [scopes, setScopes] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(
            ['contact', 'collect', 'assist', 'service'].map((key) => [
                key,
                !!(previous?.scopes as Record<string, boolean> | undefined)?.[
                    key
                ],
            ]),
        ),
    );
    const [sharing, setSharing] = useState<Sharing[]>(() =>
        ((previous?.sharing as Sharing[] | undefined) ?? []).map((item) => ({
            ...item,
            expires_at: localInstant(item.expires_at),
        })),
    );
    const [due, setDue] = useState(
        previous?.review_due_at
            ? localInstant(String(previous.review_due_at))
            : toDatetimeLocal(Date.now() + 30 * 86400000),
    );
    const [reason, setReason] = useState('');
    const [step, setStep] = useState(0);
    const [version, setVersion] = useState(referral.version);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const title =
        mode === 'authority'
            ? 'Review authority and participation'
            : 'Review provider assessment';
    const steps = [
        {
            key: 'person',
            label:
                mode === 'authority'
                    ? 'Authority and participation'
                    : 'Person and choice',
            icon: UserRound,
            blurb: 'Record reviewed facts',
        },
        {
            key: 'scope',
            label:
                mode === 'authority'
                    ? 'Permissions and notice'
                    : 'Support and capacity',
            icon: ShieldCheck,
            blurb: 'Evidence and remaining needs',
        },
        {
            key: 'review',
            label: 'Review',
            icon: ClipboardCheck,
            blurb: 'Check this new version',
        },
    ];
    function go(index: number) {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    }
    function change(key: string, value: string) {
        setValues((current) => ({ ...current, [key]: value }));
        if (key === 'state' && value !== 'current') {
            setScopes({
                contact: false,
                collect: false,
                assist: false,
                service: false,
            });
            setSharing([]);
        }
    }
    function valid(index: number) {
        if (
            index < 2 &&
            questions[index].some(
                (question) =>
                    question.key !== 'representative' &&
                    !values[question.key]?.trim(),
            )
        ) {
            setError(
                'Complete the review fields, recording what remains unknown where appropriate.',
            );
            return false;
        }
        if (index === 1 && (!due || reason.trim().length < 5)) {
            setError('Add the next review date and reason for this review.');
            return false;
        }
        return true;
    }
    async function save() {
        if (pending.current || conflict) return;
        for (const index of [0, 1])
            if (!valid(index)) {
                go(index);
                return;
            }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await axios.post<{ saved: boolean }>(
                `${referralHref(referral.reference)}/reviews/${mode}`,
                {
                    version,
                    ...values,
                    review_due_at: due,
                    reason,
                    ...(mode === 'authority' ? { scopes, sharing } : {}),
                },
            );
            if (!result.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? (caught.response?.status ?? null)
                : null;
            const response = axios.isAxiosError(caught)
                ? caught.response?.data
                : null;
            setConflict(status === 409 || status === null);
            setError(
                response?.errors
                    ? Object.values(response.errors as Record<string, string[]>)
                          .flat()
                          .join(' ')
                    : (response?.message ??
                          'Save was not confirmed. Reload and review the saved record before trying again.'),
            );
            if (status === 422 && response?.errors) {
                const keys = Object.keys(response.errors);
                go(
                    questions[0].some((item) => keys.includes(item.key))
                        ? 0
                        : 1,
                );
            }
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    function refresh() {
        router.reload({
            only: ['referral'],
            preserveScroll: true,
            onSuccess: (page) => {
                setVersion((page.props.referral as ReferralDetail).version);
                setConflict(false);
                setError(
                    'Latest saved review loaded. Compare it with this draft below. If the earlier save already succeeded, close this draft.',
                );
                go(2);
            },
        });
    }
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!busy) {
                        if (saved) onClose();
                        else setDiscard(true);
                    }
                }}
                title={title}
                description={`${title} for ${referral.name}`}
                railIcon={ShieldCheck}
                railTitle={title}
                railSub="Each save retains the previous review in restricted history."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) go(index);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? go(step - 1) : setDiscard(true))}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < 2 ? (
                        <Button
                            onClick={() => {
                                if (valid(step)) {
                                    setError('');
                                    go(step + 1);
                                }
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || conflict}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save review'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Review recorded"
                            blurb="The review and its history are saved. Readiness and any planned service start must use the current evidence."
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        className="text-section-title"
                        data-heading
                        tabIndex={-1}
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertTitle>Review needed</AlertTitle>
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <Button variant="outline" onClick={refresh}>
                                        Reload and keep draft
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step < 2 && (
                        <>
                            {step === 0 && (
                                <Alert variant="info">
                                    <AlertDescription>
                                        {mode === 'authority'
                                            ? 'Family relationships alone do not establish authority. Record the person’s participation, the basis and the specific scope. Withdrawal removes future permissions and preserves history.'
                                            : 'This is the provider’s assessment. External funding decisions, service agreements and safe-start approval are recorded separately.'}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {questions[step].map((question) => (
                                <Field
                                    key={question.key}
                                    label={question.title}
                                >
                                    {question.options ? (
                                        <SelectInput
                                            value={values[question.key]}
                                            onChange={(value) =>
                                                change(question.key, value)
                                            }
                                            placeholder={question.title}
                                            options={question.options.map(
                                                (value) => ({
                                                    value,
                                                    label: label(value),
                                                }),
                                            )}
                                        />
                                    ) : question.short ? (
                                        <Input
                                            value={values[question.key]}
                                            onChange={(event) =>
                                                change(
                                                    question.key,
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={300}
                                        />
                                    ) : (
                                        <Textarea
                                            value={values[question.key]}
                                            onChange={(event) =>
                                                change(
                                                    question.key,
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={
                                                [
                                                    'basis_note',
                                                    'suitability_note',
                                                    'goals',
                                                    'safety_needs',
                                                ].includes(question.key)
                                                    ? 4000
                                                    : 2000
                                            }
                                        />
                                    )}
                                </Field>
                            ))}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            {mode === 'authority' && (
                                <>
                                    <div className="space-y-3">
                                        {Object.entries(
                                            authorityScopeLabels,
                                        ).map(([key, text]) => (
                                            <div
                                                key={key}
                                                className="flex items-center gap-3"
                                            >
                                                <Checkbox
                                                    id={`authority-${key}`}
                                                    checked={scopes[key]}
                                                    disabled={
                                                        values.state !==
                                                        'current'
                                                    }
                                                    onCheckedChange={(value) =>
                                                        setScopes(
                                                            (current) => ({
                                                                ...current,
                                                                [key]:
                                                                    value ===
                                                                    true,
                                                            }),
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`authority-${key}`}
                                                    className="flex items-center"
                                                >
                                                    {text}
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="space-y-4">
                                        <h3 className="text-section-title">
                                            Specific sharing permissions
                                        </h3>
                                        <p className="text-subtle">
                                            Record the recipient, information,
                                            purpose and expiry. Saving this
                                            review does not send information.
                                        </p>
                                        {sharing.map((item, index) => (
                                            <div
                                                key={index}
                                                className="space-y-3 rounded-lg bg-muted p-4"
                                            >
                                                {(
                                                    [
                                                        'recipient',
                                                        'purpose',
                                                        'information',
                                                    ] as const
                                                ).map((key) => (
                                                    <Field
                                                        key={key}
                                                        label={label(key)}
                                                    >
                                                        <Input
                                                            value={item[key]}
                                                            maxLength={
                                                                key ===
                                                                'recipient'
                                                                    ? 300
                                                                    : 1000
                                                            }
                                                            onChange={(event) =>
                                                                setSharing(
                                                                    (current) =>
                                                                        current.map(
                                                                            (
                                                                                row,
                                                                                i,
                                                                            ) =>
                                                                                i ===
                                                                                index
                                                                                    ? {
                                                                                          ...row,
                                                                                          [key]: event
                                                                                              .target
                                                                                              .value,
                                                                                      }
                                                                                    : row,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                ))}
                                                <ReferralDateTime
                                                    id={`sharing-expiry-${index}`}
                                                    label="Permission expires"
                                                    value={item.expires_at}
                                                    onChange={(value) =>
                                                        setSharing((current) =>
                                                            current.map(
                                                                (row, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...row,
                                                                              expires_at:
                                                                                  value,
                                                                          }
                                                                        : row,
                                                            ),
                                                        )
                                                    }
                                                />
                                                <Button
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setSharing((current) =>
                                                            current.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                    Remove permission
                                                </Button>
                                            </div>
                                        ))}
                                        <Button
                                            variant="outline"
                                            disabled={
                                                values.state !== 'current' ||
                                                sharing.length >= 20
                                            }
                                            onClick={() =>
                                                setSharing((current) => [
                                                    ...current,
                                                    {
                                                        recipient: '',
                                                        purpose: '',
                                                        information: '',
                                                        expires_at: due,
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Add sharing permission
                                        </Button>
                                    </div>
                                </>
                            )}
                            <ReferralDateTime
                                id="review-due"
                                label="Next review due"
                                value={due}
                                onChange={setDue}
                            />
                            <Field label="Evidence and reason for this review">
                                <Textarea
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title={title}
                                onEdit={() => go(0)}
                            >
                                {questions.flat().map((question) => (
                                    <ReviewRow
                                        key={question.key}
                                        label={question.title}
                                        value={
                                            question.options
                                                ? label(values[question.key])
                                                : values[question.key] ||
                                                  'Not applicable'
                                        }
                                    />
                                ))}
                                {mode === 'authority' && (
                                    <>
                                        <ReviewRow
                                            label="Permitted activities"
                                            value={
                                                Object.keys(scopes)
                                                    .filter(
                                                        (key) => scopes[key],
                                                    )
                                                    .map(
                                                        (key) =>
                                                            authorityScopeLabels[
                                                                key
                                                            ] ?? label(key),
                                                    )
                                                    .join(', ') || 'None'
                                            }
                                        />
                                        {sharing.map((item, index) => (
                                            <ReviewRow
                                                key={index}
                                                label={item.recipient}
                                                value={`${item.purpose} · ${item.information} · expires ${item.expires_at.replace('T', ' ')}`}
                                            />
                                        ))}
                                    </>
                                )}
                                <ReviewRow
                                    label="Next review (NZ time)"
                                    value={due.replace('T', ' ')}
                                />
                                <ReviewRow label="Reason" value={reason} />
                            </ReviewCard>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Latest saved review"
                            >
                                {questions.flat().map((question) => (
                                    <ReviewRow
                                        key={question.key}
                                        label={question.title}
                                        value={String(
                                            previous?.[question.key] ??
                                                'Not recorded',
                                        )}
                                    />
                                ))}
                            </ReviewCard>
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard review draft?"
                description="Unsaved changes will be cleared. If a save was not confirmed, check the referral history before recording it again."
                confirmText="Discard draft"
            />
        </>
    );
}
