/* Organisation-wide medication safety rules (eMAR P0 decisions of 28 Sep
 * 2026): how a health-profile allergy match is handled (EM-07) and whether a
 * restricted or area-limited medication competency stops a worker signing a
 * dose as given (NF-03). Every rule starts at today's behaviour; the server
 * enforces the saved choice at the point of administration and audits each
 * change. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export type SafetyPolicyValues = {
    profile_allergy_match: 'warn' | 'block';
    restricted_competency: 'off' | 'block' | 'cosigner';
    competency_areas: 'off' | 'failed' | 'failed_or_not_seen';
};

type Field = keyof SafetyPolicyValues;

/** false = still on its default; nobody has deliberately saved a choice. */
export type SafetyPolicyReviewed = Record<Field, boolean>;

const RULES: {
    field: Field;
    label: string;
    help: string;
    options: { value: string; label: string }[];
}[] = [
    {
        field: 'profile_allergy_match',
        label: 'An allergy on the health profile matches the medicine',
        help: 'Health-profile allergies have no severity recorded. Severe allergies in the medication allergy list always block.',
        options: [
            { value: 'warn', label: 'Warn — show an allergy alert' },
            {
                value: 'block',
                label: 'Block — refuse to record the dose as given',
            },
        ],
    },
    {
        field: 'restricted_competency',
        label: 'A worker’s medication competency is marked restricted',
        help: 'Refusals and withheld doses can always be recorded. A co-signer confirms with their own witness PIN — never their login password.',
        options: [
            { value: 'off', label: 'Off — no extra check' },
            {
                value: 'block',
                label: 'Block — they can’t sign doses as given',
            },
            {
                value: 'cosigner',
                label: 'Co-signer with witness PIN — a present, qualified colleague confirms each dose with their own PIN',
            },
        ],
    },
    {
        field: 'competency_areas',
        label: 'The controlled-drug or covert area wasn’t passed',
        help: 'Applies to controlled-drug orders and orders with an active covert authorisation. Insulin isn’t covered yet: orders don’t record whether a medicine is insulin.',
        options: [
            { value: 'off', label: 'Off — no extra check' },
            { value: 'failed', label: 'Block when the area was failed' },
            {
                value: 'failed_or_not_seen',
                label: 'Block when failed or not seen at assessment',
            },
        ],
    },
];

export function SafetyPolicyCard({
    values,
    reviewed,
    canManage,
}: {
    values: SafetyPolicyValues;
    reviewed: SafetyPolicyReviewed;
    canManage: boolean;
}) {
    const [draft, setDraft] = useState<SafetyPolicyValues>(values);
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);
    // Saving confirms every rule shown, so an unreviewed default can be
    // confirmed without changing it.
    const changed = RULES.filter(
        (r) => draft[r.field] !== values[r.field] || !reviewed[r.field],
    );

    function save() {
        router.put('/emar/settings/safety-policy', draft, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => {
                setSaving(false);
                setConfirming(false);
            },
        });
    }

    return (
        <Card className="mb-5">
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    Organisation-wide safety rules
                </CardTitle>
                <p className="text-subtle">
                    These apply at every site when a dose is signed. Changes are
                    recorded in the audit log.
                </p>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {RULES.map((rule) => (
                    <div
                        key={rule.field}
                        className="grid gap-2 md:grid-cols-[1fr_minmax(0,22rem)] md:items-start"
                    >
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Label htmlFor={`safety-${rule.field}`}>
                                    {rule.label}
                                </Label>
                                {!reviewed[rule.field] ? (
                                    <StatusBadge variant="warning">
                                        Default — not yet reviewed
                                    </StatusBadge>
                                ) : null}
                            </div>
                            <p className="text-caption mt-1">{rule.help}</p>
                        </div>
                        <Select
                            value={draft[rule.field]}
                            onValueChange={(value) =>
                                setDraft((current) => ({
                                    ...current,
                                    [rule.field]: value,
                                }))
                            }
                            disabled={!canManage || saving}
                        >
                            <SelectTrigger id={`safety-${rule.field}`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {rule.options.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                ))}
                {canManage ? (
                    <div className="flex justify-end">
                        <Button
                            size="sm"
                            disabled={changed.length === 0 || saving}
                            onClick={() => setConfirming(true)}
                        >
                            Save safety rules
                        </Button>
                    </div>
                ) : (
                    <p className="text-caption">
                        Only someone who manages medication settings for all
                        sites can change these rules.
                    </p>
                )}
            </CardContent>
            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={save}
                processing={saving}
                title="Change the medication safety rules?"
                description={
                    <span className="flex flex-col gap-2">
                        <span>From the next dose signed, at every site:</span>
                        {changed.map((rule) => (
                            <span key={rule.field}>
                                <strong>{rule.label}:</strong>{' '}
                                {
                                    rule.options.find(
                                        (o) => o.value === draft[rule.field],
                                    )?.label
                                }
                            </span>
                        ))}
                    </span>
                }
                confirmText="Save rules"
            />
        </Card>
    );
}
