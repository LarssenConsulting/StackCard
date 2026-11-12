<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
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
            // The approval is bound to the IP shown in the modal: the agent may report another one meanwhile.
            ->schema([Hidden::make('ip')])
            ->fillForm(fn (Server $record) => ['ip' => $record->primary_ip])
            ->action(function (Server $record, array $data) {
                $ip = $data['ip'] ?? null;
                if (auth()->user()?->isAdmin() !== true || ! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    return;
                }
                $approved = Server::query()->whereKey($record->getKey())->where('primary_ip', $ip)
                    ->update(['approved_ip' => $ip]);

                if ($approved === 0) {
                    Notification::make()->danger()
                        ->title('L’IP a changé entre-temps')
                        ->body('L’agent a signalé une autre adresse depuis l’ouverture de cette fenêtre : rien n’a été validé, relisez la fiche.')
                        ->send();

                    return;
                }

                Notification::make()->success()->title("IP {$ip} validée")->send();
            });
    }
}
