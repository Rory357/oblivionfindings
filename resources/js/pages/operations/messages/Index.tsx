import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Hash, MessageSquareText, Plus, Search, Send, Users, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type User = { id: number; name: string; email: string };
type Message = {
    id: number;
    content: string;
    created_at: string;
    sender: { id: number; name: string } | null;
    message_type: string;
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
    // When a conversation is selected via show route
    conversation?: Conversation;
    messages?: { data: Message[] };
};

function getInitials(name: string): string {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}

function formatTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) return d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

function formatMessageTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}

function getConversationName(conv: Conversation, currentUserId: number): string {
    if (conv.title) return conv.title;
    if (conv.client) return `${conv.client.first_name} ${conv.client.last_name} Team`;
    if (conv.conversation_type === 'direct') {
        const other = conv.participants?.find(p => p.user?.id !== currentUserId);
        return other?.user?.name ?? 'Direct Message';
    }
    return conv.participants?.map(p => p.user?.name).filter(Boolean).join(', ') || 'Group Chat';
}

export default function MessagesChat({ conversations = [], users = [], currentUserId = 0, conversation, messages }: Props) {
    const { labels } = usePage().props as any;
    const [selectedId, setSelectedId] = useState<number | null>(conversation?.id ?? null);
    const [searchQuery, setSearchQuery] = useState('');
    const [showNewChat, setShowNewChat] = useState(false);
    const [newChatSearch, setNewChatSearch] = useState('');
    const [messageText, setMessageText] = useState('');
    const [activeMessages, setActiveMessages] = useState<Message[]>(messages?.data ?? []);
    const [activeConversation, setActiveConversation] = useState<Conversation | null>(conversation ?? null);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    // Scroll to bottom when messages change
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [activeMessages]);

    // Filter conversations
    const filteredConversations = conversations.filter(c => {
        if (!searchQuery) return true;
        const name = getConversationName(c, currentUserId).toLowerCase();
        return name.includes(searchQuery.toLowerCase());
    });

    // Filter users for new chat
    const filteredUsers = users.filter(u => {
        if (!newChatSearch) return true;
        return u.name.toLowerCase().includes(newChatSearch.toLowerCase()) || u.email.toLowerCase().includes(newChatSearch.toLowerCase());
    });

    const selectConversation = useCallback((conv: Conversation) => {
        setSelectedId(conv.id);
        setActiveConversation(conv);
        setShowNewChat(false);
        // Fetch messages
        router.get(`/operations/messages/${conv.id}`, {}, {
            preserveState: false,
            only: ['conversation', 'messages'],
        });
    }, []);

    const sendMessage = useCallback(() => {
        if (!messageText.trim() || !selectedId) return;
        router.post(`/operations/messages/${selectedId}`, { content: messageText }, {
            preserveScroll: true,
            onSuccess: () => {
                setMessageText('');
                inputRef.current?.focus();
            },
        });
    }, [messageText, selectedId]);

    const startNewChat = useCallback((userId: number) => {
        router.post('/operations/messages/create', { participant_ids: [userId] }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowNewChat(false);
                setNewChatSearch('');
            },
        });
    }, []);

    // Update messages when props change
    useEffect(() => {
        if (messages?.data) setActiveMessages(messages.data);
        if (conversation) {
            setActiveConversation(conversation);
            setSelectedId(conversation.id);
        }
    }, [messages, conversation]);

    return (
        <AppLayout>
            <Head title="Messages" />
            <div className="flex h-[calc(100vh-4rem)] overflow-hidden">
                {/* Left Panel - Conversation List */}
                <div className="flex w-80 flex-col border-r bg-background">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <h1 className="text-lg font-semibold">Chat</h1>
                        <Button size="sm" variant="ghost" className="h-8 w-8 p-0" onClick={() => { setShowNewChat(true); setSelectedId(null); }}>
                            <Plus className="h-4 w-4" />
                        </Button>
                    </div>

                    {/* Search */}
                    <div className="border-b px-3 py-2">
                        <div className="relative">
                            <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
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
                                <p className="text-sm text-muted-foreground">No conversations yet</p>
                                <Button size="sm" variant="outline" className="mt-2" onClick={() => setShowNewChat(true)}>
                                    Start a chat
                                </Button>
                            </div>
                        )}
                        {filteredConversations.map((conv) => {
                            const name = getConversationName(conv, currentUserId);
                            const isSelected = selectedId === conv.id;
                            return (
                                <button
                                    key={conv.id}
                                    className={`flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent ${isSelected ? 'bg-accent' : ''}`}
                                    onClick={() => selectConversation(conv)}
                                >
                                    <Avatar className="h-9 w-9 shrink-0">
                                        <AvatarFallback className={`text-xs ${conv.conversation_type === 'group' || conv.conversation_type === 'client_team' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-700'}`}>
                                            {conv.conversation_type === 'group' || conv.conversation_type === 'client_team' ? <Users className="h-4 w-4" /> : getInitials(name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center justify-between">
                                            <span className={`truncate text-sm ${conv.unread_count > 0 ? 'font-bold' : 'font-medium'}`}>{name}</span>
                                            {conv.latest_message && (
                                                <span className="ml-2 shrink-0 text-[10px] text-muted-foreground">{formatTime(conv.latest_message.created_at)}</span>
                                            )}
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <p className={`truncate text-xs ${conv.unread_count > 0 ? 'font-medium text-foreground' : 'text-muted-foreground'}`}>
                                                {conv.latest_message ? `${conv.latest_message.sender?.name?.split(' ')[0] ?? ''}: ${conv.latest_message.content}` : 'No messages yet'}
                                            </p>
                                            {conv.unread_count > 0 && (
                                                <Badge className="ml-1 h-5 min-w-[20px] shrink-0 px-1.5 text-[10px]">{conv.unread_count}</Badge>
                                            )}
                                        </div>
                                    </div>
                                </button>
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
                                <h2 className="text-sm font-semibold">New Conversation</h2>
                                <Button size="sm" variant="ghost" className="ml-auto h-7 w-7 p-0" onClick={() => setShowNewChat(false)}>
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                            <div className="border-b px-4 py-2">
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                    <Input
                                        placeholder="Search people..."
                                        className="h-9 pl-8 text-sm"
                                        value={newChatSearch}
                                        onChange={(e) => setNewChatSearch(e.target.value)}
                                        autoFocus
                                    />
                                </div>
                            </div>
                            <div className="flex-1 overflow-y-auto">
                                {filteredUsers.map((user) => (
                                    <button
                                        key={user.id}
                                        className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent"
                                        onClick={() => startNewChat(user.id)}
                                    >
                                        <Avatar className="h-9 w-9">
                                            <AvatarFallback className="bg-slate-100 text-xs text-slate-700">{getInitials(user.name)}</AvatarFallback>
                                        </Avatar>
                                        <div>
                                            <div className="text-sm font-medium">{user.name}</div>
                                            <div className="text-xs text-muted-foreground">{user.email}</div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : selectedId && activeConversation ? (
                        /* Active Chat */
                        <>
                            {/* Chat Header */}
                            <div className="flex items-center gap-3 border-b px-4 py-3">
                                <Avatar className="h-8 w-8">
                                    <AvatarFallback className="bg-indigo-100 text-xs text-indigo-700">
                                        {activeConversation.conversation_type === 'group' ? <Hash className="h-4 w-4" /> : getInitials(getConversationName(activeConversation, currentUserId))}
                                    </AvatarFallback>
                                </Avatar>
                                <div>
                                    <h2 className="text-sm font-semibold">{getConversationName(activeConversation, currentUserId)}</h2>
                                    <p className="text-xs text-muted-foreground">
                                        {activeConversation.participants?.length ?? 0} members
                                    </p>
                                </div>
                            </div>

                            {/* Messages Area */}
                            <div className="flex-1 overflow-y-auto px-4 py-4">
                                {activeMessages.length === 0 && (
                                    <div className="flex h-full flex-col items-center justify-center text-center">
                                        <MessageSquareText className="mb-3 h-12 w-12 text-muted-foreground/20" />
                                        <p className="text-sm text-muted-foreground">No messages yet. Say hello!</p>
                                    </div>
                                )}
                                {[...activeMessages].reverse().map((msg, idx, arr) => {
                                    const isMe = msg.sender?.id === currentUserId;
                                    const prevMsg = idx > 0 ? arr[idx - 1] : null;
                                    const showAvatar = !prevMsg || prevMsg.sender?.id !== msg.sender?.id;
                                    return (
                                        <div key={msg.id} className={`flex gap-2 ${isMe ? 'flex-row-reverse' : ''} ${showAvatar ? 'mt-4' : 'mt-0.5'}`}>
                                            {!isMe && showAvatar ? (
                                                <Avatar className="mt-1 h-7 w-7 shrink-0">
                                                    <AvatarFallback className="bg-slate-100 text-[10px] text-slate-600">{getInitials(msg.sender?.name ?? '?')}</AvatarFallback>
                                                </Avatar>
                                            ) : !isMe ? <div className="w-7 shrink-0" /> : null}
                                            <div className={`max-w-[70%] ${isMe ? 'items-end' : 'items-start'}`}>
                                                {showAvatar && !isMe && (
                                                    <p className="mb-0.5 text-[10px] font-medium text-muted-foreground">{msg.sender?.name}</p>
                                                )}
                                                <div className={`inline-block rounded-2xl px-3 py-2 text-sm ${isMe ? 'bg-indigo-600 text-white' : 'bg-muted'}`}>
                                                    {msg.content}
                                                </div>
                                                <p className={`mt-0.5 text-[10px] text-muted-foreground ${isMe ? 'text-right' : ''}`}>{formatMessageTime(msg.created_at)}</p>
                                            </div>
                                        </div>
                                    );
                                })}
                                <div ref={messagesEndRef} />
                            </div>

                            {/* Message Input */}
                            <div className="border-t px-4 py-3">
                                <div className="flex items-center gap-2">
                                    <Input
                                        ref={inputRef}
                                        placeholder="Type a message..."
                                        className="flex-1"
                                        value={messageText}
                                        onChange={(e) => setMessageText(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }}
                                        autoFocus
                                    />
                                    <Button size="sm" className="h-9 w-9 p-0" disabled={!messageText.trim()} onClick={sendMessage}>
                                        <Send className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </>
                    ) : (
                        /* No Chat Selected */
                        <div className="flex flex-1 flex-col items-center justify-center text-center">
                            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-900/30">
                                <MessageSquareText className="h-8 w-8 text-indigo-600 dark:text-indigo-400" />
                            </div>
                            <h2 className="mt-4 text-lg font-semibold">Welcome to Chat</h2>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                Select a conversation or start a new one to begin messaging your team.
                            </p>
                            <Button size="sm" className="mt-4 gap-1.5" onClick={() => setShowNewChat(true)}>
                                <Plus className="h-3.5 w-3.5" />
                                New Conversation
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
