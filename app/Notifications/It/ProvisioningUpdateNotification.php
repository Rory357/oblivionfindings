<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Provisioning lifecycle updates for the people who are waiting on the work:
 * approvers (a decision is needed), requesters/beneficiaries (progress) and
 * workflow owners (corrective work). Reference + public title only — never
 * work notes, evidence, HR context or account references.
 */
class ProvisioningUpdateNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public const EVENTS = ['approval_requested', 'approved', 'rejected', 'approval_expired', 'fulfilled', 'failed',
        'retried', 'cancelled', 'workflow_completed', 'reversal_requested', 'launched'];

    public const AUDIENCES = ['approver', 'requester', 'agent'];

    public function __construct(
        private ItProvisioningRequest $request,
        private string $event,
        private string $audience,
    ) {}

    public function reference(): string
    {
        return 'IT-P'.str_pad((string) $this->request->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Approvers and agents hold work access and see the task itself. Requesters
     * only ever see the retained public catalogue title of the whole request;
     * a workflow task without one falls back to its lifecycle label.
     */
    public function title(): string
    {
        if ($this->audience !== 'requester') {
            return (string) $this->request->item;
        }
        $taskIds = $this->request->provisioning_workflow_id
            ? ItProvisioningRequest::query()->where('provisioning_workflow_id', $this->request->provisioning_workflow_id)->pluck('id')->all()
            : [$this->request->id];
        $contract = ItCatalogSubmission::query()->where('result_type', $this->request->getMorphClass())
            ->whereIn('result_id', $taskIds)->oldest('id')->value('contract_snapshot');
        $name = is_array($contract) ? ($contract['name'] ?? null) : null;
        if (is_string($name) && $name !== '') {
            return $name;
        }
        $lifecycle = $this->request->workflow?->lifecycle_type;

        return $lifecycle ? ucfirst($lifecycle).' provisioning' : 'IT provisioning';
    }

    public function subject(): string
    {
        $label = match ($this->event) {
            'approval_requested' => 'Approval needed',
            'approved' => 'Approved',
            'rejected' => 'Approval declined',
            'approval_expired' => 'Approval expired',
            'fulfilled' => 'Work completed',
            'failed' => 'Needs attention',
            'retried' => 'Work queued again',
            'cancelled' => 'Cancelled',
            'workflow_completed' => 'All tasks completed',
            'reversal_requested' => 'Corrective work requested',
            default => 'Request received',
        };

        return "{$label} — {$this->reference()} {$this->title()}";
    }

    public function actionUrl(): string
    {
        return $this->audience === 'requester'
            ? "/it/provisioning/{$this->request->id}"
            : "/it/provisioning/tasks/{$this->request->id}";
    }

    public function itEmailDeliveryContext(): array
    {
        return [
            'provisioning_request_id' => (int) $this->request->id,
            'type' => 'it_provisioning_update',
            'audience' => $this->audience,
            'subject' => $this->subject(),
            'retry_context' => ['event' => $this->event, 'audience' => $this->audience],
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $line = match ($this->event) {
            'approval_requested' => 'You are currently responsible for approving this IT provisioning task:',
            'approved' => 'The approval you requested was recorded. The work can now be completed:',
            'rejected' => 'The approval you requested was declined. Review the decision before requesting another review:',
            'approval_expired' => 'The approval deadline passed without a decision. Request a new review to continue:',
            'fulfilled' => 'IT recorded completion of this task:',
            'failed' => 'IT could not complete this task and will review it before trying again:',
            'retried' => 'IT queued this task for another attempt:',
            'cancelled' => 'This IT provisioning task was cancelled:',
            'workflow_completed' => 'Every task in this IT provisioning request is now complete:',
            'reversal_requested' => 'Corrective (reversal) work was requested for completed tasks in a workflow you own:',
            default => 'Your IT provisioning request was received and routed:',
        };

        return (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hello {$notifiable->name},")
            ->line($line)
            ->line("**{$this->reference()}** — {$this->title()}")
            ->action($this->audience === 'approver' ? 'Review approval' : 'Open request', url($this->actionUrl()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_provisioning_'.$this->event,
            'provisioning_request_id' => (int) $this->request->id,
            'workflow_id' => $this->request->provisioning_workflow_id,
            'reference' => $this->reference(),
            'title' => $this->title(),
            'event' => $this->event,
            'audience' => $this->audience,
            'action_url' => $this->actionUrl(),
        ];
    }
}
