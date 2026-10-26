<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Actions\LinkDatabases;
use App\Filament\Resources\Servers\Actions\GenerateEnrollmentTokenAction;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GenerateEnrollmentTokenAction::make(),
            Action::make('revoke')
                ->label('Révoquer')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('L’agent ne pourra plus envoyer d’inventaire. Les données actuelles sont conservées.')
                ->visible(fn (Server $record) => $record->status !== Server::STATUS_REVOKED)
                ->action(function (Server $record, LinkDatabases $linkDatabases) {
                    $record->tokens()->delete();
                    $record->enrollmentTokens()->whereNull('used_at')->delete();
                    $record->update(['status' => Server::STATUS_REVOKED]);
                    $linkDatabases();

                    Notification::make()->title('Serveur révoqué')->success()->send();
                }),
            EditAction::make(),
        ];
    }
}
