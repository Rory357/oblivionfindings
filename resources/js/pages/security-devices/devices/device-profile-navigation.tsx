import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    Activity,
    BookOpen,
    Network,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useMemo } from 'react';
import type {
    DeviceProfileGroupKey,
    DeviceProfileSection,
    DeviceProfileSectionKey,
} from './device-profile';

const groupDefinitions: Array<{
    key: DeviceProfileGroupKey;
    label: string;
    description: string;
    icon: LucideIcon;
}> = [
    {
        key: 'status',
        label: 'Status',
        description: 'Current health and monitoring',
        icon: Activity,
    },
    {
        key: 'technical',
        label: 'Technical',
        description: 'Connections and configuration',
        icon: Network,
    },
    {
        key: 'operations',
        label: 'Operations',
        description: 'Ownership, work, and maintenance',
        icon: Wrench,
    },
    {
        key: 'records',
        label: 'Records',
        description: 'Documents and accountability',
        icon: BookOpen,
    },
];

export function DeviceProfileNavigation({
    sections,
    activeSection,
    onSectionChange,
}: {
    sections: DeviceProfileSection[];
    activeSection: DeviceProfileSectionKey;
    onSectionChange: (section: DeviceProfileSectionKey) => void;
}) {
    const groups = useMemo(
        () =>
            groupDefinitions
                .map((group) => ({
                    ...group,
                    sections: sections.filter(
                        (section) => section.group === group.key,
                    ),
                }))
                .filter((group) => group.sections.length > 0),
        [sections],
    );
    const activeGroup =
        groups.find((group) =>
            group.sections.some((section) => section.key === activeSection),
        ) ?? groups[0];

    return (
        <nav aria-label="Device profile sections" className="space-y-3">
            <div className="md:hidden">
                <label
                    htmlFor="device-profile-section"
                    className="mb-1.5 block text-sm font-medium"
                >
                    Device information
                </label>
                <Select
                    value={activeSection}
                    onValueChange={(value) =>
                        onSectionChange(value as DeviceProfileSectionKey)
                    }
                >
                    <SelectTrigger
                        id="device-profile-section"
                        data-test="device-profile-mobile-select"
                        data-testid="device-profile-mobile-select"
                        className="h-11 w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {groups.map((group) => (
                            <div key={group.key}>
                                <p className="px-2 py-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {group.label}
                                </p>
                                {group.sections.map((section) => (
                                    <SelectItem
                                        key={section.key}
                                        value={section.key}
                                    >
                                        {section.label}
                                        {section.count
                                            ? ` (${section.count})`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </div>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="hidden space-y-3 md:block">
                <div className="grid gap-2 lg:grid-cols-4">
                    {groups.map((group) => {
                        const Icon = group.icon;
                        const selected = group.key === activeGroup?.key;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- selector card has two text tiers and custom pressed-state layout.
                            <button
                                key={group.key}
                                type="button"
                                data-test={`device-profile-group-${group.key}`}
                                data-testid={`device-profile-group-${group.key}`}
                                aria-pressed={selected}
                                onClick={() => {
                                    const first = group.sections[0];
                                    if (first) onSectionChange(first.key);
                                }}
                                className={cn(
                                    'min-h-16 rounded-xl border px-4 py-3 text-left transition-colors',
                                    selected
                                        ? 'border-primary bg-primary/5 text-foreground'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                                )}
                            >
                                <span className="flex items-center gap-2 text-sm font-semibold">
                                    <Icon className="h-4 w-4" />
                                    {group.label}
                                </span>
                                <span className="mt-1 block text-xs">
                                    {group.description}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {activeGroup && (
                    <div className="flex flex-wrap gap-2 rounded-xl border bg-muted/30 p-2">
                        {activeGroup.sections.map((section) => {
                            const selected = section.key === activeSection;
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- compact profile selector needs tab semantics inside a wrapping rail.
                                <button
                                    key={section.key}
                                    type="button"
                                    data-test={`device-profile-section-${section.key}`}
                                    data-testid={`device-profile-section-${section.key}`}
                                    aria-current={selected ? 'page' : undefined}
                                    onClick={() => onSectionChange(section.key)}
                                    className={cn(
                                        'inline-flex min-h-10 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        selected
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:bg-background/70 hover:text-foreground',
                                    )}
                                >
                                    {section.label}
                                    {Boolean(section.count) && (
                                        <Badge
                                            variant="secondary"
                                            className="h-5 min-w-5 justify-center px-1.5 text-[10px]"
                                        >
                                            {section.count}
                                        </Badge>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>
        </nav>
    );
}
