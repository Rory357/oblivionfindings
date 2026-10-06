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
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export function MedicationTarget({ target }: { target: number | null }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ target: string | null }>({
        target: target === null ? '' : String(target),
    });
    return (
        <>
            <Button
                variant="outline"
                size="sm"
                onClick={() => {
                    form.setData(
                        'target',
                        target === null ? '' : String(target),
                    );
                    form.clearErrors();
                    setOpen(true);
                }}
            >
                {target === null
                    ? 'Set organisation target'
                    : 'Change organisation target'}
            </Button>
            <Dialog
                open={open}
                onOpenChange={(next) => !form.processing && setOpen(next)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Medication error target</DialogTitle>
                        <DialogDescription>
                            Monthly errors that reached the person. Near misses
                            are counted separately and have no RAG target.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                target: data.target === '' ? null : data.target,
                            }));
                            form.post(
                                '/governance/clinical/medication-target',
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                },
                            );
                        }}
                        className="space-y-4"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="medication-error-target">
                                Monthly target
                            </Label>
                            <Input
                                id="medication-error-target"
                                inputMode="numeric"
                                type="number"
                                min={0}
                                step={1}
                                value={form.data.target ?? ''}
                                onChange={(e) =>
                                    form.setData('target', e.target.value)
                                }
                                aria-invalid={Boolean(form.errors.target)}
                                aria-describedby="medication-target-help"
                            />
                            <p
                                id="medication-target-help"
                                className="text-subtle"
                            >
                                Leave blank for Not configured. Counts above a
                                configured target show Needs watching.
                            </p>
                            {form.errors.target && (
                                <p
                                    role="alert"
                                    className="text-subtle text-status-critical"
                                >
                                    {form.errors.target}
                                </p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Save target'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
