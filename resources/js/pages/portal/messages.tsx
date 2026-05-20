import { PageHero, PageLayout } from '@/components/page';
import { PresenceBadge, PresenceDot } from '@/components/presence-dot';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Camera,
    Check,
    CheckCheck,
    FileText,
    MessageSquare,
    MessageSquareText,
    Mic,
    MicOff,
    Paperclip,
    Pin,
    Plus,
    Search,
    Send,
    Smile,
    Star,
    Trash2,
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
type Attachment = {
    type: 'photo' | 'document';
    name: string;
    url?: string;
    thumbnail_url?: string;
    size: number;
    mime_type: string;
};
type Message = {
    id: number;
    content: string;
    sender_id: number;
    sender_type: string;
    message_type?: string;
    attachments?: Attachment[] | null;
    is_pinned?: boolean;
    is_read?: boolean;
    read_at?: string | null;
    is_deleted?: boolean;
    shift_id?: number | null;
    reactions?: ReactionGroup[];
    sender?: { id: number; name: string } | null;
    created_at: string;
};
type PinnedMessage = {
    id: number;
    content: string;
    sender_name?: string;
    created_at: string;
};
type Participant = { id: number; name: string; presence?: string };
type Conversation = {
    id: number;
    title?: string | null;
    latest_message?: { content: string; created_at: string } | null;
    participants: Participant[];
    updated_at: string;
};
type Worker = { id: number; name: string; presence?: string };

type Props = {
    client: { id: number; first_name: string; last_name: string };
    conversations: Conversation[];
    supportWorkers: Worker[];
    currentUserId: number;
    activeConversation?: {
        id: number;
        title?: string | null;
        participants: Participant[];
    } | null;
    activeMessages?: Message[];
    pinnedMessages?: PinnedMessage[];
};

const QUICK_REPLIES = [
    'Noted, will do! 👍',
    'Thank you for letting us know.',
    "We'll discuss this at the next handover.",
    'Everything is going well today! 😊',
    "I'll follow up on this shortly.",
    "Great idea, we'll make it happen.",
];

const CHAT_REACTIONS = ['👍', '❤️', '😊', '✅', '🙏', '😢'];

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
    if (d.toDateString() === now.toDateString())
        return d.toLocaleTimeString('en-NZ', {
            hour: '2-digit',
            minute: '2-digit',
        });
    const y = new Date(now);
    y.setDate(y.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}
function formatMessageTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function PortalMessages({
    client,
    conversations,
    supportWorkers,
    currentUserId,
    activeConversation: propConvo,
    activeMessages: propMsgs,
    pinnedMessages: propPinned,
}: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const [selectedId, setSelectedId] = useState<number | null>(
        propConvo?.id ?? null,
    );
    const [showNewChat, setShowNewChat] = useState(false);
    const [messageText, setMessageText] = useState('');
    const [showUploadDialog, setShowUploadDialog] = useState(false);
    const [uploadType, setUploadType] = useState<'photo' | 'document'>('photo');
    const [uploadFile, setUploadFile] = useState<File | null>(null);
    const [uploadCaption, setUploadCaption] = useState('');
    const [isDragging, setIsDragging] = useState(false);
    const [showSearch, setShowSearch] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState<any[]>([]);
    const [isRecording, setIsRecording] = useState(false);
    const [showPinned, setShowPinned] = useState(false);
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
    const dragCounter = useRef(0);
    const [activeMessages, setActiveMessages] = useState<Message[]>(
        propMsgs ?? [],
    );
    const [activeConvo, setActiveConvo] = useState<typeof propConvo>(
        propConvo ?? null,
    );
    const [pinnedMsgs, setPinnedMsgs] = useState<PinnedMessage[]>(
        propPinned ?? [],
    );
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const mediaRecorderRef = useRef<MediaRecorder | null>(null);
    const audioChunksRef = useRef<Blob[]>([]);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [activeMessages]);
    useEffect(() => {
        const close = (e: MouseEvent) => {
            const target = e.target as HTMLElement;
            if (target.closest('[data-ctx-menu]')) return;
            setCtxMenu(null);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);
    useEffect(() => {
        if (propMsgs) setActiveMessages(propMsgs);
        if (propConvo) {
            setActiveConvo(propConvo);
            setSelectedId(propConvo.id);
        }
        if (propPinned) setPinnedMsgs(propPinned);
    }, [propMsgs, propConvo, propPinned]);

    const getConvoName = (conv: {
        title?: string | null;
        participants: Participant[];
    }) => {
        if (conv.title) return conv.title;
        const other = conv.participants.find((p) => p.id !== currentUserId);
        return other?.name ?? 'Conversation';
    };

    const selectConversation = useCallback(
        (conv: Conversation) => {
            setSelectedId(conv.id);
            setShowNewChat(false);
            setShowSearch(false);
            router.get(
                `/portal/clients/${client.id}/messages/${conv.id}`,
                {},
                { preserveState: false },
            );
        },
        [client.id],
    );

    const sendMessage = useCallback(() => {
        if (!messageText.trim() || !selectedId) return;
        const content = replyingTo
            ? `> ${replyingTo.senderName}: ${replyingTo.content}\n\n${messageText}`
            : messageText;
        router.post(
            `/portal/clients/${client.id}/messages/${selectedId}`,
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
    }, [messageText, selectedId, client.id, replyingTo]);

    const startNewChat = useCallback(
        (workerId: number) => {
            router.post(
                `/portal/clients/${client.id}/messages/start`,
                { worker_id: workerId, content: 'Hello! 👋' },
                {
                    preserveScroll: true,
                    onSuccess: () => setShowNewChat(false),
                },
            );
        },
        [client.id],
    );

    const submitUpload = useCallback(() => {
        if (!uploadFile || !selectedId) return;
        const formData = new FormData();
        formData.append('attachment', uploadFile);
        if (uploadCaption) formData.append('content', uploadCaption);
        router.post(
            `/portal/clients/${client.id}/messages/${selectedId}`,
            formData,
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setShowUploadDialog(false);
                    setUploadFile(null);
                    setUploadCaption('');
                },
            },
        );
    }, [uploadFile, uploadCaption, selectedId, client.id]);

    const toggleReaction = useCallback(
        (messageId: number, emoji: string) => {
            router.post(
                `/portal/clients/${client.id}/messages/react/${messageId}`,
                { emoji },
                { preserveScroll: true, preserveState: true },
            );
        },
        [client.id],
    );

    const togglePin = useCallback(
        (messageId: number) => {
            router.post(
                `/portal/clients/${client.id}/messages/pin/${messageId}`,
                {},
                { preserveScroll: true },
            );
        },
        [client.id],
    );

    const doSearch = useCallback(
        async (q: string) => {
            if (q.length < 2) {
                setSearchResults([]);
                return;
            }
            try {
                const res = await fetch(
                    `/portal/clients/${client.id}/messages-search?q=${encodeURIComponent(q)}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                if (res.ok) setSearchResults(await res.json());
            } catch {
                /* ignore */
            }
        },
        [client.id],
    );

    // Voice note recording
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
                const file = new File([blob], `voice-note-${Date.now()}.webm`, {
                    type: 'audio/webm',
                });
                if (!selectedId) return;
                const formData = new FormData();
                formData.append('attachment', file);
                formData.append('content', '🎙️ Voice note');
                router.post(
                    `/portal/clients/${client.id}/messages/${selectedId}`,
                    formData,
                    { preserveScroll: true, forceFormData: true },
                );
            };
            recorder.start();
            mediaRecorderRef.current = recorder;
            setIsRecording(true);
            // Auto-stop after 60 seconds
            setTimeout(() => {
                if (mediaRecorderRef.current?.state === 'recording') {
                    mediaRecorderRef.current.stop();
                    setIsRecording(false);
                }
            }, 60000);
        } catch {
            toast.error('Microphone access denied');
        }
    }, [selectedId, client.id]);

    const stopRecording = useCallback(() => {
        if (mediaRecorderRef.current?.state === 'recording') {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
        }
    }, []);

    // Drag handlers
    const handleDragEnter = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        dragCounter.current++;
        if (e.dataTransfer.types.includes('Files')) setIsDragging(true);
    }, []);
    const handleDragLeave = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        dragCounter.current--;
        if (dragCounter.current === 0) setIsDragging(false);
    }, []);
    const handleDragOver = useCallback((e: React.DragEvent) => {
        e.preventDefault();
    }, []);
    const handleDrop = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        dragCounter.current = 0;
        const file = e.dataTransfer.files?.[0];
        if (!file) return;
        setUploadType(file.type.startsWith('image/') ? 'photo' : 'document');
        setUploadFile(file);
        setUploadCaption('');
        setShowUploadDialog(true);
    }, []);

    const handleMessageRightClick = useCallback(
        (e: React.MouseEvent, msg: Message) => {
            e.preventDefault();
            e.stopPropagation();
            setCtxMenu({
                x: e.clientX,
                y: e.clientY,
                messageId: msg.id,
                isOwn: msg.sender_id === currentUserId,
                content: msg.content,
                senderName: msg.sender?.name,
            });
        },
        [currentUserId],
    );

    const handleEmptyRightClick = useCallback((e: React.MouseEvent) => {
        const target = e.target as HTMLElement;
        if (target.closest('[data-msg-id]')) return; // handled by message handler
        e.preventDefault();
        setCtxMenu({ x: e.clientX, y: e.clientY });
    }, []);

    const copyToClipboard = useCallback((text: string) => {
        navigator.clipboard
            .writeText(text)
            .then(() => toast.success('Copied!'))
            .catch(() => {});
        setCtxMenu(null);
    }, []);

    const replyTo = useCallback(
        (msgId: number, senderName: string, content: string) => {
            setReplyingTo({
                id: msgId,
                senderName,
                content: content.slice(0, 80),
            });
            inputRef.current?.focus();
            setCtxMenu(null);
        },
        [],
    );

    const hasConversations = conversations.length > 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Messages',
                    href: `/portal/clients/${client.id}/messages`,
                },
            ]}
        >
            <Head title={`Messages - ${clientName}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={MessageSquare}
                        title="Messages"
                        description={`Chat with ${clientName}'s care team.`}
                        stats={[
                            { label: 'Conversations', value: conversations.length },
                        ]}
                    />
                }
            >
            <div className="flex h-[calc(100vh-22rem)] overflow-hidden">
                {/* Left Panel */}
                <div className="flex w-72 flex-col border-r bg-card">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <h2 className="text-base font-semibold">Messages</h2>
                        <div className="flex items-center gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => {
                                    setShowSearch(!showSearch);
                                    setShowNewChat(false);
                                }}
                                className="h-8 w-8 rounded-full"
                                title="Search messages"
                            >
                                <Search className="h-4 w-4 text-muted-foreground" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                onClick={() => {
                                    setShowNewChat(!showNewChat);
                                    setSelectedId(null);
                                    setActiveConvo(null);
                                    setShowSearch(false);
                                }}
                                className="h-8 w-8 rounded-full"
                                title="New message"
                            >
                                <Plus className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>

                    {/* Search Panel */}
                    {showSearch && (
                        <div className="space-y-2 border-b p-3">
                            <Input
                                placeholder="Search messages..."
                                className="h-8 text-xs"
                                autoFocus
                                value={searchQuery}
                                onChange={(e) => {
                                    setSearchQuery(e.target.value);
                                    doSearch(e.target.value);
                                }}
                            />
                            {searchResults.length > 0 && (
                                <div className="max-h-48 space-y-1 overflow-y-auto">
                                    {searchResults.map((r: any) => (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            key={r.id}
                                            className="h-auto w-full justify-start rounded-lg p-2 text-left text-xs"
                                            onClick={() => {
                                                setShowSearch(false);
                                                router.get(
                                                    `/portal/clients/${client.id}/messages/${r.conversation_id}`,
                                                );
                                            }}
                                        >
                                            <p className="truncate font-medium">
                                                {r.content.slice(0, 60)}
                                            </p>
                                            <p className="text-[10px] text-muted-foreground">
                                                {r.sender_name} ·{' '}
                                                {formatTime(r.created_at)}
                                            </p>
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex-1 overflow-y-auto">
                        {(!hasConversations || showNewChat) && (
                            <div className="border-b p-3">
                                <p className="mb-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    Care Team
                                </p>
                                <div className="space-y-1">
                                    {supportWorkers.map((worker) => (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            key={worker.id}
                                            className="h-auto w-full justify-start gap-2.5 rounded-lg px-2 py-2 text-left"
                                            onClick={() =>
                                                startNewChat(worker.id)
                                            }
                                        >
                                            <div className="relative shrink-0">
                                                <Avatar className="h-8 w-8">
                                                    <AvatarFallback className="bg-muted text-[10px] text-foreground">
                                                        {getInitials(
                                                            worker.name,
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <span className="absolute -right-0.5 -bottom-0.5">
                                                    <PresenceDot
                                                        status={
                                                            worker.presence ??
                                                            'offline'
                                                        }
                                                    />
                                                </span>
                                            </div>
                                            <div className="min-w-0">
                                                <p className="truncate text-xs font-medium">
                                                    {worker.name}
                                                </p>
                                                <PresenceBadge
                                                    status={
                                                        worker.presence ??
                                                        'offline'
                                                    }
                                                />
                                            </div>
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}
                        {hasConversations &&
                            conversations.map((conv) => {
                                const name = getConvoName(conv);
                                const isSelected = selectedId === conv.id;
                                const other = conv.participants.find(
                                    (p) => p.id !== currentUserId,
                                );
                                return (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        key={conv.id}
                                        className={`h-auto w-full justify-start gap-3 rounded-none px-3 py-2.5 text-left ${isSelected ? 'bg-accent' : ''}`}
                                        onClick={() => selectConversation(conv)}
                                    >
                                        <div className="relative shrink-0">
                                            <Avatar className="h-9 w-9">
                                                <AvatarFallback className="bg-primary/10 text-xs text-primary">
                                                    {getInitials(name)}
                                                </AvatarFallback>
                                            </Avatar>
                                            {other?.presence && (
                                                <span className="absolute -right-0.5 -bottom-0.5">
                                                    <PresenceDot
                                                        status={other.presence}
                                                    />
                                                </span>
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between">
                                                <span className="truncate text-sm font-medium">
                                                    {name}
                                                </span>
                                                {conv.latest_message && (
                                                    <span className="ml-1 shrink-0 text-[10px] text-muted-foreground">
                                                        {formatTime(
                                                            conv.latest_message
                                                                .created_at,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="truncate text-[11px] text-muted-foreground">
                                                {conv.latest_message?.content ??
                                                    'No messages'}
                                            </p>
                                        </div>
                                    </Button>
                                );
                            })}
                        {!hasConversations && !showNewChat && (
                            <div className="flex flex-col items-center justify-center px-4 py-8 text-center">
                                <MessageSquareText className="mb-2 h-8 w-8 text-muted-foreground/20" />
                                <p className="text-xs text-muted-foreground">
                                    No conversations yet
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Right Panel */}
                <div
                    className="relative flex flex-1 flex-col bg-background"
                    onDragEnter={handleDragEnter}
                    onDragLeave={handleDragLeave}
                    onDragOver={handleDragOver}
                    onDrop={handleDrop}
                >
                    {isDragging && selectedId && (
                        <div className="absolute inset-0 z-50 flex items-center justify-center rounded-lg border-2 border-dashed border-primary bg-primary/10 backdrop-blur-sm">
                            <div className="text-center">
                                <Paperclip className="mx-auto mb-2 h-10 w-10 text-primary" />
                                <p className="text-lg font-semibold text-primary">
                                    Drop file here
                                </p>
                            </div>
                        </div>
                    )}

                    {selectedId && activeConvo ? (
                        <>
                            {/* Header */}
                            <div className="flex items-center justify-between border-b px-4 py-3">
                                <div className="flex items-center gap-3">
                                    <div className="relative">
                                        <Avatar className="h-9 w-9">
                                            <AvatarFallback className="bg-primary/10 text-xs text-primary">
                                                {getInitials(
                                                    getConvoName(activeConvo),
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        {(() => {
                                            const o =
                                                activeConvo.participants.find(
                                                    (p) =>
                                                        p.id !== currentUserId,
                                                );
                                            return o?.presence ? (
                                                <span className="absolute -right-0.5 -bottom-0.5">
                                                    <PresenceDot
                                                        status={o.presence}
                                                        size="md"
                                                    />
                                                </span>
                                            ) : null;
                                        })()}
                                    </div>
                                    <div>
                                        <h2 className="text-sm font-semibold">
                                            {getConvoName(activeConvo)}
                                        </h2>
                                        {(() => {
                                            const o =
                                                activeConvo.participants.find(
                                                    (p) =>
                                                        p.id !== currentUserId,
                                                );
                                            return o?.presence ? (
                                                <PresenceBadge
                                                    status={o.presence}
                                                />
                                            ) : null;
                                        })()}
                                    </div>
                                </div>
                                {pinnedMsgs.length > 0 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="xs"
                                        onClick={() =>
                                            setShowPinned(!showPinned)
                                        }
                                        className="h-auto rounded-full px-2.5 py-1 text-[10px] font-medium text-muted-foreground"
                                    >
                                        <Pin className="h-3 w-3" />
                                        {pinnedMsgs.length} pinned
                                    </Button>
                                )}
                            </div>

                            {/* Pinned Messages Banner */}
                            {showPinned && pinnedMsgs.length > 0 && (
                                <div className="border-b bg-status-warning-bg px-4 py-2">
                                    <div className="mb-1 flex items-center justify-between">
                                        <span className="text-[10px] font-semibold tracking-wider text-status-warning uppercase">
                                            <Pin className="mr-1 inline h-3 w-3" />
                                            Pinned Messages
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

                            {/* Messages */}
                            <div
                                className="flex-1 overflow-y-auto px-4 py-4"
                                onContextMenu={handleEmptyRightClick}
                            >
                                {activeMessages.length === 0 && (
                                    <div className="flex h-full flex-col items-center justify-center text-center">
                                        <span className="mb-2 text-4xl">
                                            👋
                                        </span>
                                        <p className="text-sm text-muted-foreground">
                                            Start the conversation!
                                        </p>
                                    </div>
                                )}
                                {activeMessages.map((msg, idx) => {
                                    const isMe =
                                        msg.sender_id === currentUserId;
                                    const prevMsg =
                                        idx > 0
                                            ? activeMessages[idx - 1]
                                            : null;
                                    const showAvatar =
                                        !prevMsg ||
                                        prevMsg.sender_id !== msg.sender_id;
                                    const isLastByMe =
                                        isMe &&
                                        (idx === activeMessages.length - 1 ||
                                            activeMessages[idx + 1]
                                                ?.sender_id !== currentUserId);
                                    return (
                                        <div
                                            key={msg.id}
                                            data-msg-id={msg.id}
                                            className={`group flex gap-2 ${isMe ? 'flex-row-reverse' : ''} ${showAvatar ? 'mt-4' : 'mt-0.5'}`}
                                            onContextMenu={(e) =>
                                                handleMessageRightClick(e, msg)
                                            }
                                        >
                                            {!isMe && showAvatar ? (
                                                <Avatar className="mt-1 h-7 w-7 shrink-0">
                                                    <AvatarFallback className="bg-muted text-[10px] text-muted-foreground">
                                                        {getInitials(
                                                            msg.sender?.name ??
                                                                '?',
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                            ) : !isMe ? (
                                                <div className="w-7 shrink-0" />
                                            ) : null}
                                            <div className={`max-w-[75%]`}>
                                                {showAvatar && !isMe && (
                                                    <p className="mb-0.5 text-[10px] font-medium text-muted-foreground">
                                                        {msg.sender?.name}
                                                        {msg.sender_type !==
                                                            'family' && (
                                                            <Badge
                                                                variant="outline"
                                                                className="ml-1 border-status-info/30 bg-status-info-bg text-[8px] text-status-info"
                                                            >
                                                                Staff
                                                            </Badge>
                                                        )}
                                                    </p>
                                                )}
                                                {/* Deleted message */}
                                                {msg.is_deleted ? (
                                                    <div
                                                        className={`inline-flex items-center gap-1.5 rounded-2xl px-3.5 py-2 text-sm italic ${isMe ? 'bg-primary/30 text-primary-foreground/60' : 'bg-muted/60 text-muted-foreground'}`}
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                        <span>
                                                            This message was
                                                            deleted
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <>
                                                        {/* Attachments */}
                                                        {msg.attachments?.map(
                                                            (att, ai) => (
                                                                <div
                                                                    key={ai}
                                                                    className="mb-1"
                                                                >
                                                                    {att.type ===
                                                                        'photo' &&
                                                                    att.url ? (
                                                                        <a
                                                                            href={
                                                                                att.url
                                                                            }
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                        >
                                                                            <img
                                                                                src={
                                                                                    att.thumbnail_url ||
                                                                                    att.url
                                                                                }
                                                                                alt={
                                                                                    att.name
                                                                                }
                                                                                className="max-w-[200px] rounded-xl border shadow-sm transition-shadow hover:shadow-md"
                                                                            />
                                                                        </a>
                                                                    ) : (
                                                                        <div
                                                                            className={`inline-flex items-center gap-2 rounded-xl border px-3 py-2 ${isMe ? 'bg-primary/80 text-primary-foreground' : 'bg-muted'}`}
                                                                        >
                                                                            <FileText className="h-4 w-4 shrink-0" />
                                                                            <div>
                                                                                <p className="truncate text-xs font-medium">
                                                                                    {
                                                                                        att.name
                                                                                    }
                                                                                </p>
                                                                                <p className="text-[10px] opacity-70">
                                                                                    {(
                                                                                        att.size /
                                                                                        1024
                                                                                    ).toFixed(
                                                                                        0,
                                                                                    )}{' '}
                                                                                    KB
                                                                                </p>
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ),
                                                        )}
                                                        {/* Voice note */}
                                                        {msg.message_type ===
                                                            'attachment' &&
                                                            msg.content?.includes(
                                                                '🎙️',
                                                            ) &&
                                                            msg.attachments?.[0]?.mime_type?.includes(
                                                                'audio',
                                                            ) && (
                                                                <div
                                                                    className={`inline-flex items-center gap-2 rounded-2xl px-3 py-2 ${isMe ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}
                                                                >
                                                                    <Mic className="h-4 w-4 shrink-0" />
                                                                    {msg
                                                                        .attachments[0]
                                                                        .url ? (
                                                                        <audio
                                                                            controls
                                                                            className="h-8 max-w-[180px]"
                                                                            src={
                                                                                msg
                                                                                    .attachments[0]
                                                                                    .url
                                                                            }
                                                                        />
                                                                    ) : (
                                                                        <span className="text-xs">
                                                                            Voice
                                                                            note
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        {/* Text */}
                                                        {msg.content &&
                                                            !(
                                                                msg.message_type ===
                                                                    'attachment' &&
                                                                msg.attachments
                                                                    ?.length
                                                            ) &&
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
                                                                        className={`inline-block rounded-2xl px-3.5 py-2 text-sm leading-relaxed ${isMe ? 'bg-primary text-primary-foreground' : 'bg-muted'} ${msg.is_pinned ? 'ring-2 ring-status-warning' : ''}`}
                                                                    >
                                                                        {msg.is_pinned && (
                                                                            <Pin className="mr-1 inline h-3 w-3 opacity-60" />
                                                                        )}
                                                                        {quoteLine && (
                                                                            <div
                                                                                className={`mb-1.5 rounded-lg border-l-2 px-2 py-1 text-xs ${isMe ? 'border-l-white/40 bg-white/10' : 'border-l-primary/40 bg-primary/5'}`}
                                                                            >
                                                                                <p className="truncate opacity-70">
                                                                                    {
                                                                                        quoteLine
                                                                                    }
                                                                                </p>
                                                                            </div>
                                                                        )}
                                                                        {
                                                                            mainText
                                                                        }
                                                                    </div>
                                                                );
                                                            })()}
                                                        {msg.content &&
                                                            msg.message_type ===
                                                                'attachment' &&
                                                            msg.attachments
                                                                ?.length &&
                                                            !msg.content.includes(
                                                                '🎙️',
                                                            ) && (
                                                                <p
                                                                    className={`mt-0.5 text-xs ${isMe ? 'text-right' : ''} text-muted-foreground`}
                                                                >
                                                                    {
                                                                        msg.content
                                                                    }
                                                                </p>
                                                            )}
                                                        {/* Reactions */}
                                                        {msg.reactions &&
                                                            msg.reactions
                                                                .length > 0 && (
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
                                                    </>
                                                )}
                                                {/* Actions — always visible for pinned, hover for others */}
                                                <div
                                                    className={`mt-0.5 gap-1 ${msg.is_pinned ? 'flex' : 'hidden group-hover:flex'} ${isMe ? 'justify-end' : ''}`}
                                                >
                                                    <Popover>
                                                        <PopoverTrigger asChild>
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
                                                                            {e}
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
                                                            togglePin(msg.id)
                                                        }
                                                        className={`h-6 w-6 rounded-full ${msg.is_pinned ? 'bg-status-warning-bg text-status-warning' : 'bg-muted'}`}
                                                    >
                                                        <Pin className="h-3 w-3" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            replyTo(
                                                                msg.id,
                                                                msg.sender
                                                                    ?.name ??
                                                                    (isMe
                                                                        ? 'You'
                                                                        : '?'),
                                                                msg.content,
                                                            )
                                                        }
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
                                <div className="flex items-center gap-2 border-t border-l-4 border-l-primary bg-primary/5 px-4 py-2">
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

                            {/* Input */}
                            <div className="border-t bg-card px-4 py-3">
                                {/* Quick replies */}
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
                                            {QUICK_REPLIES.map((reply, i) => (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    key={i}
                                                    className="h-auto w-full justify-start rounded-lg p-2 text-left text-xs"
                                                    onClick={() => {
                                                        setMessageText(reply);
                                                        inputRef.current?.focus();
                                                    }}
                                                >
                                                    {reply}
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
                                        onClick={() =>
                                            setShowUploadDialog(true)
                                        }
                                        className="h-9 w-9 shrink-0 rounded-full text-muted-foreground hover:text-foreground"
                                        title="Attach file"
                                    >
                                        <Paperclip className="h-4 w-4" />
                                    </Button>
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
                                        title={
                                            isRecording
                                                ? 'Stop recording'
                                                : 'Voice note'
                                        }
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
                        <div className="flex flex-1 flex-col items-center justify-center px-8 text-center">
                            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <MessageSquareText className="h-8 w-8 text-primary" />
                            </div>
                            <h2 className="mt-4 text-lg font-semibold">
                                Chat with {clientName}'s Team
                            </h2>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                Select a support worker to start a conversation.
                            </p>
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
                            {/* Reaction bar at top */}
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
                            {/* Menu items */}
                            <div className="p-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto w-full justify-start gap-3 rounded-lg px-3 py-2 text-sm"
                                    onClick={() => {
                                        if (ctxMenu.messageId)
                                            replyTo(
                                                ctxMenu.messageId,
                                                ctxMenu.isOwn
                                                    ? 'You'
                                                    : (ctxMenu.senderName ??
                                                          '?'),
                                                ctxMenu.content ?? '',
                                            );
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
                                            if (ctxMenu.content)
                                                copyToClipboard(
                                                    ctxMenu.content,
                                                );
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
                                                    `/portal/clients/${client.id}/messages/archive/${ctxMenu.messageId}`,
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
                                    setShowUploadDialog(true);
                                    setCtxMenu(null);
                                }}
                            >
                                <Paperclip className="h-4 w-4 text-muted-foreground" />
                                <span>Attach file</span>
                            </Button>
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

            {/* Upload Dialog */}
            <Dialog open={showUploadDialog} onOpenChange={setShowUploadDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Share a File</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setUploadType('photo')}
                                className={`h-auto flex-col gap-2 rounded-xl border-2 p-4 ${uploadType === 'photo' ? 'border-primary bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'}`}
                            >
                                <Camera className="h-6 w-6" />
                                <span className="text-sm font-medium">
                                    Photo
                                </span>
                                <span className="text-[10px]">
                                    JPG, PNG, GIF
                                </span>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setUploadType('document')}
                                className={`h-auto flex-col gap-2 rounded-xl border-2 p-4 ${uploadType === 'document' ? 'border-primary bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'}`}
                            >
                                <FileText className="h-6 w-6" />
                                <span className="text-sm font-medium">
                                    Document
                                </span>
                                <span className="text-[10px]">
                                    PDF, DOC, XLS, TXT
                                </span>
                            </Button>
                        </div>
                        <div
                            className={`relative rounded-xl border-2 border-dashed p-6 text-center transition-colors ${uploadFile ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/30'}`}
                            onDragOver={(e) => {
                                e.preventDefault();
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                const f = e.dataTransfer.files?.[0];
                                if (f) setUploadFile(f);
                            }}
                        >
                            {uploadFile ? (
                                <div className="flex items-center justify-center gap-3">
                                    {uploadType === 'photo' &&
                                    uploadFile.type.startsWith('image/') ? (
                                        <img
                                            src={URL.createObjectURL(
                                                uploadFile,
                                            )}
                                            alt="Preview"
                                            className="h-16 w-16 rounded-lg object-cover"
                                        />
                                    ) : (
                                        <FileText className="h-10 w-10 text-primary" />
                                    )}
                                    <div className="text-left">
                                        <p className="text-sm font-medium">
                                            {uploadFile.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {(uploadFile.size / 1024).toFixed(
                                                0,
                                            )}{' '}
                                            KB
                                        </p>
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="mt-1 h-auto p-0 text-xs text-status-critical"
                                            onClick={() => setUploadFile(null)}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    {uploadType === 'photo' ? (
                                        <Camera className="mx-auto h-8 w-8 text-muted-foreground/40" />
                                    ) : (
                                        <FileText className="mx-auto h-8 w-8 text-muted-foreground/40" />
                                    )}
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Drag & drop or click to browse
                                    </p>
                                    <input
                                        type="file"
                                        className="absolute inset-0 cursor-pointer opacity-0"
                                        accept={
                                            uploadType === 'photo'
                                                ? 'image/*'
                                                : '.pdf,.doc,.docx,.xls,.xlsx,.txt,.rtf,.csv'
                                        }
                                        onChange={(e) =>
                                            setUploadFile(
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </>
                            )}
                        </div>
                        <div>
                            <Label className="text-xs">
                                {uploadType === 'photo' ? 'Caption' : 'Title'}{' '}
                                (optional)
                            </Label>
                            <Input
                                className="mt-1"
                                placeholder={
                                    uploadType === 'photo'
                                        ? 'Add a caption...'
                                        : 'Document title...'
                                }
                                value={uploadCaption}
                                onChange={(e) =>
                                    setUploadCaption(e.target.value)
                                }
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <p className="text-[10px] text-muted-foreground">
                                {uploadType === 'photo'
                                    ? 'Saved to Photo Gallery'
                                    : 'Saved to Documents'}
                            </p>
                            <Button
                                disabled={!uploadFile}
                                onClick={submitUpload}
                            >
                                <Send className="mr-1.5 h-3.5 w-3.5" />
                                Send
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
