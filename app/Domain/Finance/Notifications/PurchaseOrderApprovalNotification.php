<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinPurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PurchaseOrderApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FinPurchaseOrder $purchaseOrder
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $po = $this->purchaseOrder;
        $po->loadMissing('vendor:id,name');

        $vendorName = $po->vendor?->name ?? 'Unknown Vendor';
        $total = number_format((float) $po->total_amount, 2);

        return (new MailMessage)
            ->subject("Purchase Order {$po->po_number} Requires Approval")
            ->line("A new purchase order requires your approval.")
            ->line('')
            ->line("**PO Number:** {$po->po_number}")
            ->line("**Vendor:** {$vendorName}")
            ->line("**Total Amount:** NZD \${$total}")
            ->action('View Purchase Order', url("/finance/purchase-orders/{$po->id}"))
            ->line('Please review and approve or decline this purchase order.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'purchase_order_approval',
            'purchase_order_id' => $this->purchaseOrder->id,
            'po_number' => $this->purchaseOrder->po_number,
        ];
    }
}
