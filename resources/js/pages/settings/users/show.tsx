import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ArrowLeft, Calendar, CheckCircle2, Clock, Mail, Plus, Shield, ShieldAlert, User, X, XCircle } from 'lucide-react';
import { useState } from 'react';

type Role = { id: number; name: string; label?: string };
type Props = {
    user: {
        id: number;
        name: string;
        email: string;
        avatar?: string;
        is_active: boolean;
        approved_at?: string;
        created_at?: string;
        roles?: Role[];
        user_type?: string;
        staff_profile?: any;
    };
    allRoles?: Role[];
};

export default function UserShow({ user, allRoles = [] }: Props) {
    const u = user ?? {} as any;
    const roles: Role[] = u.roles ?? [];
    const initials = (u.name ?? '?').split(' ').map((n: string) => n[0]).join('').slice(0, 2).toUpperCase();
    const [showAddRole, setShowAddRole] = useState(false);
    const assignedIds = new Set(roles.map((r: Role) => r.id));
    const availableRoles = allRoles.filter((r) => !assignedIds.has(r.id));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: 'Users', href: '/settings/users' },
        { title: u.name ?? 'User' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${u.name ?? 'User'} — Settings`} />
            <SettingsLayout>
                <div className="space-y-6">
                    {/* Back link */}
                    <Link href="/settings/users" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="h-4 w-4" /> Back to Users
                    </Link>

                    {/* Profile Header */}
                    <div className="relative overflow-hidden rounded-xl border bg-white dark:bg-gray-950">
                        <div className="h-1.5 w-full bg-gradient-to-r from-violet-600 via-indigo-500 to-purple-600" />
                        <div className="px-6 py-6">
                            <div className="flex items-center gap-5">
                                <Avatar className="h-16 w-16 border-2 border-violet-100 shadow-md">
                                    <AvatarImage src={u.avatar} alt={u.name} />
                                    <AvatarFallback className="bg-violet-600 text-lg font-semibold text-white">{initials}</AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-3">
                                        <h1 className="text-xl font-semibold tracking-tight truncate">{u.name}</h1>
                                        {u.is_active ? (
                                            <Badge className="bg-emerald-100 text-emerald-700 text-xs">Active</Badge>
                                        ) : (
                                            <Badge variant="destructive" className="text-xs">Inactive</Badge>
                                        )}
                                        {u.user_type && (
                                            <Badge variant="secondary" className="text-xs capitalize">{u.user_type}</Badge>
                                        )}
                                    </div>
                                    <p className="mt-0.5 text-sm text-muted-foreground flex items-center gap-1.5">
                                        <Mail className="h-3.5 w-3.5" /> {u.email}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {!u.is_active && (
                                        <Button size="sm" onClick={() => router.post(`/settings/users/${u.id}/approve`, {}, { preserveScroll: true })}>
                                            <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Approve
                                        </Button>
                                    )}
                                    {u.is_active && (
                                        <Button size="sm" variant="outline" className="text-amber-600 border-amber-300 hover:bg-amber-50"
                                            onClick={() => router.post(`/settings/users/${u.id}/suspend`, {}, { preserveScroll: true })}>
                                            <ShieldAlert className="mr-1.5 h-3.5 w-3.5" /> Suspend
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Two-column layout */}
                    <div className="grid gap-6 lg:grid-cols-[1fr_0.67fr]">
                        {/* Left column */}
                        <div className="space-y-6">
                            {/* Account Details */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <User className="h-5 w-5 text-violet-600" /> Account Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">Name</span>
                                        <span className="col-span-2 font-medium">{u.name}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">Email</span>
                                        <span className="col-span-2">{u.email}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">User Type</span>
                                        <span className="col-span-2 capitalize">{u.user_type ?? '—'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">Status</span>
                                        <span className="col-span-2">
                                            {u.is_active ? (
                                                <span className="flex items-center gap-1 text-emerald-600"><CheckCircle2 className="h-3.5 w-3.5" /> Active</span>
                                            ) : (
                                                <span className="flex items-center gap-1 text-red-600"><XCircle className="h-3.5 w-3.5" /> Inactive</span>
                                            )}
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">Approved</span>
                                        <span className="col-span-2">{u.approved_at ? new Date(u.approved_at).toLocaleDateString('en-NZ') : '—'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 text-sm">
                                        <span className="text-muted-foreground">Member since</span>
                                        <span className="col-span-2 flex items-center gap-1">
                                            <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                                            {u.created_at ? new Date(u.created_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'long', year: 'numeric' }) : '—'}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Right column */}
                        <div className="space-y-6">
                            {/* Roles */}
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="flex items-center gap-2">
                                                <Shield className="h-5 w-5 text-violet-600" /> Roles
                                            </CardTitle>
                                            <CardDescription>Assign or remove roles for this user</CardDescription>
                                        </div>
                                        {availableRoles.length > 0 && (
                                            <Button size="sm" variant="outline" className="gap-1" onClick={() => setShowAddRole(!showAddRole)}>
                                                <Plus className="h-3.5 w-3.5" /> Add
                                            </Button>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {/* Add role dropdown */}
                                    {showAddRole && availableRoles.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5 rounded-lg border border-dashed border-violet-300 bg-violet-50/50 p-3">
                                            <span className="text-xs text-muted-foreground w-full mb-1">Click to assign:</span>
                                            {availableRoles.map((role) => (
                                                <button
                                                    key={role.id}
                                                    onClick={() => {
                                                        const newIds = [...Array.from(assignedIds), role.id];
                                                        router.put(`/settings/users/${u.id}/roles`, { role_ids: newIds }, { preserveScroll: true });
                                                        setShowAddRole(false);
                                                    }}
                                                    className="inline-flex items-center gap-1 rounded-full border border-violet-200 bg-white px-2.5 py-1 text-xs font-medium text-violet-700 hover:bg-violet-100 transition-colors"
                                                >
                                                    <Plus className="h-3 w-3" /> {role.label || role.name}
                                                </button>
                                            ))}
                                        </div>
                                    )}

                                    {/* Current roles */}
                                    {roles.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">No roles assigned</p>
                                    ) : (
                                        <div className="space-y-2">
                                            {roles.map((role: Role) => (
                                                <div key={role.id} className="flex items-center justify-between rounded-lg border px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        <Shield className="h-3.5 w-3.5 text-violet-600" />
                                                        <span className="text-sm font-medium">{role.label || role.name}</span>
                                                    </div>
                                                    <button
                                                        onClick={() => {
                                                            const newIds = Array.from(assignedIds).filter(id => id !== role.id);
                                                            router.put(`/settings/users/${u.id}/roles`, { role_ids: newIds }, { preserveScroll: true });
                                                        }}
                                                        className="rounded p-1 text-muted-foreground hover:bg-red-50 hover:text-red-600 transition-colors"
                                                        title="Remove role"
                                                    >
                                                        <X className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Quick Actions */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Clock className="h-5 w-5 text-violet-600" /> Quick Actions
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <Link href="/settings/access">
                                        <Button variant="outline" size="sm" className="w-full justify-start gap-2">
                                            <Shield className="h-3.5 w-3.5" /> Edit Permissions
                                        </Button>
                                    </Link>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
