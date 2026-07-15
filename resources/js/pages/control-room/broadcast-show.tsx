import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import {
    Bell,
    CheckCircle,
    Clock,
    Mail,
    Megaphone,
    MessageSquare,
    Send,
    Smartphone,
    Users,
    XCircle,
} from 'lucide-react';

// --- TypeScript Interfaces ---

interface TargetUser {
    id: number;
    name: string;
    email: string;
}

interface Recipient {
    id: number;
    channel: string;
    status: string;
    status_detail: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    target_user: TargetUser | null;
}

interface BroadcastSummary {
    broadcast_group_id: string;
    content: string;
    template_used: string | null;
    sent_at: string | null;
    channels: string[];
    total: number;
    delivered: number;
    sent: number;
    pending: number;
    failed: number;
}

interface Props {
    summary: BroadcastSummary;
    recipients: Recipient[];
}

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

const STATUS_CONFIG: Record<
    string,
    { icon: React.ReactNode; color: string; label: string }
> = {
    delivered: {
        icon: <CheckCircle className="h-3.5 w-3.5" />,
        color: 'text-status-success',
        label: 'Delivered',
    },
    sent: {
        icon: <Send className="h-3.5 w-3.5" />,
        color: 'text-status-info',
        label: 'Sent',
    },
    pending: {
        icon: <Clock className="h-3.5 w-3.5" />,
        color: 'text-status-warning',
        label: 'Pending',
    },
    failed: {
        icon: <XCircle className="h-3.5 w-3.5" />,
        color: 'text-status-critical',
        label: 'Failed',
    },
    skipped: {
        icon: <XCircle className="h-3.5 w-3.5" />,
        color: 'text-muted-foreground',
        label: 'Skipped',
    },
};

export default function BroadcastShow({ summary, recipients }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                {
                    title: 'Broadcast Messages',
                    href: '/control-room/broadcast',
                },
                { title: 'Broadcast Detail', href: '#' },
            ]}
        >
            <Head title="Broadcast Detail" />
            <PageShell>
                <CommandCentrePage
                    variant="compact"
                    current="/control-room/broadcast"
                    icon={Megaphone}
                    title="Broadcast delivery"
                    description="View delivery status for each recipient."
                    status="Broadcast record"
                    freshness={
                        summary.sent_at
                            ? `Sent ${formatDateTime(summary.sent_at)}`
                            : 'Not sent'
                    }
                >
                    {/* Summary Card */}
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle className="text-base">Message</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="rounded-lg bg-muted p-4 text-sm whitespace-pre-wrap">
                                {summary.content}
                            </div>

                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase">
                                        Sent At
                                    </p>
                                    <p className="text-sm">
                                        {formatDateTime(summary.sent_at)}
                                    </p>
                                </div>
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase">
                                        Channels
                                    </p>
                                    <div className="flex flex-wrap gap-1">
                                        {summary.channels.map((ch) => (
                                            <Badge
                                                key={ch}
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                {CHANNEL_LABELS[ch] ?? ch}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                                {summary.template_used && (
                                    <div className="space-y-1">
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
                                            Template
                                        </p>
                                        <p className="text-sm capitalize">
                                            {summary.template_used.replace(
                                                /_/g,
                                                ' ',
                                            )}
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Stats Bar */}
                            <div className="flex flex-wrap gap-6 rounded-lg border p-4 text-sm">
                                <div className="flex items-center gap-2">
                                    <Users className="h-4 w-4 text-muted-foreground" />
                                    <span>
                                        Total: <strong>{summary.total}</strong>
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 text-status-success">
                                    <CheckCircle className="h-4 w-4" />
                                    <span>
                                        Delivered:{' '}
                                        <strong>{summary.delivered}</strong>
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 text-status-info">
                                    <Send className="h-4 w-4" />
                                    <span>
                                        Sent: <strong>{summary.sent}</strong>
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 text-status-warning">
                                    <Clock className="h-4 w-4" />
                                    <span>
                                        Pending:{' '}
                                        <strong>{summary.pending}</strong>
                                    </span>
                                </div>
                                {summary.failed > 0 && (
                                    <div className="flex items-center gap-2 text-status-critical">
                                        <XCircle className="h-4 w-4" />
                                        <span>
                                            Failed:{' '}
                                            <strong>{summary.failed}</strong>
                                        </span>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recipients Table */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recipients ({recipients.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th className="pr-4 pb-3 font-medium">
                                                Recipient
                                            </th>
                                            <th className="pr-4 pb-3 font-medium">
                                                Channel
                                            </th>
                                            <th className="pr-4 pb-3 font-medium">
                                                Status
                                            </th>
                                            <th className="pr-4 pb-3 font-medium">
                                                Sent
                                            </th>
                                            <th className="pb-3 font-medium">
                                                Delivered
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recipients.map((recipient) => {
                                            const statusCfg =
                                                STATUS_CONFIG[
                                                    recipient.status
                                                ] ?? STATUS_CONFIG.pending;
                                            return (
                                                <tr
                                                    key={recipient.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-3 pr-4">
                                                        <div className="font-medium">
                                                            {recipient
                                                                .target_user
                                                                ?.name ??
                                                                'Unknown User'}
                                                        </div>
                                                        {recipient.target_user
                                                            ?.email && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {
                                                                    recipient
                                                                        .target_user
                                                                        .email
                                                                }
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <span className="flex items-center gap-1.5">
                                                            {
                                                                CHANNEL_ICONS[
                                                                    recipient
                                                                        .channel
                                                                ]
                                                            }
                                                            {CHANNEL_LABELS[
                                                                recipient
                                                                    .channel
                                                            ] ??
                                                                recipient.channel}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <span
                                                            className={`flex items-center gap-1.5 ${statusCfg.color}`}
                                                        >
                                                            {statusCfg.icon}
                                                            {statusCfg.label}
                                                        </span>
                                                        {recipient.status_detail && (
                                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                                {
                                                                    recipient.status_detail
                                                                }
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-4 whitespace-nowrap text-muted-foreground">
                                                        {formatTime(
                                                            recipient.sent_at,
                                                        )}
                                                    </td>
                                                    <td className="py-3 whitespace-nowrap text-muted-foreground">
                                                        {formatTime(
                                                            recipient.delivered_at,
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </CommandCentrePage>
            </PageShell>
        </AppLayout>
    );
}
