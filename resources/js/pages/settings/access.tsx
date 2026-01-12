import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Access Control', href: '/settings/access' },
];

type Role = { id: number; name: string; label: string };
type Permission = { id: number; key: string; description?: string | null };
type UserItem = {
    id: number;
    name: string;
    email: string;
    role?: string | null;
    approved_at?: string | null;
    roles: Role[];
};

type Props = {
    users: UserItem[];
    roles: Role[];
    permissions: Permission[];
    // userOverrides[userId][permissionId] = true|false
    userOverrides: Record<number, Record<number, boolean>>;
};

function modeFromOverride(
    val: boolean | undefined,
): 'inherit' | 'allow' | 'deny' {
    if (val === undefined) return 'inherit';
    return val ? 'allow' : 'deny';
}

export default function AccessControlPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(
        props.users?.[0]?.id ?? null,
    );

    const filteredUsers = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return props.users;
        return props.users.filter(
            (u) =>
                u.name.toLowerCase().includes(q) ||
                u.email.toLowerCase().includes(q),
        );
    }, [query, props.users]);

    const selected = useMemo(
        () => props.users.find((u) => u.id === selectedId) ?? null,
        [props.users, selectedId],
    );

    const selectedIsPending = !selected?.approved_at;

    const initialRoleIds = selected?.roles?.map((r) => r.id) ?? [];
    const initialOverrides: Record<string, 'inherit' | 'allow' | 'deny'> =
        Object.fromEntries(
            props.permissions.map((p) => [
                String(p.id),
                modeFromOverride(
                    props.userOverrides?.[selected?.id ?? 0]?.[p.id],
                ),
            ]),
        );

    const form = useForm<{
        role_ids: number[];
        overrides: Record<string, 'inherit' | 'allow' | 'deny'>;
    }>({
        role_ids: initialRoleIds,
        overrides: initialOverrides,
    });

    // When selecting a different user, refresh form state
    const selectUser = (id: number) => {
        setSelectedId(id);
        const u = props.users.find((x) => x.id === id);
        if (!u) return;
        form.setData({
            role_ids: u.roles.map((r) => r.id),
            overrides: Object.fromEntries(
                props.permissions.map((p) => [
                    String(p.id),
                    modeFromOverride(props.userOverrides?.[id]?.[p.id]),
                ]),
            ),
        });
        form.clearErrors();
    };

    if (!can?.settings?.manageAccess) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Access Control" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don’t have permission to manage access.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Access Control" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Access Control"
                        description="Assign roles and set per-user permission overrides. Overrides take precedence over role permissions."
                    />

                    <div className="grid gap-6 lg:grid-cols-[260px_1fr]">
                        {/* User list */}
                        <div className="space-y-3">
                            <Input
                                placeholder="Search users…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />

                            <div className="max-h-[520px] overflow-auto rounded-md border">
                                {filteredUsers.map((u) => (
                                    <button
                                        key={u.id}
                                        type="button"
                                        onClick={() => selectUser(u.id)}
                                        className={`w-full border-b p-3 text-left last:border-b-0 hover:bg-muted ${
                                            selectedId === u.id
                                                ? 'bg-muted'
                                                : ''
                                        }`}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="text-sm font-medium">
                                                {u.name}
                                            </div>
                                            {!u.approved_at && (
                                                <Badge variant="secondary">
                                                    Pending
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {u.email}
                                        </div>
                                    </button>
                                ))}

                                {filteredUsers.length === 0 && (
                                    <div className="p-3 text-sm text-muted-foreground">
                                        No users found.
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Editor */}
                        <div className="space-y-6">
                            {!selected ? (
                                <div className="rounded-md border p-4 text-sm text-muted-foreground">
                                    Select a user to edit their access.
                                </div>
                            ) : (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        form.put(
                                            `/settings/access/${selected.id}`,
                                        );
                                    }}
                                    className="space-y-6"
                                >
                                    <div className="rounded-md border p-4">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <div className="text-sm font-semibold">
                                                    {selected.name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {selected.email}
                                                </div>
                                            </div>

                                            {selectedIsPending ? (
                                                <Badge variant="secondary">
                                                    Pending approval
                                                </Badge>
                                            ) : (
                                                <Badge>Active</Badge>
                                            )}
                                        </div>

                                        {selectedIsPending && (
                                            <div className="mt-3 rounded-md border bg-muted/30 p-3 text-sm">
                                                This user cannot log in yet. Assign roles, then approve.
                                            </div>
                                        )}
                                    </div>

                                    <div className="space-y-3">
                                        <div className="text-sm font-semibold">
                                            Roles
                                        </div>
                                        <InputError message={(form.errors as any).role_ids} />
                                        <div className="space-y-2">
                                            {props.roles.map((r) => {
                                                const checked =
                                                    form.data.role_ids.includes(
                                                        r.id,
                                                    );
                                                return (
                                                    <label
                                                        key={r.id}
                                                        className="flex items-center gap-3 rounded-md border p-3"
                                                    >
                                                        <Checkbox
                                                            checked={checked}
                                                            onCheckedChange={(
                                                                v,
                                                            ) => {
                                                                const next = v
                                                                    ? [
                                                                          ...form
                                                                              .data
                                                                              .role_ids,
                                                                          r.id,
                                                                      ]
                                                                    : form.data.role_ids.filter(
                                                                          (x) =>
                                                                              x !==
                                                                              r.id,
                                                                      );
                                                                form.setData(
                                                                    'role_ids',
                                                                    next,
                                                                );
                                                            }}
                                                        />
                                                        <div>
                                                            <div className="text-sm font-medium">
                                                                {r.label}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {r.name}
                                                            </div>
                                                        </div>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <Separator />

                                    <div className="space-y-3">
                                        <div className="text-sm font-semibold">
                                            Permission overrides
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Inherit = use role permissions.
                                            Allow/Deny will override roles.
                                        </div>

                                        <div className="space-y-2">
                                            {props.permissions.map((p) => (
                                                <div
                                                    key={p.id}
                                                    className="grid gap-2 rounded-md border p-3 md:grid-cols-[1fr_220px]"
                                                >
                                                    <div>
                                                        <div className="text-sm font-medium">
                                                            {p.key}
                                                        </div>
                                                        {p.description && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {p.description}
                                                            </div>
                                                        )}
                                                    </div>

                                                    <div>
                                                        <Label className="sr-only">
                                                            Override
                                                        </Label>
                                                        <Select
                                                            value={
                                                                form.data
                                                                    .overrides[
                                                                    String(p.id)
                                                                ]
                                                            }
                                                            onValueChange={(
                                                                value: any,
                                                            ) =>
                                                                form.setData(
                                                                    'overrides',
                                                                    {
                                                                        ...form
                                                                            .data
                                                                            .overrides,
                                                                        [String(
                                                                            p.id,
                                                                        )]:
                                                                            value,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Inherit" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="inherit">
                                                                    Inherit
                                                                </SelectItem>
                                                                <SelectItem value="allow">
                                                                    Allow
                                                                </SelectItem>
                                                                <SelectItem value="deny">
                                                                    Deny
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            Save
                                        </Button>

                                        {selectedIsPending && (
                                            <Button
                                                type="button"
                                                disabled={form.processing}
                                                onClick={() =>
                                                    form.post(
                                                        `/settings/access/${selected.id}/approve`,
                                                    )
                                                }
                                            >
                                                Approve
                                            </Button>
                                        )}

                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => form.reset()}
                                        >
                                            Reset
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
