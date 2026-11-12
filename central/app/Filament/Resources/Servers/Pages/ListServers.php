<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Servers\Actions\LockSlugsAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServers extends ListRecords
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LockSlugsAction::make(),
            CreateAction::make(),
        ];
    }
}
