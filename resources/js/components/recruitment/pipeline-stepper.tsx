import { stageOrder, stageLabels } from './status-badge';
import { CheckCircle2, Clock, ArrowRight } from 'lucide-react';

interface PipelineStepperProps {
    currentStage: string;
    compact?: boolean;
}

export function PipelineStepper({ currentStage, compact }: PipelineStepperProps) {
    const currentStageIndex = stageOrder.indexOf(currentStage);

    return (
        <div className="flex items-center gap-0.5 overflow-x-auto pb-1">
            {stageOrder.map((stage, i) => {
                const isActive = i === currentStageIndex;
                const isPast = i < currentStageIndex;
                return (
                    <div key={stage} className="flex items-center shrink-0">
                        <div
                            className={`flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium transition-all ${
                                isActive
                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                    : isPast
                                        ? 'bg-primary/20 text-primary'
                                        : 'bg-muted text-muted-foreground'
                            } ${compact ? 'text-[10px] px-2 py-0.5' : ''}`}
                        >
                            {isPast && <CheckCircle2 className={compact ? 'h-2.5 w-2.5' : 'h-3 w-3'} />}
                            {isActive && <Clock className={compact ? 'h-2.5 w-2.5' : 'h-3 w-3'} />}
                            {!compact && (stageLabels[stage] || stage)}
                            {compact && (stageLabels[stage]?.split(' ')[0] || stage)}
                        </div>
                        {i < stageOrder.length - 1 && (
                            <ArrowRight className={`mx-0.5 h-3 w-3 shrink-0 ${isPast ? 'text-primary' : 'text-muted-foreground/30'}`} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}
