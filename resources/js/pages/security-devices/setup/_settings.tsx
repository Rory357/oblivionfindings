/**
 * Setup's On/Off switches, kept as a draft until "Review changes" (Fleet settings pattern):
 * changed rows are marked, the save bar counts them, and the review flags a switch that
 * loosens a safety check, whose save then uses the destructive button.
 */
import { SaveBar, SettingGroup, SettingRow, OnOff } from '@/components/referrals/settings-ui';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import axios from 'axios';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { SettingSwitch } from './_types';

export const SWITCH_WORDS: Record<string, { label: string; hint: string; loosens?: string }> = {
    'scans.daily': {
        label: 'Scan every day',
        hint: 'At 6:00 am unless a network has its own time. Scans are gentle: at most 20 checks a second',
    },
    'scans.server_direct': {
        label: 'Let the Oblivion server scan networks it can reach directly',
        hint: 'Only when the server is inside your network or has a VPN to it',
        loosens: 'The Oblivion server will reach into networks itself, not only through each Site’s hub.',
    },
};

export type SettingsDraft = ReturnType<typeof useSettingsDraft>;

export function useSettingsDraft(settings: Record<string, SettingSwitch> | null) {
    const [draft, setDraft] = useState<Record<string, boolean>>({});
    // A save (or another page's props) brings new saved values: the draft starts again.
    useEffect(() => setDraft({}), [settings]);

    const value = (key: string) => draft[key] ?? settings?.[key]?.on ?? false;
    const set = (key: string, next: boolean) =>
        setDraft((current) => {
            const copy = { ...current };
            if (settings?.[key]?.on === next) delete copy[key];
            else copy[key] = next;
            return copy;
        });
    const changes = useMemo(() => Object.entries(draft).map(([key, on]) => ({ key, on, loosens: Boolean(settings?.[key]?.loosens_when_on && on) })), [draft, settings]);

    return { settings, value, set, changes, dirty: changes.length > 0, discard: () => setDraft({}) };
}

/** One titled group of switch rows. */
export function SwitchGroup({ icon, title, caption, keys, draft }: { icon: LucideIcon; title: string; caption: string; keys: string[]; draft: SettingsDraft }) {
    const rows = keys.filter((key) => draft.settings?.[key]);
    if (rows.length === 0) return null;

    return (
        <SettingGroup icon={icon} title={title} caption={caption}>
            {rows.map((key) => {
                const words = SWITCH_WORDS[key] ?? { label: key, hint: '' };
                const saved = draft.settings![key];
                const changedNow = draft.value(key) !== saved.on;
                return (
                    <SettingRow
                        key={key}
                        label={
                            <span className="inline-flex flex-wrap items-center gap-2">
                                {words.label}
                                {changedNow ? (
                                    <StatusBadge variant="warning" size="sm">
                                        Not saved
                                    </StatusBadge>
                                ) : saved.changed ? (
                                    <StatusBadge variant="neutral" size="sm">
                                        Changed from the default
                                    </StatusBadge>
                                ) : null}
                            </span>
                        }
                        hint={saved.can_change ? words.hint : `${words.hint}. Only someone who manages connected services can change this.`}
                        control={<OnOff label={words.label} checked={draft.value(key)} onChange={(next) => draft.set(key, next)} disabled={!saved.can_change} />}
                    />
                );
            })}
        </SettingGroup>
    );
}

/** The sticky save bar and its review pop-up. Hidden when the viewer can't change anything here. */
export function SettingsSave({ draft }: { draft: SettingsDraft }) {
    const [reviewing, setReviewing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const canChange = Object.values(draft.settings ?? {}).some((setting) => setting.can_change);
    if (!canChange) return null;
    const loosens = draft.changes.some((change) => change.loosens);

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            await axios.patch('/security-devices/setup/settings', { changes: Object.fromEntries(draft.changes.map((change) => [change.key, change.on])) });
            setReviewing(false);
            router.reload({ except: ['deviceForm'] });
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            setError(status === 403 ? 'You can’t change these settings.' : status ? 'They weren’t saved. Try again.' : 'Result not confirmed. Reload the page to see whether they were saved.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <SaveBar
                count={draft.changes.length}
                onDiscard={draft.discard}
                onReview={() => setReviewing(true)}
                busy={saving}
                note="Nothing changes until you review and save. Each change is kept in Change history."
            />
            {reviewing ? (
                <SettingsModal
                    title="Review changes"
                    description={draft.changes.length === 1 ? '1 setting changes for every Site.' : `${draft.changes.length} settings change for every Site.`}
                    onClose={() => setReviewing(false)}
                    footer={
                        <>
                            <Button variant="outline" onClick={() => setReviewing(false)} disabled={saving}>
                                Cancel
                            </Button>
                            <Button variant={loosens ? 'destructive' : 'default'} onClick={save} disabled={saving}>
                                {saving ? 'Saving…' : 'Save changes'}
                            </Button>
                        </>
                    }
                >
                    <ul className="grid gap-3">
                        {draft.changes.map((change) => {
                            const words = SWITCH_WORDS[change.key] ?? { label: change.key, hint: '' };
                            return (
                                <li key={change.key} className="grid gap-1 rounded-lg bg-muted p-3">
                                    <span className="flex flex-wrap items-center justify-between gap-2 text-sm font-medium">
                                        {words.label}
                                        <span className="text-subtle">
                                            {change.on ? 'Off' : 'On'} → <b>{change.on ? 'On' : 'Off'}</b>
                                        </span>
                                    </span>
                                    {change.loosens && words.loosens ? (
                                        <span className="flex flex-wrap items-center gap-2 text-sm">
                                            <StatusBadge variant="critical" size="sm">
                                                Loosens a safety check
                                            </StatusBadge>
                                            {words.loosens}
                                        </span>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                    {error ? (
                        <p role="alert" className="mt-3 text-sm text-status-critical">
                            {error}
                        </p>
                    ) : null}
                </SettingsModal>
            ) : null}
        </>
    );
}
