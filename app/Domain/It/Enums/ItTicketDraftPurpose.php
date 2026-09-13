<?php

namespace App\Domain\It\Enums;

enum ItTicketDraftPurpose: string
{
    case RequesterIntake = 'requester_intake';
    case TechnicianIntake = 'technician_intake';
    case PublicReply = 'public_reply';
    case InternalNote = 'internal_note';
    case TicketEdit = 'ticket_edit';
    case PublicResolution = 'public_resolution';
    case TicketWork = 'ticket_work';

    public function requiresTicket(): bool
    {
        return ! in_array($this, [self::RequesterIntake, self::TechnicianIntake], true);
    }

    public function requiresManage(): bool
    {
        return in_array($this, [self::TechnicianIntake, self::InternalNote, self::TicketEdit, self::PublicResolution, self::TicketWork], true);
    }

    public function audience(): string
    {
        return in_array($this, [self::InternalNote, self::TicketEdit, self::TechnicianIntake, self::TicketWork], true) ? 'internal' : 'public';
    }
}
