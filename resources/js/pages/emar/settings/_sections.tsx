/* Medication › Settings tabs (eMAR P11 B1): Medication rules › Safety checks;
 * Staff & PINs › Witness PINs and PIN status (the other Staff & PINs tabs are
 * in _staff.tsx). Saved settings are edited as a
 * draft (see Settings.tsx); PIN resets are records with their own action.
 * Medicine rules are in _rules.tsx. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityChip } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateLong, formatDateTime, formatTime } from '@/lib/datetime';
import {
    WITNESS_PIN_STATUS_LABEL,
    type WitnessPinStatus,
} from '@/lib/witness-pin';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Home,
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
import {
    definitionOf,
    draftValue,
    isDirty,
    notConfigured,
    reviewerOf,
    savedValue,
    withDraft,
} from './_model';
import {
    Choice,
    GroupGrid,
    GroupRow,
    Note,
    NumberInput,
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
    const unconfigured = (key: string) =>
        notConfigured(definitionOf(s, group, key), savedValue(s, group, key));
    const state = (key: string): RowState =>
        isDirty(s, draft, group, key)
            ? 'changed'
            : unconfigured(key)
              ? 'nc'
              : reviewerOf(s, group, key)
                ? null
                : 'default';
    const disabled = !canEdit(group);
    /** Show filter + search: does this row stay visible? */
    const shown = (show: string, q: string, key: string, ...text: string[]) =>
        (show === 'open'
            ? !reviewerOf(s, group, key) || unconfigured(key)
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

/* ── Staff & PINs › Witness PINs (P11 v5 rules as number inputs, Q-F). The
   forgotten-PIN fallback and the named-colleague confirmation aren't built,
   so they aren't shown. ── */
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
    const { errors, clearError } = useSettings();
    const def = (key: string) => s.definitions.pin?.[key];
    const label = (key: string) => def(key)?.label ?? key;
    const input = (key: string, unit: string, inputLabel?: string) => (
        <NumberInput
            id={`pr-${key}`}
            label={inputLabel}
            value={value(key)}
            unit={unit}
            min={def(key)?.range?.[0]}
            max={def(key)?.range?.[1]}
            disabled={disabled}
            error={errors[`pin.${key}`]}
            onChange={(v) => {
                edit(key, v);
                clearError(`pin.${key}`);
            }}
        />
    );
    const number = (
        key: string,
        rowLabel: string,
        hint: string,
        unit: string,
    ) => (
        <GroupRow
            key={key}
            id={`pr-${key}`}
            label={rowLabel}
            hint={hint}
            state={state(key)}
            error={errors[`pin.${key}`]}
            errorId={`pr-${key}-error`}
            hidden={!shown(show, q, key, rowLabel, label(key))}
            control={input(key, unit)}
        />
    );
    const off = def('renewal_months')?.numeric?.off ?? 'none';
    const renewal = value('renewal_months');
    const renOn = renewal !== off;
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
                                ? `People choose a new PIN every ${renewal || '…'} months.`
                                : 'No renewal — a PIN stays until its owner changes it.'
                        }
                        state={state('renewal_months')}
                        error={errors['pin.renewal_months']}
                        errorId="pr-renewal_months-error"
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
                                onChange={(v) => {
                                    // On asks for a number (v5): the saved one, or an empty box.
                                    edit(
                                        'renewal_months',
                                        v
                                            ? savedRenewal &&
                                              savedRenewal !== off
                                                ? savedRenewal
                                                : ''
                                            : off,
                                    );
                                    clearError('pin.renewal_months');
                                }}
                            />
                        }
                    >
                        {renOn
                            ? input(
                                  'renewal_months',
                                  'months',
                                  'Months between renewals',
                              )
                            : null}
                    </GroupRow>
                </SettingGroup>
                <SettingGroup
                    id="locking"
                    icon={LockKeyhole}
                    title="Locking"
                    caption="After wrong PINs, across all screens"
                >
                    {number(
                        'max_attempts',
                        'Locks after',
                        'Counts wrong PINs typed for one person.',
                        'attempts',
                    )}
                    {number(
                        'lockout_minutes',
                        'Stays locked for',
                        'Or until the owner resets it.',
                        'minutes',
                    )}
                </SettingGroup>
                {/* Who may reset is a permission (Settings › Roles), so this
                    group only explains it (P11 Q-F). */}
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

/* ── Staff & PINs › PIN status (P11 v5: PIN-1 staff list, house filter,
   reminders). People who can reset someone's PIN can reset it or remind them
   to set one — at most once a day each (Q-G). ── */
export type WitnessPinStaffRow = {
    id: number;
    name: string;
    status: WitnessPinStatus;
    set_at: string | null;
    locked_until: string | null;
    reset_at: string | null;
    /** False for people with broader authority than a house lead. */
    can_reset: boolean;
    /** Their own house (HR profile), for the house filter. */
    house?: string | null;
    reminded_at?: string | null;
    reminded_by?: string | null;
    /** Already reminded today (NZ): no second reminder until tomorrow. */
    reminded_today?: boolean;
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
/** The house filter's choices: every house in the list, by name. */
export const pinHouseOptions = (staff: WitnessPinStaffRow[]) => [
    { value: 'all', label: 'All houses' },
    ...[...new Set(staff.map((x) => x.house).filter(Boolean) as string[])]
        .sort((a, b) => a.localeCompare(b))
        .map((h) => ({ value: h, label: h })),
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
const reminded = (row: WitnessPinStaffRow) =>
    row.reminded_at
        ? `Reminded ${row.reminded_today ? `today ${formatTime(row.reminded_at)}` : formatDateTime(row.reminded_at)}${row.reminded_by ? `, by ${row.reminded_by}` : ''}`
        : '';
/** No usable PIN yet, someone this person can reset, not reminded today. */
export const canRemind = (can: boolean, row: WitnessPinStaffRow) =>
    can &&
    row.can_reset &&
    (row.status === 'not_set' || row.status === 'reset') &&
    !row.reminded_today;

export function PinStatus({
    witnessPin,
    q,
    state,
    house,
    clear,
}: {
    witnessPin: WitnessPinProps;
    q: string;
    state: string;
    house: string;
    clear: () => void;
}) {
    const { can_reset, staff } = witnessPin;
    const [target, setTarget] = useState<WitnessPinStaffRow | null>(null);
    const [resetting, setResetting] = useState(false);
    const [remind, setRemind] = useState<WitnessPinStaffRow[] | null>(null);
    const [reminding, setReminding] = useState(false);
    const menu = useEntityContextMenu<WitnessPinStaffRow>();
    const rows = staff.filter(
        (x) =>
            (state === 'all' || x.status === state) &&
            (house === 'all' || x.house === house) &&
            match(q, x.name, x.house),
    );
    const remindable = staff.filter((x) => canRemind(can_reset, x));
    const actions = (row: WitnessPinStaffRow): MenuItem[] =>
        compactMenu([
            canRemind(can_reset, row) && {
                label: 'Remind them to set a PIN',
                icon: Bell,
                onClick: () => setRemind([row]),
            },
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
    const sendReminders = () => {
        if (!remind?.length) return;
        router.post(
            '/emar/settings/witness-pins/remind',
            { user_ids: remind.map((x) => x.id) },
            {
                preserveScroll: true,
                onStart: () => setReminding(true),
                onFinish: () => {
                    setReminding(false);
                    setRemind(null);
                },
            },
        );
    };
    const names = (remind ?? []).map((x) => x.name);
    return (
        <Section
            id="sc-status"
            title="Staff witness PINs"
            caption={`${rows.length} of ${staff.length} shown`}
            right={
                remindable.length ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setRemind(remindable)}
                    >
                        <Bell />
                        Remind {remindable.length} to set a PIN
                    </Button>
                ) : undefined
            }
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
                    // A reminded row has up to three lines under its badge.
                    rowHeight="content"
                    identity={(x) => ({ icon: UserRound, name: x.name })}
                    columns={[
                        {
                            key: 'house',
                            label: 'House',
                            width: '1fr',
                            cell: (x) =>
                                x.house ? (
                                    <EntityChip icon={Home}>
                                        {x.house}
                                    </EntityChip>
                                ) : (
                                    <span className="text-subtle">
                                        No house
                                    </span>
                                ),
                        },
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
                                    {reminded(x) &&
                                    (x.status === 'not_set' ||
                                        x.status === 'reset') ? (
                                        <p className="text-caption mt-1">
                                            {reminded(x)}
                                        </p>
                                    ) : null}
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
                    ? 'You can reset the PIN of someone at your houses, or remind someone without one to set it. The owner then chooses a new one in their account settings before they can co-sign or witness again.'
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
            <ConfirmDialog
                open={remind !== null}
                onClose={() => setRemind(null)}
                onConfirm={sendReminders}
                processing={reminding}
                variant="default"
                title={
                    names.length === 1
                        ? `Remind ${names[0]} to set a witness PIN?`
                        : `Remind ${names.length} people to set a witness PIN?`
                }
                description={
                    <span className="flex flex-col gap-2">
                        {names.length > 1 ? (
                            <span className="font-medium text-foreground">
                                {names.join(', ')}
                            </span>
                        ) : null}
                        <span>
                            They get an in-app reminder, and push if they’ve set
                            it up. It opens their account › Witness PIN. Until
                            they set one, they can’t co-sign or witness.
                        </span>
                        <span>
                            Recorded in the audit log with your name and the
                            time.
                        </span>
                    </span>
                }
                confirmText={
                    names.length === 1
                        ? 'Send reminder'
                        : `Send ${names.length} reminders`
                }
            />
        </Section>
    );
}
