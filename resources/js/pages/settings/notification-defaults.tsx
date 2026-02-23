import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

type RoleRow = { id: number; name: string; label: string };

type Props = {
    groups: Record<string, string[]>;
    roles: RoleRow[];
    matrix: Record<number, Record<string, boolean>>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Notification Defaults', href: '/settings/notification-defaults' },
];

function humanize(key: string): string {
    return key
        .replace(/\./g, ' › ')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function NotificationDefaults({ groups, roles, matrix }: Props) {
    const allKeys = Object.values(groups).flat();
    const { data, setData, put, processing } = useForm({
        matrix: matrix,
    });

    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>(() => {
        const initial: Record<string, boolean> = {};
        Object.keys(groups).forEach((g) => (initial[g] = true));
        return initial;
    });

    const toggleGroup = (name: string) => {
        setCollapsed((prev) => ({ ...prev, [name]: !prev[name] }));
    };

    const collapseAll = () => {
        const next: Record<string, boolean> = {};
        Object.keys(groups).forEach((g) => (next[g] = true));
        setCollapsed(next);
    };

    const expandAll = () => {
        setCollapsed({});
    };

    const filteredGroups = useMemo(() => {
        const q = search.toLowerCase().trim();
        if (!q) return groups;

        const result: Record<string, string[]> = {};
        for (const [groupName, keys] of Object.entries(groups)) {
            if (groupName.toLowerCase().includes(q)) {
                result[groupName] = keys;
                continue;
            }
            const matchedKeys = keys.filter(
                (k) =>
                    k.toLowerCase().includes(q) ||
                    humanize(k).toLowerCase().includes(q),
            );
            if (matchedKeys.length > 0) {
                result[groupName] = matchedKeys;
            }
        }
        return result;
    }, [groups, search]);

    const matchCount = Object.values(filteredGroups).flat().length;
    const totalCount = allKeys.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Defaults" />
            <SettingsLayout>
                <Head title="Notification defaults" />
                <div className="space-y-6">
                    <HeadingSmall
                        title="Notification defaults"
                        description="Set default notification behaviour per role. Users can still override these in their own settings."
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Quick actions
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    const next: any = { ...data.matrix };
                                    roles.forEach((r) => {
                                        next[r.id] = next[r.id] || {};
                                        allKeys.forEach(
                                            (k) => (next[r.id][k] = true),
                                        );
                                    });
                                    setData('matrix', next);
                                }}
                            >
                                Enable all
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    const next: any = { ...data.matrix };
                                    roles.forEach((r) => {
                                        next[r.id] = next[r.id] || {};
                                        allKeys.forEach(
                                            (k) => (next[r.id][k] = false),
                                        );
                                    });
                                    setData('matrix', next);
                                }}
                            >
                                Disable all
                            </Button>
                            <Separator orientation="vertical" className="mx-1 h-8" />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={expandAll}
                            >
                                Expand all
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={collapseAll}
                            >
                                Collapse all
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Search */}
                    <div>
                        <div className="flex items-center gap-2 rounded-md border px-3">
                            <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <Input
                                placeholder="Search notification events..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="border-0 px-0 shadow-none focus-visible:ring-0"
                            />
                        </div>
                        {search && (
                            <div className="mt-1 text-xs text-muted-foreground">
                                Showing {matchCount} of {totalCount} events
                            </div>
                        )}
                    </div>

                    {Object.keys(filteredGroups).length === 0 && search && (
                        <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                            No notification events match "{search}"
                        </div>
                    )}

                    {Object.entries(filteredGroups).map(([groupName, keys]) => {
                        const isCollapsed = !!collapsed[groupName];
                        return (
                            <Card key={groupName}>
                                <CardHeader
                                    className="cursor-pointer select-none"
                                    onClick={() => toggleGroup(groupName)}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            {isCollapsed ? (
                                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                            ) : (
                                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                            )}
                                            <CardTitle className="text-base">
                                                {groupName}
                                            </CardTitle>
                                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                {keys.length} {keys.length === 1 ? 'event' : 'events'}
                                            </span>
                                        </div>
                                    </div>
                                </CardHeader>
                                {!isCollapsed && (
                                    <CardContent className="space-y-4">
                                        {keys.map((key) => (
                                            <div key={key} className="space-y-2">
                                                <div className="text-sm font-medium">
                                                    {humanize(key)}
                                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                        {key}
                                                    </span>
                                                </div>
                                                <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                                                    {roles.map((role) => {
                                                        const checked = Boolean(
                                                            (data.matrix as any)?.[
                                                                role.id
                                                            ]?.[key],
                                                        );
                                                        return (
                                                            <div
                                                                key={role.id}
                                                                className="flex items-center justify-between rounded-md border p-2"
                                                            >
                                                                <div className="text-xs font-medium">
                                                                    {role.label}
                                                                </div>
                                                                <Checkbox
                                                                    checked={checked}
                                                                    onCheckedChange={(
                                                                        v,
                                                                    ) => {
                                                                        const next: any =
                                                                            {
                                                                                ...(data.matrix as any),
                                                                            };
                                                                        next[role.id] =
                                                                            {
                                                                                ...(next[
                                                                                    role
                                                                                        .id
                                                                                ] ||
                                                                                    {}),
                                                                            };
                                                                        next[role.id][
                                                                            key
                                                                        ] = Boolean(v);
                                                                        setData(
                                                                            'matrix',
                                                                            next,
                                                                        );
                                                                    }}
                                                                />
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </CardContent>
                                )}
                            </Card>
                        );
                    })}

                    <Separator />

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" asChild>
                            <a href="/settings/notifications">Back</a>
                        </Button>
                        <Button
                            disabled={processing}
                            onClick={() => put('/settings/notifications/roles')}
                        >
                            Save defaults
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
