import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { useMemo, useState } from 'react';
import type {
    CatalogFieldOptions,
    CatalogItem,
} from './catalogue-request-fields';
import { CatalogueRequestWizard } from './catalogue-request-wizard';
export type {
    CatalogEntityFieldType,
    CatalogField,
    CatalogFieldOption,
    CatalogFieldOptions,
    CatalogItem,
} from './catalogue-request-fields';

interface Props {
    actorId: number;
    items: CatalogItem[];
    fieldOptions: CatalogFieldOptions;
    query: string;
    category: string | null;
    draftRecoveryEnabled?: boolean;
}
const humanize = (value: string) =>
    value.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());
export function ItServiceCatalogue(props: Props) {
    return <CatalogueRequestView key={props.actorId} {...props} />;
}
function CatalogueRequestView({
    actorId,
    items,
    fieldOptions,
    query,
    category,
    draftRecoveryEnabled = false,
}: Props) {
    const [selected, setSelected] = useState<CatalogItem | null>(null);
    const [concealed, setConcealed] = useState(false);
    const open = (item: CatalogItem) => setSelected(item);
    const filtered = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase();
        return items.filter(
            (item) =>
                (category === null || item.category === category) &&
                (!needle ||
                    [
                        item.name,
                        item.description,
                        item.category,
                        item.outcome_type,
                    ].some((value) =>
                        value?.toLocaleLowerCase().includes(needle),
                    )),
        );
    }, [items, query, category]);
    return (
        <section
            className="space-y-4"
            aria-labelledby="service-catalogue-title"
        >
            <h2 id="service-catalogue-title" className="sr-only">
                Service catalogue
            </h2>

            {!concealed &&
                (filtered.length ? (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {filtered.map((item) => (
                            <article
                                key={item.id}
                                className="flex min-h-56 flex-col rounded-2xl border border-border bg-card p-5 shadow-sm"
                            >
                                <div className="flex flex-wrap gap-2">
                                    <StatusBadge variant="info" size="sm">
                                        {humanize(item.outcome_type)}
                                    </StatusBadge>
                                    <StatusBadge variant="neutral" size="sm">
                                        {humanize(item.category)}
                                    </StatusBadge>
                                    {item.requires_approval ? (
                                        <StatusBadge
                                            variant="warning"
                                            size="sm"
                                        >
                                            Approval required
                                        </StatusBadge>
                                    ) : null}
                                </div>
                                <h3 className="mt-4 font-semibold">
                                    {item.name}
                                </h3>
                                <p className="mt-1 flex-1 text-sm leading-relaxed text-muted-foreground">
                                    {item.description ??
                                        'Use this form to start the supported workflow.'}
                                </p>
                                <Button
                                    className="mt-5 min-h-11 w-full"
                                    onClick={() => open(item)}
                                >
                                    {item.name}
                                </Button>
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-2xl border border-dashed border-border bg-card px-6 py-14 text-center">
                        <p className="font-semibold">No matching requests</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Try a system name or a broader description of what
                            you need.
                        </p>
                    </div>
                ))}

            {selected && (
                <CatalogueRequestWizard
                    key={`${actorId}:${selected.id}:${selected.form_schema_version}`}
                    actorId={actorId}
                    item={selected}
                    fieldOptions={fieldOptions}
                    draftRecoveryEnabled={draftRecoveryEnabled}
                    onClose={() => setSelected(null)}
                    onPrivateHidden={() => setConcealed(true)}
                />
            )}
        </section>
    );
}
