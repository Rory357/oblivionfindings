import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { Image } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Toggle } from '../connected/_forms';
import { RemotePicker, ReviewWizard, useCommand } from '../connected/_shared';
import type { Product } from './index';
type Binding = {
    medicine_version: number;
    can_manage: boolean;
    status: string;
    message?: string;
    product?: Product;
    source?: {
        source_name: string;
        source_version: string;
        attribution: string;
        expires_at: string;
    };
    binding?: { verified_at: string; reference: string };
};
export function MedicinePicture({ medicationId }: { medicationId: number }) {
    const [record, setRecord] = useState<Binding | null>(null);
    const [error, setError] = useState('');
    const [edit, setEdit] = useState(false);
    const [refresh, setRefresh] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        void axios
            .get<Binding>(
                '/emar/catalogue/medicines/' + medicationId + '/binding',
                { signal: controller.signal },
            )
            .then(({ data }) => setRecord(data))
            .catch((e) => {
                if (!axios.isCancel(e))
                    setError(
                        'The medicine picture could not be checked. Use the current chart and packaging.',
                    );
            });
        return () => controller.abort();
    }, [medicationId, refresh]);
    return (
        <>
            <ReviewCard icon={Image} title="Verified product picture">
                {error ? (
                    <SettingsNotice>{error}</SettingsNotice>
                ) : !record ? (
                    <p className="text-caption">Checking the image source…</p>
                ) : (
                    <div className="space-y-3">
                        {record.status === 'matched' &&
                        record.product?.photo_url ? (
                            <>
                                <img
                                    src={record.product.photo_url}
                                    alt={
                                        record.product.name +
                                        ' ' +
                                        record.product.strength +
                                        ' ' +
                                        record.product.form
                                    }
                                    className="max-h-48 max-w-full object-contain"
                                />
                                <ReviewRow
                                    label="Exact product"
                                    value={
                                        record.product.name +
                                        ' · ' +
                                        record.product.strength +
                                        ' · ' +
                                        record.product.form
                                    }
                                />
                                <ReviewRow
                                    label="Source"
                                    value={
                                        record.source?.source_name +
                                        ' · ' +
                                        record.source?.source_version
                                    }
                                />
                                <p className="text-caption">
                                    {record.source?.attribution} · review
                                    expires{' '}
                                    {record.source?.expires_at
                                        ? formatDateTime(
                                              record.source.expires_at,
                                          )
                                        : 'Unknown'}
                                </p>
                            </>
                        ) : (
                            <SettingsNotice>
                                {record.message ??
                                    'No current verified product image is linked. Check the chart and actual packaging.'}
                            </SettingsNotice>
                        )}
                        {record.can_manage && (
                            <Button
                                variant="outline"
                                onClick={() => setEdit(true)}
                            >
                                Review product link
                            </Button>
                        )}
                        <p className="text-caption">
                            Use the current chart and labelled packaging to
                            identify medicine. A picture alone does not confirm
                            a dose.
                        </p>
                    </div>
                )}
            </ReviewCard>
            {record && edit && (
                <BindingWizard
                    medicationId={medicationId}
                    record={record}
                    onClose={() => {
                        setEdit(false);
                        setError('');
                        setRefresh((n) => n + 1);
                    }}
                />
            )}
        </>
    );
}
function BindingWizard({
    medicationId,
    record,
    onClose,
}: {
    medicationId: number;
    record: Binding;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [product, setProduct] = useState<Product | null>(null);
    const [reference, setReference] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    return (
        <ReviewWizard
            title="Verify medicine product picture"
            description="Match the exact product and labelled packaging"
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/catalogue/medicines/' +
                            medicationId +
                            '/binding',
                        {
                            product_id: product?.id,
                            expected_medication_version:
                                record.medicine_version,
                            product_label_confirmed: confirmed,
                            reference,
                        },
                    )
                )
                    setSaved(true);
            }}
            steps={[
                {
                    label: 'Exact product',
                    valid: !!product && confirmed && !!reference,
                    content: (
                        <div className="space-y-4">
                            <RemotePicker<Product>
                                label="Reviewed product"
                                value={product ? String(product.id) : ''}
                                url={
                                    '/emar/catalogue/medicines/' +
                                    medicationId +
                                    '/products'
                                }
                                onChange={(_, p) => {
                                    setProduct(p);
                                    setConfirmed(false);
                                }}
                                rows={(d) => d.products as Product[]}
                                meta={(d) =>
                                    d.pagination as {
                                        current_page: number;
                                        last_page: number;
                                        total: number;
                                    }
                                }
                                option={(p) => ({
                                    id: String(p.id),
                                    label:
                                        p.name +
                                        ' · ' +
                                        p.strength +
                                        ' · ' +
                                        p.form,
                                    description: p.code_system + ' ' + p.code,
                                })}
                            />
                            {product?.photo_url && (
                                <img
                                    src={product.photo_url}
                                    alt={product.name + ' ' + product.strength}
                                    className="max-h-48 max-w-full object-contain"
                                />
                            )}
                            <Field
                                label="Packaging / verification evidence reference"
                                required
                            >
                                <Input
                                    value={reference}
                                    onChange={(e) =>
                                        setReference(e.target.value)
                                    }
                                />
                            </Field>
                            <Toggle
                                label="I checked the name, product strength, form and identifier against the packaging"
                                checked={confirmed}
                                onChange={setConfirmed}
                            />
                            <SettingsNotice>
                                The prescribed dose is not used to guess product
                                strength. A medicine change or expired source
                                removes this image until it is checked again.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Product', value: product?.name },
                { label: 'Product strength', value: product?.strength },
                { label: 'Form', value: product?.form },
                {
                    label: 'Identifier',
                    value: product
                        ? product.code_system + ' ' + product.code
                        : '',
                },
                { label: 'Evidence', value: reference },
            ]}
        />
    );
}
