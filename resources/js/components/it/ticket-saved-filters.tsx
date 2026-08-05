import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { BookmarkPlus, SlidersHorizontal, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

export interface SavedTicketFilterRow {
    id: number;
    name: string;
}

interface Props {
    filters: SavedTicketFilterRow[];
    activeId: number | null;
    currentFilters: Record<string, string | number | boolean>;
    canSave: boolean;
    onApply: (id: number) => void;
}

export function TicketSavedFilters({
    filters,
    activeId,
    currentFilters,
    canSave,
    onApply,
}: Props) {
    const [saveOpen, setSaveOpen] = useState(false);
    const [name, setName] = useState('');
    const [nameError, setNameError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState<SavedTicketFilterRow | null>(null);
    const [deleteBusy, setDeleteBusy] = useState(false);

    useEffect(() => {
        if (!saveOpen) {
            setName('');
            setNameError(null);
        }
    }, [saveOpen]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        const cleanName = name.trim();
        if (!cleanName || !canSave) return;

        setSaving(true);
        setNameError(null);
        router.post(
            '/it/ticket-filters',
            { name: cleanName, filters: currentFilters },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Personal ticket filter saved.');
                    setSaveOpen(false);
                },
                onError: (errors) =>
                    setNameError(
                        String(
                            errors.name ??
                                errors.filters ??
                                'This filter could not be saved.',
                        ),
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    const destroy = () => {
        if (!deleting) return;

        setDeleteBusy(true);
        router.delete(`/it/ticket-filters/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Personal ticket filter deleted.');
                setDeleting(null);
            },
            onFinish: () => setDeleteBusy(false),
        });
    };

    return (
        <section
            aria-labelledby="personal-ticket-filters-title"
            className="rounded-lg border border-border/80 bg-muted/20 px-3 py-2.5"
        >
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3
                        id="personal-ticket-filters-title"
                        className="flex items-center gap-1.5 text-[12px] font-semibold text-foreground"
                    >
                        <SlidersHorizontal className="h-3.5 w-3.5" /> My saved
                        filters
                    </h3>
                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                        Personal to you and rechecked against your current Site
                        access whenever opened.
                    </p>
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={!canSave}
                    title={
                        canSave
                            ? undefined
                            : 'Choose at least one ticket filter first.'
                    }
                    onClick={() => setSaveOpen(true)}
                >
                    <BookmarkPlus className="h-3.5 w-3.5" /> Save current
                </Button>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                {filters.length === 0 ? (
                    <p className="text-[11px] text-muted-foreground">
                        No personal filters saved yet.
                    </p>
                ) : (
                    filters.map((savedFilter) => (
                        <div
                            key={savedFilter.id}
                            className="inline-flex items-center rounded-full border border-border bg-card"
                        >
                            {/* eslint-disable-next-line no-restricted-syntax -- connected filter pill needs a segmented custom shape. */}
                            <button
                                type="button"
                                aria-pressed={activeId === savedFilter.id}
                                onClick={() => onApply(savedFilter.id)}
                                className={
                                    activeId === savedFilter.id
                                        ? 'rounded-l-full bg-primary px-3 py-1 text-[12px] font-semibold text-primary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                                        : 'rounded-l-full px-3 py-1 text-[12px] font-medium text-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                                }
                            >
                                {savedFilter.name}
                            </button>
                            {/* eslint-disable-next-line no-restricted-syntax -- connected filter pill needs a segmented custom shape. */}
                            <button
                                type="button"
                                aria-label={`Delete saved filter ${savedFilter.name}`}
                                onClick={() => setDeleting(savedFilter)}
                                className="rounded-r-full border-l border-border px-2 py-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <Trash2 className="h-3 w-3" />
                            </button>
                        </div>
                    ))
                )}
            </div>

            <Dialog open={saveOpen} onOpenChange={setSaveOpen}>
                <DialogContent>
                    <form onSubmit={save}>
                        <DialogHeader>
                            <DialogTitle>Save this ticket filter</DialogTitle>
                            <DialogDescription>
                                Save the current queue filters for your own use.
                                Site and staff choices are checked again every
                                time you open it.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="mt-5 space-y-2">
                            <Label htmlFor="saved-ticket-filter-name">
                                Filter name
                            </Label>
                            <Input
                                id="saved-ticket-filter-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                maxLength={80}
                                autoFocus
                                aria-invalid={nameError ? true : undefined}
                                aria-describedby={
                                    nameError
                                        ? 'saved-ticket-filter-error'
                                        : undefined
                                }
                                placeholder="For example: Urgent network tickets"
                            />
                            {nameError ? (
                                <p
                                    id="saved-ticket-filter-error"
                                    className="text-sm text-destructive"
                                >
                                    {nameError}
                                </p>
                            ) : null}
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSaveOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={saving || name.trim() === ''}
                            >
                                <BookmarkPlus className="h-4 w-4" />
                                {saving ? 'Saving…' : 'Save filter'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open && !deleteBusy) setDeleting(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete “{deleting?.name}”?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This removes only your personal shortcut. It does
                            not change any tickets or the predefined queue
                            views.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleteBusy}>
                            Keep filter
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={deleteBusy}
                            onClick={destroy}
                        >
                            {deleteBusy ? 'Deleting…' : 'Delete filter'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
