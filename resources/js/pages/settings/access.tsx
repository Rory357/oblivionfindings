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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Users, UserPlus, UserMinus } from 'lucide-react';

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

type BoardMember = {
    id: number;
    user_id: number;
    board_role: string;
    term_start: string;
    term_end: string | null;
    is_active: boolean;
    user: {
        id: number;
        name: string;
        email: string;
    };
};

type Props = {
    users: UserItem[];
    roles: Role[];
    permissions: Permission[];
    // userOverrides[userId][permissionId] = true|false
    userOverrides: Record<number, Record<number, boolean>>;
    boardMembers: BoardMember[];
};

function modeFromOverride(
    val: boolean | undefined,
): 'inherit' | 'allow' | 'deny' {
    if (val === undefined) return 'inherit';
    return val ? 'allow' : 'deny';
}

function formatGroupName(group: string) {
    return group
        .split('_')
        .filter(Boolean)
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

function formatTermDate(value: string | null) {
    if (!value) return 'Ongoing';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    const hasTime = /[T ]\d{2}:\d{2}/.test(value);
    return parsed.toLocaleString('en-NZ', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        ...(hasTime
            ? {
                  hour: '2-digit',
                  minute: '2-digit',
              }
            : {}),
    });
}

// Board Member Management Component
function BoardMembersSection({ 
    boardMembers, 
    users 
}: { 
    boardMembers: BoardMember[]; 
    users: UserItem[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({
        user_id: '',
        board_role: 'member',
        term_start: new Date().toISOString().split('T')[0],
        term_end: new Date(new Date().setFullYear(new Date().getFullYear() + 3)).toISOString().split('T')[0],
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/settings/board-members', {
            onSuccess: () => {
                setIsOpen(false);
                form.reset();
            },
        });
    };

    const handleRemove = (id: number) => {
        if (confirm('Remove this board member?')) {
            form.delete(`/settings/board-members/${id}`);
        }
    };

    // Get users who are not already board members
    const boardMemberUserIds = new Set(boardMembers.map(bm => bm.user_id));
    const availableUsers = users.filter(u => !boardMemberUserIds.has(u.id));

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Users className="w-5 h-5 text-blue-500" />
                    <div>
                        <h3 className="text-sm font-semibold">Board Members</h3>
                        <p className="text-xs text-muted-foreground">
                            Manage governance board appointments
                        </p>
                    </div>
                </div>
                <Dialog open={isOpen} onOpenChange={setIsOpen}>
                    <DialogTrigger asChild>
                        <Button size="sm" disabled={availableUsers.length === 0}>
                            <UserPlus className="w-4 h-4 mr-2" />
                            Appoint
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Appoint Board Member</DialogTitle>
                            <DialogDescription>
                                Assign a user to the governance board.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label>User</Label>
                                <Select 
                                    value={form.data.user_id} 
                                    onValueChange={(v) => form.setData('user_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select user..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableUsers.map((user) => (
                                            <SelectItem key={user.id} value={String(user.id)}>
                                                {user.name} ({user.email})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.user_id && (
                                    <p className="text-sm text-red-600 mt-1">{form.errors.user_id}</p>
                                )}
                            </div>
                            <div>
                                <Label>Board Role</Label>
                                <Select 
                                    value={form.data.board_role} 
                                    onValueChange={(v) => form.setData('board_role', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="chair">Chair</SelectItem>
                                        <SelectItem value="secretary">Secretary</SelectItem>
                                        <SelectItem value="treasurer">Treasurer</SelectItem>
                                        <SelectItem value="member">Member</SelectItem>
                                        <SelectItem value="observer">Observer</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Term Start</Label>
                                    <Input
                                        type="date"
                                        value={form.data.term_start}
                                        onChange={(e) => form.setData('term_start', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label>Term End</Label>
                                    <Input
                                        type="date"
                                        value={form.data.term_end}
                                        onChange={(e) => form.setData('term_end', e.target.value)}
                                    />
                                </div>
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={form.processing}>
                                    Appoint
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            {boardMembers.length > 0 ? (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Term</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="w-24"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {boardMembers.map((member) => (
                            <TableRow key={member.id}>
                                <TableCell>
                                    <div>
                                        <p className="font-medium">{member.user.name}</p>
                                        <p className="text-xs text-muted-foreground">{member.user.email}</p>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline" className="capitalize">
                                        {member.board_role}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-sm">
                                    {formatTermDate(member.term_start)} -> {formatTermDate(member.term_end)}
                                </TableCell>
                                <TableCell>
                                    {member.is_active ? (
                                        <Badge className="bg-green-100 text-green-800">Active</Badge>
                                    ) : (
                                        <Badge className="bg-gray-100 text-gray-800">Inactive</Badge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => handleRemove(member.id)}
                                        disabled={form.processing}
                                    >
                                        <UserMinus className="w-4 h-4 text-red-500" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            ) : (
                <div className="rounded-md border p-4 text-sm text-muted-foreground">
                    No board members appointed yet.
                </div>
            )}
        </div>
    );
}

export default function AccessControlPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [query, setQuery] = useState('');
    const [permQuery, setPermQuery] = useState('');
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

    const filteredPermissions = useMemo(() => {
        const q = permQuery.trim().toLowerCase();
        if (!q) return props.permissions;
        return props.permissions.filter(
            (perm) =>
                perm.key.toLowerCase().includes(q) ||
                (perm.description ?? '').toLowerCase().includes(q),
        );
    }, [permQuery, props.permissions]);

    const permissionGroups = useMemo(() => {
        const map: Record<string, Permission[]> = {};
        for (const perm of filteredPermissions) {
            const prefix = perm.key.split('.')[0] ?? 'other';
            (map[prefix] ||= []).push(perm);
        }
        return Object.entries(map)
            .sort((a, b) => a[0].localeCompare(b[0]))
            .map(
                ([group, perms]) =>
                    [
                        group,
                        perms.sort((a, b) => a.key.localeCompare(b.key)),
                    ] as const,
            );
    }, [filteredPermissions]);

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

    const setGroupOverride = (
        permIds: number[],
        value: 'inherit' | 'allow' | 'deny',
    ) => {
        const next = { ...form.data.overrides };
        for (const id of permIds) next[String(id)] = value;
        form.setData('overrides', next);
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

                    <div className="grid gap-6 lg:grid-cols-[220px_1fr]">
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
                                                This user cannot log in yet.
                                                Assign roles, then approve.
                                            </div>
                                        )}
                                    </div>

                                    <div className="space-y-3">
                                        <div className="text-sm font-semibold">
                                            Roles
                                        </div>
                                        <InputError
                                            message={
                                                (form.errors as any).role_ids
                                            }
                                        />
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

                                        <div className="space-y-3">
                                            <Input
                                                placeholder="Filter permissions…"
                                                value={permQuery}
                                                onChange={(e) =>
                                                    setPermQuery(e.target.value)
                                                }
                                            />

                                            <div className="space-y-3">
                                                {permissionGroups.map(
                                                    ([group, perms]) => {
                                                        const ids = perms.map(
                                                            (x) => x.id,
                                                        );
                                                        return (
                                                            <details
                                                                key={group}
                                                                className="rounded-md border"
                                                                open={Boolean(
                                                                    permQuery,
                                                                )}
                                                            >
                                                                <summary className="grid cursor-pointer list-none items-start gap-3 px-4 py-3 hover:bg-muted md:grid-cols-[1fr_auto] md:items-center">
                                                                    <div className="min-w-0">
                                                                        <div className="text-sm font-semibold">
                                                                            {formatGroupName(
                                                                                group,
                                                                            )}
                                                                        </div>
                                                                        <div className="text-xs text-muted-foreground">
                                                                            {
                                                                                perms.length
                                                                            }{' '}
                                                                            permission
                                                                            {perms.length ===
                                                                            1
                                                                                ? ''
                                                                                : 's'}
                                                                        </div>
                                                                    </div>

                                                                    <div className="flex flex-wrap items-center justify-start gap-2 md:justify-end">
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={(
                                                                                e,
                                                                            ) => {
                                                                                e.preventDefault();
                                                                                setGroupOverride(
                                                                                    ids,
                                                                                    'inherit',
                                                                                );
                                                                            }}
                                                                        >
                                                                            All
                                                                            inherit
                                                                        </Button>
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={(
                                                                                e,
                                                                            ) => {
                                                                                e.preventDefault();
                                                                                setGroupOverride(
                                                                                    ids,
                                                                                    'allow',
                                                                                );
                                                                            }}
                                                                        >
                                                                            All
                                                                            allow
                                                                        </Button>
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="outline"
                                                                            onClick={(
                                                                                e,
                                                                            ) => {
                                                                                e.preventDefault();
                                                                                setGroupOverride(
                                                                                    ids,
                                                                                    'deny',
                                                                                );
                                                                            }}
                                                                        >
                                                                            All
                                                                            deny
                                                                        </Button>
                                                                    </div>
                                                                </summary>

                                                                <div className="px-4 pb-4">
                                                                    <div className="mt-2 space-y-2">
                                                                        {perms.map(
                                                                            (
                                                                                p,
                                                                            ) => (
                                                                                <div
                                                                                    key={
                                                                                        p.id
                                                                                    }
                                                                                    className="grid gap-2 rounded-md border p-3 md:grid-cols-[1fr_220px]"
                                                                                >
                                                                                    <div>
                                                                                        <div className="font-mono text-sm font-medium">
                                                                                            {
                                                                                                p.key
                                                                                            }
                                                                                        </div>
                                                                                        {p.description && (
                                                                                            <div className="text-xs text-muted-foreground">
                                                                                                {
                                                                                                    p.description
                                                                                                }
                                                                                            </div>
                                                                                        )}
                                                                                    </div>

                                                                                    <div>
                                                                                        <Label className="sr-only">
                                                                                            Override
                                                                                        </Label>
                                                                                        <Select
                                                                                            value={
                                                                                                form
                                                                                                    .data
                                                                                                    .overrides[
                                                                                                    String(
                                                                                                        p.id,
                                                                                                    )
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
                                                                            ),
                                                                        )}

                                                                        {perms.length ===
                                                                            0 && (
                                                                            <div className="rounded-md border p-3 text-sm text-muted-foreground">
                                                                                No
                                                                                permissions
                                                                                in
                                                                                this
                                                                                group.
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </details>
                                                        );
                                                    },
                                                )}

                                                {filteredPermissions.length ===
                                                    0 && (
                                                    <div className="rounded-md border p-3 text-sm text-muted-foreground">
                                                        No permissions match
                                                        your filter.
                                                    </div>
                                                )}
                                            </div>
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

                    <Separator />

                    {/* Board Member Management */}
                    <BoardMembersSection 
                        boardMembers={props.boardMembers} 
                        users={props.users}
                    />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
