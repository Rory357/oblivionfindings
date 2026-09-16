import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

export type CostCentre = {
    id: number;
    code: string;
    name: string;
    type: string | null;
    site_id: number | null;
    parent_id: number | null;
    is_active: boolean;
};

/**
 * Cost centre add/edit — three fields and a flag, so it is a simple dialog
 * (POPUP_STYLE_GUIDE "simple single-section form"), not a WizardShell. One
 * component covers both: `costCentre` prefills it and PUTs the update.
 */
export function CostCentreDialog({
    open,
    onClose,
    costCentre,
}: {
    open: boolean;
    onClose: () => void;
    costCentre?: CostCentre | null;
}) {
    const isEdit = !!costCentre;

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({
            code: costCentre?.code ?? '',
            name: costCentre?.name ?? '',
            type: costCentre?.type ?? '',
            is_active: costCentre?.is_active ?? true,
        });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => close() };
        if (isEdit && costCentre) {
            put(`/finance/cost-centres/${costCentre.id}`, options);
        } else {
            post('/finance/cost-centres', options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Edit cost centre' : 'New cost centre'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update this cost centre used for expense tracking and allocation.'
                            : 'Add a cost centre for tracking and allocating expenses.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="cc-code">Code</Label>
                            <Input
                                id="cc-code"
                                value={data.code}
                                onChange={(e) =>
                                    setData('code', e.target.value)
                                }
                                placeholder="e.g. CC001"
                                maxLength={20}
                                required
                            />
                            {errors.code ? (
                                <p className="text-sm text-destructive">
                                    {errors.code}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="cc-name">Name</Label>
                            <Input
                                id="cc-name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="e.g. Administration"
                                required
                            />
                            {errors.name ? (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="cc-type">Type</Label>
                        <Input
                            id="cc-type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            placeholder="e.g. Department, Site, Programme"
                        />
                        {errors.type ? (
                            <p className="text-sm text-destructive">
                                {errors.type}
                            </p>
                        ) : null}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        Active
                    </label>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save cost centre'
                                  : 'Create cost centre'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
