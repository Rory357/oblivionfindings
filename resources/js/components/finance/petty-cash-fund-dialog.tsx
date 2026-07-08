import { useForm } from '@inertiajs/react';
import { Coins, ListChecks } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AmountField, formatMoney } from './money';
import type { AccountOption } from './new-bill-dialog';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type UserOption = { id: number; name: string };

/** Radix Select forbids empty-string item values — sentinel for “no custodian”. */
const NO_CUSTODIAN = 'none';

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Name, float & GL account', icon: Coins },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: ListChecks },
];

/**
 * Petty Cash Fund wizard — set up a new petty cash float as a stepper modal
 * (Details → Review). Posts to `finance.petty-cash.store` with the same payload
 * as the retired Create page (name, float_amount, gl_account_id,
 * custodian_user_id). On success the server redirects to the new fund's page,
 * so the modal simply closes and the flash toast confirms there.
 */
export function PettyCashFundDialog({
    open,
    onClose,
    accounts,
    users,
}: {
    open: boolean;
    onClose: () => void;
    /** Active asset GL accounts the float can post to. */
    accounts: AccountOption[];
    users: UserOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        name: string;
        float_amount: string;
        gl_account_id: string;
        custodian_user_id: string;
    }>({
        name: '',
        float_amount: '',
        gl_account_id: '',
        custodian_user_id: '',
    });
    const { data, setData, processing, errors } = form;

    const accountOptions = accounts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }));
    const custodianOptions = [
        { value: NO_CUSTODIAN, label: 'No custodian' },
        ...users.map((u) => ({ value: String(u.id), label: u.name })),
    ];

    const accountLabel = accountOptions.find((a) => a.value === data.gl_account_id)?.label ?? '—';
    const custodianLabel =
        data.custodian_user_id && data.custodian_user_id !== NO_CUSTODIAN
            ? users.find((u) => String(u.id) === data.custodian_user_id)?.name ?? '—'
            : 'None';

    const detailsValid =
        !!data.name.trim()
        && data.float_amount !== ''
        && Number(data.float_amount) > 0
        && !!data.gl_account_id;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            ...d,
            // sentinel/blank custodian → null (optional field)
            custodian_user_id: d.custodian_user_id && d.custodian_user_id !== NO_CUSTODIAN ? d.custodian_user_id : null,
        }));
        form.post('/finance/petty-cash', {
            preserveScroll: true,
            // Server redirects to the new fund's page; the modal just closes.
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New petty cash fund"
            description="Set up a new petty cash float"
            railIcon={Coins}
            railTitle="New Fund"
            railSub="Petty cash"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={detailsValid ? 100 : 40}
            pctLabel="Fund"
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button type="button" onClick={next} disabled={!detailsValid}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !detailsValid}>
                            Create fund
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={Coins} title="Fund details" blurb="Name the float, set its amount and where it posts." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Fund name" span required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Office Petty Cash"
                            />
                        </Field>
                        <Field label="Float amount (NZD)" required error={errors.float_amount}>
                            <AmountField
                                value={data.float_amount}
                                onValueChange={(v) => setData('float_amount', v)}
                                placeholder="200.00"
                                aria-label="Float amount"
                            />
                        </Field>
                        <Field label="GL account" required error={errors.gl_account_id}>
                            <SelectInput
                                value={data.gl_account_id}
                                onChange={(v) => setData('gl_account_id', v)}
                                placeholder="Select GL account"
                                options={accountOptions}
                            />
                        </Field>
                        <Field label="Custodian" span hint="optional" error={errors.custodian_user_id}>
                            <SelectInput
                                value={data.custodian_user_id}
                                onChange={(v) => setData('custodian_user_id', v)}
                                placeholder="Select custodian"
                                options={custodianOptions}
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={ListChecks} title="Review & create" blurb="Creates the fund and opens it so you can record transactions." />
                    <ReviewCard icon={Coins} title="Petty cash fund">
                        <ReviewRow label="Name" value={data.name || '—'} />
                        <ReviewRow label="Float" value={formatMoney(data.float_amount)} />
                        <ReviewRow label="GL account" value={accountLabel} />
                        <ReviewRow label="Custodian" value={custodianLabel} />
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">Creating…</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default PettyCashFundDialog;
