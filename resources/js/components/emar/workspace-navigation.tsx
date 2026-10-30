import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    type PageHeaderMeterTarget,
    type PageHeaderMeterTone,
} from '@/components/page/page-header';
import type { ComponentType, ReactNode } from 'react';

type View = {
    id: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
    badge?: ReactNode;
};

/** List scopes live inside the header; they are not another navigation tier. */
export function EmarViewFilter({
    value,
    onChange,
    items,
    label = 'View',
}: {
    value: string;
    onChange: (value: string) => void;
    items: View[];
    label?: string;
}) {
    return (
        <PageHeaderFilterSelect
            label={label}
            value={value}
            allValue={items[0]?.id}
            options={items.map((item) => ({
                value: item.id,
                label: `${item.label}${typeof item.badge === 'number' || typeof item.badge === 'string' ? ` · ${item.badge}` : ''}`,
            }))}
            onChange={onChange}
        />
    );
}

/** Individual records retain their sections on the page ground. */
export function EmarRecordTabs({
    value,
    onChange,
    items,
    ariaLabel,
}: {
    value: string;
    onChange: (value: string) => void;
    items: View[];
    ariaLabel: string;
}) {
    return (
        <TierTwoTabs
            activeTab={value}
            onTab={onChange}
            ariaLabel={ariaLabel}
            testIdPrefix="emar-record"
            tabs={items.map((item) => ({
                key: item.id,
                label: item.label,
                icon: item.icon,
                count: typeof item.badge === 'number' ? item.badge : undefined,
            }))}
            renderLink={(tab, className, inner, accessibility) => (
                <a href={tab.href} className={className} {...accessibility}>
                    {inner}
                </a>
            )}
        />
    );
}

export type EmarMeter = PageHeaderMeterTarget & {
    label: string;
    value: ReactNode;
    caption: ReactNode;
    tone?: PageHeaderMeterTone;
};

export function EmarMeters({ items }: { items: EmarMeter[] }) {
    return (
        <>
            {items.map(({ value, caption, ...item }) => (
                <PageHeaderMeterBlock key={item.label} {...item}>
                    <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ))}
        </>
    );
}
