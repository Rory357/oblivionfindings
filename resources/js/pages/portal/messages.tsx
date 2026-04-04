import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PresenceDot, PresenceBadge } from '@/components/presence-dot';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Image, MessageSquareText, Paperclip, Plus, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Participant = { id: number; name: string; presence?: string };
type Message = { id: number; content: string; sender_id: number; sender_type: string; sender?: { id: number; name: string } | null; created_at: string };
type Conversation = {
    id: number;
    title?: string | null;
    latest_message?: { content: string; created_at: string; sender_name?: string } | null;
    participants: Participant[];
    updated_at: string;
};
type Worker = { id: number; name: string; presence?: string };

type Props = {
    client: { id: number; first_name: string; last_name: string };
    conversations: Conversation[];
    supportWorkers: Worker[];
    currentUserId: number;
    activeConversation?: { id: number; title?: string | null; participants: Participant[] } | null;
    activeMessages?: Message[];
};

function getInitials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}

function formatTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
    const yesterday = new Date(now); yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function formatMessageTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}

export default function PortalMessages({ client, conversations, supportWorkers, currentUserId, activeConversation: propConvo, activeMessages: propMsgs }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const [selectedId, setSelectedId] = useState<number | null>(propConvo?.id ?? null);
    const [showNewChat, setShowNewChat] = useState(false);
    const [messageText, setMessageText] = useState('');
    const [activeMessages, setActiveMessages] = useState<Message[]>(propMsgs ?? []);
    const [activeConvo, setActiveConvo] = useState<typeof propConvo>(propConvo ?? null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => { messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [activeMessages]);
    useEffect(() => {
        if (propMsgs) setActiveMessages(propMsgs);
        if (propConvo) { setActiveConvo(propConvo); setSelectedId(propConvo.id); }
    }, [propMsgs, propConvo]);

    const getConvoName = (conv: { title?: string | null; participants: Participant[] }) => {
        if (conv.title) return conv.title;
        const other = conv.participants.find(p => p.id !== currentUserId);
        return other?.name ?? 'Conversation';
    };

    const selectConversation = useCallback((conv: Conversation) => {
        setSelectedId(conv.id);
        setShowNewChat(false);
        router.get(`/portal/clients/${client.id}/messages/${conv.id}`, {}, { preserveState: false });
    }, [client.id]);

    const sendMessage = useCallback(() => {
        if (!messageText.trim() || !selectedId) return;
        router.post(`/portal/clients/${client.id}/messages/${selectedId}`, { content: messageText }, {
            preserveScroll: true,
            onSuccess: () => { setMessageText(''); inputRef.current?.focus(); },
        });
    }, [messageText, selectedId, client.id]);

    const startNewChat = useCallback((workerId: number) => {
        router.post(`/portal/clients/${client.id}/messages/start`, {
            worker_id: workerId,
            content: 'Hello! 👋',
        }, { preserveScroll: true, onSuccess: () => setShowNewChat(false) });
    }, [client.id]);

    // Auto-show new chat picker if no conversations exist
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
                        <button
                            onClick={() => { setShowNewChat(!showNewChat); setSelectedId(null); setActiveConvo(null); }}
                            className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:bg-primary/90"
                            title="New message"
                        >
                            <Plus className="h-4 w-4" />
                        </button>
                    </div>

                    <div className="flex-1 overflow-y-auto">
                        {/* Support Workers Quick List (when no conversations or new chat) */}
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

                        {/* Conversations */}
                        {hasConversations && (
                            <div>
                                {showNewChat && <p className="px-3 pt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Recent Chats</p>}
                                {conversations.map(conv => {
                                    const name = getConvoName(conv);
                                    const isSelected = selectedId === conv.id;
                                    const otherParticipant = conv.participants.find(p => p.id !== currentUserId);
                                    return (
                                        <button key={conv.id} className={`flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent ${isSelected ? 'bg-accent' : ''}`}
                                            onClick={() => selectConversation(conv)}>
                                            <div className="relative shrink-0">
                                                <Avatar className="h-9 w-9"><AvatarFallback className="bg-primary/10 text-xs text-primary">{getInitials(name)}</AvatarFallback></Avatar>
                                                {otherParticipant?.presence && <span className="absolute -bottom-0.5 -right-0.5"><PresenceDot status={otherParticipant.presence} /></span>}
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
                            </div>
                        )}

                        {!hasConversations && !showNewChat && (
                            <div className="flex flex-col items-center justify-center px-4 py-8 text-center">
                                <MessageSquareText className="mb-2 h-8 w-8 text-muted-foreground/20" />
                                <p className="text-xs text-muted-foreground">No conversations yet</p>
                                <p className="mt-1 text-[10px] text-muted-foreground">Select a worker above to start chatting</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Right Panel - Chat */}
                <div className="flex flex-1 flex-col bg-background">
                    {selectedId && activeConvo ? (
                        <>
                            {/* Header */}
                            <div className="flex items-center gap-3 border-b px-4 py-3">
                                <div className="relative">
                                    <Avatar className="h-9 w-9"><AvatarFallback className="bg-primary/10 text-xs text-primary">{getInitials(getConvoName(activeConvo))}</AvatarFallback></Avatar>
                                    {(() => { const o = activeConvo.participants.find(p => p.id !== currentUserId); return o?.presence ? <span className="absolute -bottom-0.5 -right-0.5"><PresenceDot status={o.presence} size="md" /></span> : null; })()}
                                </div>
                                <div>
                                    <h2 className="text-sm font-semibold">{getConvoName(activeConvo)}</h2>
                                    {(() => { const o = activeConvo.participants.find(p => p.id !== currentUserId); return o?.presence ? <PresenceBadge status={o.presence} /> : null; })()}
                                </div>
                            </div>

                            {/* Messages */}
                            <div className="flex-1 overflow-y-auto px-4 py-4">
                                {activeMessages.length === 0 && (
                                    <div className="flex h-full flex-col items-center justify-center text-center">
                                        <span className="mb-2 text-4xl">👋</span>
                                        <p className="text-sm text-muted-foreground">Start the conversation!</p>
                                    </div>
                                )}
                                {activeMessages.map((msg, idx) => {
                                    const isMe = msg.sender_id === currentUserId;
                                    const prevMsg = idx > 0 ? activeMessages[idx - 1] : null;
                                    const showAvatar = !prevMsg || prevMsg.sender_id !== msg.sender_id;
                                    return (
                                        <div key={msg.id} className={`flex gap-2 ${isMe ? 'flex-row-reverse' : ''} ${showAvatar ? 'mt-4' : 'mt-0.5'}`}>
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
                                                <div className={`inline-block rounded-2xl px-3.5 py-2 text-sm leading-relaxed ${isMe ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}>
                                                    {msg.content}
                                                </div>
                                                <p className={`mt-0.5 text-[10px] text-muted-foreground/60 ${isMe ? 'text-right' : ''}`}>{formatMessageTime(msg.created_at)}</p>
                                            </div>
                                        </div>
                                    );
                                })}
                                <div ref={messagesEndRef} />
                            </div>

                            {/* Input */}
                            <div className="border-t bg-card px-4 py-3">
                                <div className="flex items-center gap-2">
                                    <input
                                        type="file"
                                        id="chat-file-upload"
                                        className="hidden"
                                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (!file || !selectedId) return;
                                            const formData = new FormData();
                                            formData.append('content', `📎 Shared a file: ${file.name}`);
                                            formData.append('attachment', file);
                                            router.post(`/portal/clients/${client.id}/messages/${selectedId}`, { content: `📎 Shared: ${file.name}` }, { preserveScroll: true });
                                            e.target.value = '';
                                        }}
                                    />
                                    <button
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        onClick={() => document.getElementById('chat-file-upload')?.click()}
                                        title="Attach file"
                                    >
                                        <Paperclip className="h-4 w-4" />
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
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                Select a support worker from the left to start a conversation, or pick an existing chat.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
