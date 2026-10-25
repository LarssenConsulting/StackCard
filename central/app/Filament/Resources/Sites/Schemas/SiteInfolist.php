<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Filament\Resources\Databases\DatabaseResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Site;
use App\Models\SiteDbRef;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The chain a site sheet answers: domain → server → directory → system user → database → account. */
class SiteInfolist
{
    public const RESOLUTIONS = [
        SiteDbRef::LOCAL => 'Sur ce serveur',
        SiteDbRef::REMOTE => 'Sur un autre serveur',
        SiteDbRef::UNRESOLVED => 'Externe ou non trouvée',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Site')
                    ->schema([
                        TextEntry::make('server_name')->label('Domaine')->placeholder('(sans ServerName)'),
                        TextEntry::make('aliases')
                            ->label('Alias')
                            ->state(fn (Site $record) => array_values(array_unique($record->aliases)))
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                        TextEntry::make('ports')->label('Ports')->state(fn (Site $record) => $record->ports()),
                        IconEntry::make('https')->label('HTTPS')->boolean(),
                        TextEntry::make('cert_expires_at')->label('Expiration du certificat')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('type')->label('Type')->badge()
                            ->formatStateUsing(fn (string $state) => Site::TYPES[$state] ?? $state),
                    ]),
                Section::make('Hébergement')
                    ->schema([
                        TextEntry::make('server.name')
                            ->label('Serveur')
                            ->url(fn (Site $record) => ServerResource::getUrl('view', ['record' => $record->server_id])),
                        TextEntry::make('document_root')->label('DocumentRoot')->placeholder('—')->copyable(),
                        TextEntry::make('app_dir')->label('Dossier de l’application')->placeholder('—')->copyable(),
                        TextEntry::make('upstream')->label('Cible du proxy')->placeholder('—'),
                        TextEntry::make('php_version')->label('PHP')->placeholder('—'),
                        TextEntry::make('process_manager')->label('Gestionnaire du processus')->placeholder('—'),
                        TextEntry::make('config_file')->label('Fichier de configuration')->placeholder('—'),
                        IconEntry::make('partial')->label('Analyse partielle')->boolean(),
                    ]),
                Section::make('Utilisateurs système')
                    ->schema([
                        TextEntry::make('system_user')
                            ->label('Exécuté par')
                            ->formatStateUsing(fn (Site $record) => trim($record->system_user.':'.$record->system_group, ':'))
                            ->placeholder('—'),
                        TextEntry::make('owner_user')
                            ->label('Propriétaire des fichiers')
                            ->formatStateUsing(fn (Site $record) => trim($record->owner_user.':'.$record->owner_group, ':'))
                            ->placeholder('—'),
                    ]),
                RepeatableEntry::make('dbRefs')
                    ->label('Bases de données utilisées')
                    ->columnSpanFull()
                    ->columns(5)
                    ->placeholder('Aucune base trouvée dans la configuration du site.')
                    ->schema([
                        TextEntry::make('db_name')
                            ->label('Base')
                            ->url(fn (SiteDbRef $record) => $record->database_id
                                ? DatabaseResource::getUrl('view', ['record' => $record->database_id])
                                : null),
                        TextEntry::make('resolution')
                            ->label('Emplacement')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => self::RESOLUTIONS[$state] ?? $state)
                            ->color(fn (string $state) => $state === SiteDbRef::UNRESOLVED ? 'warning' : 'success'),
                        TextEntry::make('database.server.name')->label('Serveur de la base')->placeholder('—'),
                        TextEntry::make('dbUser.account')
                            ->label('Compte MySQL')
                            ->placeholder(fn (SiteDbRef $record) => $record->db_user ? "{$record->db_user} (compte non trouvé)" : '—'),
                        TextEntry::make('source')->label('Lu dans')->placeholder('—'),
                    ]),
            ]);
    }
}
