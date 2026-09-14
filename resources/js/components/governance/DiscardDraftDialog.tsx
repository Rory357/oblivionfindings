import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** Dirty-close guard shared by the Governance register wizards. */
export function DiscardDraftDialog({
    open,
    onKeepEditing,
    onDiscard,
    description,
}: {
    open: boolean;
    onKeepEditing: () => void;
    onDiscard: () => void;
    description: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onKeepEditing()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                <DialogHeader>
                    <DialogTitle>Discard this draft?</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onKeepEditing}>
                        Keep editing
                    </Button>
                    <Button variant="destructive" onClick={onDiscard}>
                        Discard
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
