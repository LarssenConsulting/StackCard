<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Actions\CreateEnrollmentToken;
use App\Actions\LinkDatabases;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\EnrollmentToken;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewServer extends ViewRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateEnrollmentToken')
                ->label('Générer un jeton d’enrôlement')
                ->icon('heroicon-o-key')
                ->modalDescription('Le jeton est valable '.EnrollmentToken::VALID_HOURS.' h et ne sert qu’une fois. '
                    .'Réenrôler un serveur déjà actif remplace son jeton agent actuel.')
                ->requiresConfirmation()
                ->action(function (Server $record, CreateEnrollmentToken $createToken) {
                    $plain = $createToken($record);
                    $command = sprintf(
                        'sudo discover-agent enroll --url %s --token %s',
                        e(rtrim(config('app.url'), '/')),
                        e($plain),
                    );

                    // Shown once: only the hash is stored.
                    Notification::make()
                        ->title('Jeton d’enrôlement créé')
                        ->body(new HtmlString(
                            'Lancez sur le serveur (ce jeton ne sera plus affiché) :<br><code style="user-select:all">'
                            .$command.'</code>'
                        ))
                        ->success()
                        ->persistent()
                        ->send();
                }),
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
