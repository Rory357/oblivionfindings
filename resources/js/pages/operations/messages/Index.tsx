import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { MessageSquareText, Search, Users } from 'lucide-react';

type Conversation = {
    id: number;
    title: string | null;
    conversation_type: string;
    is_archived: boolean;
    client: { id: number; first_name: string; last_name: string } | null;
    latest_message: {
        id: number;
        content: string;
        created_at: string;
        sender: { id: number; name: string } | null;
    } | null;
    unread_count: number;
    participants_count: number;
};

type Props = {
    conversations: Conversation[];
    filters: { q?: string };
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

const TYPE_LABELS: Record<string, string> = {
    direct: 'Direct',
    group: 'Group',
    client_team: 'Client Team',
    family: 'Family',
};

export default function MessagesIndex({ conversations = [], filters = {} as any }: Props) {
    return (
        <AppLayout>
            <Head title="Messages" />
            <PageHeader title="Messages" description="Team messaging and client communications." backHref="/operations" />
            <PageShell>
                <div className="mb-4">
                    <div className="relative">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search conversations..." className="h-9 pl-8 text-sm" defaultValue={filters?.q ?? ''}
                            onChange={(e) => router.get('/operations/messages', { q: e.target.value || null }, { preserveState: true, replace: true })} />
                    </div>
                </div>
                <div className="space-y-1">
                    {(conversations ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <MessageSquareText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Conversations</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Start a conversation with your team or client care teams.</p>
                            </CardContent>
                        </Card>
                    )}
                    {(conversations ?? []).map((conv) => (
                        <Link key={conv.id} href={`/operations/messages/${conv.id}`} className="block">
                            <Card className={`transition-all hover:border-border hover:shadow-sm ${conv.unread_count > 0 ? 'border-indigo-200 bg-indigo-50/30 dark:border-indigo-900/30 dark:bg-indigo-950/10' : ''}`}>
                                <CardContent className="flex items-center gap-3 p-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        {conv.conversation_type === 'client_team' || conv.conversation_type === 'family' ? (
                                            <Users className="h-4 w-4" />
                                        ) : (
                                            <MessageSquareText className="h-4 w-4" />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className={`text-sm ${conv.unread_count > 0 ? 'font-bold' : 'font-medium'}`}>
                                                {conv.title ?? conv.client ? `${conv.client?.first_name} ${conv.client?.last_name} Team` : 'Conversation'}
                                            </span>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px]">{TYPE_LABELS[conv.conversation_type] ?? conv.conversation_type}</Badge>
                                            {conv.unread_count > 0 && (
                                                <Badge className="h-4 min-w-[16px] px-1 text-[9px]">{conv.unread_count}</Badge>
                                            )}
                                        </div>
                                        {conv.latest_message && (
                                            <p className={`mt-0.5 truncate text-xs ${conv.unread_count > 0 ? 'font-medium text-foreground' : 'text-muted-foreground'}`}>
                                                {conv.latest_message.sender?.name}: {conv.latest_message.content}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        {conv.latest_message && (
                                            <span className="text-[10px] text-muted-foreground">{formatRelativeTime(conv.latest_message.created_at)}</span>
                                        )}
                                        <span className="text-[10px] text-muted-foreground">{conv.participants_count} members</span>
                                    </div>
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            </PageShell>
        </AppLayout>
    );
}
