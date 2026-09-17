import { PageProps } from '@/types';
import { Banknote } from 'lucide-react';

import { AgedReport, type AgedBuckets } from './AgedReport';

interface AgedRow extends AgedBuckets {
    client_name: string;
}

interface Props extends PageProps {
    report: {
        rows: AgedRow[];
        grand_total: AgedBuckets;
    };
}

/**
 * Aged receivables — a thin wrapper over the shared `<AgedReport>` body
 * (the payables twin is identical apart from the entity label).
 */
export default function AgedReceivables({ report }: Props) {
    return (
        <AgedReport
            icon={Banknote}
            title="Aged receivables"
            entityLabel="Client"
            entityPlural="Clients"
            rows={report.rows.map((row) => ({ ...row, name: row.client_name }))}
            grandTotal={report.grand_total}
            ledgerHref="/finance/invoices"
            ledgerLabel="invoices"
        />
    );
}
