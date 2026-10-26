<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Actions\GenerateEnrollmentTokenAction;
use App\Filament\Resources\Servers\ServerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            GenerateEnrollmentTokenAction::make(),
            DeleteAction::make(),
        ];
    }
}
