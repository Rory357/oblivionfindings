import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { MessageSquare, Plus, Send } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { toast } from 'sonner';

type Conversation = {
    id: number;
    title?: string | null;
    latest_message?: { content: string; created_at: string; sender_name: string } | null;
    participants: Array<{ id: number; name: string }>;
    updated_at: string;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    conversations: Conversation[];
};

function timeAgo(iso: string): string {
    const seconds = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString([], { day: 'numeric', month: 'short' });
}

function truncate(text: string, maxLength: number): string {
    return text.length > maxLength ? text.slice(0, maxLength) + '...' : text;
}

export default function Messages({ client, conversations }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`;
    const [newOpen, setNewOpen] = useState(false);

    const form = useForm({
        title: '',
        message: '',
    });

    const handleStart = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.message.trim()) return;

        form.post(`/portal/clients/${client.id}/messages/start`, {
            onSuccess: () => {
                setNewOpen(false);
                form.reset();
                toast.success('Conversation started');
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: clientName, href: `/portal/clients/${client.id}/dashboard` },
                { title: 'Messages', href: `/portal/clients/${client.id}/messages` },
            ]}
        >
            <Head title={`Messages - ${clientName}`} />

            <div className="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Messages</h1>
                    <Dialog open={newOpen} onOpenChange={setNewOpen}>
                        <DialogTrigger asChild>
                            <Button>
                                <Plus className="mr-2 h-4 w-4" />
                                New Message
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New Conversation</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={handleStart} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="conv-title">Subject (optional)</Label>
                                    <Input
                                        id="conv-title"
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                        placeholder="e.g. Question about upcoming appointment"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="conv-message">Message</Label>
                                    <Textarea
                                        id="conv-message"
                                        value={form.data.message}
                                        onChange={(e) => form.setData('message', e.target.value)}
                                        placeholder="Write your message..."
                                        rows={4}
                                    />
                                    {form.errors.message && (
                                        <p className="text-sm text-destructive">{form.errors.message}</p>
                                    )}
                                </div>
                                <DialogFooter>
                                    <Button type="submit" disabled={!form.data.message.trim() || form.processing}>
                                        <Send className="mr-2 h-4 w-4" />
                                        {form.processing ? 'Sending...' : 'Send'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {conversations.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <MessageSquare className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">No conversations yet.</p>
                            <p className="text-sm text-muted-foreground">Start one with the care team.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        {conversations.map((conv) => {
                            const displayTitle =
                                conv.title || conv.participants.map((p) => p.name).join(', ') || 'Conversation';

                            return (
                                <Card
                                    key={conv.id}
                                    className="cursor-pointer transition-shadow hover:shadow-md"
                                    onClick={() =>
                                        router.get(`/portal/clients/${client.id}/messages/${conv.id}`)
                                    }
                                >
                                    <CardContent className="flex items-start gap-3 p-4">
                                        <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                            <MessageSquare className="h-4 w-4 text-primary" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <h3 className="truncate text-sm font-semibold">{displayTitle}</h3>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {timeAgo(conv.updated_at)}
                                                </span>
                                            </div>
                                            {conv.latest_message && (
                                                <p className="mt-0.5 truncate text-sm text-muted-foreground">
                                                    <span className="font-medium">
                                                        {conv.latest_message.sender_name}:
                                                    </span>{' '}
                                                    {truncate(conv.latest_message.content, 80)}
                                                </p>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
