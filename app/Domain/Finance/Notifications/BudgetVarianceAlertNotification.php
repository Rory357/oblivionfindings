<?php

namespace App\Domain\Finance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetVarianceAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $category,
        public float $budgetAmount,
        public float $actualAmount,
        public float $variancePct,
        public string $alertLevel = 'over_budget',
        public ?float $utilizationPct = null,
        public ?string $siteName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $budgetFormatted = number_format($this->budgetAmount, 2);
        $actualFormatted = number_format($this->actualAmount, 2);
        $varianceFormatted = number_format(abs($this->variancePct), 1);
        $utilizationFormatted = number_format($this->utilizationPct ?? 0, 1);
        $subjectCategory = $this->siteName
            ? "{$this->siteName} {$this->category}"
            : $this->category;

        if ($this->alertLevel === 'approaching_budget') {
            return (new MailMessage)
                ->subject("Budget variance alert — {$subjectCategory} has used {$utilizationFormatted}% of budget")
                ->line("The **{$subjectCategory}** budget is approaching its threshold.")
                ->line('')
                ->line("**Budget:** \${$budgetFormatted} NZD")
                ->line("**Actual:** \${$actualFormatted} NZD")
                ->line("**Utilisation:** {$utilizationFormatted}%")
                ->action('View Budget Report', url('/finance/reports/budget-vs-actuals'))
                ->line('Please review the spending in this category and take appropriate action.');
        }

        return (new MailMessage)
            ->subject("Budget variance alert — {$subjectCategory} is {$varianceFormatted}% over budget")
            ->line("The **{$subjectCategory}** category has exceeded its budget.")
            ->line('')
            ->line("**Budget:** \${$budgetFormatted} NZD")
            ->line("**Actual:** \${$actualFormatted} NZD")
            ->line("**Variance:** {$varianceFormatted}% over budget")
            ->action('View Budget Report', url('/finance/reports/budget-vs-actuals'))
            ->line('Please review the spending in this category and take appropriate action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'budget_variance_alert',
            'category' => $this->category,
            'budget_amount' => $this->budgetAmount,
            'actual_amount' => $this->actualAmount,
            'variance_pct' => $this->variancePct,
            'alert_level' => $this->alertLevel,
            'utilization_pct' => $this->utilizationPct,
            'site_name' => $this->siteName,
        ];
    }
}
