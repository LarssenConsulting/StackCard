<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use App\Support\ServerSlug;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/** Name and notes are typed by hand; slug and connection settings are admin-only; the rest comes from the agent. */
class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->helperText('Nom d’affichage dans Discover (le hostname réel est remonté par l’agent).')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    // The slug follows the name until someone edits it by hand.
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old, string $operation) {
                        if ($operation !== 'create') {
                            return;
                        }
                        $current = (string) $get('slug');
                        if ($current === '' || $current === ServerSlug::from((string) $old)) {
                            $set('slug', ServerSlug::from((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Identifiant (slug)')
                    ->helperText('Identifiant permanent utilisé par Ansible et les autres outils. Il est figé au premier inventaire.')
                    ->required(fn () => self::isAdmin())
                    ->maxLength(ServerSlug::MAX_LENGTH)
                    ->regex('/'.ServerSlug::PATTERN.'/')
                    ->unique(ignoreRecord: true)
                    // A non-admin's slug is never submitted: the `creating` hook picks a free one.
                    ->validatedWhenNotDehydrated(false)
                    ->validationMessages(['unique' => 'Ce slug est déjà pris.'])
                    // Disabled fields are not saved: a non-admin (or a locked slug) can never change it.
                    ->disabled(fn (?Server $record) => ! self::isAdmin() || $record?->isSlugLocked() === true),
                TextInput::make('connect_address')
                    ->label('Adresse de connexion')
                    ->helperText('Laisser vide pour utiliser l’IP principale remontée par l’agent.')
                    ->maxLength(255)
                    ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (filled($value) && ! self::isAddress((string) $value)) {
                            $fail('Saisissez une adresse IP ou un nom d’hôte valide.');
                        }
                    }])
                    ->disabled(fn () => ! self::isAdmin()),
                TextInput::make('connect_port')
                    ->label('Port SSH')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->disabled(fn () => ! self::isAdmin()),
                Toggle::make('ansible_excluded')
                    ->label('Exclure de l’inventaire Ansible')
                    ->helperText('Le serveur reste dans StackCard, mais Ansible (Semaphore) ne le voit plus : par exemple un bastion joignable seulement par VPN.')
                    ->disabled(fn () => ! self::isAdmin()),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    private static function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    /** IPv4/IPv6 or an RFC 1123 host name. */
    private static function isAddress(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false
            || (strlen($value) <= 253 && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
    }
}
