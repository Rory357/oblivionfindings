import { Network, UsersRound } from 'lucide-react';
import { useRow } from './_sections';
import { GroupGrid, GroupRow, Note, OnOff, SettingGroup } from './_ui';

export type ConnectedStatus = Record<
    string,
    {
        switched_on: boolean;
        ready: boolean;
        reason: string | null;
        running: boolean;
    }
>;

const FEATURES: { key: string; search: string; hint: string }[] = [
    {
        key: 'prescriber_portal',
        search: 'outside prescriber portal GP named access requests',
        hint: 'Named outside prescribers see the chart of the people you grant them, and send medication requests for review.',
    },
    {
        key: 'provider_transfers',
        search: 'provider handover transfer packet',
        hint: 'Medication handover packets to and from other providers, reviewed before they are released.',
    },
    {
        key: 'pharmacy_bridge',
        search: 'pharmacy bridge supply orders send',
        hint: 'Supply orders go to the approved pharmacy partner, which sends back signed acknowledgements.',
    },
    {
        key: 'picture_catalogue',
        search: 'picture catalogue reference images licensed',
        hint: 'Licensed reference pictures of medicines, shown next to staff photos of packs. Staff photos work without it.',
    },
    {
        key: 'protected_backups',
        search: 'protected chart backups downtime email encrypted',
        hint: 'Encrypted whole-house charts emailed to approved recipients’ work email, for an outage. Each house sets its schedule and how long backups are kept.',
    },
];

const CHECKS: { key: string; search: string; hint: string }[] = [
    {
        key: 'two_person_identity',
        search: 'two person identity verification prescriber grant',
        hint: 'The person who verified a prescriber’s identity and registration can’t also grant them access.',
    },
    {
        key: 'two_person_catalogue',
        search: 'two person catalogue source review',
        hint: 'The person who added or imported a catalogue source can’t also review it.',
    },
    {
        key: 'two_person_handover',
        search: 'two person provider handover review',
        hint: 'The person who made a provider handover can’t also review it.',
    },
];

/** Settings › Connected services › Switches and checks (D4, 9 Oct). */
export function ConnectedServicesSettings({
    q,
    show,
    status,
}: {
    q: string;
    show: string;
    status: ConnectedStatus;
}) {
    const { s, value, edit, state, disabled, shown } = useRow('connected');
    const label = (key: string) => s.definitions.connected?.[key]?.label ?? key;
    const featureHint = (key: string, hint: string) => {
        const st = status[key];
        if (st && !st.ready) {
            return st.switched_on
                ? `Not running — ${st.reason ?? 'not set up yet'}`
                : `${hint} Not set up yet: ${st.reason ?? ''}`.trim();
        }
        return hint;
    };
    return (
        <div className="space-y-5">
            <Note>
                Each service shares medication information outside the eMAR, so
                each one is off until you switch it on. Switching one on asks
                for a reason. A service that isn’t set up stays off.
            </Note>
            <GroupGrid>
                <SettingGroup
                    icon={Network}
                    title="Connected services"
                    caption="Optional — off until switched on"
                >
                    {FEATURES.map(({ key, search, hint }) => {
                        const st = status[key];
                        const on = value(key) === 'on';
                        return (
                            <GroupRow
                                key={key}
                                id={`connected.${key}`}
                                label={label(key)}
                                state={state(key)}
                                hidden={
                                    !shown(show, q, key, label(key), search)
                                }
                                hint={featureHint(key, hint)}
                                control={
                                    <OnOff
                                        id={`connected.${key}`}
                                        label={label(key)}
                                        checked={on}
                                        onChange={(v) =>
                                            edit(key, v ? 'on' : 'off')
                                        }
                                        // A service that isn't set up can be
                                        // switched off, never on.
                                        disabled={
                                            disabled ||
                                            (!on &&
                                                st !== undefined &&
                                                !st.ready)
                                        }
                                    />
                                }
                            />
                        );
                    })}
                </SettingGroup>
                <SettingGroup
                    icon={UsersRound}
                    title="Two-person checks"
                    caption="On by default"
                >
                    {CHECKS.map(({ key, search, hint }) => (
                        <GroupRow
                            key={key}
                            id={`connected.${key}`}
                            label={label(key)}
                            state={state(key)}
                            hidden={!shown(show, q, key, label(key), search)}
                            hint={hint}
                            control={
                                <OnOff
                                    id={`connected.${key}`}
                                    label={label(key)}
                                    checked={value(key) !== 'off'}
                                    onChange={(v) =>
                                        edit(key, v ? 'on' : 'off')
                                    }
                                    disabled={disabled}
                                />
                            }
                        />
                    ))}
                </SettingGroup>
            </GroupGrid>
        </div>
    );
}
