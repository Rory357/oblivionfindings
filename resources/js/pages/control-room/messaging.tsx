import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CheckCheck,
    MessageSquarePlus,
    Send,
    User,
} from 'lucide-react';
import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';

// --- TypeScript Interfaces ---

interface StaffMember {
    id: number;
    name: string;
}

interface Thread {
    id: string;
    type: 'alert' | 'direct';
    alert_id: number | null;
    user_id: number | null;
    title: string;
    last_message: string;
    last_message_at: string | null;
    unread_count: number;
    message_count: number;
}

interface Message {
    id: number;
    direction: 'outbound' | 'inbound';
    content: string;
    sender_name: string;
    sent_at: string | null;
    delivered_at: string | null;
    status: string;
}

interface Props {
    threads: Thread[];
    staff: StaffMember[];
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function formatMessageTime(isoString: string | null): string {
    if (!isoString) return '';
    const date = new Date(isoString);
    return date.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getInitial(name: string): string {
    return name.charAt(0).toUpperCase();
}

// --- Component ---

export default function ControlRoomMessaging({ threads, staff, can }: Props) {
    const [activeThread, setActiveThread] = useState<Thread | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [loadingMessages, setLoadingMessages] = useState(false);
    const [composeText, setComposeText] = useState('');
    const [sending, setSending] = useState(false);
    const [newConvoOpen, setNewConvoOpen] = useState(false);
    const [newConvoUser, setNewConvoUser] = useState('');
    const [newConvoAlertId, setNewConvoAlertId] = useState('');
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const scrollToBottom = useCallback(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, []);

    const fetchMessages = useCallback(
        async (thread: Thread) => {
            const params = new URLSearchParams();
            if (thread.alert_id) {
                params.set('alert_id', String(thread.alert_id));
            } else if (thread.user_id) {
                params.set('user_id', String(thread.user_id));
            }

            try {
                const response = await fetch(
                    `/control-room/messaging/thread?${params.toString()}`,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );
                if (response.ok) {
                    const data = await response.json();
                    setMessages(data.messages);

                    // Mark inbound unread messages as read
                    if (can.manage) {
                        for (const msg of data.messages) {
                            if (msg.direction === 'inbound' && !msg.delivered_at) {
                                fetch(`/control-room/messaging/${msg.id}/read`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-CSRF-TOKEN':
                                            document
                                                .querySelector('meta[name="csrf-token"]')
                                                ?.getAttribute('content') ?? '',
                                    },
                                });
                            }
                        }
                    }
                }
            } catch {
                // Silently fail on network errors for auto-refresh
            }
        },
        [can.manage],
    );

    const selectThread = useCallback(
        (thread: Thread) => {
            setActiveThread(thread);
            setMessages([]);
            setLoadingMessages(true);
            fetchMessages(thread).finally(() => setLoadingMessages(false));
        },
        [fetchMessages],
    );

    // Auto-refresh messages every 15 seconds for active thread
    useEffect(() => {
        if (refreshTimerRef.current) {
            clearInterval(refreshTimerRef.current);
            refreshTimerRef.current = null;
        }

        if (activeThread) {
            refreshTimerRef.current = setInterval(() => {
                fetchMessages(activeThread);
            }, 15000);
        }

        return () => {
            if (refreshTimerRef.current) {
                clearInterval(refreshTimerRef.current);
            }
        };
    }, [activeThread, fetchMessages]);

    // Scroll to bottom when messages change
    useEffect(() => {
        scrollToBottom();
    }, [messages, scrollToBottom]);

    const handleSend = async (e: FormEvent) => {
        e.preventDefault();
        if (!composeText.trim() || !activeThread || sending) return;

        setSending(true);

        const payload: Record<string, unknown> = {
            content: composeText.trim(),
            target_user_id: activeThread.user_id,
            alert_id: activeThread.alert_id,
        };

        try {
            const response = await fetch('/control-room/messaging/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                },
                body: JSON.stringify(payload),
            });

            if (response.ok) {
                const data = await response.json();
                setMessages((prev) => [...prev, data.message]);
                setComposeText('');
            }
        } catch {
            // Handle error silently
        } finally {
            setSending(false);
        }
    };

    const handleNewConversation = (e: FormEvent) => {
        e.preventDefault();
        if (!newConvoUser) return;

        const userId = parseInt(newConvoUser);
        const alertId = newConvoAlertId ? parseInt(newConvoAlertId) : null;
        const selectedStaff = staff.find((s) => s.id === userId);

        const threadId = alertId ? `alert-${alertId}` : `user-${userId}`;

        // Check if thread already exists
        const existingThread = threads.find((t) => t.id === threadId);
        if (existingThread) {
            selectThread(existingThread);
            setNewConvoOpen(false);
            setNewConvoUser('');
            setNewConvoAlertId('');
            return;
        }

        // Create a virtual thread for new conversation
        const newThread: Thread = {
            id: threadId,
            type: alertId ? 'alert' : 'direct',
            alert_id: alertId,
            user_id: userId,
            title: selectedStaff?.name ?? 'Unknown',
            last_message: '',
            last_message_at: null,
            unread_count: 0,
            message_count: 0,
        };

        setActiveThread(newThread);
        setMessages([]);
        setNewConvoOpen(false);
        setNewConvoUser('');
        setNewConvoAlertId('');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Messaging', href: '#' },
            ]}
        >
            <Head title="Messaging - Control Room" />
            <PageShell>
                <PageHeader
                    title="Messaging"
                    description="Message field staff and manage alert-linked conversations."
                />

                <Card className="overflow-hidden">
                    <div className="flex h-[calc(100vh-16rem)]">
                        {/* Left Panel - Thread List */}
                        <div className="flex w-80 flex-shrink-0 flex-col border-r">
                            <div className="flex items-center justify-between border-b p-4">
                                <h3 className="text-sm font-semibold">Conversations</h3>
                                {can.manage && (
                                    <Dialog open={newConvoOpen} onOpenChange={setNewConvoOpen}>
                                        <DialogTrigger asChild>
                                            <Button size="sm" variant="outline">
                                                <MessageSquarePlus className="mr-1 h-4 w-4" />
                                                New
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent className="sm:max-w-md">
                                            <form onSubmit={handleNewConversation}>
                                                <DialogHeader>
                                                    <DialogTitle>New Conversation</DialogTitle>
                                                    <DialogDescription>
                                                        Start a new direct message or alert-linked conversation.
                                                    </DialogDescription>
                                                </DialogHeader>
                                                <div className="space-y-4 py-4">
                                                    <div>
                                                        <Label htmlFor="new-convo-user">Staff Member</Label>
                                                        <Select
                                                            value={newConvoUser}
                                                            onValueChange={setNewConvoUser}
                                                        >
                                                            <SelectTrigger id="new-convo-user" className="mt-1">
                                                                <SelectValue placeholder="Select staff member..." />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {staff.map((s) => (
                                                                    <SelectItem
                                                                        key={s.id}
                                                                        value={String(s.id)}
                                                                    >
                                                                        {s.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div>
                                                        <Label htmlFor="new-convo-alert">
                                                            Alert ID (optional)
                                                        </Label>
                                                        <Input
                                                            id="new-convo-alert"
                                                            type="number"
                                                            placeholder="Link to alert #"
                                                            value={newConvoAlertId}
                                                            onChange={(e) =>
                                                                setNewConvoAlertId(e.target.value)
                                                            }
                                                            className="mt-1"
                                                        />
                                                    </div>
                                                </div>
                                                <DialogFooter>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={() => setNewConvoOpen(false)}
                                                    >
                                                        Cancel
                                                    </Button>
                                                    <Button type="submit" disabled={!newConvoUser}>
                                                        Start Conversation
                                                    </Button>
                                                </DialogFooter>
                                            </form>
                                        </DialogContent>
                                    </Dialog>
                                )}
                            </div>

                            {/* Thread List */}
                            <div className="flex-1 overflow-y-auto">
                                {threads.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
                                        <MessageSquarePlus className="mb-2 h-8 w-8" />
                                        <p className="text-sm">No conversations yet</p>
                                        <p className="text-xs">
                                            Start a new conversation to begin messaging.
                                        </p>
                                    </div>
                                ) : (
                                    threads.map((thread) => (
                                        <button
                                            key={thread.id}
                                            onClick={() => selectThread(thread)}
                                            className={`w-full border-b px-4 py-3 text-left transition-colors hover:bg-accent/50 ${
                                                activeThread?.id === thread.id
                                                    ? 'bg-accent'
                                                    : ''
                                            } ${
                                                thread.type === 'alert'
                                                    ? 'border-l-4 border-l-orange-400'
                                                    : 'border-l-4 border-l-blue-400'
                                            }`}
                                        >
                                            <div className="flex items-start gap-3">
                                                {/* Avatar */}
                                                <div
                                                    className={`flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-sm font-medium text-white ${
                                                        thread.type === 'alert'
                                                            ? 'bg-orange-500'
                                                            : 'bg-blue-500'
                                                    }`}
                                                >
                                                    {thread.type === 'alert' ? (
                                                        <AlertTriangle className="h-4 w-4" />
                                                    ) : (
                                                        getInitial(thread.title)
                                                    )}
                                                </div>

                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center justify-between">
                                                        <span className="truncate text-sm font-medium">
                                                            {thread.title}
                                                        </span>
                                                        <span className="ml-2 flex-shrink-0 text-xs text-muted-foreground">
                                                            {formatRelativeTime(
                                                                thread.last_message_at,
                                                            )}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center justify-between">
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {thread.last_message || 'No messages'}
                                                        </p>
                                                        {thread.unread_count > 0 && (
                                                            <span className="ml-2 inline-flex h-5 min-w-5 flex-shrink-0 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white">
                                                                {thread.unread_count}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    ))
                                )}
                            </div>
                        </div>

                        {/* Right Panel - Messages */}
                        <div className="flex flex-1 flex-col">
                            {activeThread ? (
                                <>
                                    {/* Thread Header */}
                                    <div className="flex items-center gap-3 border-b px-6 py-3">
                                        <div
                                            className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium text-white ${
                                                activeThread.type === 'alert'
                                                    ? 'bg-orange-500'
                                                    : 'bg-blue-500'
                                            }`}
                                        >
                                            {activeThread.type === 'alert' ? (
                                                <AlertTriangle className="h-4 w-4" />
                                            ) : (
                                                getInitial(activeThread.title)
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <h3 className="truncate text-sm font-semibold">
                                                    {activeThread.title}
                                                </h3>
                                                <Badge
                                                    variant={
                                                        activeThread.type === 'alert'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                    className="text-[10px]"
                                                >
                                                    {activeThread.type === 'alert'
                                                        ? 'Alert'
                                                        : 'Direct'}
                                                </Badge>
                                            </div>
                                            {activeThread.alert_id && (
                                                <a
                                                    href={`/control-room/alerts/${activeThread.alert_id}`}
                                                    className="text-xs text-blue-600 hover:underline"
                                                >
                                                    View linked alert #{activeThread.alert_id}
                                                </a>
                                            )}
                                        </div>
                                    </div>

                                    {/* Messages Area */}
                                    <div className="flex-1 overflow-y-auto px-6 py-4">
                                        {loadingMessages ? (
                                            <div className="flex h-full items-center justify-center text-muted-foreground">
                                                <p className="text-sm">Loading messages...</p>
                                            </div>
                                        ) : messages.length === 0 ? (
                                            <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
                                                <MessageSquarePlus className="mb-2 h-8 w-8" />
                                                <p className="text-sm">No messages yet</p>
                                                <p className="text-xs">
                                                    Send a message to start the conversation.
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="space-y-4">
                                                {messages.map((msg) => (
                                                    <div
                                                        key={msg.id}
                                                        className={`flex ${
                                                            msg.direction === 'outbound'
                                                                ? 'justify-end'
                                                                : 'justify-start'
                                                        }`}
                                                    >
                                                        <div
                                                            className={`max-w-[70%] rounded-lg px-4 py-2 ${
                                                                msg.direction === 'outbound'
                                                                    ? 'bg-primary text-primary-foreground'
                                                                    : 'bg-muted'
                                                            }`}
                                                        >
                                                            <p className="text-sm whitespace-pre-wrap">
                                                                {msg.content}
                                                            </p>
                                                            <div
                                                                className={`mt-1 flex items-center gap-1.5 ${
                                                                    msg.direction === 'outbound'
                                                                        ? 'justify-end'
                                                                        : 'justify-start'
                                                                }`}
                                                            >
                                                                <span
                                                                    className={`text-[10px] ${
                                                                        msg.direction === 'outbound'
                                                                            ? 'text-primary-foreground/70'
                                                                            : 'text-muted-foreground'
                                                                    }`}
                                                                >
                                                                    {msg.sender_name}
                                                                </span>
                                                                <span
                                                                    className={`text-[10px] ${
                                                                        msg.direction === 'outbound'
                                                                            ? 'text-primary-foreground/70'
                                                                            : 'text-muted-foreground'
                                                                    }`}
                                                                >
                                                                    {formatMessageTime(msg.sent_at)}
                                                                </span>
                                                                {msg.direction === 'outbound' && (
                                                                    <span
                                                                        className="text-primary-foreground/70"
                                                                        title={
                                                                            msg.delivered_at
                                                                                ? 'Delivered'
                                                                                : 'Sent'
                                                                        }
                                                                    >
                                                                        {msg.delivered_at ? (
                                                                            <CheckCheck className="h-3 w-3" />
                                                                        ) : (
                                                                            <Check className="h-3 w-3" />
                                                                        )}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                ))}
                                                <div ref={messagesEndRef} />
                                            </div>
                                        )}
                                    </div>

                                    {/* Compose Bar */}
                                    {can.manage && (
                                        <div className="border-t p-4">
                                            <form
                                                onSubmit={handleSend}
                                                className="flex items-center gap-2"
                                            >
                                                <Input
                                                    value={composeText}
                                                    onChange={(e) =>
                                                        setComposeText(e.target.value)
                                                    }
                                                    placeholder="Type a message..."
                                                    className="flex-1"
                                                    maxLength={2000}
                                                    disabled={sending}
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={
                                                        !composeText.trim() || sending
                                                    }
                                                >
                                                    <Send className="mr-1 h-4 w-4" />
                                                    Send
                                                </Button>
                                            </form>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
                                    <User className="mb-3 h-12 w-12" />
                                    <p className="text-lg font-medium">Select a conversation</p>
                                    <p className="text-sm">
                                        Choose a conversation from the left panel or start a new one.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
