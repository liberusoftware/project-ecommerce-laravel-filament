<?php

namespace App\Filament\App\Resources\ContactRequests\Pages;

use App\Filament\App\Resources\ContactRequests\ContactRequestResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewContactRequest extends ViewRecord
{
    protected static string $resource = ContactRequestResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Only someone who could mark it read by hand does so by opening it.
        if (static::getResource()::canEdit($this->getRecord())) {
            $this->getRecord()->markRead();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply by email')
                ->icon('heroicon-o-arrow-uturn-left')
                ->url(fn () => 'mailto:'.$this->getRecord()->email.'?subject='.rawurlencode('Re: '.($this->getRecord()->subject ?: 'Your message'))),
            DeleteAction::make(),
        ];
    }
}
