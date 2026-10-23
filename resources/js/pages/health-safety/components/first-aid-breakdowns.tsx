import {
    BreakdownRows,
    ChartCard,
    FocusDonut,
    HorizontalBars,
    TOKEN,
} from '../analytics-charts';
import type { AnalyticsProps } from '../analytics-types';

const COLOURS = [TOKEN.c1, TOKEN.c2, TOKEN.c3, TOKEN.c4, TOKEN.c5, TOKEN.info];

export function FirstAidBreakdowns({
    data,
    onOpen,
}: {
    data: AnalyticsProps['first_aid_data'];
    onOpen: () => void;
}) {
    const permitted =
        data?.source_access !== 'restricted' &&
        data?.source_access !== 'unavailable';
    const types =
        permitted && data?.by_type != null
            ? data.by_type.map((row, index) => ({
                  key: row.type,
                  label: row.type.replace(/_/g, ' '),
                  value: row.count,
                  color: COLOURS[index % COLOURS.length],
              }))
            : null;
    const outcomes =
        permitted && data?.by_outcome != null
            ? data.by_outcome.map((row, index) => ({
                  key: row.outcome,
                  label: row.outcome.replace(/_/g, ' '),
                  value: row.count,
                  color: COLOURS[index % COLOURS.length],
              }))
            : null;
    const unavailable =
        data?.source_access === 'restricted'
            ? 'Access to first-aid breakdowns is restricted.'
            : 'First-aid breakdowns are unavailable.';
    const empty = 'No matching readable first-aid records in this period.';

    return (
        <>
            <ChartCard
                title="First aid by outcome"
                subtitle="Care activity · readable records in this period"
                aria="First aid by treatment outcome"
                table={
                    outcomes === null
                        ? undefined
                        : {
                              caption: 'First aid by outcome',
                              columns: ['Outcome', 'Count'],
                              rows: outcomes.map((row) => [
                                  row.label,
                                  row.value,
                              ]),
                          }
                }
            >
                {outcomes === null ? (
                    <p
                        role="status"
                        className="text-subtle text-muted-foreground"
                    >
                        {unavailable}
                    </p>
                ) : outcomes.length === 0 ? (
                    <p className="text-subtle text-muted-foreground">{empty}</p>
                ) : (
                    <FocusDonut segments={outcomes} onSegment={onOpen} />
                )}
            </ChartCard>
            <ChartCard
                title="First aid by type"
                subtitle="Open the First Aid register for treatment details"
                aria="First aid by injury or illness type"
                table={
                    types === null
                        ? undefined
                        : {
                              caption: 'First aid by type',
                              columns: ['Type', 'Count'],
                              rows: types.map((row) => [row.label, row.value]),
                          }
                }
            >
                {types === null ? (
                    <p
                        role="status"
                        className="text-subtle text-muted-foreground"
                    >
                        {unavailable}
                    </p>
                ) : types.length === 0 ? (
                    <p className="text-subtle text-muted-foreground">{empty}</p>
                ) : (
                    <>
                        <HorizontalBars data={types} />
                        <BreakdownRows items={types} onItem={onOpen} />
                    </>
                )}
            </ChartCard>
        </>
    );
}
