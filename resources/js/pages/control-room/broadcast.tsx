import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Megaphone,
    Send,
    CheckCircle,
    XCircle,
    Clock,
    Users,
    Mail,
    Bell,
    MessageSquare,
    Smartphone,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

// --- TypeScript Interfaces ---

interface BroadcastInitiator {
    id: number;
    name: string;
}

interface BroadcastGroup {
    broadcast_group_id: string;
    content: string;
    channels: string[];
    sent_at: string | null;
    template_used: string | null;
    total_recipients: number;
    delivered_count: number;
    failed_count: number;
    initiated_by: BroadcastInitiator | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedBroadcasts {
    data: BroadcastGroup[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    broadcasts: PaginatedBroadcasts;
    roles: string[];
    roleCounts: Record<string, number>;
    totalStaff: number;
    can: {
        manage: boolean;
    };
}

const TEMPLATES: Record<string, string> = {
    fire_drill: 'URGENT: Fire drill commencing now. All staff please follow evacuation procedures immediately. Assemble at designated muster points. Await further instructions from your shift lead.',
    missing_person: 'ALERT: A resident has been reported missing. All available staff report to the control room immediately for a coordinated search. Do not leave your assigned area unattended.',
    severe_weather: 'WEATHER ALERT: Severe weather warning in effect. Secure all outdoor areas, ensure all residents are indoors, and check emergency supplies. Monitor for further updates.',
    facility_lockdown: 'LOCKDOWN: Facility lockdown is now in effect. Secure all entry and exit points. Keep all residents in their current locations. Await further instructions from the control room.',
    medication_recall: 'MEDICATION RECALL: An urgent medication recall has been issued. All nursing staff: immediately check your medication stores and quarantine affected items. Contact pharmacy for guidance.',
    it_system_outage: 'IT NOTICE: System outage affecting core applications. Switch to manual paper-based procedures. IT team is investigating. Expected resolution time will be communicated shortly.',
    custom: '',
};

const TEMPLATE_LABELS: Record<string, string> = {
    fire_drill: 'Fire Drill',
    missing_person: 'Missing Person',
    severe_weather: 'Severe Weather',
    facility_lockdown: 'Facility Lockdown',
    medication_recall: 'Medication Recall',
    it_system_outage: 'IT System Outage',
    custom: 'Custom Message',
};

const CHANNEL_LABELS: Record<string, string> = {
    in_app: 'In-App',
    push: 'Push Notification',
    sms: 'SMS',
    email: 'Email',
};

const CHANNEL_ICONS: Record<string, React.ReactNode> = {
    in_app: <Bell className="h-3.5 w-3.5" />,
    push: <Smartphone className="h-3.5 w-3.5" />,
    sms: <MessageSquare className="h-3.5 w-3.5" />,
    email: <Mail className="h-3.5 w-3.5" />,
};

const ROLE_LABELS: Record<string, string> = {
    admin: 'Admin',
    coordinator: 'Coordinator',
    support_worker: 'Support Worker',
    shift_lead: 'Shift Lead',
    nurse: 'Nurse',
};

function channelBadgeVariant(channel: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (channel) {
        case 'sms':
            return 'default';
        case 'email':
            return 'secondary';
        case 'push':
            return 'outline';
        default:
            return 'outline';
    }
}

function statusBadge(total: number, delivered: number, failed: number) {
    if (failed > 0 && delivered === 0) {
        return <Badge variant="destructive">Failed</Badge>;
    }
    if (failed > 0) {
        return <Badge variant="destructive">Partial Failure</Badge>;
    }
    if (delivered === total) {
        return <Badge className="bg-status-success-bg text-status-success-foreground hover:bg-status-success/90">Delivered</Badge>;
    }
    return <Badge variant="secondary">Sending</Badge>;
}

export default function ControlRoomBroadcast({
    broadcasts,
    roles,
    roleCounts,
    totalStaff,
    can,
}: Props) {
    const [template, setTemplate] = useState('custom');
    const [content, setContent] = useState('');
    const [channels, setChannels] = useState<string[]>(['in_app']);
    const [targetRoles, setTargetRoles] = useState<string[]>([]);
    const [sendToAll, setSendToAll] = useState(false);
    const [forceDelivery, setForceDelivery] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const handleTemplateChange = (value: string) => {
        setTemplate(value);
        if (value !== 'custom') {
            setContent(TEMPLATES[value] ?? '');
        }
    };

    const toggleChannel = (channel: string) => {
        setChannels((prev) =>
            prev.includes(channel) ? prev.filter((c) => c !== channel) : [...prev, channel],
        );
    };

    const toggleRole = (role: string) => {
        setTargetRoles((prev) =>
            prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role],
        );
    };

    const estimatedRecipients = useMemo(() => {
        if (sendToAll) return totalStaff;
        if (targetRoles.length === 0) return 0;
        // Sum role counts (may overcount due to users with multiple roles, but it's an estimate)
        return targetRoles.reduce((sum, role) => sum + (roleCounts[role] ?? 0), 0);
    }, [sendToAll, targetRoles, roleCounts, totalStaff]);

    const canSend = content.trim().length > 0 && channels.length > 0 && (sendToAll || targetRoles.length > 0);

    const handleSubmit = (e?: FormEvent) => {
        e?.preventDefault();
        if (!canSend || submitting) return;

        setSubmitting(true);
        router.post(
            '/control-room/broadcast',
            {
                content,
                channels,
                target_roles: sendToAll ? [] : targetRoles,
                send_to_all: sendToAll,
                template: template !== 'custom' ? template : null,
                force_delivery: forceDelivery,
            },
            {
                onFinish: () => {
                    setSubmitting(false);
                    setContent('');
                    setTemplate('custom');
                    setChannels(['in_app']);
                    setTargetRoles([]);
                    setSendToAll(false);
                    setForceDelivery(false);
                },
            },
        );
    };

    const handlePageChange = (url: string | null) => {
        if (url) {
            router.get(url, {}, { preserveState: true });
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Broadcast Messages', href: '#' },
            ]}
        >
            <Head title="Broadcast Messages" />
            <PageShell>
                <PageHeader title="Broadcast Messages" description="Send urgent messages to staff across multiple channels." />

                {/* Compose Section */}
                {can.manage && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Megaphone className="h-5 w-5" />
                                New Broadcast
                            </CardTitle>
                            <CardDescription>
                                Compose and send an urgent broadcast message to staff members.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={(e) => e.preventDefault()} className="space-y-6">
                                {/* Template Selector */}
                                <div className="space-y-2">
                                    <Label htmlFor="template">Message Template</Label>
                                    <Select value={template} onValueChange={handleTemplateChange}>
                                        <SelectTrigger id="template" className="w-full sm:w-72">
                                            <SelectValue placeholder="Select a template..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(TEMPLATE_LABELS).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Message Content */}
                                <div className="space-y-2">
                                    <Label htmlFor="content">Message Content</Label>
                                    <Textarea
                                        id="content"
                                        value={content}
                                        onChange={(e) => setContent(e.target.value)}
                                        placeholder="Type your broadcast message here..."
                                        rows={5}
                                        maxLength={2000}
                                        className="resize-y"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {content.length}/2000 characters
                                    </p>
                                </div>

                                {/* Channels */}
                                <div className="space-y-3">
                                    <Label>Channels</Label>
                                    <div className="flex flex-wrap gap-4">
                                        {Object.entries(CHANNEL_LABELS).map(([key, label]) => (
                                            <label
                                                key={key}
                                                className="flex items-center gap-2 cursor-pointer"
                                            >
                                                <Checkbox
                                                    checked={channels.includes(key)}
                                                    onCheckedChange={() => toggleChannel(key)}
                                                />
                                                <span className="flex items-center gap-1.5 text-sm">
                                                    {CHANNEL_ICONS[key]}
                                                    {label}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                {/* Send to All Toggle */}
                                <div className="flex items-center gap-3">
                                    <Switch
                                        id="send-to-all"
                                        checked={sendToAll}
                                        onCheckedChange={setSendToAll}
                                    />
                                    <Label htmlFor="send-to-all" className="cursor-pointer">
                                        Send to All Staff ({totalStaff} members)
                                    </Label>
                                </div>

                                {/* Force delivery — bypasses per-user notification prefs (DND, disabled channels) */}
                                <div className="flex items-start gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg/30 px-4 py-3">
                                    <Switch
                                        id="force-delivery"
                                        checked={forceDelivery}
                                        onCheckedChange={setForceDelivery}
                                    />
                                    <div className="flex-1">
                                        <Label
                                            htmlFor="force-delivery"
                                            className="cursor-pointer text-sm font-medium text-status-critical"
                                        >
                                            Force delivery (emergency)
                                        </Label>
                                        <p className="text-xs text-muted-foreground mt-0.5">
                                            Overrides recipients' Do Not Disturb and channel preferences. Use only for genuine emergencies (fire, lockdown, evacuation).
                                        </p>
                                    </div>
                                </div>

                                {/* Target Roles (hidden when send to all) */}
                                {!sendToAll && (
                                    <div className="space-y-3">
                                        <Label>Target Roles</Label>
                                        <div className="flex flex-wrap gap-4">
                                            {roles.map((role) => (
                                                <label
                                                    key={role}
                                                    className="flex items-center gap-2 cursor-pointer"
                                                >
                                                    <Checkbox
                                                        checked={targetRoles.includes(role)}
                                                        onCheckedChange={() => toggleRole(role)}
                                                    />
                                                    <span className="text-sm">
                                                        {ROLE_LABELS[role] ?? role} ({roleCounts[role] ?? 0})
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Estimated Recipients + Send Button */}
                                <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 rounded-lg border p-4">
                                    <div className="flex items-center gap-2 text-sm">
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                        <span>
                                            Estimated recipients:{' '}
                                            <strong>{estimatedRecipients}</strong>
                                            {channels.length > 1 && (
                                                <span className="text-muted-foreground">
                                                    {' '}({estimatedRecipients * channels.length} total messages across {channels.length} channels)
                                                </span>
                                            )}
                                        </span>
                                    </div>

                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button
                                                disabled={!canSend || submitting}
                                                className="gap-2"
                                            >
                                                <Send className="h-4 w-4" />
                                                Send Broadcast
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>Confirm Broadcast</AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Send to{' '}
                                                    <strong>{estimatedRecipients}</strong>{' '}
                                                    recipient{estimatedRecipients !== 1 ? 's' : ''} via{' '}
                                                    <strong>
                                                        {channels.map((c) => CHANNEL_LABELS[c]).join(', ')}
                                                    </strong>
                                                    ? This action cannot be undone.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                <AlertDialogAction
                                                    onClick={() => handleSubmit()}
                                                    disabled={submitting}
                                                >
                                                    {submitting ? 'Sending...' : 'Send Now'}
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Broadcast History */}
                <Card>
                    <CardHeader>
                        <CardTitle>Broadcast History</CardTitle>
                        <CardDescription>
                            Previously sent broadcast messages and their delivery status.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {broadcasts.data.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center justify-center py-12 text-center">
                                <Megaphone className="mb-3 h-10 w-10 opacity-40" />
                                <p className="text-sm">No broadcasts sent yet.</p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left">
                                                <th className="pb-3 pr-4 font-medium">Message</th>
                                                <th className="pb-3 pr-4 font-medium">Channels</th>
                                                <th className="pb-3 pr-4 font-medium">Sent At</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Recipients</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Delivered</th>
                                                <th className="pb-3 pr-4 font-medium text-right">Failed</th>
                                                <th className="pb-3 font-medium">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {broadcasts.data.map((broadcast) => (
                                                <tr
                                                    key={broadcast.broadcast_group_id}
                                                    className="hover:bg-muted/50 cursor-pointer border-b last:border-0"
                                                    onClick={() =>
                                                        router.get(
                                                            `/control-room/broadcast/${broadcast.broadcast_group_id}`,
                                                        )
                                                    }
                                                >
                                                    <td className="max-w-xs truncate py-3 pr-4">
                                                        <div className="truncate font-medium">
                                                            {broadcast.content?.slice(0, 80)}
                                                            {(broadcast.content?.length ?? 0) > 80 ? '...' : ''}
                                                        </div>
                                                        {broadcast.initiated_by && (
                                                            <div className="text-muted-foreground text-xs">
                                                                by {broadcast.initiated_by.name}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <div className="flex flex-wrap gap-1">
                                                            {broadcast.channels.map((ch) => (
                                                                <Badge
                                                                    key={ch}
                                                                    variant={channelBadgeVariant(ch)}
                                                                    className="text-xs"
                                                                >
                                                                    {CHANNEL_LABELS[ch] ?? ch}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    </td>
                                                    <td className="text-muted-foreground whitespace-nowrap py-3 pr-4">
                                                        {broadcast.sent_at
                                                            ? new Date(broadcast.sent_at).toLocaleString('en-NZ', {
                                                                  day: 'numeric',
                                                                  month: 'short',
                                                                  hour: '2-digit',
                                                                  minute: '2-digit',
                                                              })
                                                            : '-'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right">
                                                        <span className="flex items-center justify-end gap-1">
                                                            <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                                            {broadcast.total_recipients}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-4 text-right">
                                                        <span className="flex items-center justify-end gap-1 text-status-success">
                                                            <CheckCircle className="h-3.5 w-3.5" />
                                                            {broadcast.delivered_count}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-4 text-right">
                                                        <span className={`flex items-center justify-end gap-1 ${broadcast.failed_count > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}>
                                                            <XCircle className="h-3.5 w-3.5" />
                                                            {broadcast.failed_count}
                                                        </span>
                                                    </td>
                                                    <td className="py-3">
                                                        {statusBadge(
                                                            broadcast.total_recipients,
                                                            broadcast.delivered_count,
                                                            broadcast.failed_count,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Pagination */}
                                {broadcasts.last_page > 1 && (
                                    <div className="mt-4 flex items-center justify-between border-t pt-4">
                                        <p className="text-muted-foreground text-sm">
                                            Page {broadcasts.current_page} of {broadcasts.last_page} ({broadcasts.total} total)
                                        </p>
                                        <div className="flex gap-1">
                                            {broadcasts.links.map((link, idx) => (
                                                <Button
                                                    key={idx}
                                                    variant={link.active ? 'default' : 'outline'}
                                                    size="sm"
                                                    disabled={!link.url}
                                                    onClick={() => handlePageChange(link.url)}
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
