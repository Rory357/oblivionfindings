import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { PresenceBadge } from '@/components/presence-dot';
import { ArrowLeft, Send } from 'lucide-react';
import { FormEvent, useEffect, useRef } from 'react';

type Message = {
    id: number;
    content: string;
    sender_name: string;
    sender_type: string;
    created_at: string;
    is_own: boolean;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    conversation: {
        id: number;
        title?: string | null;
        participants: Array<{ id: number; name: string; presence?: string }>;
    };
    messages: Message[];
};

function formatTime(iso: string): string {
    const date = new Date(iso);
    const now = new Date();
    const isToday = date.toDateString() === now.toDateString();

    const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (isToday) return time;

    return date.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' + time;
}

export default function ShowConversation({ client, conversation, messages }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`;
    const conversationTitle = conversation.title || 'Conversation';
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const form = useForm({
        content: '',
    });

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const handleSend = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.content.trim()) return;

        form.post(`/portal/clients/${client.id}/messages/${conversation.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                textareaRef.current?.focus();
            },
        });
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSend(e as unknown as FormEvent);
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: clientName, href: `/portal/clients/${client.id}/dashboard` },
                { title: 'Messages', href: `/portal/clients/${client.id}/messages` },
                { title: conversationTitle, href: `/portal/clients/${client.id}/messages/${conversation.id}` },
            ]}
        >
            <Head title={`${conversationTitle} - ${clientName}`} />

            <div className="mx-auto flex max-w-3xl flex-col p-4 sm:p-6">
                {/* Header */}
                <div className="mb-4 flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => window.history.back()}
                        className="shrink-0"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                    <div>
                        <h1 className="text-lg font-semibold">{conversationTitle}</h1>
                        <div className="flex items-center gap-3 text-xs text-muted-foreground">
                            <span>{conversation.participants.map((p) => p.name).join(', ')}</span>
                            {conversation.participants.length > 0 && conversation.participants[0]?.presence && (
                                <PresenceBadge status={conversation.participants[0].presence} />
                            )}
                        </div>
                    </div>
                </div>

                {/* Messages */}
                <Card className="flex-1">
                    <CardContent className="max-h-[60vh] min-h-[300px] overflow-y-auto p-4">
                        {messages.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No messages yet. Start the conversation below.
                            </p>
                        ) : (
                            <div className="space-y-4">
                                {messages.map((msg) => (
                                    <div
                                        key={msg.id}
                                        className={`flex ${msg.is_own ? 'justify-end' : 'justify-start'}`}
                                    >
                                        <div
                                            className={`max-w-[75%] rounded-lg px-3 py-2 ${
                                                msg.is_own
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-muted'
                                            }`}
                                        >
                                            {!msg.is_own && (
                                                <p className={`mb-0.5 text-xs font-medium ${
                                                    msg.is_own ? 'text-primary-foreground/70' : 'text-muted-foreground'
                                                }`}>
                                                    {msg.sender_name}
                                                </p>
                                            )}
                                            <p className="whitespace-pre-wrap text-sm">{msg.content}</p>
                                            <p
                                                className={`mt-1 text-xs ${
                                                    msg.is_own
                                                        ? 'text-primary-foreground/70'
                                                        : 'text-muted-foreground'
                                                }`}
                                            >
                                                {formatTime(msg.created_at)}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                                <div ref={messagesEndRef} />
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Input bar */}
                <form onSubmit={handleSend} className="mt-3 flex items-end gap-2">
                    <Textarea
                        ref={textareaRef}
                        value={form.data.content}
                        onChange={(e) => form.setData('content', e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Type a message... (Enter to send, Shift+Enter for new line)"
                        rows={2}
                        className="min-h-[44px] resize-none"
                    />
                    <Button
                        type="submit"
                        size="icon"
                        disabled={!form.data.content.trim() || form.processing}
                        className="shrink-0"
                    >
                        <Send className="h-4 w-4" />
                    </Button>
                </form>
                {form.errors.content && (
                    <p className="mt-1 text-sm text-destructive">{form.errors.content}</p>
                )}
            </div>
        </AppLayout>
    );
}
