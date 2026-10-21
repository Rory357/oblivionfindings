import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Link } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { chatRequest } from './api';
import { useChatCalling } from './chat-calling';
import { ChatWorkspace } from './chat-workspace';

/** Keeps the current work mounted and shares no clinical context automatically. */
export function ChatLauncher({
    personId,
    label = 'Chat',
    outline = false,
}: {
    personId?: number;
    label?: string;
    outline?: boolean;
}) {
    const calling = useChatCalling();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [conversation, setConversation] = useState<number | null>(null);
    const mutation = useRef<string | null>(null);
    const currentPerson = useRef(personId);
    currentPerson.current = personId;
    useEffect(() => {
        mutation.current = null;
        setConversation(null);
        setOpen(false);
        setBusy(false);
    }, [personId]);
    if (!calling?.enabled)
        return (
            <Button variant={outline ? 'outline' : 'default'} asChild>
                <Link href="/operations/messages">
                    <MessageSquare className="size-4" />
                    {label}
                </Link>
            </Button>
        );
    async function launch() {
        if (!personId) {
            setOpen(true);
            return;
        }
        setBusy(true);
        mutation.current ??= crypto.randomUUID();
        try {
            const result = await chatRequest<{ conversation: { id: number } }>(
                '/chat/api/conversations',
                {
                    method: 'POST',
                    body: JSON.stringify({
                        participant_ids: [personId],
                        mutation_id: mutation.current,
                    }),
                },
            );
            if (currentPerson.current !== personId) return;
            setConversation(result.conversation.id);
            setOpen(true);
            mutation.current = null;
        } catch (error) {
            if (currentPerson.current === personId)
                toast.error((error as Error).message);
        } finally {
            if (currentPerson.current === personId) setBusy(false);
        }
    }
    return (
        <>
            <Button
                type="button"
                variant={outline ? 'outline' : 'default'}
                disabled={busy}
                onClick={() => void launch()}
            >
                <MessageSquare className="size-4" />
                {busy ? 'Opening Chat…' : label}
            </Button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent
                    style={{
                        maxWidth: 'min(92vw, 720px)',
                        width: 'min(92vw, 720px)',
                    }}
                    className="flex flex-col gap-3 p-3"
                >
                    <SheetHeader className="pr-8">
                        <SheetTitle>{label}</SheetTitle>
                        <SheetDescription>
                            Your current work stays open. No clinical details
                            are shared automatically.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1">
                        <ChatWorkspace
                            compact
                            initialConversation={conversation}
                        />
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
