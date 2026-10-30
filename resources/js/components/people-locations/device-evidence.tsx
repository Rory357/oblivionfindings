import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Fragment } from 'react';
import { labels, time, type Sample } from './model';

export function DeviceEvidence({ samples }: { samples: Sample[] }) {
    const ordered = [...samples].sort(
        (a, b) => Date.parse(a.at) - Date.parse(b.at),
    );
    return (
        <Card>
            <CardHeader>
                <CardTitle>Power & movement observations</CardTitle>
                <CardDescription>
                    Recorded device reports · gaps over 30 minutes are shown
                    explicitly
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    className="max-h-96 overflow-auto"
                    tabIndex={0}
                    role="region"
                    aria-label="Recorded power and movement"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Observed at</TableHead>
                                <TableHead>Battery</TableHead>
                                <TableHead>Power</TableHead>
                                <TableHead>Movement</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ordered.map((sample, i) => (
                                <Fragment key={`${sample.at}-${i}`}>
                                    {i > 0 &&
                                        Date.parse(sample.at) -
                                            Date.parse(ordered[i - 1].at) >
                                            30 * 60_000 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="bg-muted text-muted-foreground"
                                                >
                                                    Observation gap ·{' '}
                                                    {time(ordered[i - 1].at)} to{' '}
                                                    {time(sample.at)}. No
                                                    movement or charging
                                                    duration inferred.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    <TableRow>
                                        <TableCell>{time(sample.at)}</TableCell>
                                        <TableCell>
                                            {sample.battery == null
                                                ? 'Unknown'
                                                : `${sample.battery}%`}
                                        </TableCell>
                                        <TableCell>
                                            {labels[sample.power] ??
                                                'Unknown / unavailable'}
                                        </TableCell>
                                        <TableCell>
                                            {labels[
                                                sample.motion ?? 'unknown'
                                            ] ?? 'Unknown / unavailable'}
                                        </TableCell>
                                    </TableRow>
                                </Fragment>
                            ))}
                            {!ordered.length && (
                                <TableRow>
                                    <TableCell colSpan={4}>
                                        No retained device observations in this
                                        selection.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}
