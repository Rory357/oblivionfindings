import { useForm } from '@inertiajs/react';
import { AlertTriangle, Coins, ListChecks, PackageMinus } from 'lucide-react';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from './money';
import { PostingPreview, type PostingLine } from './posting-preview';
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    StepHead,
    useWizard,
    WizardShell,
    type WizardStep,
} from './wizard';

export type DisposableGlAccount = { code: string; name: string } | null;

/** The asset being disposed — everything the disposal journal preview needs. */
export type DisposableAsset = {
    id: number;
    asset_name: string;
    asset_tag: string | null;
    purchase_cost: string | number;
    accumulated_depreciation: string | number;
    /** Present only when a GL asset account is mapped — that's what makes disposal post a journal. */
    gl_asset_account: DisposableGlAccount;
    gl_depreciation_account: DisposableGlAccount;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Disposal',
        blurb: 'Date & proceeds',
        icon: Coins,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the journal',
        icon: ListChecks,
    },
];

const today = () => new Date().toISOString().split('T')[0];

/**
 * Dispose Fixed Asset wizard — records an asset disposal as a stepper modal
 * (Disposal → Review) and posts to `finance.fixed-assets.dispose`. When the
 * asset has a GL asset account mapped, disposal posts a balanced journal
 * (DR proceeds to Bank, DR accumulated depreciation, CR the asset at cost, and
 * DR/CR the gain or loss on disposal via 8100), so the review step shows a live
 * posting preview. This action can't be undone.
 */
export function FixedAssetDisposeDialog({
    open,
    onClose,
    asset,
}: {
    open: boolean;
    onClose: () => void;
    asset: DisposableAsset;
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{ disposed_date: string; disposal_proceeds: string }>({
        disposed_date: today(),
        disposal_proceeds: '',
    });
    const { data, setData, processing, errors } = form;

    const purchaseCost = Number(asset.purchase_cost) || 0;
    const accumulated = Number(asset.accumulated_depreciation) || 0;
    const bookValue = purchaseCost - accumulated;
    const proceeds = Number(data.disposal_proceeds) || 0;
    const gainLoss = proceeds - bookValue; // + = gain, - = loss

    // Mirror FixedAssetService::disposeAsset — only posts when a GL asset account exists.
    const disposalLines: PostingLine[] = useMemo(() => {
        if (!asset.gl_asset_account) return [];
        const lines: PostingLine[] = [];
        if (proceeds > 0) {
            lines.push({
                accountCode: '1000',
                accountName: 'Bank',
                debit: proceeds,
                memo: 'Disposal proceeds',
            });
        }
        if (accumulated > 0 && asset.gl_depreciation_account) {
            lines.push({
                accountCode: asset.gl_depreciation_account.code,
                accountName: asset.gl_depreciation_account.name,
                debit: accumulated,
                memo: 'Clear accumulated depreciation',
            });
        }
        lines.push({
            accountCode: asset.gl_asset_account.code,
            accountName: asset.gl_asset_account.name,
            credit: purchaseCost,
            memo: 'Remove asset at cost',
        });
        // Balancing gain/loss → 8400 Gain/Loss on Asset Disposal (config
        // finance.fixed_asset.gain_loss_account). balancing = cost - proceeds - accumulated.
        const balancing = purchaseCost - proceeds - accumulated;
        if (Math.round(balancing * 100) !== 0) {
            if (balancing > 0) {
                lines.push({
                    accountCode: '8400',
                    accountName: 'Gain/Loss on Asset Disposal',
                    debit: Math.abs(balancing),
                    memo: 'Loss on disposal',
                });
            } else {
                lines.push({
                    accountCode: '8400',
                    accountName: 'Gain/Loss on Asset Disposal',
                    credit: Math.abs(balancing),
                    memo: 'Gain on disposal',
                });
            }
        }
        return lines;
    }, [
        asset.gl_asset_account,
        asset.gl_depreciation_account,
        proceeds,
        accumulated,
        purchaseCost,
    ]);

    const detailsValid =
        !!data.disposed_date && data.disposal_proceeds !== '' && proceeds >= 0;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.post(`/finance/fixed-assets/${asset.id}/dispose`, {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    const generalError = (errors as Record<string, string | undefined>).general;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Dispose fixed asset"
            description={`Record the disposal of ${asset.asset_name}`}
            railIcon={PackageMinus}
            railTitle="Dispose Asset"
            railSub={
                asset.asset_tag ? `Tag ${asset.asset_tag}` : 'Fixed assets'
            }
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={detailsValid ? 100 : 40}
            pctLabel="Disposal"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    Book value{' '}
                    <span className="font-semibold text-foreground">
                        {formatMoney(bookValue)}
                    </span>
                </span>
            }
            footerEnd={
                <>
                    {!isFirst && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={back}
                            disabled={processing}
                        >
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button
                            type="button"
                            onClick={next}
                            disabled={!detailsValid}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={submit}
                            disabled={processing || !detailsValid}
                        >
                            Dispose asset
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={Coins}
                        title="Disposal details"
                        blurb="When it was disposed, and what you got for it."
                    />
                    {generalError && (
                        <div className="mb-4">
                            <InfoCard icon={AlertTriangle} tone="crit">
                                {generalError}
                            </InfoCard>
                        </div>
                    )}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Disposal date"
                            required
                            error={errors.disposed_date}
                        >
                            <Input
                                type="date"
                                value={data.disposed_date}
                                onChange={(e) =>
                                    setData('disposed_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Disposal proceeds (NZD)"
                            required
                            error={errors.disposal_proceeds}
                        >
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.disposal_proceeds}
                                onChange={(e) =>
                                    setData('disposal_proceeds', e.target.value)
                                }
                                placeholder="0.00"
                            />
                        </Field>
                    </div>
                    {/* eslint-disable-next-line no-restricted-syntax -- summary panel, not a content card */}
                    <div className="mt-4 space-y-1 rounded-xl border border-border bg-card/60 p-3 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">
                                Purchase cost
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(purchaseCost)}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">
                                Accumulated depreciation
                            </span>
                            <span className="tabular-nums">
                                -{formatMoney(accumulated)}
                            </span>
                        </div>
                        <div className="flex justify-between border-t pt-1 font-semibold">
                            <span>Book value</span>
                            <span className="tabular-nums">
                                {formatMoney(bookValue)}
                            </span>
                        </div>
                        {data.disposal_proceeds !== '' && (
                            <div className="flex justify-between pt-1">
                                <span className="text-muted-foreground">
                                    {gainLoss >= 0
                                        ? 'Gain on disposal'
                                        : 'Loss on disposal'}
                                </span>
                                <span
                                    className={`font-semibold tabular-nums ${gainLoss >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                >
                                    {formatMoney(Math.abs(gainLoss))}
                                </span>
                            </div>
                        )}
                    </div>
                    {!asset.gl_asset_account && (
                        <div className="mt-4">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                No GL asset account is mapped to this asset, so
                                disposal won't post a journal — it only marks
                                the asset disposed.
                            </InfoCard>
                        </div>
                    )}
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Review disposal"
                        blurb="This marks the asset disposed and posts the disposal journal. It can't be undone."
                    />
                    <ReviewCard icon={PackageMinus} title={asset.asset_name}>
                        <ReviewRow
                            label="Disposal date"
                            value={data.disposed_date}
                        />
                        <ReviewRow
                            label="Proceeds"
                            value={formatMoney(proceeds)}
                        />
                        <ReviewRow
                            label="Book value"
                            value={formatMoney(bookValue)}
                        />
                        <ReviewRow
                            label={
                                gainLoss >= 0
                                    ? 'Gain on disposal'
                                    : 'Loss on disposal'
                            }
                            value={formatMoney(Math.abs(gainLoss))}
                        />
                    </ReviewCard>
                    {disposalLines.length > 0 && (
                        <div className="mt-4">
                            <PostingPreview
                                lines={disposalLines}
                                title="Disposal journal preview"
                            />
                        </div>
                    )}
                    {processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">
                            Disposing…
                        </p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default FixedAssetDisposeDialog;
