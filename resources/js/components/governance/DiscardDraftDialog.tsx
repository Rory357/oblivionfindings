import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Dirty-close guard shared by the Governance register wizards.
 *
 * Only a brand-new record is a "draft" here — editing an existing record
 * (an approved budget, say) must never read like deleting the record, so
 * the default wording talks about discarding changes.
 */
export function DiscardDraftDialog({
    open,
    onKeepEditing,
    onDiscard,
    description,
    mode = 'edit',
}: {
    open: boolean;
    onKeepEditing: () => void;
    onDiscard: () => void;
    description: string;
    /** `create` while adding a new record; `edit` (default) for changes to an existing one. */
    mode?: 'create' | 'edit';
}) {
    const isCreate = mode === 'create';

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onKeepEditing()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                <DialogHeader>
                    <DialogTitle>
                        {isCreate
                            ? 'Discard this draft?'
                            : 'Discard your changes?'}
                    </DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onKeepEditing}>
                        Keep editing
                    </Button>
                    <Button variant="destructive" onClick={onDiscard}>
                        {isCreate ? 'Discard draft' : 'Discard changes'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
