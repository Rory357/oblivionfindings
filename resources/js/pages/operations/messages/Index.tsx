import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    CheckCheck,
    FileText,
    Hash,
    MessageSquareText,
    Mic,
    MicOff,
    Pin,
    Plus,
    Search,
    Send,
    Smile,
    Star,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type ReactionGroup = {
    emoji: string;
    count: number;
    user_ids: number[];
    user_names?: string[];
};
type User = {
    id: number;
    name: string;
    email: string;
    presence_status: 'online' | 'offline' | 'busy' | 'away';
    last_seen_at: string | null;
};
type Message = {
    id: number;
    content: string;
    created_at: string;
    sender: { id: number; name: string } | null;
    sender_id?: number;
    sender_type?: string;
    message_type: string;
    attachments?: any[] | null;
    is_pinned?: boolean;
    is_read?: boolean;
    read_at?: string | null;
    is_deleted?: boolean;
    reactions?: ReactionGroup[];
};
type PinnedMessage = {
    id: number;
    content: string;
    sender_name?: string;
    created_at: string;
};
type Conversation = {
    id: number;
    title: string | null;
    conversation_type: string;
    client: { id: number; first_name: string; last_name: string } | null;
    latest_message: Message | null;
    unread_count: number;
    participants: Array<{ user: { id: number; name: string } }>;
};

type Props = {
    conversations: Conversation[];
    users: User[];
    currentUserId: number;
    conversation?: Conversation;
    messages?: { data: Message[] };
    pinnedMessages?: PinnedMessage[];
};

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function formatTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday)
        return d.toLocaleTimeString('en-NZ', {
            hour: '2-digit',
            minute: '2-digit',
        });
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function formatMessageTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

const PRESENCE_COLORS: Record<string, string> = {
    online: 'bg-status-success',
    away: 'bg-status-warning',
    busy: 'bg-status-critical',
    offline: 'bg-muted',
};

const PRESENCE_LABELS: Record<string, string> = {
    online: 'Online',
    away: 'Away',
    busy: 'Busy',
    offline: 'Offline',
};

function PresenceDot({
    status,
    size = 'sm',
}: {
    status: string;
    size?: 'sm' | 'md';
}) {
    const s = size === 'md' ? 'h-3 w-3' : 'h-2.5 w-2.5';
    return (
        <span
            className={`inline-block rounded-full border-2 border-background ${s} ${PRESENCE_COLORS[status] ?? PRESENCE_COLORS.offline}`}
            title={PRESENCE_LABELS[status] ?? 'Offline'}
        />
    );
}

function getConversationName(
    conv: Conversation,
    currentUserId: number,
): string {
    if (conv.title) return conv.title;
    if (conv.client)
        return `${conv.client.first_name} ${conv.client.last_name} Team`;
    if (conv.conversation_type === 'direct') {
        const other = conv.participants?.find(
            (p) => p.user?.id !== currentUserId,
        );
        return other?.user?.name ?? 'Direct Message';
    }
    return (
        conv.participants
            ?.map((p) => p.user?.name)
            .filter(Boolean)
            .join(', ') || 'Group Chat'
    );
}

const CHAT_REACTIONS = ['👍', '❤️', '😊', '✅', '🙏', '😢'];
const QUICK_REPLIES = [
    'Noted, will do! 👍',
    'Thank you for letting us know.',
    "We'll discuss this at the next handover.",
    'Everything is going well today! 😊',
    "I'll follow up on this shortly.",
    "Great idea, we'll make it happen.",
];

export default function MessagesChat({
    conversations = [],
    users = [],
    currentUserId = 0,
    conversation,
    messages,
    pinnedMessages: propPinned,
}: Props) {
    const { labels } = usePage().props as any;
    const [selectedId, setSelectedId] = useState<number | null>(
        conversation?.id ?? null,
    );
    const [searchQuery, setSearchQuery] = useState('');
    const [showNewChat, setShowNewChat] = useState(false);
    const [newChatSearch, setNewChatSearch] = useState('');
    const [messageText, setMessageText] = useState('');
    const [activeMessages, setActiveMessages] = useState<Message[]>(
        messages?.data ?? [],
    );
    const [activeConversation, setActiveConversation] =
        useState<Conversation | null>(conversation ?? null);
    const [showPinned, setShowPinned] = useState(false);
    const [pinnedMsgs, setPinnedMsgs] = useState<PinnedMessage[]>(
        propPinned ?? [],
    );
    const [showMsgSearch, setShowMsgSearch] = useState(false);
    const [msgSearchQuery, setMsgSearchQuery] = useState('');
    const [msgSearchResults, setMsgSearchResults] = useState<any[]>([]);
    const [isRecording, setIsRecording] = useState(false);
    const [ctxMenu, setCtxMenu] = useState<{
        x: number;
        y: number;
        messageId?: number;
        isOwn?: boolean;
        content?: string;
        senderName?: string;
    } | null>(null);
    const [replyingTo, setReplyingTo] = useState<{
        id: number;
        senderName: string;
        content: string;
    } | null>(null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const mediaRecorderRef = useRef<MediaRecorder | null>(null);
    const audioChunksRef = useRef<Blob[]>([]);

    // Scroll to bottom when messages change
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [activeMessages]);

    // Filter conversations
    const filteredConversations = conversations.filter((c) => {
        if (!searchQuery) return true;
        const name = getConversationName(c, currentUserId).toLowerCase();
        return name.includes(searchQuery.toLowerCase());
    });

    // Filter users for new chat
    const filteredUsers = users.filter((u) => {
        if (!newChatSearch) return true;
        return (
            u.name.toLowerCase().includes(newChatSearch.toLowerCase()) ||
            u.email.toLowerCase().includes(newChatSearch.toLowerCase())
        );
    });

    const selectConversation = useCallback((conv: Conversation) => {
        setSelectedId(conv.id);
        setActiveConversation(conv);
        setShowNewChat(false);
        // Fetch messages
        router.get(
            `/operations/messages/${conv.id}`,
            {},
            {
                preserveState: false,
                only: ['conversation', 'messages'],
            },
        );
    }, []);

    const sendMessage = useCallback(() => {
        if (!messageText.trim() || !selectedId) return;
        const content = replyingTo
            ? `> ${replyingTo.senderName}: ${replyingTo.content}\n\n${messageText}`
            : messageText;
        router.post(
            `/operations/messages/${selectedId}`,
            { content },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMessageText('');
                    setReplyingTo(null);
                    inputRef.current?.focus();
                },
            },
        );
    }, [messageText, selectedId, replyingTo]);

    const startNewChat = useCallback((userId: number) => {
        router.post(
            '/operations/messages/create',
            { participant_ids: [userId] },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowNewChat(false);
                    setNewChatSearch('');
                },
            },
        );
    }, []);

    // Update messages when props change
    useEffect(() => {
        if (messages?.data) setActiveMessages(messages.data);
        if (conversation) {
            setActiveConversation(conversation);
            setSelectedId(conversation.id);
        }
        if (propPinned) setPinnedMsgs(propPinned);
    }, [messages, conversation, propPinned]);

    // Close context menu on mousedown outside
    useEffect(() => {
        const close = (e: MouseEvent) => {
            if ((e.target as HTMLElement).closest('[data-ctx-menu]')) return;
            setCtxMenu(null);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    const toggleReaction = useCallback((msgId: number, emoji: string) => {
        router.post(
            `/operations/messages/react/${msgId}`,
            { emoji },
            { preserveScroll: true, preserveState: true },
        );
    }, []);

    const togglePin = useCallback((msgId: number) => {
        router.post(
            `/operations/messages/pin/${msgId}`,
            {},
            { preserveScroll: true },
        );
    }, []);

    const doMsgSearch = useCallback(async (q: string) => {
        if (q.length < 2) {
            setMsgSearchResults([]);
            return;
        }
        try {
            const res = await fetch(
                `/operations/messages-search?q=${encodeURIComponent(q)}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );
            if (res.ok) setMsgSearchResults(await res.json());
        } catch {
            /* */
        }
    }, []);

    const startRecording = useCallback(async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const recorder = new MediaRecorder(stream);
            audioChunksRef.current = [];
            recorder.ondataavailable = (e) => {
                if (e.data.size > 0) audioChunksRef.current.push(e.data);
            };
            recorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const blob = new Blob(audioChunksRef.current, {
                    type: 'audio/webm',
                });
                if (!selectedId) return;
                router.post(
                    `/operations/messages/${selectedId}`,
                    { content: '🎙️ Voice note', message_type: 'text' },
                    { preserveScroll: true },
                );
            };
            recorder.start();
            mediaRecorderRef.current = recorder;
            setIsRecording(true);
            setTimeout(() => {
                if (mediaRecorderRef.current?.state === 'recording') {
                    mediaRecorderRef.current.stop();
                    setIsRecording(false);
                }
            }, 60000);
        } catch {
            toast.error('Microphone access denied');
        }
    }, [selectedId]);

    const stopRecording = useCallback(() => {
        if (mediaRecorderRef.current?.state === 'recording') {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
        }
    }, []);

    const handleMsgRightClick = useCallback(
        (e: React.MouseEvent, msg: Message) => {
            e.preventDefault();
            e.stopPropagation();
            const senderId = msg.sender_id ?? msg.sender?.id;
            setCtxMenu({
                x: e.clientX,
                y: e.clientY,
                messageId: msg.id,
                isOwn: senderId === currentUserId,
                content: msg.content,
                senderName: msg.sender?.name,
            });
        },
        [currentUserId],
    );

    return (
        <AppLayout>
            <Head title="Messages" />
            <div className="flex h-[calc(100vh-4rem)] overflow-hidden">
                {/* Left Panel - Conversation List */}
                <div className="flex w-80 flex-col border-r bg-background">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <div>
                            <h1 className="text-lg font-semibold">Messages</h1>
                            <p className="text-xs text-muted-foreground">
                                Secure team conversations and client
                                coordination.
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-8 w-8 p-0"
                            onClick={() => {
                                setShowNewChat(true);
                                setSelectedId(null);
                            }}
                        >
                            <Plus className="h-4 w-4" />
                        </Button>
                    </div>

                    {/* Search */}
                    <div className="border-b px-3 py-2">
                        <div className="relative">
                            <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                            <Input
                                placeholder="Search conversations..."
                                className="h-9 pl-8 text-sm"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                    </div>

                    {/* Conversation List */}
                    <div className="flex-1 overflow-y-auto">
                        {filteredConversations.length === 0 && (
                            <div className="flex flex-col items-center justify-center px-4 py-12 text-center">
                                <MessageSquareText className="mb-2 h-8 w-8 text-muted-foreground/30" />
                                <p className="text-sm text-muted-foreground">
                                    No conversations yet
                                </p>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="mt-2"
                                    onClick={() => setShowNewChat(true)}
                                >
                                    Start a chat
                                </Button>
                            </div>
                        )}
                        {filteredConversations.map((conv) => {
                            const name = getConversationName(
                                conv,
                                currentUserId,
                            );
                            const isSelected = selectedId === conv.id;
                            return (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    key={conv.id}
                                    className={`h-auto w-full justify-start gap-3 rounded-none px-4 py-3 text-left ${isSelected ? 'bg-accent' : ''}`}
                                    onClick={() => selectConversation(conv)}
                                >
                                    <div className="relative shrink-0">
                                        <Avatar className="h-9 w-9">
                                            <AvatarFallback
                                                className={`text-xs ${conv.conversation_type === 'group' || conv.conversation_type === 'client_team' ? 'bg-primary/10 text-primary' : 'bg-muted text-foreground'}`}
                                            >
                                                {conv.conversation_type ===
                                                    'group' ||
                                                conv.conversation_type ===
                                                    'client_team' ? (
                                                    <Users className="h-4 w-4" />
                                                ) : (
                                                    getInitials(name)
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        {conv.conversation_type === 'direct' &&
                                            (() => {
                                                const otherId =
                                                    conv.participants?.find(
                                                        (p) =>
                                                            p.user?.id !==
                                                            currentUserId,
                                                    )?.user?.id;
                                                const otherUser = users.find(
                                                    (u) => u.id === otherId,
                                                );
                                                return otherUser ? (
                                                    <div className="absolute -right-0.5 -bottom-0.5">
                                                        <PresenceDot
                                                            status={
                                                                otherUser.presence_status
                                                            }
                                                        />
                                                    </div>
                                                ) : null;
                                            })()}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center justify-between">
                                            <span
                                                className={`truncate text-sm ${conv.unread_count > 0 ? 'font-bold' : 'font-medium'}`}
                                            >
                                                {name}
                                            </span>
                                            {conv.latest_message && (
                                                <span className="ml-2 shrink-0 text-[10px] text-muted-foreground">
                                                    {formatTime(
                                                        conv.latest_message
                                                            .created_at,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <p
                                                className={`truncate text-xs ${conv.unread_count > 0 ? 'font-medium text-foreground' : 'text-muted-foreground'}`}
                                            >
                                                {conv.latest_message
                                                    ? `${conv.latest_message.sender?.name?.split(' ')[0] ?? ''}: ${conv.latest_message.content}`
                                                    : 'No messages yet'}
                                            </p>
                                            {conv.unread_count > 0 && (
                                                <Badge className="ml-1 h-5 min-w-[20px] shrink-0 px-1.5 text-[10px]">
                                                    {conv.unread_count}
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </Button>
                            );
                        })}
                    </div>
                </div>

                {/* Right Panel - Chat Area */}
                <div className="flex flex-1 flex-col bg-background">
                    {showNewChat ? (
                        /* New Chat Panel */
                        <div className="flex flex-1 flex-col">
                            <div className="flex items-center gap-3 border-b px-4 py-3">
                                <h2 className="text-sm font-semibold">
                                    New Conversation
                                </h2>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto h-7 w-7 p-0"
                                    onClick={() => setShowNewChat(false)}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                            <div className="border-b px-4 py-2">
                                <div className="relative">
                                    <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                    <Input
                                        placeholder="Search people..."
                                        className="h-9 pl-8 text-sm"
                                        value={newChatSearch}
                                        onChange={(e) =>
                                            setNewChatSearch(e.target.value)
                                        }
                                        autoFocus
                                    />
                                </div>
                            </div>
                            <div className="flex-1 overflow-y-auto">
                                {filteredUsers.map((user) => (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        key={user.id}
                                        className="h-auto w-full justify-start gap-3 rounded-none px-4 py-3 text-left"
                                        onClick={() => startNewChat(user.id)}
                                    >
                                        <div className="relative">
                                            <Avatar className="h-9 w-9">
                                                <AvatarFallback className="bg-muted text-xs text-foreground">
                                                    {getInitials(user.name)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="absolute -right-0.5 -bottom-0.5">
                                                <PresenceDot
                                                    status={
                                                        user.presence_status
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {user.name}
                                                </span>
                                                <span
                                                    className={`text-[10px] ${user.presence_status === 'online' ? 'text-status-success' : user.presence_status === 'busy' ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                >
                                                    {PRESENCE_LABELS[
                                                        user.presence_status
                                                    ] ?? 'Offline'}
                                                </span>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {user.email}
                                            </div>
                                        </div>
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ) : selectedId && activeConversation ? (
                        /* Active Chat */
                        <>
                            {/* Chat Header */}
                            <div className="flex items-center gap-3 border-b px-4 py-3">
                                <div className="relative">
                                    <Avatar className="h-8 w-8">
                                        <AvatarFallback className="bg-primary/10 text-xs text-primary">
                                            {activeConversation.conversation_type ===
                                            'group' ? (
                                                <Hash className="h-4 w-4" />
                                            ) : (
                                                getInitials(
                                                    getConversationName(
                                                        activeConversation,
                                                        currentUserId,
                                                    ),
                                                )
                                            )}
                                        </AvatarFallback>
                                    </Avatar>
                                    {activeConversation.conversation_type ===
                                        'direct' &&
                                        (() => {
                                            const otherId =
                                                activeConversation.participants?.find(
                                                    (p) =>
                                                        p.user?.id !==
                                                        currentUserId,
                                                )?.user?.id;
                                            const otherUser = users.find(
                                                (u) => u.id === otherId,
                                            );
                                            return otherUser ? (
                                                <div className="absolute -right-0.5 -bottom-0.5">
                                                    <PresenceDot
                                                        status={
                                                            otherUser.presence_status
                                                        }
                                                        size="md"
                                                    />
                                                </div>
                                            ) : null;
                                        })()}
                                </div>
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        {getConversationName(
                                            activeConversation,
                                            currentUserId,
                                        )}
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        {activeConversation.conversation_type ===
                                        'direct'
                                            ? (() => {
                                                  const otherId =
                                                      activeConversation.participants?.find(
                                                          (p) =>
                                                              p.user?.id !==
                                                              currentUserId,
                                                      )?.user?.id;
                                                  const otherUser = users.find(
                                                      (u) => u.id === otherId,
                                                  );
                                                  return (
                                                      PRESENCE_LABELS[
                                                          otherUser?.presence_status ??
                                                              'offline'
                                                      ] ?? 'Offline'
                                                  );
                                              })()
                                            : `${activeConversation.participants?.length ?? 0} members`}
                                    </p>
                                </div>
                                {pinnedMsgs.length > 0 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="xs"
                                        onClick={() =>
                                            setShowPinned(!showPinned)
                                        }
                                        className="ml-auto h-auto rounded-full px-2.5 py-1 text-[10px] font-medium text-muted-foreground"
                                    >
                                        <Pin className="h-3 w-3" />
                                        {pinnedMsgs.length} pinned
                                    </Button>
                                )}
                            </div>

                            {/* Pinned Messages */}
                            {showPinned && pinnedMsgs.length > 0 && (
                                <div className="border-b bg-status-warning-bg px-4 py-2">
                                    <div className="mb-1 flex items-center justify-between">
                                        <span className="text-[10px] font-semibold tracking-wider text-status-warning uppercase">
                                            <Pin className="mr-1 inline h-3 w-3" />
                                            Pinned
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => setShowPinned(false)}
                                            className="h-6 w-6 text-muted-foreground"
                                        >
                                            <X className="h-3 w-3" />
                                        </Button>
                                    </div>
                                    {pinnedMsgs.map((pm) => (
                                        <div
                                            key={pm.id}
                                            className="mb-1 rounded-lg bg-white/60 p-2 text-xs dark:bg-card"
                                        >
                                            <p className="font-medium">
                                                {pm.content.slice(0, 100)}
                                            </p>
                                            <p className="mt-0.5 text-[10px] text-muted-foreground">
                                                {pm.sender_name} ·{' '}
                                                {formatTime(pm.created_at)}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Messages Area */}
                            <div className="flex-1 overflow-y-auto px-4 py-4">
                                {activeMessages.length === 0 && (
                                    <div className="flex h-full flex-col items-center justify-center text-center">
                                        <MessageSquareText className="mb-3 h-12 w-12 text-muted-foreground/20" />
                                        <p className="text-sm text-muted-foreground">
                                            No messages yet. Say hello!
                                        </p>
                                    </div>
                                )}
                                {[...activeMessages]
                                    .reverse()
                                    .map((msg, idx, arr) => {
                                        const senderId =
                                            msg.sender_id ?? msg.sender?.id;
                                        const isMe = senderId === currentUserId;
                                        const prevMsg =
                                            idx > 0 ? arr[idx - 1] : null;
                                        const prevSenderId = prevMsg
                                            ? (prevMsg.sender_id ??
                                              prevMsg.sender?.id)
                                            : null;
                                        const showAvatar =
                                            !prevMsg ||
                                            prevSenderId !== senderId;
                                        const isLastByMe =
                                            isMe &&
                                            (idx === arr.length - 1 ||
                                                (arr[idx + 1]?.sender_id ??
                                                    arr[idx + 1]?.sender
                                                        ?.id) !== senderId);
                                        return (
                                            <div
                                                key={msg.id}
                                                className={`group flex gap-2 ${isMe ? 'flex-row-reverse' : ''} ${showAvatar ? 'mt-4' : 'mt-0.5'}`}
                                                onContextMenu={(e) =>
                                                    handleMsgRightClick(e, msg)
                                                }
                                            >
                                                {!isMe && showAvatar ? (
                                                    <Avatar className="mt-1 h-7 w-7 shrink-0">
                                                        <AvatarFallback className="bg-muted text-[10px] text-muted-foreground">
                                                            {getInitials(
                                                                msg.sender
                                                                    ?.name ??
                                                                    '?',
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                ) : !isMe ? (
                                                    <div className="w-7 shrink-0" />
                                                ) : null}
                                                <div className={`max-w-[70%]`}>
                                                    {showAvatar && !isMe && (
                                                        <p className="mb-0.5 text-[10px] font-medium text-muted-foreground">
                                                            {msg.sender?.name}
                                                        </p>
                                                    )}
                                                    {msg.is_deleted ? (
                                                        <div
                                                            className={`inline-flex items-center gap-1.5 rounded-2xl px-3 py-2 text-sm italic ${isMe ? 'bg-primary/30 text-white/60' : 'bg-muted/60 text-muted-foreground'}`}
                                                        >
                                                            <Trash2 className="h-3 w-3" />
                                                            <span>
                                                                This message was
                                                                deleted
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        (() => {
                                                            const hasQuote =
                                                                msg.content.startsWith(
                                                                    '> ',
                                                                );
                                                            const parts =
                                                                hasQuote
                                                                    ? msg.content.split(
                                                                          '\n\n',
                                                                      )
                                                                    : null;
                                                            const quoteLine =
                                                                parts
                                                                    ? parts[0].replace(
                                                                          /^> /,
                                                                          '',
                                                                      )
                                                                    : null;
                                                            const mainText =
                                                                parts
                                                                    ? parts
                                                                          .slice(
                                                                              1,
                                                                          )
                                                                          .join(
                                                                              '\n\n',
                                                                          )
                                                                    : msg.content;
                                                            return (
                                                                <div
                                                                    className={`inline-block rounded-2xl px-3 py-2 text-sm ${isMe ? 'bg-primary text-white' : 'bg-muted'} ${msg.is_pinned ? 'ring-2 ring-status-warning' : ''}`}
                                                                >
                                                                    {msg.is_pinned && (
                                                                        <Pin className="mr-1 inline h-3 w-3 opacity-60" />
                                                                    )}
                                                                    {quoteLine && (
                                                                        <div
                                                                            className={`mb-1.5 rounded-lg border-l-2 px-2 py-1 text-xs ${isMe ? 'border-l-white/40 bg-white/10' : 'border-l-indigo-400 bg-primary/10'}`}
                                                                        >
                                                                            <p className="truncate opacity-70">
                                                                                {
                                                                                    quoteLine
                                                                                }
                                                                            </p>
                                                                        </div>
                                                                    )}
                                                                    {mainText}
                                                                </div>
                                                            );
                                                        })()
                                                    )}
                                                    {/* Reactions - only for non-deleted */}
                                                    {msg.reactions &&
                                                        msg.reactions.length >
                                                            0 && (
                                                            <div className="mt-0.5 flex flex-wrap gap-1">
                                                                {msg.reactions.map(
                                                                    (r) => (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            key={
                                                                                r.emoji
                                                                            }
                                                                            onClick={() =>
                                                                                toggleReaction(
                                                                                    msg.id,
                                                                                    r.emoji,
                                                                                )
                                                                            }
                                                                            title={
                                                                                r
                                                                                    .user_names
                                                                                    ?.length
                                                                                    ? `Reacted by: ${r.user_names.join(', ')}`
                                                                                    : undefined
                                                                            }
                                                                            className={`h-auto gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] ${r.user_ids.includes(currentUserId) ? 'border-primary/50 bg-primary/5' : 'border-border'}`}
                                                                        >
                                                                            {
                                                                                r.emoji
                                                                            }{' '}
                                                                            <span className="font-medium">
                                                                                {
                                                                                    r.count
                                                                                }
                                                                            </span>
                                                                        </Button>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                    {/* Time + read receipt */}
                                                    <div
                                                        className={`mt-0.5 flex items-center gap-1 text-[10px] text-muted-foreground/60 ${isMe ? 'justify-end' : ''}`}
                                                    >
                                                        <span>
                                                            {formatMessageTime(
                                                                msg.created_at,
                                                            )}
                                                        </span>
                                                        {isMe &&
                                                            isLastByMe &&
                                                            (msg.is_read ? (
                                                                <span
                                                                    title={`Read ${msg.read_at ? formatTime(msg.read_at) : ''}`}
                                                                >
                                                                    <CheckCheck className="h-3 w-3 text-status-info" />
                                                                </span>
                                                            ) : (
                                                                <span title="Sent">
                                                                    <Check className="h-3 w-3" />
                                                                </span>
                                                            ))}
                                                    </div>
                                                    {/* Hover actions */}
                                                    <div
                                                        className={`mt-0.5 gap-1 ${msg.is_pinned ? 'flex' : 'hidden group-hover:flex'} ${isMe ? 'justify-end' : ''}`}
                                                    >
                                                        <Popover>
                                                            <PopoverTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-6 w-6 rounded-full bg-muted"
                                                                >
                                                                    <Smile className="h-3 w-3" />
                                                                </Button>
                                                            </PopoverTrigger>
                                                            <PopoverContent
                                                                className="w-auto p-1.5"
                                                                align={
                                                                    isMe
                                                                        ? 'end'
                                                                        : 'start'
                                                                }
                                                            >
                                                                <div className="flex gap-1">
                                                                    {CHAT_REACTIONS.map(
                                                                        (e) => (
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                key={
                                                                                    e
                                                                                }
                                                                                onClick={() =>
                                                                                    toggleReaction(
                                                                                        msg.id,
                                                                                        e,
                                                                                    )
                                                                                }
                                                                                className="h-auto rounded-lg p-1.5 text-base"
                                                                            >
                                                                                {
                                                                                    e
                                                                                }
                                                                            </Button>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            </PopoverContent>
                                                        </Popover>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                togglePin(
                                                                    msg.id,
                                                                )
                                                            }
                                                            className={`h-6 w-6 rounded-full ${msg.is_pinned ? 'bg-status-warning-bg text-status-warning' : 'bg-muted'}`}
                                                        >
                                                            <Pin className="h-3 w-3" />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => {
                                                                setReplyingTo({
                                                                    id: msg.id,
                                                                    senderName:
                                                                        isMe
                                                                            ? 'You'
                                                                            : (msg
                                                                                  .sender
                                                                                  ?.name ??
                                                                              '?'),
                                                                    content:
                                                                        msg.content.slice(
                                                                            0,
                                                                            80,
                                                                        ),
                                                                });
                                                                inputRef.current?.focus();
                                                            }}
                                                            className="h-6 w-6 rounded-full bg-muted"
                                                            title="Reply"
                                                        >
                                                            <Send className="h-3 w-3 rotate-180" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                <div ref={messagesEndRef} />
                            </div>

                            {/* Reply preview */}
                            {replyingTo && (
                                <div className="bg-primary/10/50 flex items-center gap-2 border-t border-l-4 border-l-indigo-500 px-4 py-2 dark:bg-primary/10">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[10px] font-semibold text-primary">
                                            Replying to {replyingTo.senderName}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {replyingTo.content}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => setReplyingTo(null)}
                                        className="h-6 w-6 shrink-0 text-muted-foreground"
                                    >
                                        <X className="h-4 w-4" />
                                    </Button>
                                </div>
                            )}

                            {/* Message Input */}
                            <div className="border-t bg-card px-4 py-3">
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="mb-2 h-auto p-0 text-[10px] text-muted-foreground hover:bg-transparent hover:text-foreground"
                                        >
                                            💬 Quick replies
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        className="w-64 p-2"
                                        align="start"
                                    >
                                        <div className="space-y-1">
                                            {QUICK_REPLIES.map((r, i) => (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    key={i}
                                                    className="h-auto w-full justify-start rounded-lg p-2 text-left text-xs"
                                                    onClick={() => {
                                                        setMessageText(r);
                                                        inputRef.current?.focus();
                                                    }}
                                                >
                                                    {r}
                                                </Button>
                                            ))}
                                        </div>
                                    </PopoverContent>
                                </Popover>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={
                                            isRecording
                                                ? stopRecording
                                                : startRecording
                                        }
                                        className={`h-9 w-9 shrink-0 rounded-full ${isRecording ? 'animate-pulse bg-status-critical text-white' : 'text-muted-foreground hover:text-foreground'}`}
                                    >
                                        {isRecording ? (
                                            <MicOff className="h-4 w-4" />
                                        ) : (
                                            <Mic className="h-4 w-4" />
                                        )}
                                    </Button>
                                    <Input
                                        ref={inputRef}
                                        placeholder="Type a message..."
                                        className="flex-1 rounded-full"
                                        value={messageText}
                                        onChange={(e) =>
                                            setMessageText(e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (
                                                e.key === 'Enter' &&
                                                !e.shiftKey
                                            ) {
                                                e.preventDefault();
                                                sendMessage();
                                            }
                                        }}
                                        autoFocus
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        className="h-9 w-9 shrink-0 rounded-full"
                                        disabled={!messageText.trim()}
                                        onClick={sendMessage}
                                    >
                                        <Send className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </>
                    ) : (
                        /* No Chat Selected */
                        <div className="flex flex-1 flex-col items-center justify-center text-center">
                            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 dark:bg-primary/30">
                                <MessageSquareText className="h-8 w-8 text-primary dark:text-primary" />
                            </div>
                            <h2 className="mt-4 text-lg font-semibold">
                                Welcome to Chat
                            </h2>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                Select a conversation or start a new one to
                                begin messaging your team.
                            </p>
                            <Button
                                size="sm"
                                className="mt-4 gap-1.5"
                                onClick={() => setShowNewChat(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                New Conversation
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            {/* Context Menu — WhatsApp style */}
            {ctxMenu && (
                <div
                    data-ctx-menu
                    className="fixed z-50 w-[280px] overflow-hidden rounded-2xl border bg-card shadow-2xl"
                    style={{
                        top: Math.min(ctxMenu.y, window.innerHeight - 300),
                        left: Math.min(ctxMenu.x, window.innerWidth - 300),
                    }}
                    onClick={(e) => e.stopPropagation()}
                >
                    {ctxMenu.messageId ? (
                        <>
                            <div className="flex items-center justify-center gap-1 border-b px-3 py-2.5">
                                {CHAT_REACTIONS.map((e) => (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        key={e}
                                        onClick={() => {
                                            if (ctxMenu.messageId)
                                                toggleReaction(
                                                    ctxMenu.messageId,
                                                    e,
                                                );
                                            setCtxMenu(null);
                                        }}
                                        className="h-auto rounded-full p-1.5 text-xl transition-transform hover:scale-125"
                                    >
                                        {e}
                                    </Button>
                                ))}
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="h-auto rounded-full p-1.5 text-lg text-muted-foreground transition-transform hover:scale-125"
                                        >
                                            +
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        className="w-auto p-2"
                                        align="end"
                                    >
                                        <div className="grid grid-cols-6 gap-1">
                                            {[
                                                '😀',
                                                '😂',
                                                '🥺',
                                                '😍',
                                                '🤔',
                                                '👏',
                                                '🔥',
                                                '💯',
                                                '😱',
                                                '🎉',
                                                '💪',
                                                '🙌',
                                            ].map((e) => (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    key={e}
                                                    onClick={() => {
                                                        if (ctxMenu.messageId)
                                                            toggleReaction(
                                                                ctxMenu.messageId,
                                                                e,
                                                            );
                                                        setCtxMenu(null);
                                                    }}
                                                    className="h-auto rounded-lg p-1 text-xl"
                                                >
                                                    {e}
                                                </Button>
                                            ))}
                                        </div>
                                    </PopoverContent>
                                </Popover>
                            </div>
                            <div className="p-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm"
                                    onClick={() => {
                                        if (ctxMenu.messageId)
                                            setReplyingTo({
                                                id: ctxMenu.messageId,
                                                senderName: ctxMenu.isOwn
                                                    ? 'You'
                                                    : (ctxMenu.senderName ??
                                                      '?'),
                                                content: (
                                                    ctxMenu.content ?? ''
                                                ).slice(0, 80),
                                            });
                                        inputRef.current?.focus();
                                        setCtxMenu(null);
                                    }}
                                >
                                    <Send className="h-4 w-4 rotate-180 text-muted-foreground" />
                                    <span>Reply</span>
                                </Button>
                                {ctxMenu.content && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm"
                                        onClick={() => {
                                            navigator.clipboard
                                                .writeText(ctxMenu.content!)
                                                .then(() =>
                                                    toast.success('Copied!'),
                                                );
                                            setCtxMenu(null);
                                        }}
                                    >
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <span>Copy</span>
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm"
                                    onClick={() => {
                                        if (ctxMenu.messageId)
                                            togglePin(ctxMenu.messageId);
                                        setCtxMenu(null);
                                    }}
                                >
                                    <Pin className="h-4 w-4 text-muted-foreground" />
                                    <span>
                                        {activeMessages.find(
                                            (m) => m.id === ctxMenu.messageId,
                                        )?.is_pinned
                                            ? 'Unpin'
                                            : 'Pin'}
                                    </span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm"
                                    onClick={() => {
                                        if (ctxMenu.messageId)
                                            togglePin(ctxMenu.messageId);
                                        setCtxMenu(null);
                                    }}
                                >
                                    <Star className="h-4 w-4 text-status-warning" />
                                    <span>
                                        {activeMessages.find(
                                            (m) => m.id === ctxMenu.messageId,
                                        )?.is_pinned
                                            ? 'Unstar'
                                            : 'Star'}
                                    </span>
                                </Button>
                                {ctxMenu.isOwn && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm text-status-critical hover:bg-status-critical-bg dark:hover:bg-status-critical"
                                        onClick={() => {
                                            if (
                                                ctxMenu.messageId &&
                                                confirm(
                                                    'Delete this message? It will show as "deleted" but data is kept for auditing.',
                                                )
                                            ) {
                                                router.delete(
                                                    `/operations/messages/archive/${ctxMenu.messageId}`,
                                                    { preserveScroll: true },
                                                );
                                            }
                                            setCtxMenu(null);
                                        }}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                        <span>Delete</span>
                                    </Button>
                                )}
                            </div>
                        </>
                    ) : (
                        <div className="p-1">
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm"
                                onClick={() => {
                                    startRecording();
                                    setCtxMenu(null);
                                }}
                            >
                                <Mic className="h-4 w-4 text-status-critical" />
                                <span>Voice note</span>
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
