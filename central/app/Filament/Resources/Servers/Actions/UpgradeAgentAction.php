<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Models\Server;
use App\Support\AgentRelease;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;

/** The steps to bring an outdated agent to the published version, opened from its version. */
class UpgradeAgentAction
{
    public static function make(): Action
    {
        return Action::make('upgradeAgent')
            ->modalHeading(fn (Server $record) => "Mettre à jour l’agent de {$record->name}")
            ->modalDescription(fn (Server $record) => "Version installée : {$record->agent_version} · "
                .'version publiée : '.AgentRelease::latest())
            ->modalContent(fn () => self::steps())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer');
    }

    private static function steps(): HtmlString
    {
        $code = fn (string $text) => '<code style="user-select:all">'.e($text).'</code>';

        return new HtmlString(
            '<ol style="list-style:decimal;padding-left:1.25rem;display:grid;gap:.75rem">'
            .'<li>Sur le serveur, relancez le script d’installation :<br>'
            .$code('curl -fsSL '.config('discover.agent_install_url').' | sudo sh').'<br>'
            .'Il installe la nouvelle version, puis demande « Mettre à jour le jeton ? » : '
            .'répondez <strong>non</strong> pour garder la configuration actuelle.</li>'
            .'<li>Vérifiez la version installée :<br>'.$code('sudo discover-agent status').'</li>'
            .'<li>Envoyez un inventaire tout de suite (sinon au prochain passage du timer, sous une heure) :<br>'
            .$code('sudo discover-agent run').'</li>'
            .'</ol>'
        );
    }
}
