<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Actions\CreateEnrollmentToken;
use App\Models\EnrollmentToken;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Issue an enrollment token and show the install command once. Available wherever a server is
 * shown, so a closed modal is never a dead end: a new token replaces the lost one.
 *
 * One modal, two steps: a confirmation, then (same modal, kept open) the token and the commands.
 */
class GenerateEnrollmentTokenAction
{
    public static function make(): Action
    {
        $issued = fn (array $arguments) => isset($arguments['token']);

        return Action::make('generateEnrollmentToken')
            ->label(fn (Server $record) => self::hasToken($record)
                ? 'Régénérer le jeton d’enrôlement'
                : 'Générer un jeton d’enrôlement')
            ->icon('heroicon-o-key')
            ->requiresConfirmation(fn (array $arguments) => ! $issued($arguments))
            ->modalHeading(fn (array $arguments, Server $record) => $issued($arguments)
                ? 'Jeton d’enrôlement créé'
                : "Jeton d’enrôlement de {$record->name}")
            ->modalDescription(fn (array $arguments) => $issued($arguments)
                ? 'Ce jeton ne sera plus affiché : en cas de perte, régénérez-en un.'
                : 'Le jeton est valable '.EnrollmentToken::VALID_HOURS.' h et ne sert qu’une fois. '
                    .'Un jeton encore inutilisé est remplacé. '
                    .'Réenrôler un serveur déjà actif remplace son jeton agent actuel.')
            ->modalContent(fn (array $arguments) => $issued($arguments) ? self::steps($arguments['token']) : null)
            ->modalWidth(fn (array $arguments) => $issued($arguments) ? Width::TwoExtraLarge : null)
            ->modalSubmitActionLabel('Générer')
            ->modalSubmitAction(fn (Action $action, array $arguments) => $issued($arguments) ? false : $action)
            ->modalCancelActionLabel(fn (array $arguments) => $issued($arguments) ? 'Fermer' : 'Annuler')
            ->action(function (Action $action, Server $record, CreateEnrollmentToken $createToken, Component $livewire) {
                // Shown once: only the hash is stored. The modal stays open on its second step.
                $livewire->mergeMountedActionArguments(['token' => $createToken($record)]);
                $action->halt();
            });
    }

    private static function steps(string $token): HtmlString
    {
        $url = rtrim(config('app.url'), '/');
        $code = fn (string $text) => '<code style="user-select:all">'.e($text).'</code>';

        return new HtmlString(
            '<ol style="list-style:decimal;padding-left:1.25rem;display:grid;gap:.75rem">'
            .'<li>Sur le serveur, lancez la commande puis répondez aux deux questions :<br>'
            .$code('curl -fsSL '.config('discover.agent_install_url').' | sudo sh').'<br>'
            .'Adresse du central : '.$code(preg_replace('#^https://#', '', $url)).'<br>'
            .'Jeton : '.$code($token).'</li>'
            .'<li>L’enrôlement envoie un premier inventaire. Pour relancer la synchronisation à la main :<br>'
            .$code('sudo discover-agent run').'</li>'
            .'</ol>'
            .'<p style="margin-top:1rem">Agent déjà installé : '
            .$code("sudo discover-agent enroll --url {$url} --token {$token}").'</p>'
        );
    }

    /** A token was issued and is still usable, or the agent is already enrolled. */
    private static function hasToken(Server $server): bool
    {
        return $server->pendingEnrollmentToken() !== null || $server->status === Server::STATUS_ACTIVE;
    }
}
