import { Button } from '@/components/ui/button';
import { useState } from 'react';
import {
    SharedNavigationDesigner,
    type NavigationEditorSlots,
    type NavigationWorkspace,
} from './_navigation';

/** Tab authoring stays on the page, alongside its hero and content canvas. */
export function EditHubTabs({
    onClose,
    onPublished,
    editorSlots,
}: {
    onClose: () => void;
    onPublished: (workspace: NavigationWorkspace) => void;
    editorSlots?: NavigationEditorSlots;
}) {
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [confirmLeave, setConfirmLeave] = useState(false);
    const close = () => {
        if (busy) return;
        if (dirty) setConfirmLeave(true);
        else onClose();
    };
    return (
        <section
            aria-label="Edit Company Hub tabs"
            className="hub-inline-tab-editor"
        >
            <div className="flex items-center justify-between gap-4 border-b pb-3">
                <p className="text-sm text-muted-foreground">
                    Select a tab on the page to edit it. Use the panel to change
                    its destination or drag it into order.
                </p>
                <Button variant="outline" disabled={busy} onClick={close}>
                    Done editing tabs
                </Button>
            </div>
            <SharedNavigationDesigner
                onDirtyChange={setDirty}
                onBusyChange={setBusy}
                onPublished={onPublished}
                editorSlots={editorSlots}
            />
            {confirmLeave && (
                <div
                    role="region"
                    aria-label="Unsaved tab changes"
                    className="flex flex-wrap items-center gap-3 rounded-xl border border-status-warning/30 bg-status-warning/5 p-4"
                >
                    <div className="mr-auto">
                        <h3 className="font-semibold">
                            Leave without saving tab changes?
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Only unsaved tab edits will be discarded. Your page
                            content and saved navigation drafts stay in place.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => setConfirmLeave(false)}
                    >
                        Keep editing tabs
                    </Button>
                    <Button onClick={onClose}>Leave without saving</Button>
                </div>
            )}
        </section>
    );
}
