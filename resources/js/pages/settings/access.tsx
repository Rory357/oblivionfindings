import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    Calendar,
    Car,
    ChevronDown,
    ClipboardList,
    ExternalLink,
    Key,
    Landmark,
    Search,
    Settings,
    ShieldAlert,
    Users,
    UserPlus,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Overrides & Governance', href: '/settings/access' },
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
    notes?: string | null;
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
    userOverrides: Record<number, Record<number, boolean>>;
    boardMembers: BoardMember[];
};

// --- Module definitions (matching Roles Edit page) ---

const MODULE_DEFINITIONS: {
    key: string;
    label: string;
    icon: React.ElementType;
    prefixes: string[];
}[] = [
    {
        key: 'operations',
        label: 'Operations',
        icon: ClipboardList,
        prefixes: [
            'clients', 'shifts', 'timesheets', 'care_plans', 'care_notes',
            'medications', 'service_agreements', 'funding', 'rosters',
            'appointments', 'goals', 'progress_notes', 'support_plans',
            'contacts', 'documents', 'portal',
        ],
    },
    {
        key: 'sites',
        label: 'Sites & Locations',
        icon: Building2,
        prefixes: ['sites', 'hazards', 'checklists', 'rooms', 'inspections', 'locations', 'maintenance'],
    },
    {
        key: 'hr',
        label: 'HR & People',
        icon: Users,
        prefixes: ['staff', 'leave', 'training', 'qualifications', 'certifications', 'payroll', 'onboarding', 'competencies'],
    },
    {
        key: 'fleet',
        label: 'Fleet & Assets',
        icon: Car,
        prefixes: ['assets', 'fleet', 'vehicles', 'equipment', 'consumables'],
    },
    {
        key: 'governance',
        label: 'Governance',
        icon: Landmark,
        prefixes: ['governance', 'board', 'policies', 'compliance', 'meetings'],
    },
    {
        key: 'incidents',
        label: 'Incidents & Safety',
        icon: ShieldAlert,
        prefixes: ['incidents', 'risks', 'investigations', 'notifications', 'safety'],
    },
    {
        key: 'settings',
        label: 'Settings',
        icon: Settings,
        prefixes: ['settings', 'integrations', 'roles', 'permissions', 'billing', 'organisation'],
    },
    {
        key: 'system',
        label: 'System',
        icon: Wrench,
        prefixes: ['audit', 'reports', 'exports', 'imports', 'logs', 'system'],
    },
];

function modeFromOverride(val: boolean | undefined): 'inherit' | 'allow' | 'deny' {
    if (val === undefined) return 'inherit';
    return val ? 'allow' : 'deny';
}

function formatTermDate(value: string | null) {
    if (!value) return 'Ongoing';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleString('en-NZ', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    });
}

function getInitials(name: string) {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

const BOARD_ROLE_COLOURS: Record<string, string> = {
    chair: 'bg-violet-100 text-violet-800 dark:bg-violet-900 dark:text-violet-300',
    secretary: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    treasurer: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300',
    member: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300',
    observer: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
};

// --- Three-state override toggle ---

function OverrideToggle({
    value,
    onChange,
}: {
    value: 'inherit' | 'allow' | 'deny';
    onChange: (v: 'inherit' | 'allow' | 'deny') => void;
}) {
    return (
        <div className="inline-flex items-center gap-0.5 rounded-full border bg-muted/30 p-0.5">
            <button
                type="button"
                onClick={() => onChange('inherit')}
                className={`rounded-full px-2.5 py-1 text-xs font-medium transition-colors ${
                    value === 'inherit'
                        ? 'bg-gray-200 text-gray-700 shadow-sm dark:bg-gray-700 dark:text-gray-200'
                        : 'text-muted-foreground hover:text-foreground'
                }`}
            >
                <span className="flex items-center gap-1.5">
                    <span className={`inline-block h-2 w-2 rounded-full ${value === 'inherit' ? 'bg-gray-500' : 'bg-gray-300'}`} />
                    Inherit
                </span>
            </button>
            <button
                type="button"
                onClick={() => onChange('allow')}
                className={`rounded-full px-2.5 py-1 text-xs font-medium transition-colors ${
                    value === 'allow'
                        ? 'bg-green-100 text-green-700 shadow-sm dark:bg-green-900 dark:text-green-200'
                        : 'text-muted-foreground hover:text-foreground'
                }`}
            >
                <span className="flex items-center gap-1.5">
                    <span className={`inline-block h-2 w-2 rounded-full ${value === 'allow' ? 'bg-green-500' : 'bg-gray-300'}`} />
                    Allow
                </span>
            </button>
            <button
                type="button"
                onClick={() => onChange('deny')}
                className={`rounded-full px-2.5 py-1 text-xs font-medium transition-colors ${
                    value === 'deny'
                        ? 'bg-red-100 text-red-700 shadow-sm dark:bg-red-900 dark:text-red-200'
                        : 'text-muted-foreground hover:text-foreground'
                }`}
            >
                <span className="flex items-center gap-1.5">
                    <span className={`inline-block h-2 w-2 rounded-full ${value === 'deny' ? 'bg-red-500' : 'bg-gray-300'}`} />
                    Deny
                </span>
            </button>
        </div>
    );
}

// --- Permission Overrides Tab ---

function PermissionOverridesTab({
    users,
    permissions,
    userOverrides,
}: {
    users: UserItem[];
    permissions: Permission[];
    userOverrides: Record<number, Record<number, boolean>>;
}) {
    const [selectedUserId, setSelectedUserId] = useState<string>('');
    const [permQuery, setPermQuery] = useState('');

    const selectedUser = useMemo(
        () => users.find((u) => u.id === Number(selectedUserId)) ?? null,
        [users, selectedUserId],
    );

    const initialOverrides = useMemo(() => {
        if (!selectedUser) return {};
        return Object.fromEntries(
            permissions.map((p) => [
                String(p.id),
                modeFromOverride(userOverrides?.[selectedUser.id]?.[p.id]),
            ]),
        );
    }, [selectedUser, permissions, userOverrides]);

    const form = useForm<{
        overrides: Record<string, 'inherit' | 'allow' | 'deny'>;
    }>({
        overrides: initialOverrides,
    });

    // Sync form when user changes
    const selectUser = (userId: string) => {
        setSelectedUserId(userId);
        const u = users.find((x) => x.id === Number(userId));
        if (!u) return;
        form.setData(
            'overrides',
            Object.fromEntries(
                permissions.map((p) => [
                    String(p.id),
                    modeFromOverride(userOverrides?.[u.id]?.[p.id]),
                ]),
            ),
        );
        form.clearErrors();
    };

    const filteredPermissions = useMemo(() => {
        const q = permQuery.trim().toLowerCase();
        if (!q) return permissions;
        return permissions.filter(
            (perm) =>
                perm.key.toLowerCase().includes(q) ||
                (perm.description ?? '').toLowerCase().includes(q),
        );
    }, [permQuery, permissions]);

    // Group permissions into modules
    const moduleGroups = useMemo(() => {
        return MODULE_DEFINITIONS.map((mod) => {
            const modPerms = filteredPermissions.filter((p) => {
                const prefix = p.key.split('.')[0] ?? '';
                return mod.prefixes.includes(prefix);
            });
            return { ...mod, permissions: modPerms };
        }).filter((mod) => mod.permissions.length > 0);
    }, [filteredPermissions]);

    // Count overrides for a module
    const getOverrideCount = (perms: Permission[]) => {
        return perms.filter(
            (p) => form.data.overrides[String(p.id)] && form.data.overrides[String(p.id)] !== 'inherit',
        ).length;
    };

    const setGroupOverride = (permIds: number[], value: 'inherit' | 'allow' | 'deny') => {
        const next = { ...form.data.overrides };
        for (const id of permIds) next[String(id)] = value;
        form.setData('overrides', next);
    };

    return (
        <div className="space-y-6">
            <p className="text-sm text-muted-foreground">
                Override role-based permissions for specific users. Use this for exceptions — most users should get permissions via roles.
            </p>

            {/* User selector */}
            <div className="max-w-sm">
                <Label className="mb-2 block text-sm font-medium">Select User</Label>
                <Select value={selectedUserId} onValueChange={selectUser}>
                    <SelectTrigger>
                        <SelectValue placeholder="Search and select a user..." />
                    </SelectTrigger>
                    <SelectContent>
                        {users.map((u) => (
                            <SelectItem key={u.id} value={String(u.id)}>
                                {u.name} ({u.email})
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {!selectedUser ? (
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Key className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Select a user above to manage their permission overrides
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-6">
                    {/* User info card */}
                    <Card>
                        <CardContent className="flex items-center justify-between gap-4 py-4">
                            <div className="flex items-center gap-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-violet-100 text-sm font-semibold text-violet-700 dark:bg-violet-900 dark:text-violet-300">
                                    {getInitials(selectedUser.name)}
                                </div>
                                <div>
                                    <div className="font-medium">{selectedUser.name}</div>
                                    <div className="text-sm text-muted-foreground">{selectedUser.email}</div>
                                    {selectedUser.roles.length > 0 && (
                                        <div className="mt-1.5 flex flex-wrap gap-1">
                                            {selectedUser.roles.map((r) => (
                                                <Badge key={r.id} variant="secondary" className="text-xs">
                                                    {r.label}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={`/settings/users/${selectedUser.id}`}>
                                    Edit in User Profile
                                    <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Permission search */}
                    <div className="relative max-w-sm">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Filter permissions..."
                            value={permQuery}
                            onChange={(e) => setPermQuery(e.target.value)}
                            className="pl-9"
                        />
                    </div>

                    {/* Permission modules */}
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(`/settings/access/${selectedUser.id}`);
                        }}
                        className="space-y-4"
                    >
                        {moduleGroups.map((mod) => {
                            const Icon = mod.icon;
                            const overrideCount = getOverrideCount(mod.permissions);
                            const permIds = mod.permissions.map((p) => p.id);

                            return (
                                <Collapsible key={mod.key} defaultOpen={Boolean(permQuery) || overrideCount > 0}>
                                    <Card>
                                        <CollapsibleTrigger asChild>
                                            <CardHeader className="cursor-pointer select-none transition-colors hover:bg-muted/50">
                                                <div className="flex items-center justify-between">
                                                    <div className="flex items-center gap-3">
                                                        <Icon className="h-5 w-5 text-violet-600" />
                                                        <div>
                                                            <CardTitle className="text-sm">{mod.label}</CardTitle>
                                                            <CardDescription className="text-xs">
                                                                {mod.permissions.length} permission{mod.permissions.length !== 1 ? 's' : ''}
                                                                {overrideCount > 0 && (
                                                                    <span className="ml-1.5 text-violet-600">
                                                                        ({overrideCount} override{overrideCount !== 1 ? 's' : ''})
                                                                    </span>
                                                                )}
                                                            </CardDescription>
                                                        </div>
                                                    </div>
                                                    <ChevronDown className="h-4 w-4 text-muted-foreground transition-transform [[data-state=open]>&]:rotate-180" />
                                                </div>
                                            </CardHeader>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent>
                                            <CardContent className="border-t pt-4">
                                                {/* Bulk actions */}
                                                <div className="mb-4 flex flex-wrap gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setGroupOverride(permIds, 'inherit')}
                                                    >
                                                        All Inherit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setGroupOverride(permIds, 'allow')}
                                                    >
                                                        All Allow
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setGroupOverride(permIds, 'deny')}
                                                    >
                                                        All Deny
                                                    </Button>
                                                </div>

                                                <div className="space-y-2">
                                                    {mod.permissions.map((p) => (
                                                        <div
                                                            key={p.id}
                                                            className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
                                                        >
                                                            <div className="min-w-0">
                                                                <div className="font-mono text-sm">{p.key}</div>
                                                                {p.description && (
                                                                    <div className="text-xs text-muted-foreground">{p.description}</div>
                                                                )}
                                                            </div>
                                                            <OverrideToggle
                                                                value={form.data.overrides[String(p.id)] ?? 'inherit'}
                                                                onChange={(v) =>
                                                                    form.setData('overrides', {
                                                                        ...form.data.overrides,
                                                                        [String(p.id)]: v,
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                            </CardContent>
                                        </CollapsibleContent>
                                    </Card>
                                </Collapsible>
                            );
                        })}

                        {moduleGroups.length === 0 && filteredPermissions.length === 0 && (
                            <Card>
                                <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                    No permissions match your filter.
                                </CardContent>
                            </Card>
                        )}

                        <div className="flex items-center gap-3 pt-2">
                            <Button type="submit" disabled={form.processing} className="bg-violet-600 hover:bg-violet-700">
                                Save Overrides
                            </Button>
                            <Button type="button" variant="outline" onClick={() => form.reset()}>
                                Reset
                            </Button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}

// --- Board & Governance Tab ---

function BoardGovernanceTab({
    boardMembers,
    users,
}: {
    boardMembers: BoardMember[];
    users: UserItem[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingMember, setEditingMember] = useState<BoardMember | null>(null);

    const form = useForm({
        user_id: '',
        board_role: 'member',
        term_start: new Date().toISOString().split('T')[0],
        term_end: new Date(new Date().setFullYear(new Date().getFullYear() + 3)).toISOString().split('T')[0],
        notes: '',
    });

    const editForm = useForm({
        board_role: '',
        term_start: '',
        term_end: '',
        notes: '',
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

    const handleEditSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingMember) return;
        editForm.put(`/settings/board-members/${editingMember.id}`, {
            onSuccess: () => {
                setEditingMember(null);
                editForm.reset();
            },
        });
    };

    const handleRemove = (id: number) => {
        if (confirm('Remove this board member?')) {
            form.delete(`/settings/board-members/${id}`);
        }
    };

    const openEdit = (member: BoardMember) => {
        setEditingMember(member);
        editForm.setData({
            board_role: member.board_role,
            term_start: member.term_start?.split('T')[0] ?? '',
            term_end: member.term_end?.split('T')[0] ?? '',
            notes: member.notes ?? '',
        });
    };

    const boardMemberUserIds = new Set(boardMembers.map((bm) => bm.user_id));
    const availableUsers = users.filter((u) => !boardMemberUserIds.has(u.id));

    // Stats
    const activeMembers = boardMembers.filter((m) => m.is_active);
    const now = new Date();
    const expiringSoon = boardMembers.filter((m) => {
        if (!m.term_end || !m.is_active) return false;
        const end = new Date(m.term_end);
        const daysLeft = (end.getTime() - now.getTime()) / (1000 * 60 * 60 * 24);
        return daysLeft > 0 && daysLeft <= 90;
    });

    return (
        <div className="space-y-6">
            <p className="text-sm text-muted-foreground">
                Appoint board members and manage governance roles.
            </p>

            {/* Stats row */}
            <div className="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardContent className="flex items-center gap-3 py-4">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900">
                            <Users className="h-5 w-5 text-violet-600" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold">{boardMembers.length}</div>
                            <div className="text-xs text-muted-foreground">Total Board Members</div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center gap-3 py-4">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900">
                            <Landmark className="h-5 w-5 text-emerald-600" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold">{activeMembers.length}</div>
                            <div className="text-xs text-muted-foreground">Active Terms</div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center gap-3 py-4">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900">
                            <Calendar className="h-5 w-5 text-amber-600" />
                        </div>
                        <div>
                            <div className="text-2xl font-bold">{expiringSoon.length}</div>
                            <div className="text-xs text-muted-foreground">Expiring Soon</div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Appoint button */}
            <div className="flex justify-end">
                <Dialog open={isOpen} onOpenChange={setIsOpen}>
                    <DialogTrigger asChild>
                        <Button className="bg-violet-600 hover:bg-violet-700" disabled={availableUsers.length === 0}>
                            <UserPlus className="mr-2 h-4 w-4" />
                            Appoint Member
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
                                    <p className="mt-1 text-sm text-red-600">{form.errors.user_id}</p>
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
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder="Optional notes about this appointment..."
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={form.processing} className="bg-violet-600 hover:bg-violet-700">
                                    Appoint
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            {/* Board member grid */}
            {boardMembers.length > 0 ? (
                <div className="grid gap-4 md:grid-cols-2">
                    {boardMembers.map((member) => {
                        const roleColour = BOARD_ROLE_COLOURS[member.board_role] ?? BOARD_ROLE_COLOURS.member;
                        return (
                            <Card key={member.id}>
                                <CardContent className="py-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-violet-100 text-sm font-semibold text-violet-700 dark:bg-violet-900 dark:text-violet-300">
                                                {getInitials(member.user.name)}
                                            </div>
                                            <div>
                                                <div className="font-medium">{member.user.name}</div>
                                                <div className="text-xs text-muted-foreground">{member.user.email}</div>
                                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                                    <Badge className={`capitalize ${roleColour}`}>
                                                        {member.board_role}
                                                    </Badge>
                                                    {member.is_active ? (
                                                        <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge className="bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300">
                                                            Expired
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-2 text-xs text-muted-foreground">
                                                    {formatTermDate(member.term_start)} &rarr; {formatTermDate(member.term_end)}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => openEdit(member)}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-red-600 hover:text-red-700"
                                                onClick={() => handleRemove(member.id)}
                                                disabled={form.processing}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            ) : (
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Landmark className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium">No board members appointed</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Get started by appointing your first board member.
                        </p>
                    </CardContent>
                </Card>
            )}

            {/* Edit dialog */}
            <Dialog open={!!editingMember} onOpenChange={(open) => !open && setEditingMember(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Board Member</DialogTitle>
                        <DialogDescription>
                            Update {editingMember?.user.name}'s board appointment.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleEditSubmit} className="space-y-4">
                        <div>
                            <Label>Board Role</Label>
                            <Select
                                value={editForm.data.board_role}
                                onValueChange={(v) => editForm.setData('board_role', v)}
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
                                    value={editForm.data.term_start}
                                    onChange={(e) => editForm.setData('term_start', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label>Term End</Label>
                                <Input
                                    type="date"
                                    value={editForm.data.term_end}
                                    onChange={(e) => editForm.setData('term_end', e.target.value)}
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                                placeholder="Optional notes..."
                                rows={3}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={editForm.processing} className="bg-violet-600 hover:bg-violet-700">
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

// --- Main Page ---

export default function AccessControlPage(props: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    if (!can?.settings?.manageAccess) {
        return (
            <SettingsLayout>
                <HeadingSmall title="Overrides & Governance" description="" />
                <div className="rounded-md border p-4 text-sm">
                    You don't have permission to manage access.
                </div>
            </SettingsLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Overrides & Governance" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Permission Overrides & Governance"
                        description="Fine-tune individual user permissions and manage board appointments"
                    />

                    <TabsRoot defaultValue="overrides">
                        <TabsList>
                            <TabsTrigger value="overrides">
                                <Key className="mr-2 h-4 w-4" />
                                Permission Overrides
                            </TabsTrigger>
                            <TabsTrigger value="governance">
                                <Landmark className="mr-2 h-4 w-4" />
                                Board & Governance
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="overrides" className="mt-6">
                            <PermissionOverridesTab
                                users={props.users}
                                permissions={props.permissions}
                                userOverrides={props.userOverrides}
                            />
                        </TabsContent>

                        <TabsContent value="governance" className="mt-6">
                            <BoardGovernanceTab
                                boardMembers={props.boardMembers}
                                users={props.users}
                            />
                        </TabsContent>
                    </TabsRoot>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
