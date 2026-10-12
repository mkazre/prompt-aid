<?php

namespace App\Filament\Resources\ContactMessages\Pages;

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

/** Opening a message marks it read. Status + notes + reply-by-email from the header. */
class ViewContactMessage extends ViewRecord
{
    protected static string $resource = ContactMessageResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var ContactMessage $msg */
        $msg = $this->getRecord();
        if (! $msg->read_at) {
            $msg->forceFill([
                'read_at' => now(),
                'status' => $msg->status === 'new' ? 'in_progress' : $msg->status,
            ])->save();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')->label('Reply by email')->icon('heroicon-o-arrow-uturn-left')
                ->url(fn (ContactMessage $record) => $record->mailtoUrl()),
            Action::make('handle')->label('Status & notes')->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn (ContactMessage $record) => ContactMessageResource::canEdit($record))
                ->fillForm(fn (ContactMessage $record) => ['status' => $record->status, 'admin_notes' => $record->admin_notes])
                ->schema([
                    Select::make('status')->options(ContactMessage::STATUSES)->required()->native(false),
                    Textarea::make('admin_notes')->label('Internal notes')->rows(4)->maxLength(5000),
                ])
                ->action(fn (ContactMessage $record, array $data) => $record->update($data)),
            Action::make('unread')->label('Mark unread')->icon('heroicon-o-envelope')->color('gray')
                ->action(function (ContactMessage $record) {
                    $record->update(['read_at' => null]);
                    $this->redirect(ContactMessageResource::getUrl('index'));
                }),
            DeleteAction::make(),
        ];
    }
}
