import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import {
    Bell,
    Car,
    Fuel,
    MapPin,
    Shield,
    User,
    Users,
    Wrench,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

type NotificationChannel = {
    email: boolean;
    app: boolean;
    control_room: boolean;
};

type NotificationType = {
    key: string;
    label: string;
    description: string;
    icon: React.ElementType;
    channels: NotificationChannel;
    available_channels: ('email' | 'app' | 'control_room')[];
    section: string;
};

const DEFAULT_NOTIFICATIONS: NotificationType[] = [
    {
        key: 'vehicle_offline',
        label: 'Vehicle goes offline',
        description: 'Triggered when a tracked vehicle loses connection for more than 15 minutes.',
        icon: Car,
        channels: { email: true, app: true, control_room: false },
        available_channels: ['email', 'app'],
        section: 'Vehicle Monitoring',
    },
    {
        key: 'geofence_breach',
        label: 'Geofence breach',
        description: 'Triggered when a vehicle enters or exits a geofence zone.',
        icon: MapPin,
        channels: { email: true, app: true, control_room: true },
        available_channels: ['email', 'app', 'control_room'],
        section: 'Vehicle Monitoring',
    },
    {
        key: 'harsh_driving',
        label: 'Harsh driving event',
        description: 'Triggered by harsh braking, acceleration, or speeding events.',
        icon: Zap,
        channels: { email: false, app: true, control_room: true },
        available_channels: ['app', 'control_room'],
        section: 'Vehicle Monitoring',
    },
    {
        key: 'wof_rego_expiring',
        label: 'WOF/Rego expiring in 30 days',
        description: 'Reminder when vehicle compliance documents are approaching expiry.',
        icon: Shield,
        channels: { email: true, app: false, control_room: false },
        available_channels: ['email'],
        section: 'Compliance',
    },
    {
        key: 'driver_licence_expiring',
        label: 'Driver licence expiring',
        description: 'Alert when a driver licence is expiring within 30 days.',
        icon: User,
        channels: { email: true, app: false, control_room: false },
        available_channels: ['email'],
        section: 'Compliance',
    },
    {
        key: 'maintenance_overdue',
        label: 'Maintenance overdue',
        description: 'Triggered when a scheduled service or maintenance task is past due.',
        icon: Wrench,
        channels: { email: true, app: true, control_room: false },
        available_channels: ['email', 'app'],
        section: 'Maintenance',
    },
    {
        key: 'booking_status',
        label: 'Booking approved/rejected',
        description: 'Notification when a vehicle booking request is approved or rejected.',
        icon: Bell,
        channels: { email: false, app: true, control_room: false },
        available_channels: ['app'],
        section: 'Booking',
    },
    {
        key: 'family_transport_departure',
        label: 'Transport departure',
        description: 'Notify family portal users when a resident departs on transport.',
        icon: Users,
        channels: { email: true, app: true, control_room: false },
        available_channels: ['email', 'app'],
        section: 'Family Notifications',
    },
    {
        key: 'family_transport_arrival',
        label: 'Transport arrival',
        description: 'Notify family portal users when a resident arrives at their destination.',
        icon: Users,
        channels: { email: true, app: true, control_room: false },
        available_channels: ['email', 'app'],
        section: 'Family Notifications',
    },
    {
        key: 'family_transport_incident',
        label: 'Incident during transport',
        description: 'Alert family portal users if an incident occurs during resident transport.',
        icon: Users,
        channels: { email: true, app: true, control_room: true },
        available_channels: ['email', 'app', 'control_room'],
        section: 'Family Notifications',
    },
];

function ToggleButton({ enabled, onClick }: { enabled: boolean; onClick: () => void }) {
    return (
        <Switch checked={enabled} onCheckedChange={() => onClick()} />
    );
}

const SECTION_ICONS: Record<string, React.ElementType> = {
    'Vehicle Monitoring': Car,
    'Compliance': Shield,
    'Maintenance': Wrench,
    'Booking': Bell,
    'Family Notifications': Users,
};

const SECTION_COLORS: Record<string, string> = {
    'Vehicle Monitoring': 'border-l-blue-500',
    'Compliance': 'border-l-amber-500',
    'Maintenance': 'border-l-purple-500',
    'Booking': 'border-l-green-500',
    'Family Notifications': 'border-l-cyan-500',
};

export default function NotificationSettings() {
    const [notifications, setNotifications] = useState<NotificationType[]>(DEFAULT_NOTIFICATIONS);
    const [saved, setSaved] = useState(false);

    const toggleChannel = (key: string, channel: keyof NotificationChannel) => {
        setNotifications((prev) =>
            prev.map((n) =>
                n.key === key
                    ? { ...n, channels: { ...n.channels, [channel]: !n.channels[channel] } }
                    : n,
            ),
        );
        setSaved(false);
    };

    const handleSave = () => {
        // UI-only for now - settings can be persisted later
        setSaved(true);
        setTimeout(() => setSaved(false), 3000);
    };

    // Group notifications by section
    const sections: Record<string, NotificationType[]> = {};
    for (const n of notifications) {
        if (!sections[n.section]) sections[n.section] = [];
        sections[n.section].push(n);
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Settings', href: '#' },
                { title: 'Notifications', href: '/fleet-assets/settings/notifications' },
            ]}
        >
            <Head title="Notification Settings" />
            <PageShell>
                <PageHero
                    title="Notification Settings"
                    description="Configure which fleet events trigger notifications and how they are delivered."
                    backHref="/fleet-assets"
                    backLabel="Back to Dashboard"
                />

                <div className="space-y-6">
                    {Object.entries(sections).map(([sectionName, items]) => {
                        const SectionIcon = SECTION_ICONS[sectionName] ?? Bell;
                        return (
                            <Card key={sectionName} className={cn('border-l-4', SECTION_COLORS[sectionName] ?? 'border-l-gray-500')}>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <SectionIcon className="h-4 w-4" />
                                        {sectionName}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-1">
                                        {/* Header row */}
                                        <div className="flex items-center gap-4 px-3 py-2 text-xs font-medium text-muted-foreground">
                                            <div className="flex-1">Event</div>
                                            <div className="w-16 text-center">Email</div>
                                            <div className="w-16 text-center">App</div>
                                            <div className="w-20 text-center">Control Room</div>
                                        </div>
                                        {items.map((notification) => {
                                            const Icon = notification.icon;
                                            return (
                                                <div key={notification.key} className="flex items-center gap-4 rounded-lg border p-3 transition-colors hover:bg-muted/30">
                                                    <div className="flex flex-1 items-start gap-3">
                                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                                            <Icon className="h-4 w-4 text-muted-foreground" />
                                                        </div>
                                                        <div>
                                                            <div className="text-sm font-medium">{notification.label}</div>
                                                            <div className="text-xs text-muted-foreground mt-0.5">
                                                                {notification.description}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div className="w-16 flex justify-center">
                                                        {notification.available_channels.includes('email') ? (
                                                            <ToggleButton
                                                                enabled={notification.channels.email}
                                                                onClick={() => toggleChannel(notification.key, 'email')}
                                                            />
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">---</span>
                                                        )}
                                                    </div>
                                                    <div className="w-16 flex justify-center">
                                                        {notification.available_channels.includes('app') ? (
                                                            <ToggleButton
                                                                enabled={notification.channels.app}
                                                                onClick={() => toggleChannel(notification.key, 'app')}
                                                            />
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">---</span>
                                                        )}
                                                    </div>
                                                    <div className="w-20 flex justify-center">
                                                        {notification.available_channels.includes('control_room') ? (
                                                            <ToggleButton
                                                                enabled={notification.channels.control_room}
                                                                onClick={() => toggleChannel(notification.key, 'control_room')}
                                                            />
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">---</span>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <div className="flex items-center justify-between rounded-lg border bg-muted/30 px-4 py-3">
                    <p className="text-xs text-muted-foreground">
                        Settings are stored locally for now. Backend persistence will be added in a future update.
                    </p>
                    <div className="flex items-center gap-3">
                        {saved && (
                            <span className="text-sm text-status-success dark:text-status-success">Settings saved</span>
                        )}
                        <Button onClick={handleSave}>
                            Save Preferences
                        </Button>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
