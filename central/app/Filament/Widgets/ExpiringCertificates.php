<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringCertificates extends TableWidget
{
    public const DAYS = 30;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function expiring(): Builder
    {
        return Site::query()
            ->whereNotNull('cert_expires_at')
            ->where('cert_expires_at', '<=', now()->addDays(self::DAYS));
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Certificats qui expirent dans moins de '.self::DAYS.' jours')
            ->query(fn () => self::expiring()->with('server'))
            ->defaultSort('cert_expires_at')
            ->paginated(false)
            ->emptyStateHeading('Aucun certificat n’expire prochainement.')
            ->recordUrl(fn (Site $record) => SiteResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('server_name')->label('Domaine'),
                TextColumn::make('server.name')->label('Serveur'),
                TextColumn::make('cert_expires_at')
                    ->label('Expire')
                    ->date('d/m/Y')
                    ->description(fn (Site $record) => $record->cert_expires_at->diffForHumans())
                    ->color('danger'),
            ]);
    }
}
