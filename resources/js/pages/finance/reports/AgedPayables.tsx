import { PageProps } from '@/types';
import { Receipt } from 'lucide-react';

import { AgedReport, type AgedBuckets } from './AgedReport';

interface AgedRow extends AgedBuckets {
    vendor_name: string;
}

interface Props extends PageProps {
    report: {
        rows: AgedRow[];
        grand_total: AgedBuckets;
    };
}

/**
 * Aged payables — a thin wrapper over the shared `<AgedReport>` body
 * (the receivables twin is identical apart from the entity label).
 */
export default function AgedPayables({ report }: Props) {
    return (
        <AgedReport
            icon={Receipt}
            title="Aged payables"
            entityLabel="Vendor"
            entityPlural="Vendors"
            rows={report.rows.map((row) => ({ ...row, name: row.vendor_name }))}
            grandTotal={report.grand_total}
            ledgerHref="/finance/bills"
            ledgerLabel="bills"
        />
    );
}
