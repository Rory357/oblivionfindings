import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { Send, ShieldAlert } from 'lucide-react';
import { FormEvent } from 'react';

type ConsentType = {
    id: number;
    name: string;
    category: string;
    description?: string;
    purpose?: string;
    validity_period_days?: number | null;
};

type PortalUser = {
    id: number;
    name: string;
    email: string;
    relationship?: string | null;
};

type Props = {
    client: { id: number; full_name: string };
    consent_types: ConsentType[];
    portal_users: PortalUser[];
    relationship_options: Record<string, string>;
};

const SUBSTITUTE_RELATIONSHIPS = new Set([
    'welfare_guardian',
    'epoa_personal_care',
    'parent_guardian',
    'court_appointed',
]);

const EMPTY_DECISION_EVIDENCE = {
    capacity_outcome: '',
    capacity_assessed_at: '',
    capacity_assessment_expires_at: '',
    capacity_assessment_reason: '',
    capacity_evidence_type: '',
    capacity_evidence_reference: '',
    best_interests_process_reason: '',
    best_interests_evidence_type: '',
    best_interests_evidence_reference: '',
    best_interests_consultees: [] as string[],
};

export function isSubstituteRelationship(relationship: string): boolean {
    return SUBSTITUTE_RELATIONSHIPS.has(relationship);
}

export default function ConsentRequestsCreate({
    client,
    consent_types,
    portal_users,
    relationship_options,
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        consent_type_id: '',
        recipient_user_id: '',
        recipient_relationship: '',
        purpose: '',
        least_restrictive_justification: '',
        data_scope: '',
        retention_period_days: '',
        withdrawal_method_text:
            'You may withdraw this consent at any time by contacting the key worker, or through your family portal account.',
        staff_notes: '',
        expires_in_days: '14',
        ...EMPTY_DECISION_EVIDENCE,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(`/operations/clients/${client.id}/consent-requests`);
    };

    const noPortalUsers = portal_users.length === 0;
    const requiresDecisionEvidence = isSubstituteRelationship(
        data.recipient_relationship,
    );

    return (
        <AppLayout>
            <Head title={`New consent request — ${client.full_name}`} />
            <PageShell>
                <PageHero
                    variant="compact"
                    title="Request consent via family portal"
                    description={`Compose a Right-7 disclosure for ${client.full_name}'s authorised signatory to review.`}
                />

                {noPortalUsers && (
                    <Card className="border-status-warning/30 bg-status-warning-bg">
                        <CardContent className="flex items-start gap-3 p-4">
                            <ShieldAlert className="mt-0.5 h-5 w-5 text-status-warning" />
                            <div>
                                <div className="font-medium">
                                    No family-portal contacts linked.
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Add a family-portal contact to this client
                                    first (Family tab → invite). Then return
                                    here to request consent.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <form
                    onSubmit={submit}
                    className="space-y-6"
                    data-test="consent-request-create-form"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                What are you asking permission for?
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="consent_type_id">
                                    Consent type
                                </Label>
                                <Select
                                    value={data.consent_type_id}
                                    onValueChange={(v) =>
                                        setData('consent_type_id', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="consent_type_id"
                                        data-test="consent-type-select"
                                    >
                                        <SelectValue placeholder="Pick a consent type…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {consent_types.map((ct) => (
                                            <SelectItem
                                                key={ct.id}
                                                value={String(ct.id)}
                                            >
                                                {ct.name}{' '}
                                                <span className="text-muted-foreground">
                                                    ({ct.category})
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.consent_type_id && (
                                    <Err msg={errors.consent_type_id} />
                                )}
                            </div>

                            <div>
                                <Label htmlFor="purpose">
                                    Purpose (shown verbatim to the signatory)
                                </Label>
                                <Textarea
                                    id="purpose"
                                    data-test="consent-purpose-input"
                                    rows={3}
                                    value={data.purpose}
                                    onChange={(e) =>
                                        setData('purpose', e.target.value)
                                    }
                                    placeholder="e.g. Monitor location of personal GPS tracker for safety after documented wandering incidents 2026-04-01 and 2026-04-08."
                                />
                                {errors.purpose && <Err msg={errors.purpose} />}
                            </div>

                            <div>
                                <Label htmlFor="least_restrictive_justification">
                                    Least-restrictive justification
                                </Label>
                                <Textarea
                                    id="least_restrictive_justification"
                                    rows={2}
                                    value={data.least_restrictive_justification}
                                    onChange={(e) =>
                                        setData(
                                            'least_restrictive_justification',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Alternatives reviewed (staffing increase, environmental modifications). Tracker chosen as least restrictive."
                                />
                                {errors.least_restrictive_justification && (
                                    <Err
                                        msg={
                                            errors.least_restrictive_justification
                                        }
                                    />
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="data_scope">
                                        Who sees the data
                                    </Label>
                                    <Input
                                        id="data_scope"
                                        data-test="consent-data-scope-input"
                                        value={data.data_scope}
                                        onChange={(e) =>
                                            setData(
                                                'data_scope',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Care team + on-call coordinator"
                                    />
                                    {errors.data_scope && (
                                        <Err msg={errors.data_scope} />
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="retention_period_days">
                                        Data retention (days)
                                    </Label>
                                    <Input
                                        id="retention_period_days"
                                        data-test="consent-retention-days-input"
                                        type="number"
                                        min={1}
                                        max={3650}
                                        value={data.retention_period_days}
                                        onChange={(e) =>
                                            setData(
                                                'retention_period_days',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="180"
                                    />
                                    {errors.retention_period_days && (
                                        <Err
                                            msg={errors.retention_period_days}
                                        />
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="withdrawal_method_text">
                                    How to withdraw
                                </Label>
                                <Textarea
                                    id="withdrawal_method_text"
                                    data-test="consent-withdrawal-input"
                                    rows={2}
                                    value={data.withdrawal_method_text}
                                    onChange={(e) =>
                                        setData(
                                            'withdrawal_method_text',
                                            e.target.value,
                                        )
                                    }
                                />
                                {errors.withdrawal_method_text && (
                                    <Err msg={errors.withdrawal_method_text} />
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Who is signing?</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="recipient_user_id">
                                    Family-portal recipient
                                </Label>
                                <Select
                                    value={data.recipient_user_id}
                                    onValueChange={(v) =>
                                        setData('recipient_user_id', v)
                                    }
                                    disabled={noPortalUsers}
                                >
                                    <SelectTrigger
                                        id="recipient_user_id"
                                        data-test="consent-recipient-select"
                                    >
                                        <SelectValue placeholder="Pick a portal user…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {portal_users.map((u) => (
                                            <SelectItem
                                                key={u.id}
                                                value={String(u.id)}
                                            >
                                                {u.name} ({u.email})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.recipient_user_id && (
                                    <Err msg={errors.recipient_user_id} />
                                )}
                            </div>

                            <div>
                                <Label htmlFor="recipient_relationship">
                                    Authority relationship
                                </Label>
                                <Select
                                    value={data.recipient_relationship}
                                    onValueChange={(v) =>
                                        setData({
                                            ...data,
                                            ...(!isSubstituteRelationship(v)
                                                ? EMPTY_DECISION_EVIDENCE
                                                : {}),
                                            recipient_relationship: v,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="recipient_relationship"
                                        data-test="consent-relationship-select"
                                    >
                                        <SelectValue placeholder="Select relationship…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(
                                            relationship_options,
                                        ).map(([k, v]) => (
                                            <SelectItem key={k} value={k}>
                                                {v}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Substituted consent under PPPR Act 1988
                                    requires the recipient holds welfare
                                    guardianship, EPOA — Personal Care &amp;
                                    Welfare, or equivalent court order.
                                    Next-of-kin alone is informational only.
                                </p>
                                {errors.recipient_relationship && (
                                    <Err msg={errors.recipient_relationship} />
                                )}
                            </div>

                            <div>
                                <Label htmlFor="expires_in_days">
                                    Auto-expire request after (days)
                                </Label>
                                <Input
                                    id="expires_in_days"
                                    data-test="consent-expires-days-input"
                                    type="number"
                                    min={1}
                                    max={60}
                                    value={data.expires_in_days}
                                    onChange={(e) =>
                                        setData(
                                            'expires_in_days',
                                            e.target.value,
                                        )
                                    }
                                />
                                {errors.expires_in_days && (
                                    <Err msg={errors.expires_in_days} />
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {requiresDecisionEvidence && (
                        <Card data-test="substitute-decision-evidence">
                            <CardHeader>
                                <CardTitle>
                                    Capacity and best-interests evidence
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <p className="text-sm text-muted-foreground">
                                    Record the decision-specific assessment and
                                    best-interests process separately from the
                                    representative&apos;s authority. You will be
                                    recorded as the assessor and evidence
                                    recorder for this request.
                                </p>

                                <div>
                                    <Label htmlFor="capacity_outcome">
                                        Capacity assessment outcome
                                    </Label>
                                    <Select
                                        value={data.capacity_outcome}
                                        onValueChange={(v) =>
                                            setData('capacity_outcome', v)
                                        }
                                    >
                                        <SelectTrigger
                                            id="capacity_outcome"
                                            data-test="capacity-outcome-select"
                                        >
                                            <SelectValue placeholder="Select the recorded outcome…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="lacks_capacity">
                                                Lacks capacity for this decision
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.capacity_outcome && (
                                        <Err msg={errors.capacity_outcome} />
                                    )}
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="capacity_assessed_at">
                                            Assessed at
                                        </Label>
                                        <Input
                                            id="capacity_assessed_at"
                                            data-test="capacity-assessed-at-input"
                                            type="datetime-local"
                                            required
                                            value={data.capacity_assessed_at}
                                            onChange={(e) =>
                                                setData(
                                                    'capacity_assessed_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {errors.capacity_assessed_at && (
                                            <Err
                                                msg={
                                                    errors.capacity_assessed_at
                                                }
                                            />
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="capacity_assessment_expires_at">
                                            Assessment expires at
                                        </Label>
                                        <Input
                                            id="capacity_assessment_expires_at"
                                            data-test="capacity-expires-at-input"
                                            type="datetime-local"
                                            required
                                            value={
                                                data.capacity_assessment_expires_at
                                            }
                                            onChange={(e) =>
                                                setData(
                                                    'capacity_assessment_expires_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {errors.capacity_assessment_expires_at && (
                                            <Err
                                                msg={
                                                    errors.capacity_assessment_expires_at
                                                }
                                            />
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="capacity_assessment_reason">
                                        Assessment reason
                                    </Label>
                                    <Textarea
                                        id="capacity_assessment_reason"
                                        data-test="capacity-reason-input"
                                        rows={3}
                                        required
                                        minLength={20}
                                        maxLength={2000}
                                        value={data.capacity_assessment_reason}
                                        onChange={(e) =>
                                            setData(
                                                'capacity_assessment_reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Describe why the client could not understand, retain, use or weigh the information for this specific decision."
                                    />
                                    {errors.capacity_assessment_reason && (
                                        <Err
                                            msg={
                                                errors.capacity_assessment_reason
                                            }
                                        />
                                    )}
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="capacity_evidence_type">
                                            Assessment evidence type
                                        </Label>
                                        <Input
                                            id="capacity_evidence_type"
                                            data-test="capacity-evidence-type-input"
                                            required
                                            maxLength={80}
                                            value={data.capacity_evidence_type}
                                            onChange={(e) =>
                                                setData(
                                                    'capacity_evidence_type',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Documented assessment"
                                        />
                                        {errors.capacity_evidence_type && (
                                            <Err
                                                msg={
                                                    errors.capacity_evidence_type
                                                }
                                            />
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="capacity_evidence_reference">
                                            Assessment evidence reference
                                        </Label>
                                        <Input
                                            id="capacity_evidence_reference"
                                            data-test="capacity-evidence-reference-input"
                                            required
                                            maxLength={255}
                                            value={
                                                data.capacity_evidence_reference
                                            }
                                            onChange={(e) =>
                                                setData(
                                                    'capacity_evidence_reference',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Record or document reference"
                                        />
                                        {errors.capacity_evidence_reference && (
                                            <Err
                                                msg={
                                                    errors.capacity_evidence_reference
                                                }
                                            />
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="best_interests_process_reason">
                                        Best-interests process and reason
                                    </Label>
                                    <Textarea
                                        id="best_interests_process_reason"
                                        data-test="best-interests-reason-input"
                                        rows={3}
                                        required
                                        minLength={20}
                                        maxLength={2000}
                                        value={
                                            data.best_interests_process_reason
                                        }
                                        onChange={(e) =>
                                            setData(
                                                'best_interests_process_reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Record known wishes, foreseeable effects, consultation and less restrictive alternatives considered for this decision."
                                    />
                                    {errors.best_interests_process_reason && (
                                        <Err
                                            msg={
                                                errors.best_interests_process_reason
                                            }
                                        />
                                    )}
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="best_interests_evidence_type">
                                            Best-interests evidence type
                                        </Label>
                                        <Input
                                            id="best_interests_evidence_type"
                                            data-test="best-interests-evidence-type-input"
                                            required
                                            maxLength={80}
                                            value={
                                                data.best_interests_evidence_type
                                            }
                                            onChange={(e) =>
                                                setData(
                                                    'best_interests_evidence_type',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Multidisciplinary review"
                                        />
                                        {errors.best_interests_evidence_type && (
                                            <Err
                                                msg={
                                                    errors.best_interests_evidence_type
                                                }
                                            />
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="best_interests_evidence_reference">
                                            Best-interests evidence reference
                                        </Label>
                                        <Input
                                            id="best_interests_evidence_reference"
                                            data-test="best-interests-evidence-reference-input"
                                            required
                                            maxLength={255}
                                            value={
                                                data.best_interests_evidence_reference
                                            }
                                            onChange={(e) =>
                                                setData(
                                                    'best_interests_evidence_reference',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Meeting or review reference"
                                        />
                                        {errors.best_interests_evidence_reference && (
                                            <Err
                                                msg={
                                                    errors.best_interests_evidence_reference
                                                }
                                            />
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="best_interests_consultees">
                                        People consulted
                                    </Label>
                                    <Textarea
                                        id="best_interests_consultees"
                                        data-test="best-interests-consultees-input"
                                        rows={3}
                                        required
                                        value={data.best_interests_consultees.join(
                                            '\n',
                                        )}
                                        onChange={(e) =>
                                            setData(
                                                'best_interests_consultees',
                                                e.target.value.split('\n'),
                                            )
                                        }
                                        placeholder="One person or role per line"
                                    />
                                    {errors.best_interests_consultees && (
                                        <Err
                                            msg={
                                                errors.best_interests_consultees
                                            }
                                        />
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Internal notes (not shown to signatory)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                id="staff_notes"
                                data-test="consent-staff-notes-input"
                                rows={3}
                                value={data.staff_notes}
                                onChange={(e) =>
                                    setData('staff_notes', e.target.value)
                                }
                                placeholder="Clinical context, prior conversations, etc."
                            />
                            {errors.staff_notes && (
                                <Err msg={errors.staff_notes} />
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || noPortalUsers}
                            data-test="consent-request-submit"
                        >
                            <Send className="mr-2 h-4 w-4" />
                            {processing ? 'Sending…' : 'Send to family portal'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}

function Err({ msg }: { msg: string }) {
    return <p className="mt-1 text-xs text-status-critical">{msg}</p>;
}
