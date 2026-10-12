<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Filament\Resources\SupportTickets\SupportTicketResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Ticket details (status, priority, category, assignee) on top; the thread
 * and the staff "Reply" action live in the Replies relation manager below
 * (shown together, not as tabs).
 */
class EditSupportTicket extends EditRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->reference.' — '.$this->getRecord()->subject;
    }

    protected function getSaveFormAction(): \Filament\Actions\Action
    {
        return parent::getSaveFormAction()->label('Save ticket details');
    }

    protected function getRedirectUrl(): ?string
    {
        return null; // stay on the ticket after saving
    }
}
