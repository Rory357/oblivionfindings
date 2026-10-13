import type { RecordDoseMeters } from '@/components/emar/record/types';
import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
} from '@/components/page/page-header';
import { formatTime } from '@/lib/datetime';

/**
 * EA-110 (Lane C9): the approved P02 record header's Due now, Late and
 * Recorded today, from the same schedule as Meds today. "n/a" when nothing
 * is on the person's chart today; "Unavailable" when the day's dose history
 * can't be read — never a misleading zero.
 */
export function DoseMeters({
    doses,
    onChart,
    onHistory,
}: {
    doses: RecordDoseMeters | null;
    onChart: () => void;
    onHistory: () => void;
}) {
    const unavailable = doses === null;
    const none = doses !== null && doses.scheduled === 0;
    const donut = doses !== null && !none && doses.so_far > 0;
    return (
        <>
            <PageHeaderMeterBlock
                label="Due now"
                onClick={onChart}
                ariaLabel="View doses due now on the chart"
            >
                <PageHeaderMeterBig>
                    {unavailable ? 'Unavailable' : none ? 'n/a' : doses.due}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {unavailable
                        ? 'Today’s dose history can’t be read'
                        : doses.due && doses.due_until
                          ? `Until ${formatTime(doses.due_until)}`
                          : 'Nothing due right now'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Late"
                tone={doses && doses.late ? 'warning' : 'brand'}
                onClick={onChart}
                ariaLabel="View late doses on the chart"
            >
                <PageHeaderMeterBig>
                    {unavailable ? '—' : none ? 'n/a' : doses.late}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {unavailable
                        ? '—'
                        : doses.late && doses.oldest_late
                          ? `Oldest due ${formatTime(doses.oldest_late)}`
                          : 'None late'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Recorded today"
                value={donut ? `${doses.recorded}/${doses.so_far}` : undefined}
                onClick={onHistory}
                ariaLabel="View today’s recorded doses in History"
            >
                {donut ? (
                    <PageHeaderMeterDonut
                        percent={(100 * doses.recorded) / doses.so_far}
                        caption="of doses due so far"
                    />
                ) : (
                    <>
                        <PageHeaderMeterBig>
                            {unavailable ? '—' : 'n/a'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {unavailable ? '—' : 'No doses due yet'}
                        </PageHeaderMeterCaption>
                    </>
                )}
            </PageHeaderMeterBlock>
        </>
    );
}
