import type { ResponseClock } from './chat-response-clock';

export type ChatPerson = { id: number; name: string; role?: string };
export type ChatMention = {
    user_id: number;
    start: number;
    length: number;
    label: string;
};
export type ChatConversation = {
    id: number;
    title: string;
    type: string;
    client: { id: number; name: string } | null;
    participants: ChatPerson[];
    mentionable_ids?: number[];
    latest_mention_id?: number | null;
    latest: { content: string; created_at: string } | null;
    unread: number;
    muted: boolean;
    favourite: boolean;
    can_send: boolean;
    can_call?: boolean;
    policy?: ChatPolicy;
    can_manage_people?: boolean;
    care_team_cover: boolean;
    audience_review_required?: boolean;
    audience_review_token?: string | null;
    queue_status: string;
    response_clock?: ResponseClock | null;
    assigned_to: number | null;
    version: number;
    archived: boolean;
    archived_for_me?: boolean;
    unread_marker?: string | null;
    management?: {
        owner_ids: number[];
        can_rename: boolean;
        can_remove: boolean;
        removable_ids?: number[];
        can_transfer: boolean;
        can_leave: boolean;
        must_transfer_to_leave: boolean;
        eligible_owner_ids: number[];
    };
};
// Formatted text (C2.6, docs/chat-experience/c2.6-message-model.md): rendered as elements, never as HTML.
export type ChatDocumentMark =
    | 'bold'
    | 'italic'
    | 'underline'
    | 'strike'
    | 'code';
export type ChatDocumentInline =
    | { type: 'text'; text: string; marks: ChatDocumentMark[] }
    | { type: 'mention'; user_id: number; label: string }
    | {
          type: 'link';
          href: string;
          content: { type: 'text'; text: string; marks: ChatDocumentMark[] }[];
      };
export type ChatDocumentBlock =
    | { type: 'paragraph'; content: ChatDocumentInline[] }
    | { type: 'list'; ordered: boolean; items: ChatDocumentBlock[][] }
    | { type: 'quote'; blocks: ChatDocumentBlock[] }
    | { type: 'code'; text: string };
export type ChatDocument = { v: 1; blocks: ChatDocumentBlock[] };
export type ChatMessage = {
    id: number;
    sender_id: number;
    sender_name: string;
    mine: boolean;
    content: string;
    kind?: 'text' | 'card' | 'poll' | 'sticker' | 'call' | 'system';
    // Null for plain text; content always holds the plain-text copy.
    document?: ChatDocument | null;
    deleted: boolean;
    pinned: boolean;
    // Who pinned it ("You" for the viewer) and when; null for unpinned messages.
    pinned_by_name?: string | null;
    pinned_at?: string | null;
    saved?: boolean;
    mentions?: ChatMention[];
    created_at: string;
    edited_at: string | null;
    edit_version?: number;
    can_edit?: boolean;
    can_remove?: boolean;
    change_until?: string | null;
    change_unavailable_reason?: string | null;
    reply_to_id: number | null;
    reply_unavailable?: boolean;
    reply?: {
        id: number;
        sender_name: string;
        content: string;
        deleted: boolean;
    } | null;
    mutation_id: string | null;
    attachments: {
        id: number;
        name: string;
        mime_type: string;
        size_bytes: number;
        caption?: string | null;
        media_kind?: 'file' | 'voice_note';
        duration_ms?: number | null;
        url: string;
    }[];
    legacy_attachments: {
        name: string;
        url: string | null;
    }[];
    // people: who reacted ("You" for the viewer), first 20, in the order they reacted.
    reactions: {
        emoji: string;
        count: number;
        mine: boolean;
        people?: string[];
    }[];
};
export type ChatPolicy = {
    enabled: boolean;
    family_enabled: boolean;
    files_enabled: boolean;
    voice_notes_enabled?: boolean;
    voice_notes_available?: boolean;
    draft_recovery_enabled?: boolean;
    max_voice_note_seconds?: number;
    max_file_mb: number;
    max_files: number;
    response_expectation: string;
};
export type ChatList = {
    conversations: ChatConversation[];
    people: ChatPerson[];
    settings: ChatPolicy;
    can_manage_channels?: boolean;
    sites?: { id: number; name: string }[];
};
export type ChatHistory = {
    conversation: ChatConversation;
    messages: ChatMessage[];
    has_more: boolean;
    sync_cursor?: string;
    has_more_changes?: boolean;
};

export type ChatChanges = {
    conversation: ChatConversation;
    messages: ChatMessage[];
    new_message_ids: number[];
    sync_cursor: string;
    has_more_changes: boolean;
};
