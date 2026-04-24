import { AlertCircle, AlertTriangle, Ban, CheckCircle, Info, ShieldAlert } from 'lucide-react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface SafetyWarning {
  type: string;
  severity: 'info' | 'caution' | 'warning' | 'danger';
  message: string;
  details?: Record<string, unknown>;
}

export interface SafetyCheck {
  safe: boolean;
  blocked: boolean;
  block_reason?: string;
  safety_level: 'safe' | 'caution' | 'warning' | 'danger' | 'blocked';
  safety_info: {
    label: string;
    color: string;
    icon: string;
  };
  warnings: SafetyWarning[];
  can_proceed: boolean;
  requires_acknowledgment: boolean;
}

interface Props {
  safetyCheck: SafetyCheck | null;
  onOverride?: () => void;
  showDetails?: boolean;
}

const severityConfig = {
  info: { icon: Info, color: 'text-blue-600', bgColor: 'bg-blue-50', borderColor: 'border-blue-200' },
  caution: { icon: AlertCircle, color: 'text-yellow-600', bgColor: 'bg-yellow-50', borderColor: 'border-yellow-200' },
  warning: { icon: AlertTriangle, color: 'text-orange-600', bgColor: 'bg-orange-50', borderColor: 'border-orange-200' },
  danger: { icon: ShieldAlert, color: 'text-red-600', bgColor: 'bg-red-50', borderColor: 'border-red-200' },
};

const levelConfig = {
  safe: { icon: CheckCircle, color: 'text-green-600', bgColor: 'bg-green-50', borderColor: 'border-green-200' },
  caution: { icon: AlertCircle, color: 'text-yellow-600', bgColor: 'bg-yellow-50', borderColor: 'border-yellow-200' },
  warning: { icon: AlertTriangle, color: 'text-orange-600', bgColor: 'bg-orange-50', borderColor: 'border-orange-200' },
  danger: { icon: ShieldAlert, color: 'text-red-600', bgColor: 'bg-red-50', borderColor: 'border-red-200' },
  blocked: { icon: Ban, color: 'text-red-700', bgColor: 'bg-red-100', borderColor: 'border-red-300' },
};

export default function SafetyCheckPanel({ safetyCheck, onOverride, showDetails = true }: Props) {
  const level = useMemo(() => {
    if (!safetyCheck) return null;
    return levelConfig[safetyCheck.safety_level] || levelConfig.safe;
  }, [safetyCheck]);

  if (!safetyCheck) {
    return (
      <Card className="border-border">
        <CardContent className="pt-6">
          <div className="flex items-center gap-2 text-muted-foreground">
            <Info className="h-5 w-5" />
            <span>Safety check pending...</span>
          </div>
        </CardContent>
      </Card>
    );
  }

  const LevelIcon = level?.icon || CheckCircle;

  return (
    <Card className={`${level?.bgColor} ${level?.borderColor} border-2`}>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-base">
          <LevelIcon className={`h-5 w-5 ${level?.color}`} />
          <span className={level?.color}>
            Safety Check: {safetyCheck.safety_info.label}
          </span>
          <Badge variant="outline" className={level?.color}>
            {safetyCheck.safety_level.toUpperCase()}
          </Badge>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {safetyCheck.blocked && (
          <div className="rounded-md border border-red-200 bg-red-100 p-3 text-red-800">
            <div className="flex items-start gap-2">
              <Ban className="mt-0.5 h-5 w-5 shrink-0" />
              <div>
                <div className="font-semibold">Administration Blocked</div>
                <div className="text-sm">{safetyCheck.block_reason}</div>
              </div>
            </div>
          </div>
        )}

        {showDetails && safetyCheck.warnings.length > 0 && (
          <div className="space-y-2">
            <div className="text-sm font-medium text-foreground">Warnings & Alerts:</div>
            {safetyCheck.warnings.map((warning, idx) => {
              const config = severityConfig[warning.severity] || severityConfig.info;
              const WarningIcon = config.icon;

              return (
                <div
                  key={idx}
                  className={`rounded-md border p-3 ${config.bgColor} ${config.borderColor}`}
                >
                  <div className="flex items-start gap-2">
                    <WarningIcon className={`mt-0.5 h-4 w-4 shrink-0 ${config.color}`} />
                    <div className={`text-sm ${config.color}`}>{warning.message}</div>
                  </div>
                  {warning.details && (
                    <div className="mt-2 pl-6 text-xs text-muted-foreground">
                      {Object.entries(warning.details).map(([key, value]) => (
                        <div key={key} className="capitalize">
                          {key.replace(/_/g, ' ')}: {String(value)}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}

        {!safetyCheck.can_proceed && onOverride && (
          <div className="flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={onOverride}
              className="border-red-300 text-red-700 hover:bg-red-50"
            >
              Override (Manager Required)
            </Button>
          </div>
        )}

        {safetyCheck.can_proceed && safetyCheck.warnings.length === 0 && (
          <div className="flex items-center gap-2 text-sm text-green-700">
            <CheckCircle className="h-4 w-4" />
            <span>All safety checks passed. Safe to administer.</span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
