import { SettingsNotice } from '@/components/settings/settings-notice';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { Globe, Users, Wallet, Workflow, type LucideIcon } from 'lucide-react';
import type { ImpactLine, SettingsImpactSummary } from './settings-types';
import { Nested, NestedRow } from './settings-ui';

const areas: { key: ImpactLine['area']; title: string; icon: LucideIcon }[] = [
    { key: 'intake', title: 'Intake', icon: Globe },
    { key: 'workflow', title: 'Workflow', icon: Workflow },
    { key: 'funding', title: 'Funding', icon: Wallet },
    { key: 'access', title: 'Access', icon: Users },
];

/** What changes for whom, then the open work it touches, within the reviewer's own access. */
export function SettingsImpact({ impact }: { impact: SettingsImpactSummary }) {
    const lines = impact.lines ?? [];
    return (
        <div className="space-y-4">
            {lines.length ? (
                <div className="grid gap-4 lg:grid-cols-2">
                    {areas
                        .filter((area) =>
                            lines.some((line) => line.area === area.key),
                        )
                        .map((area) => (
                            <Nested
                                key={area.key}
                                icon={area.icon}
                                title={area.title}
                            >
                                <ul className="space-y-2.5">
                                    {lines
                                        .filter(
                                            (line) => line.area === area.key,
                                        )
                                        .map((line, index) => (
                                            <li
                                                key={index}
                                                className="text-[13px]"
                                            >
                                                <p>{line.text}</p>
                                                <p className="text-caption mt-0.5 flex flex-wrap items-center gap-2">
                                                    {line.affects}
                                                    {line.loosens && (
                                                        <StatusBadge
                                                            variant="critical"
                                                            size="sm"
                                                        >
                                                            Loosens a safeguard
                                                        </StatusBadge>
                                                    )}
                                                </p>
                                            </li>
                                        ))}
                                </ul>
                            </Nested>
                        ))}
                </div>
            ) : (
                <p className="text-subtle">
                    Nothing that people use changes in this version.
                </p>
            )}
            <Nested icon={Users} title="Open work you can see">
                <NestedRow
                    label="Open referrals"
                    value={impact.open_referrals}
                />
                <NestedRow
                    label="Keep their current settings"
                    value={impact.retaining_another_version}
                />
                <NestedRow
                    label="Waiting for a named owner"
                    value={impact.without_owner}
                />
                <NestedRow
                    label="For services this version turns off"
                    value={impact.outside_enabled_services}
                />
                <NestedRow
                    label="Funding cases on pathways this version turns off"
                    value={
                        impact.funding_outside_enabled_pathways ?? (
                            <span className="font-normal text-muted-foreground">
                                Not available
                            </span>
                        )
                    }
                />
                <p className="text-caption mt-2">
                    {impact.scope}
                    {impact.funding_outside_enabled_pathways === null
                        ? ' Funding impact requires permission to view funding assistance.'
                        : ''}{' '}
                    Checked {formatDateTimeLong(impact.checked_at)}.
                </p>
            </Nested>
            <SettingsNotice role="note">
                <span>
                    New enquiries use this version from its start time. Open
                    referrals keep their recorded settings and due dates;
                    publishing does not reassign or migrate these records.
                    Current permissions and approval limits are always checked.
                </span>
            </SettingsNotice>
        </div>
    );
}
