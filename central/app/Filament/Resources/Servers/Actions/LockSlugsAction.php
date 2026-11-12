<?php

namespace App\Filament\Resources\Servers\Actions;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Freeze the slug of every server already seen by an agent: tools can then rely on it. */
class LockSlugsAction
{
    public static function make(): Action
    {
        return Action::make('lockSlugs')
            ->label('Figer les slugs')
            ->icon('heroicon-o-lock-closed')
            ->visible(fn () => auth()->user()?->isAdmin() === true && self::lockable()->exists())
            ->requiresConfirmation()
            ->modalHeading('Figer les slugs')
            ->modalDescription('Les slugs ne pourront plus être modifiés. Les outils (Ansible, suivi des CVE) pourront s’y fier.')
            ->modalSubmitActionLabel('Figer')
            ->action(function () {
                if (auth()->user()?->isAdmin() !== true) {
                    return;
                }
                $count = self::lockable()->update(['slug_locked_at' => now()]);

                Notification::make()
                    ->success()
                    ->title($count === 1 ? '1 slug figé' : "{$count} slugs figés")
                    ->send();
            });
    }

    private static function lockable()
    {
        return Server::query()->whereNull('slug_locked_at')->whereNotNull('last_seen_at');
    }
}
