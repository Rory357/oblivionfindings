import { cn, isSameUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    BellRing,
    Building2,
    FileText,
    Languages,
    Lock,
    Menu,
    Paintbrush,
    Palette,
    Plug,
    Shield,
    ShieldCheck,
    User,
    UserCog,
    Users,
    Wifi,
} from 'lucide-react';
import { type PropsWithChildren, useMemo, useState } from 'react';

import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import type { LucideIcon } from 'lucide-react';

interface NavSection {
    label: string;
    permission?: string;
    items: {
        icon: LucideIcon;
        title: string;
        href: string;
        permission?: string;
    }[];
}

const navSections: NavSection[] = [
    {
        label: 'General',
        items: [
            { icon: User, title: 'Profile', href: edit() },
            { icon: Lock, title: 'Password', href: editPassword() },
            { icon: ShieldCheck, title: 'Two-Factor Auth', href: show() },
            { icon: Palette, title: 'Appearance', href: editAppearance() },
        ],
    },
    {
        label: 'Branding',
        permission: 'settings.manageBranding',
        items: [
            { icon: Paintbrush, title: 'Branding', href: '/settings/branding' },
        ],
    },
    {
        label: 'Organisation',
        items: [
            { icon: Languages, title: 'Terminology', href: '/settings/terminology', permission: 'settings.manageTerminology' },
            { icon: Building2, title: 'Service Contexts', href: '/settings/service-contexts', permission: 'settings.manageServiceContexts' },
        ],
    },
    {
        label: 'User Management',
        permission: 'settings.manageAccess',
        items: [
            { icon: Users, title: 'Users', href: '/settings/users' },
            { icon: UserCog, title: 'Access Control', href: '/settings/access' },
        ],
    },
    {
        label: 'Roles & Permissions',
        permission: 'settings.manageAccess',
        items: [
            { icon: Shield, title: 'Roles', href: '/settings/roles' },
        ],
    },
    {
        label: 'Security',
        permission: 'settings.manageAccess',
        items: [
            { icon: Shield, title: 'Security Settings', href: '/settings/security' },
        ],
    },
    {
        label: 'Notifications',
        items: [
            { icon: Bell, title: 'My Notifications', href: '/settings/notifications' },
            { icon: BellRing, title: 'Role Defaults', href: '/settings/notifications/roles', permission: 'settings.manageAccess' },
            { icon: AlertTriangle, title: 'Escalation Rules', href: '/settings/notifications/escalations', permission: 'settings.manageAccess' },
        ],
    },
    {
        label: 'Integrations',
        permission: 'integrations.view',
        items: [
            { icon: Plug, title: 'Integration Hub', href: '/settings/integrations' },
            { icon: Wifi, title: 'UniFi', href: '/settings/integrations/unifi' },
        ],
    },
    {
        label: 'Audit',
        permission: 'settings.manageAccess',
        items: [
            { icon: FileText, title: 'Audit Logs', href: '/settings/audit-logs' },
        ],
    },
];

function resolvePermission(can: Record<string, any> | undefined, key: string): boolean {
    if (!can || !key) return true;
    const parts = key.split('.');
    let current: any = can;
    for (const part of parts) {
        if (current == null || typeof current !== 'object') return false;
        current = current[part];
    }
    return !!current;
}

function NavContent({ currentPath, can }: { currentPath: string; can: Record<string, any> | undefined }) {
    return (
        <nav className="flex flex-col gap-1 py-2">
            {navSections.map((section) => {
                // Filter items by permission
                const visibleItems = section.items.filter((item) => {
                    const perm = item.permission ?? section.permission;
                    return !perm || resolvePermission(can, perm);
                });

                if (visibleItems.length === 0) return null;

                return (
                    <div key={section.label}>
                        <h4 className="text-muted-foreground px-3 pt-4 pb-1 text-xs font-medium uppercase tracking-wider">
                            {section.label}
                        </h4>
                        {visibleItems.map((item) => {
                            const isActive = isSameUrl(currentPath, item.href);
                            const Icon = item.icon;
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={cn(
                                        'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition-colors',
                                        'hover:bg-muted/50',
                                        isActive
                                            ? 'border-l-2 border-violet-600 bg-violet-50 font-medium text-violet-700'
                                            : 'text-foreground/80',
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            'h-4 w-4 shrink-0',
                                            isActive ? 'text-violet-600' : 'text-muted-foreground',
                                        )}
                                    />
                                    {item.title}
                                </Link>
                            );
                        })}
                    </div>
                );
            })}
        </nav>
    );
}

export default function SettingsLayout({ children }: PropsWithChildren) {
    if (typeof window === 'undefined') {
        return null;
    }

    const currentPath = window.location.pathname;
    const { auth } = usePage().props as any;
    const can = auth?.can;
    const [mobileOpen, setMobileOpen] = useState(false);

    // Find current page title for mobile header
    const currentTitle = useMemo(() => {
        for (const section of navSections) {
            for (const item of section.items) {
                if (isSameUrl(currentPath, item.href)) {
                    return item.title;
                }
            }
        }
        return 'Settings';
    }, [currentPath]);

    return (
        <div className="flex h-full min-h-0">
            {/* Desktop sidebar */}
            <aside className="hidden w-60 shrink-0 overflow-y-auto border-r bg-white lg:block">
                <div className="px-3 pt-4 pb-2">
                    <h2 className="px-3 text-lg font-semibold tracking-tight">Settings</h2>
                </div>
                <NavContent currentPath={currentPath} can={can} />
            </aside>

            {/* Mobile header + sheet */}
            <div className="flex flex-1 flex-col overflow-hidden">
                <div className="flex items-center gap-3 border-b bg-white px-4 py-3 lg:hidden">
                    <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                        <SheetTrigger asChild>
                            <Button variant="ghost" size="icon" className="shrink-0">
                                <Menu className="h-5 w-5" />
                                <span className="sr-only">Open settings menu</span>
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" className="w-72 p-0">
                            <SheetTitle className="px-6 pt-5 pb-0 text-lg font-semibold">Settings</SheetTitle>
                            <div className="px-3" onClick={() => setMobileOpen(false)}>
                                <NavContent currentPath={currentPath} can={can} />
                            </div>
                        </SheetContent>
                    </Sheet>
                    <span className="text-sm font-medium">{currentTitle}</span>
                </div>

                {/* Content area */}
                <div className="flex-1 overflow-y-auto p-6">
                    <div className="mx-auto max-w-4xl">
                        <section className="space-y-12">{children}</section>
                    </div>
                </div>
            </div>
        </div>
    );
}
