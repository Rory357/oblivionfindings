import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Clock, Mail } from 'lucide-react';

interface CandidateCardProps {
    id: number;
    name: string;
    position: string;
    daysInStage: number;
    source: string;
    email?: string;
}

function getDaysColor(days: number) {
    if (days <= 7) return 'text-green-500';
    if (days <= 14) return 'text-amber-500';
    return 'text-red-500';
}

function getInitials(name: string) {
    const parts = name.split(' ');
    return (parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '');
}

export function CandidateCard({ id, name, position, daysInStage, source, email }: CandidateCardProps) {
    return (
        <Link href={`/hr/recruitment/candidates/${id}`} className="block">
            <Card className="hover:bg-muted/50 hover:shadow-md transition-all cursor-pointer group">
                <CardContent className="p-3">
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary uppercase">
                            {getInitials(name)}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="font-medium text-sm truncate group-hover:text-primary transition-colors">{name}</p>
                            <p className="text-xs text-muted-foreground truncate">{position}</p>
                            {email && (
                                <p className="text-xs text-muted-foreground truncate flex items-center gap-1 mt-0.5">
                                    <Mail className="h-2.5 w-2.5 shrink-0" />
                                    {email}
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center justify-between mt-2.5 pt-2 border-t border-border/50">
                        <div className={`flex items-center gap-1 text-xs font-medium ${getDaysColor(daysInStage)}`}>
                            <Clock className="h-3 w-3" />
                            {daysInStage}d
                        </div>
                        <Badge variant="outline" className="text-[10px] px-1.5 py-0">{source}</Badge>
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}
