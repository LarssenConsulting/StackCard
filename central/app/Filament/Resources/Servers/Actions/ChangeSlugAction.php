<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Models\Server;
use App\Support\ServerSlug;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Admin escape hatch for a locked slug (a typo, a wrong client name). The slug is the identifier other
 * tools match on, so the form spells out what breaks and asks for an explicit confirmation.
 */
class ChangeSlugAction
{
    public static function make(): Action
    {
        return Action::make('changeSlug')
            ->label('Changer le slug')
            ->icon('heroicon-o-pencil-square')
            ->color('danger')
            ->visible(fn (Server $record) => auth()->user()?->isAdmin() === true && $record->isSlugLocked())
            ->modalHeading('Changer le slug')
            ->modalDescription(fn (Server $record) => "Le slug « {$record->slug} » est figé : les outils s’y fient. "
                .'Le changer casse tout ce qui utilise l’ancien : nom d’hôte Ansible (playbooks, --limit), '
                .'lignes du suivi des CVE, toute intégration de l’API. À réserver à une erreur (faute de frappe, mauvais client).')
            ->schema([
                TextInput::make('slug')
                    ->label('Nouveau slug')
                    ->required()
                    ->maxLength(ServerSlug::MAX_LENGTH)
                    ->regex('/'.ServerSlug::PATTERN.'/')
                    ->rules(['unique:servers,slug'])
                    ->validationMessages(['unique' => 'Ce slug est déjà pris.']),
                Checkbox::make('confirm')
                    ->label('Je mettrai à jour les outils qui utilisent l’ancien slug.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirmez pour continuer.']),
            ])
            ->modalSubmitActionLabel('Changer le slug')
            ->action(function (Server $record, array $data) {
                if (auth()->user()?->isAdmin() !== true) {
                    return;
                }
                $old = $record->slug;
                $record->renameLockedSlug($data['slug']);

                Notification::make()->success()
                    ->title("Slug changé : {$old} → {$record->slug}")
                    ->body('Pensez à mettre à jour les outils qui utilisaient l’ancien slug.')
                    ->send();
            });
    }
}
