<?php

namespace App\Filament\Resources\Databases\Schemas;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Sites\Schemas\SiteInfolist;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Database;
use App\Models\DbGrant;
use App\Models\SiteDbRef;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class DatabaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Base de données')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->label('Nom'),
                        TextEntry::make('server.name')
                            ->label('Serveur')
                            ->url(fn (Database $record) => ServerResource::getUrl('view', ['record' => $record->server_id])),
                        TextEntry::make('size_bytes')
                            ->label('Taille')
                            ->formatStateUsing(fn (int $state) => Number::fileSize($state, precision: 1)),
                    ]),
                RepeatableEntry::make('siteDbRefs')
                    ->label('Sites qui l’utilisent')
                    ->columns(4)
                    ->placeholder('Aucun site inventorié n’utilise cette base.')
                    ->schema([
                        TextEntry::make('site.server_name')
                            ->label('Site')
                            ->url(fn (SiteDbRef $record) => SiteResource::getUrl('view', ['record' => $record->site_id])),
                        TextEntry::make('site.server.name')->label('Hébergé sur'),
                        TextEntry::make('db_user')->label('Compte utilisé')->placeholder('—'),
                        TextEntry::make('resolution')
                            ->label('Emplacement')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => SiteInfolist::RESOLUTIONS[$state] ?? $state),
                    ]),
                RepeatableEntry::make('grants')
                    ->label('Comptes ayant des droits')
                    ->columns(2)
                    ->placeholder('Aucun droit spécifique à cette base.')
                    ->schema([
                        TextEntry::make('dbUser.account')->label('Compte'),
                        TextEntry::make('privileges')
                            ->label('Droits')
                            ->state(fn (DbGrant $record) => implode(', ', $record->privileges)),
                    ]),
            ]);
    }
}
