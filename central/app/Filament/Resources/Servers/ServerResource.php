<?php

namespace App\Filament\Resources\Servers;

use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\DatabasesRelationManager;
use App\Filament\Resources\Servers\RelationManagers\DbUsersRelationManager;
use App\Filament\Resources\Servers\RelationManagers\DockerContainersRelationManager;
use App\Filament\Resources\Servers\RelationManagers\FirewallRulesRelationManager;
use App\Filament\Resources\Servers\RelationManagers\NodeAppsRelationManager;
use App\Filament\Resources\Servers\RelationManagers\SitesRelationManager;
use App\Filament\Resources\Servers\RelationManagers\SnapshotsRelationManager;
use App\Filament\Resources\Servers\Schemas\ServerForm;
use App\Filament\Resources\Servers\Schemas\ServerInfolist;
use App\Filament\Resources\Servers\Tables\ServersTable;
use App\Models\Server;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $modelLabel = 'serveur';

    protected static ?string $pluralModelLabel = 'serveurs';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ServerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ServerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SitesRelationManager::class,
            NodeAppsRelationManager::class,
            DockerContainersRelationManager::class,
            FirewallRulesRelationManager::class,
            DatabasesRelationManager::class,
            DbUsersRelationManager::class,
            SnapshotsRelationManager::class,
        ];
    }

    /** Tab key of a relation manager on the server sheet, for links (?relation=…). */
    public static function relationIndex(string $manager): string
    {
        return (string) array_search($manager, static::getRelations(), true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServers::route('/'),
            'create' => CreateServer::route('/create'),
            'view' => ViewServer::route('/{record}'),
            'edit' => EditServer::route('/{record}/edit'),
        ];
    }
}
