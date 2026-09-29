/* Second-person confirmation (eMAR P00 v5 / PIN-1): the organisation's
 * witness PIN rules and every staff member's PIN status. Nobody can see or
 * set another person's PIN; an authorised lead can reset it, which makes the
 * owner choose a new one. Rule changes and resets are audited. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    EntityContextMenu,
    EntityTable,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
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
import { formatDateLong, formatTime } from '@/lib/datetime';
import {
    WITNESS_PIN_STATUS_LABEL,
    type WitnessPinStatus,
} from '@/lib/witness-pin';
import { router } from '@inertiajs/react';
import { KeyRound, RefreshCw, UserRound } from 'lucide-react';
import { useState } from 'react';

export type WitnessPinRuleValues = {
    max_attempts: '3' | '5' | '10';
    lockout_minutes: '5' | '15' | '30' | '60';
    renewal_months: 'none' | '6' | '12';
};

type Field = keyof WitnessPinRuleValues;

export type WitnessPinStaffRow = {
    id: number;
    name: string;
    status: WitnessPinStatus;
    set_at: string | null;
    locked_until: string | null;
    reset_at: string | null;
};

export type WitnessPinProps = {
    values: WitnessPinRuleValues;
    /** false = still the shipped default, never deliberately saved. */
    reviewed: Record<Field, boolean>;
    can_manage: boolean;
    can_reset: boolean;
    staff: WitnessPinStaffRow[];
};

const RULES: {
    field: Field;
    label: string;
    help: string;
    options: { value: string; label: string }[];
}[] = [
    {
        field: 'max_attempts',
        label: 'Wrong attempts before a PIN locks',
        help: 'Counts wrong PINs typed for one person, across every screen.',
        options: [
            { value: '3', label: '3 attempts' },
            { value: '5', label: '5 attempts' },
            { value: '10', label: '10 attempts' },
        ],
    },
    {
        field: 'lockout_minutes',
        label: 'How long a locked PIN stays locked',
        help: 'The owner can reset it sooner by confirming their login password.',
        options: [
            { value: '5', label: '5 minutes' },
            { value: '15', label: '15 minutes' },
            { value: '30', label: '30 minutes' },
            { value: '60', label: '60 minutes' },
        ],
    },
    {
        field: 'renewal_months',
        label: 'PIN renewal',
        help: 'When set, people are asked to choose a new PIN after this long.',
        options: [
            { value: 'none', label: 'No renewal' },
            { value: '6', label: 'Every 6 months' },
            { value: '12', label: 'Every 12 months' },
        ],
    },
];

const STATUS_VARIANT: Record<
    WitnessPinStatus,
    'success' | 'warning' | 'critical'
> = {
    set: 'success',
    not_set: 'warning',
    locked: 'critical',
    reset: 'warning',
    expired: 'warning',
};

function statusDetail(row: WitnessPinStaffRow): string {
    if (row.status === 'locked' && row.locked_until) {
        return `Locked until ${formatTime(row.locked_until)} after wrong attempts`;
    }
    if (row.status === 'reset' && row.reset_at) {
        return `Reset on ${formatDateLong(row.reset_at)} — can’t co-sign or witness yet`;
    }
    if (row.status === 'not_set') {
        return 'Can’t be chosen to co-sign or witness';
    }
    if (row.status === 'expired') {
        return 'Must choose a new PIN before co-signing or witnessing';
    }

    return row.set_at ? `Last changed ${formatDateLong(row.set_at)}` : '';
}

export function WitnessPinCard({
    witnessPin,
}: {
    witnessPin: WitnessPinProps;
}) {
    const { values, reviewed, can_manage, can_reset, staff } = witnessPin;
    const [draft, setDraft] = useState<WitnessPinRuleValues>(values);
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);
    const [resetTarget, setResetTarget] = useState<WitnessPinStaffRow | null>(
        null,
    );
    const [resetting, setResetting] = useState(false);
    const ctxMenu = useEntityContextMenu<WitnessPinStaffRow>();

    // Saving confirms every rule shown, so a shipped default can be confirmed
    // without changing it.
    const changed = RULES.filter(
        (r) => draft[r.field] !== values[r.field] || !reviewed[r.field],
    );

    function save() {
        router.put('/emar/settings/witness-pin-rules', draft, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => {
                setSaving(false);
                setConfirming(false);
            },
        });
    }

    function reset() {
        if (!resetTarget) return;
        router.post(
            `/emar/settings/witness-pins/${resetTarget.id}/reset`,
            {},
            {
                preserveScroll: true,
                onStart: () => setResetting(true),
                onFinish: () => {
                    setResetting(false);
                    setResetTarget(null);
                },
            },
        );
    }

    const actionsFor = (row: WitnessPinStaffRow): MenuItem[] =>
        can_reset && row.status !== 'not_set' && row.status !== 'reset'
            ? [
                  {
                      label: 'Reset PIN (they must set a new one)',
                      icon: RefreshCw,
                      onClick: () => setResetTarget(row),
                      danger: true,
                  },
              ]
            : [];

    return (
        <Card className="mb-5">
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    Second-person confirmation
                </CardTitle>
                <p className="text-subtle">
                    For controlled-drug witnessing, restricted-competency
                    co-signing and verbal-order read-backs, at every site. The
                    colleague types their own witness PIN on the recorder’s
                    screen.
                </p>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="grid gap-2 md:grid-cols-[1fr_minmax(0,22rem)] md:items-start">
                    <div>
                        <p className="text-sm font-medium">Method</p>
                        <p className="text-caption mt-1">
                            Chosen by Stephan on 29 September 2026. The
                            colleague’s login password is no longer accepted.
                        </p>
                    </div>
                    <p className="flex items-center gap-2 text-sm">
                        <KeyRound className="h-4 w-4 text-muted-foreground" />
                        Personal witness PIN · 6 digits
                    </p>
                </div>
                {RULES.map((rule) => (
                    <div
                        key={rule.field}
                        className="grid gap-2 md:grid-cols-[1fr_minmax(0,22rem)] md:items-start"
                    >
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Label htmlFor={`witness-pin-${rule.field}`}>
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
                            disabled={!can_manage || saving}
                        >
                            <SelectTrigger id={`witness-pin-${rule.field}`}>
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
                <div className="grid gap-2 md:grid-cols-[1fr_minmax(0,22rem)] md:items-start">
                    <div>
                        <p className="text-sm font-medium">
                            Who can reset another person’s PIN
                        </p>
                        <p className="text-caption mt-1">
                            A reset never shows or sets the PIN. The owner must
                            choose a new one before they can co-sign or witness.
                        </p>
                    </div>
                    <p className="text-sm">
                        People with the “Reset another person’s witness PIN”
                        permission — house leads and clinical leads by default.
                        Change it in Settings › Roles.
                    </p>
                </div>
                {can_manage ? (
                    <div className="flex justify-end">
                        <Button
                            size="sm"
                            disabled={changed.length === 0 || saving}
                            onClick={() => setConfirming(true)}
                        >
                            Save PIN rules
                        </Button>
                    </div>
                ) : (
                    <p className="text-caption">
                        Only someone who manages medication settings for all
                        sites can change these rules.
                    </p>
                )}

                <div className="mt-2">
                    <p className="text-sm font-medium">Staff witness PINs</p>
                    <p className="text-caption mb-2">
                        Status only — nobody can see or set another person’s
                        PIN.
                    </p>
                    {staff.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No staff in your sites can act as a second person.
                        </p>
                    ) : (
                        <EntityTable
                            rows={staff}
                            rowKey={(row) => row.id}
                            identity={(row) => ({
                                icon: UserRound,
                                name: row.name,
                            })}
                            identityLabel="Staff member"
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Witness PIN',
                                    width: '1fr',
                                    cell: (row) => (
                                        <StatusBadge
                                            variant={STATUS_VARIANT[row.status]}
                                        >
                                            {
                                                WITNESS_PIN_STATUS_LABEL[
                                                    row.status
                                                ]
                                            }
                                        </StatusBadge>
                                    ),
                                },
                                {
                                    key: 'detail',
                                    label: 'Detail',
                                    width: '1.6fr',
                                    cell: (row) => (
                                        <span className="text-sm text-muted-foreground">
                                            {statusDetail(row)}
                                        </span>
                                    ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={(e, row) => ctxMenu.open(e, row)}
                            minWidth={640}
                        />
                    )}
                    {!can_reset ? (
                        <p className="text-caption mt-2">
                            You can see PIN status. Resetting a PIN needs the
                            “Reset another person’s witness PIN” permission.
                        </p>
                    ) : null}
                </div>
            </CardContent>
            {ctxMenu.ctx && actionsFor(ctxMenu.ctx.record).length > 0 ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={UserRound}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={save}
                processing={saving}
                title="Change the witness PIN rules?"
                description={
                    <span className="flex flex-col gap-2">
                        <span>From the next PIN typed, at every site:</span>
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
                        <span>
                            Recorded in the audit log with your name and the
                            time.
                        </span>
                    </span>
                }
                confirmText="Save PIN rules"
                variant="default"
            />
            <ConfirmDialog
                open={resetTarget !== null}
                onClose={() => setResetTarget(null)}
                onConfirm={reset}
                processing={resetting}
                title={`Reset ${resetTarget?.name ?? ''}’s witness PIN?`}
                description={
                    <span className="flex flex-col gap-2">
                        <span>
                            They won’t be able to co-sign or witness until they
                            choose a new PIN in Settings › Witness PIN.
                        </span>
                        <span>
                            Nobody sees the old or the new PIN. The reset is
                            recorded in the audit log.
                        </span>
                    </span>
                }
                confirmText="Reset PIN"
                variant="destructive"
            />
        </Card>
    );
}
