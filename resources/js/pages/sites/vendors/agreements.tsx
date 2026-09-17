import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { AgreementTable, type Agreement } from './_commercial';

export default function Renewals({
    agreements,
    ready,
}: {
    agreements: Agreement[];
    ready: boolean;
}) {
    const [search, setSearch] = useState('');
    const [tab, setTab] = useState('due');
    const counts = {
        due: agreements.filter((a) => a.followup?.status === 'due').length,
        active: agreements.filter((a) => a.status !== 'retired').length,
        retired: agreements.filter((a) => a.status === 'retired').length,
        all: agreements.length,
    };
    const shown = agreements.filter(
        (a) =>
            (tab === 'all' ||
                (tab === 'due'
                    ? a.followup?.status === 'due'
                    : tab === 'retired'
                      ? a.status === 'retired'
                      : a.status !== 'retired')) &&
            (a.title + ' ' + a.vendor_name)
                .toLowerCase()
                .includes(search.toLowerCase()),
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Vendors', href: '/vendors' },
                { title: 'Renewals', href: '/vendors/renewals' },
            ]}
        >
            <Head title="Commercial renewals" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={FileText}
                        title="Commercial renewals"
                        subline="One follow-up per agreement · approved Finance and Management scope"
                        actions={
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Find agreement or supplier"
                            />
                        }
                        meters={Object.entries(counts).map(([key, count]) => (
                            <PageHeaderMeterBlock
                                key={key}
                                label={key}
                                onClick={() => setTab(key)}
                            >
                                <PageHeaderMeterBig>{count}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Open this view
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                        rail={
                            <PageHeaderRail
                                value={tab}
                                onSelect={setTab}
                                items={Object.entries(counts).map(
                                    ([key, count]) => ({
                                        key,
                                        label:
                                            key === 'due'
                                                ? 'Due for review'
                                                : key,
                                        count,
                                    }),
                                )}
                            />
                        }
                    />
                }
            >
                {!ready ? (
                    <p role="status">
                        The reviewed commercial database update is required.
                    </p>
                ) : (
                    <div className="space-y-5">
                        <p className="text-subtle">
                            {shown.length} of {agreements.length} agreements
                            shown
                        </p>
                        <AgreementTable
                            agreements={shown}
                            open={(a) =>
                                router.visit(
                                    '/vendors/' +
                                        a.vendor_id +
                                        '?agreement=' +
                                        a.id,
                                )
                            }
                        />
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
