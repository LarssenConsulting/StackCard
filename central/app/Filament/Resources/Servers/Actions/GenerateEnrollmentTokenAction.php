<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Actions\CreateEnrollmentToken;
use App\Models\EnrollmentToken;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Issue an enrollment token and show the install command once. Available wherever a server is
 * shown, so a closed notification is never a dead end: a new token replaces the lost one.
 */
class GenerateEnrollmentTokenAction
{
    public static function make(): Action
    {
        return Action::make('generateEnrollmentToken')
            ->label(fn (Server $record) => self::hasToken($record)
                ? 'Régénérer le jeton d’enrôlement'
                : 'Générer un jeton d’enrôlement')
            ->icon('heroicon-o-key')
            ->modalDescription('Le jeton est valable '.EnrollmentToken::VALID_HOURS.' h et ne sert qu’une fois. '
                .'Un jeton encore inutilisé est remplacé. '
                .'Réenrôler un serveur déjà actif remplace son jeton agent actuel.')
            ->requiresConfirmation()
            ->action(function (Server $record, CreateEnrollmentToken $createToken) {
                $plain = $createToken($record);
                $url = rtrim(config('app.url'), '/');
                $code = fn (string $text) => '<code style="user-select:all">'.e($text).'</code>';

                // Shown once: only the hash is stored.
                Notification::make()
                    ->title('Jeton d’enrôlement créé')
                    ->body(new HtmlString(
                        'Sur le serveur, lancez la commande puis répondez aux deux questions '
                        .'(ce jeton ne sera plus affiché ; en cas de perte, régénérez-en un) :<br>'
                        .$code('curl -fsSL '.config('discover.agent_install_url').' | sudo sh').'<br>'
                        .'Adresse du central : '.$code(preg_replace('#^https://#', '', $url)).'<br>'
                        .'Jeton : '.$code($plain).'<br><br>'
                        .'Agent déjà installé : '.$code("sudo discover-agent enroll --url {$url} --token {$plain}")
                    ))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /** A token was issued and is still usable, or the agent is already enrolled. */
    private static function hasToken(Server $server): bool
    {
        return $server->pendingEnrollmentToken() !== null || $server->status === Server::STATUS_ACTIVE;
    }
}
