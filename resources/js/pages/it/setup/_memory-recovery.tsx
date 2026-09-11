import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { useState } from 'react';
import type { SetupProof, SetupWork, useSetupMemory } from './use-setup-memory';

export function SetupMemoryRecovery({
    memory,
    onResume,
}: {
    memory: ReturnType<typeof useSetupMemory>;
    onResume: (result: { work: SetupWork; proof: SetupProof }) => void;
}) {
    const [discard, setDiscard] = useState<string | null>(null);
    if (!memory.notices.length && !memory.warning && !memory.busy) return null;
    return (
        <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3 text-sm">
            {memory.notices.length > 0 && (
                <p>
                    Unsaved setup work is available in this browser. Resume
                    checks your current access before showing any entered
                    values.
                </p>
            )}
            {memory.warning && <p role="alert">{memory.warning}</p>}
            {memory.notices.map((notice, index) => (
                <div
                    key={notice.id}
                    className="flex flex-wrap items-center gap-2"
                >
                    <span>
                        Retained form {index + 1}
                        {notice.unknown ? ' · save not confirmed' : ''}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={memory.busy}
                        onClick={async () => {
                            const result = await memory.resume(notice.id);
                            if (result) onResume(result);
                        }}
                    >
                        Resume form {index + 1}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={memory.busy}
                        onClick={() => setDiscard(notice.id)}
                    >
                        Discard retained form {index + 1}
                    </Button>
                </div>
            ))}
            {memory.busy && (
                <div role="status">
                    Checking current access…{' '}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={memory.cancel}
                    >
                        Cancel wait
                    </Button>
                </div>
            )}
            <ConfirmDialog
                open={discard !== null}
                onClose={() => setDiscard(null)}
                title="Discard retained setup work?"
                description="This removes only this unsaved browser copy. It does not cancel an earlier server save."
                confirmText="Discard retained work"
                variant="destructive"
                onConfirm={() => {
                    if (discard) memory.discard(discard);
                    setDiscard(null);
                }}
            />
        </div>
    );
}
