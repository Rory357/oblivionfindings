/* Medication › Settings tabs (eMAR P11 B1): Medication rules › Safety checks;
 * Staff & PINs › Witness PINs and PIN status. Saved settings are edited as a
 * draft (see Settings.tsx); PIN resets are records with their own action.
 * Medicine rules are in _rules.tsx. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateLong, formatTime } from '@/lib/datetime';
import {
    WITNESS_PIN_STATUS_LABEL,
    type WitnessPinStatus,
} from '@/lib/witness-pin';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    KeyRound,
    ListChecks,
    LockKeyhole,
    RefreshCw,
    UserCheck,
    UserRound,
    Users,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useSettings } from './_context';
import { draftValue, isDirty, reviewerOf, withDraft } from './_model';
import {
    Choice,
    GroupGrid,
    GroupRow,
    Note,
    OnOff,
    RowMenu,
    Section,
    SettingGroup,
    type RowState,
} from './_ui';

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

export function useRow(group: string) {
    const { s, draft, setDraft, canEdit } = useSettings();
    const value = (key: string) => draftValue(s, draft, group, key);
    const edit = (key: string, v: string) =>
        setDraft((d) => withDraft(d, group, key, v));
    const state = (key: string): RowState =>
        isDirty(s, draft, group, key)
            ? 'changed'
            : reviewerOf(s, group, key)
              ? null
              : 'default';
    const disabled = !canEdit(group);
    /** Show filter + search: does this row stay visible? */
    const shown = (show: string, q: string, key: string, ...text: string[]) =>
        (show === 'open'
            ? !reviewerOf(s, group, key)
            : show === 'changed'
              ? isDirty(s, draft, group, key)
              : true) && match(q, ...text);
    return { s, value, edit, state, disabled, shown };
}

export function NoMatches({ q, clear }: { q: string; clear: () => void }) {
    return (
        <EmptyState
            icon={ListChecks}
            title={
                q
                    ? `No settings in this tab match “${q}”`
                    : 'No settings match this filter'
            }
            description="Try another tab, or clear the search and filter."
            action={
                <Button variant="outline" size="sm" onClick={clear}>
                    Clear search and filter
                </Button>
            }
        />
    );
}

/* ── Medication rules › Safety checks (P00 v5 options, grouped; switches for on/off) ── */
const SAFETY_SWITCH: Record<string, { off: string; on: [string, string][] }> = {
    restricted_competency: {
        off: 'off',
        on: [
            ['block', 'Block'],
            ['cosigner', 'Co-signer with witness PIN'],
        ],
    },
    competency_areas: {
        off: 'off',
        on: [
            ['failed', 'When the area was failed'],
            ['failed_or_not_seen', 'When failed or not seen'],
        ],
    },
};

export function SafetyChecks({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { s, value, edit, state, disabled, shown } = useRow('safety');
    const label = (key: string) => s.definitions.safety?.[key]?.label ?? key;
    const onOff = (key: string, hint: string): ReactNode => {
        const sw = SAFETY_SWITCH[key];
        const on = value(key) !== sw.off;
        const saved = s.values.safety?.[key];
        return (
            <GroupRow
                key={key}
                id={`sf-${key}`}
                label={label(key)}
                hint={hint}
                state={state(key)}
                hidden={!shown(show, q, key, label(key))}
                control={
                    <OnOff
                        id={`sf-${key}`}
                        checked={on}
                        disabled={disabled}
                        onChange={(v) =>
                            edit(
                                key,
                                v
                                    ? saved && saved !== sw.off
                                        ? saved
                                        : sw.on[0][0]
                                    : sw.off,
                            )
                        }
                    />
                }
            >
                {on ? (
                    <Choice
                        value={value(key)}
                        options={sw.on}
                        disabled={disabled}
                        onChange={(v) => edit(key, v)}
                    />
                ) : null}
            </GroupRow>
        );
    };
    return (
        <Section
            id="sc-safety"
            title="Safety checks when a dose is signed"
            caption="Every house"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="allergy"
                    icon={AlertTriangle}
                    title="Allergies"
                    caption="When a medicine matches an allergy on the person’s record"
                    wide
                >
                    <GroupRow
                        id="sf-allergy"
                        label="When there’s a match"
                        hint="The match is always shown before signing."
                        state={state('profile_allergy_match')}
                        hidden={
                            !shown(
                                show,
                                q,
                                'profile_allergy_match',
                                label('profile_allergy_match'),
                            )
                        }
                    >
                        <Choice
                            value={value('profile_allergy_match')}
                            disabled={disabled}
                            onChange={(v) => edit('profile_allergy_match', v)}
                            options={[
                                ['warn', 'Warn'],
                                ['block', 'Block'],
                            ]}
                        />
                    </GroupRow>
                </SettingGroup>
                <SettingGroup
                    id="competency"
                    icon={UserCheck}
                    title="Competency"
                    caption="Checks on the person signing a dose as given"
                >
                    {onOff(
                        'restricted_competency',
                        'Refused and withheld can always be recorded.',
                    )}
                    {onOff(
                        'competency_areas',
                        'Controlled drugs and covert plans. Insulin isn’t checked yet.',
                    )}
                </SettingGroup>
            </GroupGrid>
        </Section>
    );
}

/* ── Staff & PINs › Witness PINs (PIN-1 rules on the P11 groups) ── */
export function WitnessPins({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { s, value, edit, state, disabled, shown } = useRow('pin');
    const label = (key: string) => s.definitions.pin?.[key]?.label ?? key;
    const renewal = value('renewal_months');
    const renOn = renewal !== 'none';
    const savedRenewal = s.values.pin?.renewal_months;
    return (
        <Section
            id="sc-pins"
            title="Second-person confirmation"
            caption="Co-signing, witnessing controlled drugs, and confirming a different amount"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="method"
                    icon={KeyRound}
                    title="Witness PIN"
                    caption="Each person sets their own in their account"
                >
                    <GroupRow
                        id="pr-method"
                        label="Method"
                        hint="The colleague’s login password is no longer used for this."
                        hidden={
                            !match(q, 'Method', 'Personal 6-digit PIN') ||
                            show !== 'all'
                        }
                        control={
                            <StatusBadge variant="neutral">
                                <LockKeyhole className="size-3" />
                                Personal 6-digit PIN
                            </StatusBadge>
                        }
                    />
                    <GroupRow
                        id="pr-renon"
                        label={label('renewal_months')}
                        hint={
                            renOn
                                ? `People choose a new PIN every ${renewal} months.`
                                : 'No renewal — a PIN stays until its owner changes it.'
                        }
                        state={state('renewal_months')}
                        hidden={
                            !shown(
                                show,
                                q,
                                'renewal_months',
                                label('renewal_months'),
                            )
                        }
                        control={
                            <OnOff
                                id="pr-renon"
                                checked={renOn}
                                disabled={disabled}
                                onChange={(v) =>
                                    edit(
                                        'renewal_months',
                                        v
                                            ? savedRenewal &&
                                              savedRenewal !== 'none'
                                                ? savedRenewal
                                                : '12'
                                            : 'none',
                                    )
                                }
                            />
                        }
                    >
                        {renOn ? (
                            <Choice
                                value={renewal}
                                disabled={disabled}
                                onChange={(v) => edit('renewal_months', v)}
                                options={[
                                    ['6', 'Every 6 months'],
                                    ['12', 'Every 12 months'],
                                ]}
                            />
                        ) : null}
                    </GroupRow>
                </SettingGroup>
                <SettingGroup
                    id="locking"
                    icon={LockKeyhole}
                    title="Locking"
                    caption="After wrong PINs, across all screens"
                >
                    <GroupRow
                        id="pr-attempts"
                        label="Locks after"
                        hint="Counts wrong PINs typed for one person."
                        state={state('max_attempts')}
                        hidden={
                            !shown(
                                show,
                                q,
                                'max_attempts',
                                'Locks after',
                                label('max_attempts'),
                            )
                        }
                    >
                        <Choice
                            value={value('max_attempts')}
                            disabled={disabled}
                            onChange={(v) => edit('max_attempts', v)}
                            options={[
                                ['3', '3 attempts'],
                                ['5', '5 attempts'],
                                ['10', '10 attempts'],
                            ]}
                        />
                    </GroupRow>
                    <GroupRow
                        id="pr-lockout"
                        label="Stays locked for"
                        hint="Or until the owner resets it."
                        state={state('lockout_minutes')}
                        hidden={
                            !shown(
                                show,
                                q,
                                'lockout_minutes',
                                'Stays locked for',
                                label('lockout_minutes'),
                            )
                        }
                    >
                        <Choice
                            value={value('lockout_minutes')}
                            disabled={disabled}
                            onChange={(v) => edit('lockout_minutes', v)}
                            options={[
                                ['5', '5 minutes'],
                                ['15', '15 minutes'],
                                ['30', '30 minutes'],
                                ['60', '60 minutes'],
                            ]}
                        />
                    </GroupRow>
                </SettingGroup>
                {/* Who may reset is a permission today (Settings › Roles); it becomes a
                    setting with its enforcement (chunk 5), so this group only explains it. */}
                <SettingGroup
                    id="reset"
                    icon={RefreshCw}
                    title="Who can reset a PIN"
                    caption="A reset never shows or sets the PIN"
                >
                    <GroupRow
                        id="pr-reset"
                        label="Who can reset another person’s PIN"
                        hint="The owner must choose a new PIN before they can co-sign or witness."
                        hidden={
                            show !== 'all' ||
                            !match(q, 'Who can reset another person’s PIN')
                        }
                    >
                        <p className="text-subtle">
                            People with the “Reset another person’s witness PIN”
                            permission — house leads and clinical leads by
                            default. A house lead can’t reset another lead’s or
                            an all-sites user’s PIN. Change it in Settings ›
                            Roles.
                        </p>
                    </GroupRow>
                </SettingGroup>
            </GroupGrid>
        </Section>
    );
}

/* ── Staff & PINs › PIN status (PIN-1 staff list; reset by people with the permission) ── */
export type WitnessPinStaffRow = {
    id: number;
    name: string;
    status: WitnessPinStatus;
    set_at: string | null;
    locked_until: string | null;
    reset_at: string | null;
    /** False for people with broader authority than a house lead. */
    can_reset: boolean;
};
export type WitnessPinProps = {
    can_reset: boolean;
    staff: WitnessPinStaffRow[];
};
export const PIN_STATUS_OPTIONS = [
    { value: 'all', label: 'Any PIN status' },
    { value: 'set', label: WITNESS_PIN_STATUS_LABEL.set },
    { value: 'not_set', label: WITNESS_PIN_STATUS_LABEL.not_set },
    { value: 'locked', label: WITNESS_PIN_STATUS_LABEL.locked },
    { value: 'reset', label: WITNESS_PIN_STATUS_LABEL.reset },
    { value: 'expired', label: WITNESS_PIN_STATUS_LABEL.expired },
];
const PIN_VARIANT: Record<
    WitnessPinStatus,
    'success' | 'warning' | 'critical'
> = {
    set: 'success',
    not_set: 'warning',
    locked: 'critical',
    reset: 'warning',
    expired: 'warning',
};
function pinDetail(row: WitnessPinStaffRow): string {
    if (row.status === 'locked' && row.locked_until)
        return `Locked until ${formatTime(row.locked_until)} after wrong attempts`;
    if (row.status === 'reset' && row.reset_at)
        return `Reset on ${formatDateLong(row.reset_at)} — can’t co-sign or witness yet`;
    if (row.status === 'not_set')
        return 'Can’t be chosen to co-sign or witness';
    if (row.status === 'expired')
        return 'Must choose a new PIN before co-signing or witnessing';
    return row.set_at ? `Last changed ${formatDateLong(row.set_at)}` : '';
}

export function PinStatus({
    witnessPin,
    q,
    state,
    clear,
}: {
    witnessPin: WitnessPinProps;
    q: string;
    state: string;
    clear: () => void;
}) {
    const { can_reset, staff } = witnessPin;
    const [target, setTarget] = useState<WitnessPinStaffRow | null>(null);
    const [resetting, setResetting] = useState(false);
    const menu = useEntityContextMenu<WitnessPinStaffRow>();
    const rows = staff.filter(
        (x) => (state === 'all' || x.status === state) && match(q, x.name),
    );
    const actions = (row: WitnessPinStaffRow): MenuItem[] =>
        compactMenu([
            can_reset &&
                row.can_reset &&
                row.status !== 'not_set' &&
                row.status !== 'reset' && {
                    label: 'Reset witness PIN',
                    icon: RefreshCw,
                    danger: true,
                    onClick: () => setTarget(row),
                },
        ]);
    const reset = () => {
        if (!target) return;
        router.post(
            `/emar/settings/witness-pins/${target.id}/reset`,
            {},
            {
                preserveScroll: true,
                onStart: () => setResetting(true),
                onFinish: () => {
                    setResetting(false);
                    setTarget(null);
                },
            },
        );
    };
    return (
        <Section
            id="sc-status"
            title="Staff witness PINs"
            caption={`${rows.length} of ${staff.length} shown`}
        >
            <p className="text-subtle">
                Status only — nobody can see or set another person’s PIN.
            </p>
            {!staff.length ? (
                <EmptyState
                    icon={Users}
                    title="No staff to show"
                    description="Nobody at your houses can act as a second person yet."
                />
            ) : rows.length ? (
                <EntityTable<WitnessPinStaffRow>
                    rows={rows}
                    rowKey={(x) => x.id}
                    identityLabel="Person"
                    identityWidth="1.4fr"
                    minWidth={760}
                    identity={(x) => ({ icon: UserRound, name: x.name })}
                    columns={[
                        {
                            key: 'pin',
                            label: 'Witness PIN',
                            width: '1.8fr',
                            cell: (x) => (
                                <div>
                                    <StatusBadge
                                        variant={PIN_VARIANT[x.status]}
                                        size="sm"
                                    >
                                        {WITNESS_PIN_STATUS_LABEL[x.status]}
                                    </StatusBadge>
                                    {pinDetail(x) ? (
                                        <p className="text-caption mt-1">
                                            {pinDetail(x)}
                                        </p>
                                    ) : null}
                                </div>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onRowContextMenu={menu.open}
                />
            ) : (
                <EmptyState
                    icon={Users}
                    title="Nobody matches"
                    description="Clear the filters or the search."
                    action={
                        <Button variant="outline" size="sm" onClick={clear}>
                            Clear filters
                        </Button>
                    }
                />
            )}
            <RowMenu
                ctx={
                    menu.ctx && actions(menu.ctx.record).length
                        ? menu.ctx
                        : null
                }
                close={menu.close}
                icon={KeyRound}
                title={(x) => x.name}
                items={actions}
            />
            <Note>
                {can_reset
                    ? 'You can reset the PIN of someone at your houses. The owner then chooses a new one in their account settings before they can co-sign or witness again.'
                    : 'Resetting a PIN needs the “Reset another person’s witness PIN” permission — house leads and clinical leads have it by default.'}
            </Note>
            <ConfirmDialog
                open={target !== null}
                onClose={() => setTarget(null)}
                onConfirm={reset}
                processing={resetting}
                title={`Reset ${target?.name ?? ''}’s witness PIN?`}
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
        </Section>
    );
}
