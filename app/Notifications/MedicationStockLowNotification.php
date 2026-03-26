<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MedicationStockLowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $medication,
        public string $clientName,
        public int $count,
        public string $unit,
        public int $reorderLevel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_stock_low',
            'title' => 'Low Stock Alert',
            'message' => "{$this->medication} for {$this->clientName} has only {$this->count} {$this->unit} remaining (reorder level: {$this->reorderLevel})",
            'severity' => 'warning',
            'action_url' => '/emar/stock',
            'medication' => $this->medication,
            'client_name' => $this->clientName,
            'count' => $this->count,
            'unit' => $this->unit,
            'reorder_level' => $this->reorderLevel,
        ];
    }
}
