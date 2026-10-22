import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Briefcase,
    LockKeyhole,
    Settings2,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import type { SettingsAreaProps } from './settings-types';
import {
    AlwaysOn,
    GroupGrid,
    Note,
    OverviewGrid,
    SettingGroup,
    SettingRow,
} from './settings-ui';

/** Grants that need a different person from whoever prepared the work. */
const independent = [
    'funding.verify',
    'proposals.approve',
    'commence',
    'settings.publish',
    'settings.governance',
];
const groups: {
    title: string;
    caption: string;
    icon: LucideIcon;
    keys: string[];
}[] = [
    {
        title: 'Everyday referral work',
        caption: 'Recording, owning and moving referrals.',
        icon: Briefcase,
        keys: [
            'viewAny',
            'create',
            'edit',
            'assign',
            'contact',
            'communications.manage',
            'communications.send',
            'tasks.manage',
            'transition',
            'close',
            'reopen',
            'duplicates.manage',
        ],
    },
    {
        title: 'Assessment, funding and handover',
        caption: 'Sensitive details, money and starting support.',
        icon: Wallet,
        keys: [
            'assessment.view',
            'assessment.manage',
            'evidence.manage',
            'funding.view',
            'funding.manage',
            'funding.verify',
            'proposals.manage',
            'proposals.approve',
            'convert',
            'commence',
        ],
    },
    {
        title: 'Information and configuration',
        caption: 'Sharing, reports and these settings.',
        icon: Settings2,
        keys: [
            'disclose',
            'reports.view',
            'reports.export',
            'settings.view',
            'settings.manage',
            'settings.publish',
            'settings.governance',
        ],
    },
    {
        title: 'Record scope',
        caption: 'Which referrals a person can see at all.',
        icon: LockKeyhole,
        keys: ['viewAssigned', 'viewAllSites', 'centralIntake', 'restricted'],
    },
];

/** Access: read-only view of role grants (edited in the one shared role editor) and the fixed boundaries. */
export function AccessSettings({ settings, tab, jump }: SettingsAreaProps) {
    const grants = settings.role_grants ?? [];
    const [roleId, setRoleId] = useState(() =>
        String(
            (grants.find((role) => role.keys.length) ?? grants[0])?.id ?? '',
        ),
    );
    const role = grants.find((item) => String(item.id) === roleId);
    const withAccess = grants.filter((item) => item.keys.length);
    const holding = (key: string) =>
        grants.filter((item) => item.keys.includes(key)).length;
    const editRoles = settings.can_edit_roles ? (
        <Button variant="outline" size="sm" asChild>
            <Link href="/settings/roles">
                Change in Roles and permissions
                <ArrowUpRight className="size-4" />
            </Link>
        </Button>
    ) : null;

    if (tab === 'overview')
        return (
            <OverviewGrid
                title="Access"
                caption="Who can do what. Permissions are managed once for the whole app."
                cards={[
                    {
                        key: 'roles',
                        icon: Users,
                        title: 'Role permissions',
                        rows: [
                            [
                                'Roles with referral access',
                                `${withAccess.length} of ${grants.length}`,
                            ],
                            [
                                'Roles that can publish settings',
                                `${holding('settings.publish')}`,
                            ],
                            [
                                'Roles that can approve weaker safeguards',
                                holding('settings.governance')
                                    ? `${holding('settings.governance')}`
                                    : 'None yet',
                            ],
                        ],
                        cta: 'Review role permissions',
                        onReview: () => jump('access', 'configuration'),
                    },
                    {
                        key: 'scope',
                        icon: LockKeyhole,
                        title: 'Authorization boundaries',
                        rows: [
                            ['Sites', 'Only the sites on a staff record'],
                            [
                                'Privacy',
                                'Restricted referrals need their own permission',
                            ],
                            ['Settings access', 'Never opens referral records'],
                        ],
                        cta: 'Review record scope',
                        onReview: () => jump('access', 'scope'),
                    },
                ]}
                note="Roles and permissions are changed in Settings › Roles and permissions, so there is one place to manage access across the app."
            />
        );

    if (tab === 'scope')
        return (
            <GroupGrid>
                <SettingGroup
                    icon={LockKeyhole}
                    title="Authorization boundaries"
                    caption="One organisation, several approved sites. These limits apply to every list, total, search, export and notice."
                    wide
                >
                    <SettingRow
                        label="Approved sites"
                        hint="Staff see referrals for the sites on their staff record. “Access referrals across approved active sites” widens this."
                        control={<AlwaysOn>Always applies</AlwaysOn>}
                    />
                    <SettingRow
                        label="Assigned work only"
                        hint="With “View assigned referrals and funding cases”, staff see only what’s assigned to them."
                        control={<AlwaysOn>Always applies</AlwaysOn>}
                    />
                    <SettingRow
                        label="Central intake"
                        hint="Unassigned enquiries need “Access the unallocated central intake queue”."
                        control={<AlwaysOn>Always applies</AlwaysOn>}
                    />
                    <SettingRow
                        label="Privacy-restricted referrals"
                        hint="Need “Access privacy-restricted referrals” on top of every other permission."
                        control={<AlwaysOn>Always applies</AlwaysOn>}
                    />
                    <SettingRow
                        label="Current staff record"
                        hint="Access stops as soon as a person’s staff record is inactive, even if their role still grants it."
                        control={<AlwaysOn>Always applies</AlwaysOn>}
                    />
                    <SettingRow
                        label="Settings access"
                        hint="Viewing or changing these settings never opens referral records or exports."
                        control={<AlwaysOn>Always applies</AlwaysOn>}
                    />
                </SettingGroup>
            </GroupGrid>
        );

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="w-72 max-w-full">
                    <Field label="Role">
                        <SelectInput
                            ariaLabel="Role"
                            placeholder="Choose a role"
                            value={roleId}
                            onChange={setRoleId}
                            options={grants.map((item) => ({
                                value: String(item.id),
                                label: item.name,
                            }))}
                        />
                    </Field>
                </div>
                {editRoles}
            </div>
            {role ? (
                <>
                    <GroupGrid>
                        {groups.map((group) => (
                            <SettingGroup
                                key={group.title}
                                icon={group.icon}
                                title={group.title}
                                caption={group.caption}
                            >
                                {group.keys
                                    .filter((key) => settings.permissions[key])
                                    .map((key) => (
                                        <SettingRow
                                            key={key}
                                            label={settings.permissions[key]}
                                            hint={
                                                independent.includes(key)
                                                    ? 'Needs a different person from whoever prepared the work'
                                                    : undefined
                                            }
                                            control={
                                                role.keys.includes(key) ? (
                                                    <StatusBadge
                                                        variant="success"
                                                        size="sm"
                                                    >
                                                        Granted
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge
                                                        variant="neutral"
                                                        size="sm"
                                                    >
                                                        Not granted
                                                    </StatusBadge>
                                                )
                                            }
                                        />
                                    ))}
                            </SettingGroup>
                        ))}
                    </GroupGrid>
                    <Note>
                        This shows what the {role.name} role grants. Individual
                        staff can have extra or removed permissions on their own
                        record. Referral work also needs “View referrals”, and
                        site, assignment and privacy limits still apply.
                    </Note>
                </>
            ) : (
                <Note>No roles are set up yet.</Note>
            )}
        </div>
    );
}
