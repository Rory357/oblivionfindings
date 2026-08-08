import { Badge } from '@/components/ui/badge';
import { useMemo } from 'react';
import {
    Bar,
    BarChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type WeeklyHoursChartProps = {
    dailyHours: Record<string, number>;
    totalHours: number;
    weekStart?: string;
};

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export function WeeklyHoursChart({
    dailyHours,
    totalHours,
    weekStart,
}: WeeklyHoursChartProps) {
    const chartData = useMemo(() => {
        if (!weekStart) {
            // Build from keys directly
            return Object.entries(dailyHours).map(([date, hours]) => {
                const d = new Date(date);
                const dayIdx = (d.getDay() + 6) % 7; // Monday = 0
                return {
                    day: DAY_LABELS[dayIdx] ?? date.slice(-2),
                    hours: Number(hours) || 0,
                };
            });
        }

        // Fill all 7 days of the week
        const start = new Date(weekStart);
        return DAY_LABELS.map((label, i) => {
            const d = new Date(start);
            d.setDate(d.getDate() + i);
            const key = d.toISOString().slice(0, 10);
            return {
                day: label,
                hours: Number(dailyHours[key]) || 0,
            };
        });
    }, [dailyHours, weekStart]);

    const maxHours = Math.max(...chartData.map((d) => d.hours), 8);

    return (
        <div>
            <div className="mb-3 flex items-center justify-between">
                <span className="text-sm font-medium">This Week</span>
                <Badge variant="secondary" className="font-mono">
                    {totalHours.toFixed(1)}h
                </Badge>
            </div>

            <ResponsiveContainer width="100%" height={140}>
                <BarChart
                    data={chartData}
                    margin={{ top: 0, right: 0, bottom: 0, left: -24 }}
                >
                    <XAxis
                        dataKey="day"
                        axisLine={false}
                        tickLine={false}
                        tick={{
                            fontSize: 11,
                            fill: 'hsl(var(--muted-foreground))',
                        }}
                    />
                    <YAxis
                        domain={[0, maxHours]}
                        axisLine={false}
                        tickLine={false}
                        tick={{
                            fontSize: 10,
                            fill: 'hsl(var(--muted-foreground))',
                        }}
                        width={32}
                    />
                    <Tooltip
                        formatter={(value: any) => [
                            `${Number(value).toFixed(1)}h`,
                            'Hours',
                        ]}
                        contentStyle={{
                            backgroundColor: 'hsl(var(--card))',
                            border: '1px solid hsl(var(--border))',
                            borderRadius: '8px',
                            fontSize: '12px',
                        }}
                    />
                    <Bar
                        dataKey="hours"
                        fill="hsl(var(--primary))"
                        radius={[4, 4, 0, 0]}
                        maxBarSize={32}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
