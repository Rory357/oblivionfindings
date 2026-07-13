import {
    AddClientDialog,
    type AddClientDialogProps,
    type ClientWizardForm,
} from '@/components/clients/add-client-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

type EditPayload = {
    initialValues: Partial<ClientWizardForm>;
    sites: AddClientDialogProps['sites'];
    serviceContexts: AddClientDialogProps['serviceContexts'];
    keyWorkers: AddClientDialogProps['keyWorkers'];
    geofences: AddClientDialogProps['geofences'];
    defaultServiceContextId?: number | null;
};

/**
 * Compatibility adapter for existing profile-edit callers. The form itself is
 * the canonical Add Client completion wizard; this component only hydrates it.
 */
export function ClientEditDialog({
    clientId,
    open,
    onOpenChange,
}: {
    clientId: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    siteSingular?: string;
}) {
    const [payload, setPayload] = useState<EditPayload | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!open || !clientId) {
            if (!open) {
                setPayload(null);
                setError(null);
            }
            return;
        }

        const controller = new AbortController();
        setPayload(null);
        setError(null);

        fetch(`/operations/clients/${clientId}/edit?modal=1`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(
                        'The complete profile could not be loaded.',
                    );
                }
                return (await response.json()) as EditPayload;
            })
            .then(setPayload)
            .catch((reason: unknown) => {
                if (
                    reason instanceof DOMException &&
                    reason.name === 'AbortError'
                ) {
                    return;
                }
                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'The complete profile could not be loaded.',
                );
            });

        return () => controller.abort();
    }, [attempt, clientId, open]);

    if (!open) return null;

    if (payload && clientId) {
        return (
            <AddClientDialog
                isOpen
                onClose={() => onOpenChange(false)}
                clientId={clientId}
                initialValues={payload.initialValues}
                sites={payload.sites}
                serviceContexts={payload.serviceContexts}
                keyWorkers={payload.keyWorkers}
                geofences={payload.geofences}
                defaultServiceContextId={payload.defaultServiceContextId}
                onSaved={() =>
                    router.reload({
                        preserveScroll: true,
                        preserveState: true,
                    })
                }
            />
        );
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Complete profile</DialogTitle>
                    <DialogDescription>
                        Load the full client record before making changes.
                    </DialogDescription>
                </DialogHeader>

                {error ? (
                    <div className="flex gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                        <p>{error}</p>
                    </div>
                ) : (
                    <div className="flex items-center justify-center gap-3 py-10 text-sm text-muted-foreground">
                        <Loader2 className="h-5 w-5 animate-spin" />
                        Loading the complete profile…
                    </div>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    {error ? (
                        <Button
                            type="button"
                            onClick={() => setAttempt((n) => n + 1)}
                        >
                            Try again
                        </Button>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
