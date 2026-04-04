import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover, PopoverContent, PopoverTrigger,
} from '@/components/ui/popover';
import { PresenceDot, PresenceBadge } from '@/components/presence-dot';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Camera, Check, CheckCheck, FileText, Mic, MicOff, MessageSquareText, Paperclip, Pin, Plus, Search, Send, Smile, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type ReactionGroup = { emoji: string; count: number; user_ids: number[]; user_names?: string[] };
type Attachment = { type: 'photo' | 'document'; name: string; url?: string; thumbnail_url?: string; size: number; mime_type: string };
type Message = {
    id: number; content: string; sender_id: number; sender_type: string; message_type?: string;
    attachments?: Attachment[] | null; is_pinned?: boolean; is_read?: boolean; read_at?: string | null;
    shift_id?: number | null; reactions?: ReactionGroup[];
    sender?: { id: number; name: string } | null; created_at: string;
};
type PinnedMessage = { id: number; content: string; sender_name?: string; created_at: string };
type Participant = { id: number; name: string; presence?: string };
type Conversation = { id: number; title?: string | null; latest_message?: { content: string; created_at: string } | null; participants: Participant[]; updated_at: string };
type Worker = { id: number; name: string; presence?: string };

type Props = {
    client: { id: number; first_name: string; last_name: string };
    conversations: Conversation[];
    supportWorkers: Worker[];
    currentUserId: number;
    activeConversation?: { id: number; title?: string | null; participants: Participant[] } | null;
    activeMessages?: Message[];
    pinnedMessages?: PinnedMessage[];
};

const QUICK_REPLIES = [
    'Noted, will do! 👍',
    'Thank you for letting us know.',
    'We\'ll discuss this at the next handover.',
    'Everything is going well today! 😊',
    'I\'ll follow up on this shortly.',
    'Great idea, we\'ll make it happen.',
];

const CHAT_REACTIONS = ['👍', '❤️', '😊', '✅', '🙏', '😢'];

function getInitials(name: string): string { return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2); }
function formatTime(iso: string): string {
    const d = new Date(iso); const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
    const y = new Date(now); y.setDate(y.getDate() - 1);
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}
function formatMessageTime(iso: string): string { return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }); }

export default function PortalMessages({ client, conversations, supportWorkers, currentUserId, activeConversation: propConvo, activeMessages: propMsgs, pinnedMessages: propPinned }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const [selectedId, setSelectedId] = useState<number | null>(propConvo?.id ?? null);
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
    const [ctxMenu, setCtxMenu] = useState<{ x: number; y: number; messageId?: number; isOwn?: boolean; content?: string; senderName?: string } | null>(null);
    const [replyingTo, setReplyingTo] = useState<{ id: number; senderName: string; content: string } | null>(null);
    const dragCounter = useRef(0);
    const [activeMessages, setActiveMessages] = useState<Message[]>(propMsgs ?? []);
    const [activeConvo, setActiveConvo] = useState<typeof propConvo>(propConvo ?? null);
    const [pinnedMsgs, setPinnedMsgs] = useState<PinnedMessage[]>(propPinned ?? []);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const mediaRecorderRef = useRef<MediaRecorder | null>(null);
    const audioChunksRef = useRef<Blob[]>([]);

    useEffect(() => { messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [activeMessages]);
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
        if (propConvo) { setActiveConvo(propConvo); setSelectedId(propConvo.id); }
        if (propPinned) setPinnedMsgs(propPinned);
    }, [propMsgs, propConvo, propPinned]);

    const getConvoName = (conv: { title?: string | null; participants: Participant[] }) => {
        if (conv.title) return conv.title;
        const other = conv.participants.find(p => p.id !== currentUserId);
        return other?.name ?? 'Conversation';
    };

    const selectConversation = useCallback((conv: Conversation) => {
        setSelectedId(conv.id); setShowNewChat(false); setShowSearch(false);
        router.get(`/portal/clients/${client.id}/messages/${conv.id}`, {}, { preserveState: false });
    }, [client.id]);

    const sendMessage = useCallback(() => {
        if (!messageText.trim() || !selectedId) return;
        const content = replyingTo
            ? `> ${replyingTo.senderName}: ${replyingTo.content}\n\n${messageText}`
            : messageText;
        router.post(`/portal/clients/${client.id}/messages/${selectedId}`, { content }, {
            preserveScroll: true, onSuccess: () => { setMessageText(''); setReplyingTo(null); inputRef.current?.focus(); },
        });
    }, [messageText, selectedId, client.id, replyingTo]);

    const startNewChat = useCallback((workerId: number) => {
        router.post(`/portal/clients/${client.id}/messages/start`, { worker_id: workerId, content: 'Hello! 👋' }, {
            preserveScroll: true, onSuccess: () => setShowNewChat(false),
        });
    }, [client.id]);

    const submitUpload = useCallback(() => {
        if (!uploadFile || !selectedId) return;
        const formData = new FormData();
        formData.append('attachment', uploadFile);
        if (uploadCaption) formData.append('content', uploadCaption);
        router.post(`/portal/clients/${client.id}/messages/${selectedId}`, formData, {
            preserveScroll: true, forceFormData: true,
            onSuccess: () => { setShowUploadDialog(false); setUploadFile(null); setUploadCaption(''); },
        });
    }, [uploadFile, uploadCaption, selectedId, client.id]);

    const toggleReaction = useCallback((messageId: number, emoji: string) => {
        router.post(`/portal/clients/${client.id}/messages/react/${messageId}`, { emoji }, { preserveScroll: true, preserveState: true });
    }, [client.id]);

    const togglePin = useCallback((messageId: number) => {
        router.post(`/portal/clients/${client.id}/messages/pin/${messageId}`, {}, { preserveScroll: true });
    }, [client.id]);

    const doSearch = useCallback(async (q: string) => {
        if (q.length < 2) { setSearchResults([]); return; }
        try {
            const res = await fetch(`/portal/clients/${client.id}/messages-search?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (res.ok) setSearchResults(await res.json());
        } catch { /* ignore */ }
    }, [client.id]);

    // Voice note recording
    const startRecording = useCallback(async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const recorder = new MediaRecorder(stream);
            audioChunksRef.current = [];
            recorder.ondataavailable = (e) => { if (e.data.size > 0) audioChunksRef.current.push(e.data); };
            recorder.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                const blob = new Blob(audioChunksRef.current, { type: 'audio/webm' });
                const file = new File([blob], `voice-note-${Date.now()}.webm`, { type: 'audio/webm' });
                if (!selectedId) return;
                const formData = new FormData();
                formData.append('attachment', file);
                formData.append('content', '🎙️ Voice note');
                router.post(`/portal/clients/${client.id}/messages/${selectedId}`, formData, { preserveScroll: true, forceFormData: true });
            };
            recorder.start();
            mediaRecorderRef.current = recorder;
            setIsRecording(true);
            // Auto-stop after 60 seconds
            setTimeout(() => { if (mediaRecorderRef.current?.state === 'recording') { mediaRecorderRef.current.stop(); setIsRecording(false); } }, 60000);
        } catch { toast.error('Microphone access denied'); }
    }, [selectedId, client.id]);

    const stopRecording = useCallback(() => {
        if (mediaRecorderRef.current?.state === 'recording') { mediaRecorderRef.current.stop(); setIsRecording(false); }
    }, []);

    // Drag handlers
    const handleDragEnter = useCallback((e: React.DragEvent) => { e.preventDefault(); dragCounter.current++; if (e.dataTransfer.types.includes('Files')) setIsDragging(true); }, []);
    const handleDragLeave = useCallback((e: React.DragEvent) => { e.preventDefault(); dragCounter.current--; if (dragCounter.current === 0) setIsDragging(false); }, []);
    const handleDragOver = useCallback((e: React.DragEvent) => { e.preventDefault(); }, []);
    const handleDrop = useCallback((e: React.DragEvent) => {
        e.preventDefault(); setIsDragging(false); dragCounter.current = 0;
        const file = e.dataTransfer.files?.[0]; if (!file) return;
        setUploadType(file.type.startsWith('image/') ? 'photo' : 'document');
        setUploadFile(file); setUploadCaption(''); setShowUploadDialog(true);
    }, []);

    const handleMessageRightClick = useCallback((e: React.MouseEvent, msg: Message) => {
        e.preventDefault();
        setCtxMenu({ x: e.clientX, y: e.clientY, messageId: msg.id, isOwn: msg.sender_id === currentUserId, content: msg.content, senderName: msg.sender?.name });
    }, [currentUserId]);

    const handleEmptyRightClick = useCallback((e: React.MouseEvent) => {
        const target = e.target as HTMLElement;
        if (target.closest('[data-msg-id]')) return; // handled by message handler
        e.preventDefault();
        setCtxMenu({ x: e.clientX, y: e.clientY });
    }, []);

    const copyToClipboard = useCallback((text: string) => {
        navigator.clipboard.writeText(text).then(() => toast.success('Copied!')).catch(() => {});
        setCtxMenu(null);
    }, []);

    const replyTo = useCallback((msgId: number, senderName: string, content: string) => {
        setReplyingTo({ id: msgId, senderName, content: content.slice(0, 80) });
        inputRef.current?.focus();
        setCtxMenu(null);
    }, []);

    const hasConversations = conversations.length > 0;

    return (
        <AppLayout breadcrumbs={[
            { title: 'Portal', href: '/portal' },
            { title: clientName, href: `/portal/clients/${client.id}/dashboard` },
            { title: 'Messages', href: `/portal/clients/${client.id}/messages` },
        ]}>
            <Head title={`Messages - ${clientName}`} />

            <div className="flex h-[calc(100vh-7rem)] overflow-hidden">
                {/* Left Panel */}
                <div className="flex w-72 flex-col border-r bg-card">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <h1 className="text-base font-semibold">Messages</h1>
                        <div className="flex items-center gap-1">
                            <button onClick={() => { setShowSearch(!showSearch); setShowNewChat(false); }} className="flex h-8 w-8 items-center justify-center rounded-full hover:bg-muted transition-colors" title="Search messages">
                                <Search className="h-4 w-4 text-muted-foreground" />
                            </button>
                            <button onClick={() => { setShowNewChat(!showNewChat); setSelectedId(null); setActiveConvo(null); setShowSearch(false); }}
                                className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:bg-primary/90" title="New message">
                                <Plus className="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    {/* Search Panel */}
                    {showSearch && (
                        <div className="border-b p-3 space-y-2">
                            <Input placeholder="Search messages..." className="h-8 text-xs" autoFocus value={searchQuery}
                                onChange={e => { setSearchQuery(e.target.value); doSearch(e.target.value); }} />
                            {searchResults.length > 0 && (
                                <div className="max-h-48 space-y-1 overflow-y-auto">
                                    {searchResults.map((r: any) => (
                                        <button key={r.id} className="w-full rounded-lg p-2 text-left text-xs hover:bg-accent" onClick={() => { setShowSearch(false); router.get(`/portal/clients/${client.id}/messages/${r.conversation_id}`); }}>
                                            <p className="font-medium truncate">{r.content.slice(0, 60)}</p>
                                            <p className="text-[10px] text-muted-foreground">{r.sender_name} · {formatTime(r.created_at)}</p>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex-1 overflow-y-auto">
                        {(!hasConversations || showNewChat) && (
                            <div className="border-b p-3">
                                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Care Team</p>
                                <div className="space-y-1">
                                    {supportWorkers.map(worker => (
                                        <button key={worker.id} className="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors hover:bg-accent" onClick={() => startNewChat(worker.id)}>
                                            <div className="relative shrink-0">
                                                <Avatar className="h-8 w-8"><AvatarFallback className="bg-slate-100 text-[10px] text-slate-700">{getInitials(worker.name)}</AvatarFallback></Avatar>
                                                <span className="absolute -bottom-0.5 -right-0.5"><PresenceDot status={worker.presence ?? 'offline'} /></span>
                                            </div>
                                            <div className="min-w-0">
                                                <p className="truncate text-xs font-medium">{worker.name}</p>
                                                <PresenceBadge status={worker.presence ?? 'offline'} />
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                        {hasConversations && conversations.map(conv => {
                            const name = getConvoName(conv); const isSelected = selectedId === conv.id;
                            const other = conv.participants.find(p => p.id !== currentUserId);
                            return (
                                <button key={conv.id} className={`flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent ${isSelected ? 'bg-accent' : ''}`} onClick={() => selectConversation(conv)}>
                                    <div className="relative shrink-0">
                                        <Avatar className="h-9 w-9"><AvatarFallback className="bg-primary/10 text-xs text-primary">{getInitials(name)}</AvatarFallback></Avatar>
                                        {other?.presence && <span className="absolute -bottom-0.5 -right-0.5"><PresenceDot status={other.presence} /></span>}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center justify-between">
                                            <span className="truncate text-sm font-medium">{name}</span>
                                            {conv.latest_message && <span className="ml-1 shrink-0 text-[10px] text-muted-foreground">{formatTime(conv.latest_message.created_at)}</span>}
                                        </div>
                                        <p className="truncate text-[11px] text-muted-foreground">{conv.latest_message?.content ?? 'No messages'}</p>
                                    </div>
                                </button>
                            );
                        })}
                        {!hasConversations && !showNewChat && (
                            <div className="flex flex-col items-center justify-center px-4 py-8 text-center">
                                <MessageSquareText className="mb-2 h-8 w-8 text-muted-foreground/20" />
                                <p className="text-xs text-muted-foreground">No conversations yet</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Right Panel */}
                <div className="relative flex flex-1 flex-col bg-background" onDragEnter={handleDragEnter} onDragLeave={handleDragLeave} onDragOver={handleDragOver} onDrop={handleDrop}>
                    {isDragging && selectedId && (
                        <div className="absolute inset-0 z-50 flex items-center justify-center bg-primary/10 backdrop-blur-sm border-2 border-dashed border-primary rounded-lg">
                            <div className="text-center"><Paperclip className="mx-auto h-10 w-10 text-primary mb-2" /><p className="text-lg font-semibold text-primary">Drop file here</p></div>
                        </div>
                    )}

                    {selectedId && activeConvo ? (
                        <>
                            {/* Header */}
                            <div className="flex items-center justify-between border-b px-4 py-3">
                                <div className="flex items-center gap-3">
                                    <div className="relative">
                                        <Avatar className="h-9 w-9"><AvatarFallback className="bg-primary/10 text-xs text-primary">{getInitials(getConvoName(activeConvo))}</AvatarFallback></Avatar>
                                        {(() => { const o = activeConvo.participants.find(p => p.id !== currentUserId); return o?.presence ? <span className="absolute -bottom-0.5 -right-0.5"><PresenceDot status={o.presence} size="md" /></span> : null; })()}
                                    </div>
                                    <div>
                                        <h2 className="text-sm font-semibold">{getConvoName(activeConvo)}</h2>
                                        {(() => { const o = activeConvo.participants.find(p => p.id !== currentUserId); return o?.presence ? <PresenceBadge status={o.presence} /> : null; })()}
                                    </div>
                                </div>
                                {pinnedMsgs.length > 0 && (
                                    <button onClick={() => setShowPinned(!showPinned)} className="flex items-center gap-1 rounded-full border px-2.5 py-1 text-[10px] font-medium text-muted-foreground hover:bg-muted transition-colors">
                                        <Pin className="h-3 w-3" />{pinnedMsgs.length} pinned
                                    </button>
                                )}
                            </div>

                            {/* Pinned Messages Banner */}
                            {showPinned && pinnedMsgs.length > 0 && (
                                <div className="border-b bg-amber-50/50 px-4 py-2 dark:bg-amber-950/10">
                                    <div className="flex items-center justify-between mb-1">
                                        <span className="text-[10px] font-semibold uppercase tracking-wider text-amber-600"><Pin className="inline h-3 w-3 mr-1" />Pinned Messages</span>
                                        <button onClick={() => setShowPinned(false)} className="text-muted-foreground hover:text-foreground"><X className="h-3 w-3" /></button>
                                    </div>
                                    {pinnedMsgs.map(pm => (
                                        <div key={pm.id} className="rounded-lg bg-white/60 dark:bg-card p-2 mb-1 text-xs">
                                            <p className="font-medium">{pm.content.slice(0, 100)}</p>
                                            <p className="text-[10px] text-muted-foreground mt-0.5">{pm.sender_name} · {formatTime(pm.created_at)}</p>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Messages */}
                            <div className="flex-1 overflow-y-auto px-4 py-4" onContextMenu={handleEmptyRightClick}>
                                {activeMessages.length === 0 && (
                                    <div className="flex h-full flex-col items-center justify-center text-center">
                                        <span className="mb-2 text-4xl">👋</span><p className="text-sm text-muted-foreground">Start the conversation!</p>
                                    </div>
                                )}
                                {activeMessages.map((msg, idx) => {
                                    const isMe = msg.sender_id === currentUserId;
                                    const prevMsg = idx > 0 ? activeMessages[idx - 1] : null;
                                    const showAvatar = !prevMsg || prevMsg.sender_id !== msg.sender_id;
                                    const isLastByMe = isMe && (idx === activeMessages.length - 1 || activeMessages[idx + 1]?.sender_id !== currentUserId);
                                    return (
                                        <div key={msg.id} data-msg-id={msg.id} className={`group flex gap-2 ${isMe ? 'flex-row-reverse' : ''} ${showAvatar ? 'mt-4' : 'mt-0.5'}`}
                                            onContextMenu={(e) => handleMessageRightClick(e, msg)}>
                                            {!isMe && showAvatar ? (
                                                <Avatar className="mt-1 h-7 w-7 shrink-0"><AvatarFallback className="bg-slate-100 text-[10px] text-slate-600">{getInitials(msg.sender?.name ?? '?')}</AvatarFallback></Avatar>
                                            ) : !isMe ? <div className="w-7 shrink-0" /> : null}
                                            <div className={`max-w-[75%]`}>
                                                {showAvatar && !isMe && (
                                                    <p className="mb-0.5 text-[10px] font-medium text-muted-foreground">
                                                        {msg.sender?.name}
                                                        {msg.sender_type !== 'family' && <Badge variant="outline" className="ml-1 text-[8px] border-blue-200 bg-blue-50 text-blue-700">Staff</Badge>}
                                                    </p>
                                                )}
                                                {/* Attachments */}
                                                {msg.attachments?.map((att, ai) => (
                                                    <div key={ai} className="mb-1">
                                                        {att.type === 'photo' && att.url ? (
                                                            <a href={att.url} target="_blank" rel="noopener noreferrer">
                                                                <img src={att.thumbnail_url || att.url} alt={att.name} className="max-w-[200px] rounded-xl border shadow-sm hover:shadow-md transition-shadow" />
                                                            </a>
                                                        ) : (
                                                            <div className={`inline-flex items-center gap-2 rounded-xl border px-3 py-2 ${isMe ? 'bg-primary/80 text-primary-foreground' : 'bg-muted'}`}>
                                                                <FileText className="h-4 w-4 shrink-0" /><div><p className="truncate text-xs font-medium">{att.name}</p><p className="text-[10px] opacity-70">{(att.size / 1024).toFixed(0)} KB</p></div>
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                                {/* Voice note */}
                                                {msg.message_type === 'attachment' && msg.content?.includes('🎙️') && msg.attachments?.[0]?.mime_type?.includes('audio') && (
                                                    <div className={`inline-flex items-center gap-2 rounded-2xl px-3 py-2 ${isMe ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}>
                                                        <Mic className="h-4 w-4 shrink-0" />
                                                        {msg.attachments[0].url ? (
                                                            <audio controls className="h-8 max-w-[180px]" src={msg.attachments[0].url} />
                                                        ) : (
                                                            <span className="text-xs">Voice note</span>
                                                        )}
                                                    </div>
                                                )}
                                                {/* Text */}
                                                {msg.content && !(msg.message_type === 'attachment' && msg.attachments?.length) && (() => {
                                                    const hasQuote = msg.content.startsWith('> ');
                                                    const parts = hasQuote ? msg.content.split('\n\n') : null;
                                                    const quoteLine = parts ? parts[0].replace(/^> /, '') : null;
                                                    const mainText = parts ? parts.slice(1).join('\n\n') : msg.content;
                                                    return (
                                                        <div className={`inline-block rounded-2xl px-3.5 py-2 text-sm leading-relaxed ${isMe ? 'bg-primary text-primary-foreground' : 'bg-muted'} ${msg.is_pinned ? 'ring-2 ring-amber-300' : ''}`}>
                                                            {msg.is_pinned && <Pin className="inline h-3 w-3 mr-1 opacity-60" />}
                                                            {quoteLine && (
                                                                <div className={`mb-1.5 rounded-lg border-l-2 px-2 py-1 text-xs ${isMe ? 'border-l-white/40 bg-white/10' : 'border-l-primary/40 bg-primary/5'}`}>
                                                                    <p className="opacity-70 truncate">{quoteLine}</p>
                                                                </div>
                                                            )}
                                                            {mainText}
                                                        </div>
                                                    );
                                                })()}
                                                {msg.content && msg.message_type === 'attachment' && msg.attachments?.length && !msg.content.includes('🎙️') && (
                                                    <p className={`text-xs mt-0.5 ${isMe ? 'text-right' : ''} text-muted-foreground`}>{msg.content}</p>
                                                )}
                                                {/* Reactions */}
                                                {msg.reactions && msg.reactions.length > 0 && (
                                                    <div className="mt-0.5 flex flex-wrap gap-1">
                                                        {msg.reactions.map(r => (
                                                            <button key={r.emoji} onClick={() => toggleReaction(msg.id, r.emoji)}
                                                                title={r.user_names?.length ? `Reacted by: ${r.user_names.join(', ')}` : undefined}
                                                                className={`inline-flex items-center gap-0.5 rounded-full border px-1.5 py-0.5 text-[10px] transition-colors hover:bg-muted ${r.user_ids.includes(currentUserId) ? 'border-primary/50 bg-primary/5' : 'border-border'}`}>
                                                                {r.emoji} <span className="font-medium">{r.count}</span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                                {/* Time + read receipt */}
                                                <div className={`mt-0.5 flex items-center gap-1 text-[10px] text-muted-foreground/60 ${isMe ? 'justify-end' : ''}`}>
                                                    <span>{formatMessageTime(msg.created_at)}</span>
                                                    {isMe && isLastByMe && (
                                                        msg.is_read ? <span title={`Read ${msg.read_at ? formatTime(msg.read_at) : ''}`}><CheckCheck className="h-3 w-3 text-blue-500" /></span> : <span title="Sent"><Check className="h-3 w-3" /></span>
                                                    )}
                                                </div>
                                                {/* Actions — always visible for pinned, hover for others */}
                                                <div className={`mt-0.5 gap-1 ${msg.is_pinned ? 'flex' : 'hidden group-hover:flex'} ${isMe ? 'justify-end' : ''}`}>
                                                    <Popover>
                                                        <PopoverTrigger asChild>
                                                            <button className="h-6 w-6 rounded-full bg-muted flex items-center justify-center hover:bg-accent transition-colors"><Smile className="h-3 w-3" /></button>
                                                        </PopoverTrigger>
                                                        <PopoverContent className="w-auto p-1.5" align={isMe ? 'end' : 'start'}>
                                                            <div className="flex gap-1">{CHAT_REACTIONS.map(e => <button key={e} onClick={() => toggleReaction(msg.id, e)} className="rounded-lg p-1.5 text-base hover:bg-muted">{e}</button>)}</div>
                                                        </PopoverContent>
                                                    </Popover>
                                                    <button onClick={() => togglePin(msg.id)} className={`h-6 w-6 rounded-full flex items-center justify-center transition-colors ${msg.is_pinned ? 'bg-amber-100 text-amber-600' : 'bg-muted hover:bg-accent'}`}>
                                                        <Pin className="h-3 w-3" />
                                                    </button>
                                                    {!isMe && (
                                                        <button onClick={() => replyTo(msg.id, msg.sender?.name ?? '?', msg.content)} className="h-6 w-6 rounded-full bg-muted flex items-center justify-center hover:bg-accent transition-colors">
                                                            <Send className="h-3 w-3 rotate-180" />
                                                        </button>
                                                    )}
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
                                        <p className="text-[10px] font-semibold text-primary">Replying to {replyingTo.senderName}</p>
                                        <p className="truncate text-xs text-muted-foreground">{replyingTo.content}</p>
                                    </div>
                                    <button onClick={() => setReplyingTo(null)} className="shrink-0 text-muted-foreground hover:text-foreground"><X className="h-4 w-4" /></button>
                                </div>
                            )}

                            {/* Input */}
                            <div className="border-t bg-card px-4 py-3">
                                {/* Quick replies */}
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <button className="mb-2 text-[10px] text-muted-foreground hover:text-foreground transition-colors">💬 Quick replies</button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-64 p-2" align="start">
                                        <div className="space-y-1">
                                            {QUICK_REPLIES.map((reply, i) => (
                                                <button key={i} className="w-full rounded-lg p-2 text-left text-xs hover:bg-accent transition-colors" onClick={() => { setMessageText(reply); inputRef.current?.focus(); }}>
                                                    {reply}
                                                </button>
                                            ))}
                                        </div>
                                    </PopoverContent>
                                </Popover>
                                <div className="flex items-center gap-2">
                                    <button onClick={() => setShowUploadDialog(true)} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" title="Attach file">
                                        <Paperclip className="h-4 w-4" />
                                    </button>
                                    <button onClick={isRecording ? stopRecording : startRecording}
                                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full transition-colors ${isRecording ? 'bg-red-500 text-white animate-pulse' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                        title={isRecording ? 'Stop recording' : 'Voice note'}>
                                        {isRecording ? <MicOff className="h-4 w-4" /> : <Mic className="h-4 w-4" />}
                                    </button>
                                    <Input ref={inputRef} placeholder="Type a message..." className="flex-1 rounded-full" value={messageText}
                                        onChange={e => setMessageText(e.target.value)}
                                        onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }}
                                        autoFocus />
                                    <button className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-50"
                                        disabled={!messageText.trim()} onClick={sendMessage}>
                                        <Send className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </>
                    ) : (
                        <div className="flex flex-1 flex-col items-center justify-center text-center px-8">
                            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <MessageSquareText className="h-8 w-8 text-primary" />
                            </div>
                            <h2 className="mt-4 text-lg font-semibold">Chat with {clientName}'s Team</h2>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">Select a support worker to start a conversation.</p>
                        </div>
                    )}
                </div>
            </div>

            {/* Context Menu */}
            {ctxMenu && (
                <div data-ctx-menu className="fixed z-50 min-w-[180px] rounded-xl border bg-card p-1 shadow-xl" style={{ top: ctxMenu.y, left: ctxMenu.x }} onClick={e => e.stopPropagation()}>
                    {ctxMenu.messageId ? (
                        <>
                            <button className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-accent" onClick={() => { if (ctxMenu.messageId) togglePin(ctxMenu.messageId); setCtxMenu(null); }}>
                                <Pin className="h-4 w-4 text-amber-500" />
                                {activeMessages.find(m => m.id === ctxMenu.messageId)?.is_pinned ? 'Unpin message' : 'Pin message'}
                            </button>
                            <Popover>
                                <PopoverTrigger asChild>
                                    <button className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-accent">
                                        <Smile className="h-4 w-4 text-amber-500" />React
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent className="w-auto p-1.5" align="start">
                                    <div className="flex gap-1">{CHAT_REACTIONS.map(e => <button key={e} onClick={() => { if (ctxMenu.messageId) toggleReaction(ctxMenu.messageId, e); setCtxMenu(null); }} className="rounded-lg p-1.5 text-base hover:bg-muted">{e}</button>)}</div>
                                </PopoverContent>
                            </Popover>
                            {!ctxMenu.isOwn && ctxMenu.senderName && (
                                <button className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-accent" onClick={() => { if (ctxMenu.messageId && ctxMenu.senderName) replyTo(ctxMenu.messageId, ctxMenu.senderName, ctxMenu.content ?? ''); }}>
                                    <Send className="h-4 w-4 text-blue-500 rotate-180" />Reply
                                </button>
                            )}
                            {ctxMenu.content && (
                                <button className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-accent" onClick={() => { if (ctxMenu.content) copyToClipboard(ctxMenu.content); }}>
                                    <FileText className="h-4 w-4 text-muted-foreground" />Copy text
                                </button>
                            )}
                        </>
                    ) : (
                        <>
                            <button className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-accent" onClick={() => { setShowUploadDialog(true); setCtxMenu(null); }}>
                                <Paperclip className="h-4 w-4 text-primary" />Attach file
                            </button>
                            <button className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-accent" onClick={() => { startRecording(); setCtxMenu(null); }}>
                                <Mic className="h-4 w-4 text-red-500" />Voice note
                            </button>
                        </>
                    )}
                </div>
            )}

            {/* Upload Dialog */}
            <Dialog open={showUploadDialog} onOpenChange={setShowUploadDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>Share a File</DialogTitle></DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid grid-cols-2 gap-2">
                            <button onClick={() => setUploadType('photo')} className={`flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition-all ${uploadType === 'photo' ? 'border-primary bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'}`}>
                                <Camera className="h-6 w-6" /><span className="text-sm font-medium">Photo</span><span className="text-[10px]">JPG, PNG, GIF</span>
                            </button>
                            <button onClick={() => setUploadType('document')} className={`flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition-all ${uploadType === 'document' ? 'border-primary bg-primary/5 text-primary' : 'border-border text-muted-foreground hover:border-primary/30'}`}>
                                <FileText className="h-6 w-6" /><span className="text-sm font-medium">Document</span><span className="text-[10px]">PDF, DOC, XLS, TXT</span>
                            </button>
                        </div>
                        <div className={`relative rounded-xl border-2 border-dashed p-6 text-center transition-colors ${uploadFile ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/30'}`}
                            onDragOver={e => { e.preventDefault(); }} onDrop={e => { e.preventDefault(); const f = e.dataTransfer.files?.[0]; if (f) setUploadFile(f); }}>
                            {uploadFile ? (
                                <div className="flex items-center justify-center gap-3">
                                    {uploadType === 'photo' && uploadFile.type.startsWith('image/') ? <img src={URL.createObjectURL(uploadFile)} alt="Preview" className="h-16 w-16 rounded-lg object-cover" /> : <FileText className="h-10 w-10 text-primary" />}
                                    <div className="text-left"><p className="text-sm font-medium">{uploadFile.name}</p><p className="text-xs text-muted-foreground">{(uploadFile.size / 1024).toFixed(0)} KB</p><button className="mt-1 text-xs text-red-500 hover:underline" onClick={() => setUploadFile(null)}>Remove</button></div>
                                </div>
                            ) : (
                                <>{uploadType === 'photo' ? <Camera className="mx-auto h-8 w-8 text-muted-foreground/40" /> : <FileText className="mx-auto h-8 w-8 text-muted-foreground/40" />}<p className="mt-2 text-sm text-muted-foreground">Drag & drop or click to browse</p><input type="file" className="absolute inset-0 cursor-pointer opacity-0" accept={uploadType === 'photo' ? 'image/*' : '.pdf,.doc,.docx,.xls,.xlsx,.txt,.rtf,.csv'} onChange={e => setUploadFile(e.target.files?.[0] ?? null)} /></>
                            )}
                        </div>
                        <div><Label className="text-xs">{uploadType === 'photo' ? 'Caption' : 'Title'} (optional)</Label><Input className="mt-1" placeholder={uploadType === 'photo' ? 'Add a caption...' : 'Document title...'} value={uploadCaption} onChange={e => setUploadCaption(e.target.value)} /></div>
                        <div className="flex items-center justify-between">
                            <p className="text-[10px] text-muted-foreground">{uploadType === 'photo' ? 'Saved to Photo Gallery' : 'Saved to Documents'}</p>
                            <Button disabled={!uploadFile} onClick={submitUpload}><Send className="mr-1.5 h-3.5 w-3.5" />Send</Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
