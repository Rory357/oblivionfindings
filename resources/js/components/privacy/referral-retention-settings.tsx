import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    AlwaysOn,
    NumberRow,
    SaveBar,
    SettingGroup,
    SettingRow,
} from '@/components/referrals/settings-ui';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Link, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { useState } from 'react';

/** One referral retention period, backed by one governed retention policy (server: ReferralRetention). */
export type ReferralRetentionRow = {
    key: string;
    label: string;
    explanation: string;
    unit: 'years';
    default_years: number;
    years: number;
    policy: null | {
        id: number;
        name: string;
        active: boolean;
        execution_state: string;
        preview_current: boolean;
        approval_current: boolean;
    };
};

const MAX_YEARS = 50;

/** Where the period's policy stands: nothing runs until another person approves a current preview. */
export function periodStatus(row: ReferralRetentionRow): {
    variant: StatusVariant;
    text: string;
} {
    const policy = row.policy;
    if (!policy) return { variant: 'neutral', text: 'Not set up' };
    if (!policy.active)
        return { variant: 'neutral', text: 'Policy turned off' };
    if (policy.execution_state === 'approved' && policy.approval_current)
        return { variant: 'success', text: 'Approved' };
    if (policy.execution_state === 'previewed' && policy.preview_current)
        return { variant: 'info', text: 'Waiting for a second person' };
    return { variant: 'warning', text: 'Needs a preview' };
}

const years = (value: number) => `${value} ${value === 1 ? 'year' : 'years'}`;

/**
 * Referral retention periods as settings rows (Stephan, 21 Oct 2026: "can you make it a setting").
 * Saving creates or changes the governed policies; each changed period still needs a fresh preview
 * and a different person's approval on Review data before anything is removed.
 */
export function ReferralRetentionSettings({
    rows,
    canManage,
}: {
    rows: ReferralRetentionRow[];
    canManage: boolean;
}) {
    const saved = Object.fromEntries(rows.map((row) => [row.key, row.years]));
    const [values, setValues] = useState<Record<string, number>>(saved);
    const [reviewing, setReviewing] = useState(false);
    const [busy, setBusy] = useState(false);
    const changes = rows.filter(
        (row) => !row.policy || values[row.key] !== row.years,
    );
    const invalid = rows.filter((row) => {
        const value = values[row.key];
        return !Number.isInteger(value) || value < 1 || value > MAX_YEARS;
    });
    const shortened = changes.some(
        (row) => row.policy && values[row.key] < row.years,
    );
    const save = () => {
        setBusy(true);
        router.post(
            '/privacy/retention/referral-periods',
            { periods: values },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setReviewing(false);
                },
            },
        );
    };

    return (
        <div className="space-y-3">
            <SettingGroup
                icon={Inbox}
                title="Referral retention"
                caption="How long referral data is kept before an approved run removes the personal details. Referrals are never deleted, and nothing runs until another person approves a preview."
                actions={
                    <Button variant="link" size="sm" asChild>
                        <Link href="/privacy/retention/review">
                            Review data ↗
                        </Link>
                    </Button>
                }
            >
                {rows.map((row) => {
                    const status = periodStatus(row);
                    return (
                        <NumberRow
                            key={row.key}
                            label={row.label}
                            hint={
                                <>
                                    {row.explanation}{' '}
                                    {!row.policy &&
                                        `Suggested: ${years(row.default_years)}. `}
                                    <StatusBadge
                                        variant={status.variant}
                                        size="sm"
                                    >
                                        {status.text}
                                    </StatusBadge>
                                </>
                            }
                            value={values[row.key]}
                            onChange={(value) =>
                                setValues((current) => ({
                                    ...current,
                                    [row.key]: value,
                                }))
                            }
                            unit={row.unit}
                            min={1}
                            max={MAX_YEARS}
                            disabled={!canManage}
                        />
                    );
                })}
                <SettingRow
                    label="Service agreement links"
                    hint="Contract evidence linking a support proposal to its service agreement. Kept while the person’s record or the agreement exists, so it has no period."
                    control={<AlwaysOn>No period</AlwaysOn>}
                />
            </SettingGroup>
            {invalid.length > 0 && (
                <p role="alert" className="text-subtle">
                    Enter a whole number of years from 1 to {MAX_YEARS} for{' '}
                    {invalid.map((row) => row.label).join(', ')}.
                </p>
            )}
            {canManage && (
                <SaveBar
                    count={changes.length}
                    busy={busy}
                    onDiscard={() => setValues(saved)}
                    onReview={() => {
                        if (!invalid.length) setReviewing(true);
                    }}
                    note="Saving sets up or changes the retention policies. Each one runs only after a fresh preview and a second person’s approval on Review data."
                />
            )}
            <ConfirmDialog
                open={reviewing}
                onClose={() => setReviewing(false)}
                onConfirm={save}
                processing={busy}
                variant={shortened ? 'destructive' : 'default'}
                title="Save referral retention periods?"
                description={
                    <span className="block space-y-2">
                        <span className="block">
                            {changes.map((row) => (
                                <span key={row.key} className="block">
                                    {row.label}:{' '}
                                    {row.policy
                                        ? `${years(row.years)} → ${years(values[row.key])}`
                                        : `${years(values[row.key])} (new policy)`}
                                </span>
                            ))}
                        </span>
                        <span className="block">
                            {shortened
                                ? 'A shorter period removes details sooner. '
                                : ''}
                            Nothing is removed until each policy has a fresh
                            preview and a second person approves it.
                        </span>
                    </span>
                }
                confirmText="Save periods"
            />
        </div>
    );
}
