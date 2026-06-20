/**
 * Reusable multi-step wizard engine for the PPE register — a faithful, factored
 * reproduction of the Add-Client dialog chrome (resources/js/components/clients/
 * add-client-dialog.tsx) so every PPE create/edit wizard is pixel-identical in
 * style and workflow: 248px stepper rail, "Step i of N" header, 3px progress bar,
 * per-step validation that blocks Continue, jump-to-first-failure on submit,
 * Save-&-add-another, and a success pane. Compose `@/components/wizard/primitives`
 * for every field. Semantic tokens only.
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    WIZARD_FOOTER_CLASS,
    type IconType,
} from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { type FormDataType } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Plus, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';

export type WizardCtx<D> = {
    data: D;
    set: <K extends keyof D>(key: K, value: D[K]) => void;
    errors: Record<string, string>;
};

export type WizardStepDef<D> = {
    key: string;
    label: string;
    blurb: string;
    icon: IconType;
    /** Field names owned by this step — used to route a server error back to its step. */
    fields?: string[];
    /** Per-step client validation mirroring the server FormRequest. */
    validate?: (data: D) => Record<string, string>;
    render: (ctx: WizardCtx<D>) => ReactNode;
};

type WizardProps<D extends FormDataType<D>> = {
    open: boolean;
    onClose: () => void;
    onSaved?: () => void;
    icon: IconType;
    title: string;
    subtitle: string;
    edit?: boolean;
    steps: WizardStepDef<D>[];
    initial: D;
    endpoint: string | ((data: D) => string);
    method?: 'post' | 'put';
    addAnother?: boolean;
    submitLabel: string;
    savedTitle: (data: D) => string;
    transform?: (data: D) => Record<string, unknown>;
};

export function PpeWizard<D extends FormDataType<D>>(props: WizardProps<D>) {
    const { open, onClose, title, subtitle } = props;
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{
                    maxWidth: 'min(94vw, 1080px)',
                    width: 'min(94vw, 1080px)',
                }}
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <DialogDescription className="sr-only">
                    {subtitle}
                </DialogDescription>
                {open ? <WizardBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function WizardBody<D extends FormDataType<D>>({
    onClose,
    onSaved,
    icon: Icon,
    title,
    edit = false,
    steps,
    initial,
    endpoint,
    method = 'post',
    addAnother = false,
    submitLabel,
    savedTitle,
    transform,
}: WizardProps<D>) {
    const form = useForm<D>(initial);
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const cur = steps[stepIndex];
    const isReview = stepIndex === steps.length - 1;
    const pct = Math.round(((stepIndex + 1) / steps.length) * 100);

    const set = <K extends keyof D>(key: K, value: D[K]) => {
        (setData as (k: string, v: unknown) => void)(key as string, value);
        setErrors((e) => {
            if (!e[key as string]) return e;
            const next = { ...e };
            delete next[key as string];
            return next;
        });
    };

    const stepForField = (field: string): number => {
        const i = steps.findIndex((s) =>
            (s.fields ?? []).some(
                (f) => field === f || field.startsWith(`${f}.`),
            ),
        );
        return i >= 0 ? i : 0;
    };

    const goStep = (i: number) =>
        setStepIndex(Math.max(0, Math.min(i, steps.length - 1)));

    const next = () => {
        const e = cur.validate ? cur.validate(data) : {};
        setErrors(e);
        if (!Object.keys(e).length) goStep(stepIndex + 1);
    };

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (again: boolean) => {
        const all: Record<string, string> = {};
        for (const s of steps)
            Object.assign(all, s.validate ? s.validate(data) : {});
        if (Object.keys(all).length) {
            setErrors(all);
            goStep(stepForField(Object.keys(all)[0]));
            return;
        }
        setErrors({});

        const url = typeof endpoint === 'function' ? endpoint(data) : endpoint;
        const opts = {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved?.();
                if (again) resetAll();
                else setDone(true);
            },
            onError: (errs: Record<string, string>) => {
                setErrors(errs);
                const first = Object.keys(errs)[0];
                if (first) goStep(stepForField(first));
            },
        };

        form.transform((d) => {
            const shaped = transform ? transform(d as D) : d;
            return (
                method === 'put' ? { ...shaped, _method: 'put' } : shaped
            ) as never;
        });
        form.post(url, opts);
    };

    if (done) {
        return (
            <WizardSuccess
                icon={Icon}
                title={edit ? 'Changes saved' : `${title} created`}
                body={
                    <>
                        <span className="font-semibold text-foreground">
                            {savedTitle(data)}
                        </span>{' '}
                        {edit
                            ? 'has been updated.'
                            : 'has been added to the register.'}
                    </>
                }
                canAddAnother={addAnother && !edit}
                onAddAnother={resetAll}
                onClose={onClose}
            />
        );
    }

    const mergedErrors = { ...form.errors, ...errors } as Record<
        string,
        string
    >;

    return (
        <div className="flex h-[min(88vh,820px)]">
            {/* ── Stepper rail ── */}
            <aside className="hidden w-[248px] shrink-0 flex-col overflow-y-auto border-r border-sidebar-border bg-sidebar p-4 sm:flex">
                <div className="mb-4 flex items-center gap-2.5">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] bg-primary text-primary-foreground">
                        <Icon className="h-[18px] w-[18px]" />
                    </span>
                    <div className="min-w-0">
                        <div className="truncate text-[13.5px] leading-tight font-bold">
                            {title}
                        </div>
                        <div className="truncate text-[11px] text-muted-foreground">
                            {edit ? 'Edit' : 'New entry'}
                        </div>
                    </div>
                </div>

                <div className="flex flex-col gap-0.5">
                    {steps.map((s, i) => {
                        const active = i === stepIndex;
                        const complete = i < stepIndex;
                        const StepIcon = s.icon;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- wizard rail step nav item, custom layout
                            <button
                                key={s.key}
                                type="button"
                                onClick={() => goStep(i)}
                                className={cn(
                                    'flex items-center gap-2.5 rounded-lg px-2 py-2 text-left transition-colors',
                                    active ? 'bg-accent' : 'hover:bg-muted/60',
                                )}
                            >
                                <span
                                    className={cn(
                                        'grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full text-[12px] font-bold',
                                        active
                                            ? 'bg-primary text-primary-foreground'
                                            : complete
                                              ? 'bg-status-success-bg text-status-success'
                                              : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {complete ? (
                                        <Check className="h-3.5 w-3.5" />
                                    ) : (
                                        <StepIcon className="h-3.5 w-3.5" />
                                    )}
                                </span>
                                <span className="min-w-0">
                                    <span
                                        className={cn(
                                            'block truncate text-[12.5px]',
                                            active
                                                ? 'font-bold'
                                                : 'font-semibold',
                                        )}
                                    >
                                        {s.label}
                                    </span>
                                    <span className="block truncate text-[10.5px] text-muted-foreground">
                                        {s.blurb}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="mt-auto pt-4">
                    <div className="mb-1 flex items-center justify-between text-[11px] font-semibold text-muted-foreground">
                        <span>Progress</span>
                        <span className="text-primary">{pct}%</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary transition-[width] duration-300"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                </div>
            </aside>

            {/* ── Main column ── */}
            <div className="flex min-w-0 flex-1 flex-col">
                <div className="flex items-center justify-between border-b border-border px-6 py-3.5">
                    <div className="text-[13px]">
                        <span className="text-muted-foreground">
                            Step {stepIndex + 1} of {steps.length} ·{' '}
                        </span>
                        <span className="font-semibold text-foreground">
                            {cur.label}
                        </span>
                    </div>
                    {/* eslint-disable-next-line no-restricted-syntax -- wizard header close affordance */}
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="grid h-8 w-8 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="h-[3px] shrink-0 bg-muted">
                    <div
                        className="h-full bg-primary transition-[width] duration-300"
                        style={{ width: `${pct}%` }}
                    />
                </div>

                <div
                    key={cur.key}
                    className="min-h-0 flex-1 animate-in overflow-x-hidden overflow-y-auto px-6 py-6 duration-200 fade-in slide-in-from-right-1 motion-reduce:animate-none"
                >
                    {cur.render({ data, set, errors: mergedErrors })}
                </div>

                <div className={WIZARD_FOOTER_CLASS}>
                    <div>
                        {stepIndex > 0 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => goStep(stepIndex - 1)}
                            >
                                <ChevronLeft className="mr-1 h-4 w-4" /> Back
                            </Button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <>
                                {addAnother && !edit ? (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={processing}
                                        onClick={() => submit(true)}
                                    >
                                        <Plus className="mr-1 h-4 w-4" /> Save
                                        &amp; add another
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    disabled={processing}
                                    onClick={() => submit(false)}
                                >
                                    <Check className="mr-1 h-4 w-4" />{' '}
                                    {edit ? 'Save changes' : submitLabel}
                                </Button>
                            </>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue{' '}
                                <ChevronRight className="ml-1 h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function WizardSuccess({
    icon: Icon,
    title,
    body,
    canAddAnother,
    onAddAnother,
    onClose,
}: {
    icon: IconType;
    title: string;
    body: ReactNode;
    canAddAnother: boolean;
    onAddAnother: () => void;
    onClose: () => void;
}) {
    return (
        <div className="flex min-h-[420px] animate-in flex-col items-center justify-center gap-4 px-8 py-12 text-center duration-200 zoom-in-95 fade-in motion-reduce:animate-none">
            <span className="grid h-[72px] w-[72px] place-items-center rounded-full bg-status-success-bg text-status-success">
                <Check className="h-9 w-9" />
            </span>
            <div>
                <h2 className="text-xl font-bold tracking-tight">{title}</h2>
                <p className="mx-auto mt-1.5 max-w-sm text-sm text-muted-foreground">
                    {body}
                </p>
            </div>
            <div className="mt-2 flex items-center gap-2">
                {canAddAnother ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onAddAnother}
                    >
                        <Plus className="mr-1 h-4 w-4" /> Add another
                    </Button>
                ) : null}
                <Button type="button" onClick={onClose}>
                    Done
                </Button>
            </div>
            <span className="sr-only">
                <Icon className="h-4 w-4" />
            </span>
        </div>
    );
}
