<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The IP an agent reports is only used by Ansible once an admin has accepted it: a compromised
 * server could otherwise send Semaphore, and its SSH credentials, to another machine.
 */
class ApproveIpAction
{
    public static function make(): Action
    {
        return Action::make('approveIp')
            ->label('Valider la nouvelle IP')
            ->icon('heroicon-o-shield-check')
            ->color('warning')
            ->visible(fn (Server $record) => auth()->user()?->isAdmin() === true && $record->ipChangePending())
            ->requiresConfirmation()
            ->modalHeading('Valider la nouvelle IP')
            ->modalDescription(fn (Server $record) => "L’agent signale l’IP {$record->primary_ip} au lieu de {$record->approved_ip}. "
                .'Ansible se connectera à la nouvelle adresse : vérifiez d’abord que ce changement est attendu.')
            ->modalSubmitActionLabel('Valider')
            ->action(function (Server $record) {
                if (auth()->user()?->isAdmin() !== true || ! $record->ipChangePending()) {
                    return;
                }
                $record->forceFill(['approved_ip' => $record->primary_ip])->save();

                Notification::make()->success()->title("IP {$record->primary_ip} validée")->send();
            });
    }
}
