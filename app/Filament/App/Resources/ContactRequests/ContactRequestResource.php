<?php

namespace App\Filament\App\Resources\ContactRequests;

use App\Filament\App\Resources\ContactRequests\Pages\ListContactRequests;
use App\Filament\App\Resources\ContactRequests\Pages\ViewContactRequest;
use App\Models\ContactRequest;
use App\Services\StoreContext;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ContactRequestResource extends Resource
{
    protected static ?string $model = ContactRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $modelLabel = 'contact request';

    protected static ?string $navigationLabel = 'Contact Requests';

    protected static ?string $slug = 'contact-requests';

    protected static ?int $navigationSort = 9;

    public static function getNavigationBadge(): ?string
    {
        $unread = ContactRequest::unread()->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('first_name'),
                TextEntry::make('last_name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('subject')->placeholder('No subject'),
                TextEntry::make('store.name')->label('Store')->visible(fn () => StoreContext::isPlatform()),
                TextEntry::make('created_at')->label('Received')->dateTime(),
                IconEntry::make('is_read')->label('Read')->boolean()->state(fn (ContactRequest $record) => $record->isRead()),
                TextEntry::make('read_at')->label('Read at')->dateTime()->placeholder('Not yet'),
                TextEntry::make('message')->columnSpanFull()->prose()->extraAttributes(['class' => 'whitespace-pre-line']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['last_name', 'first_name'])
                    ->weight(fn (ContactRequest $record) => $record->isRead() ? null : 'bold'),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('subject')->placeholder('No subject')->limit(40)->searchable(),
                TextColumn::make('store.name')->label('Store')->sortable()->visible(fn () => StoreContext::isPlatform()),
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                IconColumn::make('is_read')->label('Read')->boolean()->state(fn (ContactRequest $record) => $record->isRead()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('read_at')->label('Read')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('markRead')
                    ->label('Mark as read')
                    ->icon('heroicon-o-envelope-open')
                    ->visible(fn (ContactRequest $record) => ! $record->isRead() && static::canEdit($record))
                    ->action(fn (ContactRequest $record) => $record->markRead()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markRead')
                        ->label('Mark as read')
                        ->icon('heroicon-o-envelope-open')
                        ->action(fn (Collection $records) => $records->filter(fn (ContactRequest $record) => static::canEdit($record))->each->markRead())
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactRequests::route('/'),
            'view' => ViewContactRequest::route('/{record}'),
        ];
    }
}
