import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ChatWorkspace } from './chat-workspace';

export function FamilyChatDrawer({
    open,
    onClose,
    clientId,
    clientName,
}: {
    open: boolean;
    onClose: () => void;
    clientId: number;
    clientName: string;
}) {
    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <SheetContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
                className="flex flex-col gap-3 p-3"
            >
                <SheetHeader className="pr-8">
                    <SheetTitle>Family Chat</SheetTitle>
                    <SheetDescription>
                        {clientName} · Choose a conversation to see its
                        audience.
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1">
                    <ChatWorkspace
                        client={{ id: clientId, name: clientName }}
                        compact
                    />
                </div>
            </SheetContent>
        </Sheet>
    );
}
