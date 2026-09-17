import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { router, usePage } from '@inertiajs/react';
import { LayoutGrid, List, Plus, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface SpecialistMeter {
    label: string;
    value: number;
    href: string;
}

export function SpecialistWorkspaceHeader({
    title,
    description,
    icon,
    path,
    query,
    filters,
    meters = [],
    createLabel,
    onCreate,
}: {
    title: string;
    description: string;
    icon: LucideIcon;
    path: string;
    query: string | null;
    filters: {
        key: string;
        label: string;
        value: string | null;
        options: { value: string; label: string }[];
    }[];
    meters?: SpecialistMeter[];
    createLabel: string;
    onCreate?: () => void;
}) {
    const { url } = usePage();
    const [search, setSearch] = useState(query ?? '');
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => {
        if (pending.current) clearTimeout(pending.current);
        setSearch(query ?? '');
        return () => {
            if (pending.current) clearTimeout(pending.current);
        };
    }, [url, query]);

    const navigate = (
        patch: Record<string, string | undefined>,
        replace = false,
    ) => {
        if (pending.current) clearTimeout(pending.current);
        const params = new URL(url, 'http://local.invalid').searchParams;
        if (Object.keys(patch).some((key) => key !== 'list_view'))
            params.delete('page');
        for (const [key, value] of Object.entries(patch)) {
            if (!value || value === 'all') params.delete(key);
            else params.set(key, value);
        }
        router.get(path, Object.fromEntries(params), {
            preserveState: true,
            preserveScroll: true,
            replace,
        });
    };

    return (
        <PageHeader
            className="overflow-clip!"
            icon={icon}
            title={title}
            subline={description}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        placeholder={`Search ${title.toLowerCase()}…`}
                        onChange={(value) => {
                            setSearch(value);
                            if (pending.current) clearTimeout(pending.current);
                            pending.current = setTimeout(
                                () =>
                                    navigate(
                                        { q: value.trim() || undefined },
                                        true,
                                    ),
                                350,
                            );
                        }}
                    />
                    {onCreate && (
                        <PageHeaderPrimaryButton icon={Plus} onClick={onCreate}>
                            {createLabel}
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                meters.length ? (
                    <>
                        {meters.map((meter) => (
                            <PageHeaderMeterBlock
                                key={meter.label}
                                label={meter.label}
                                href={meter.href}
                                ariaLabel={`View ${meter.label.toLowerCase()}`}
                            >
                                <PageHeaderMeterBig>
                                    {meter.value}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    All records within your access
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                    </>
                ) : undefined
            }
            filters={
                <>
                    <PageHeaderViewToggle
                        value={
                            new URL(
                                url,
                                'http://local.invalid',
                            ).searchParams.get('list_view') === 'cards'
                                ? 'cards'
                                : 'table'
                        }
                        onChange={(value) => navigate({ list_view: value })}
                        ariaLabel="List layout"
                        options={[
                            {
                                value: 'cards',
                                label: 'Cards',
                                icon: LayoutGrid,
                            },
                            { value: 'table', label: 'Table', icon: List },
                        ]}
                    />
                    {filters.map((filter) => (
                        <PageHeaderFilterSelect
                            key={filter.key}
                            label={filter.label}
                            value={filter.value ?? 'all'}
                            allValue="all"
                            options={filter.options}
                            onChange={(value) =>
                                navigate({
                                    [filter.key]: value,
                                    q: search.trim() || undefined,
                                })
                            }
                        />
                    ))}
                </>
            }
        />
    );
}
