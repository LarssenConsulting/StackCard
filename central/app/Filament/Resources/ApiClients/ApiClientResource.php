<?php

namespace App\Filament\Resources\ApiClients;

use App\Filament\Resources\ApiClients\Pages\CreateApiClient;
use App\Filament\Resources\ApiClients\Pages\EditApiClient;
use App\Filament\Resources\ApiClients\Pages\ListApiClients;
use App\Models\ApiClient;
use App\Rules\AllowedIpEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/** External applications allowed to read the inventory, each from a short list of addresses. */
class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'client API';

    protected static ?string $pluralModelLabel = 'clients API';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nom')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TagsInput::make('allowed_ips')
                ->label('Adresses autorisées')
                ->placeholder('203.0.113.10')
                ->helperText('L’IP sortante du serveur qui appelle l’API, ou une plage (au plus large /'
                    .AllowedIpEntry::WIDEST_V4.' en IPv4, /'.AllowedIpEntry::WIDEST_V6.' en IPv6). Toute autre adresse est refusée.')
                ->required()
                ->nestedRecursiveRules([new AllowedIpEntry])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun client API')
            ->emptyStateDescription('Personne ne peut lire l’inventaire par l’API tant qu’aucun client n’est créé.')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable(),
                TextColumn::make('allowed_ips')->label('Adresses autorisées')->badge()->color('gray'),
                TextColumn::make('token_expires')
                    ->label('Jeton valable jusqu’au')
                    ->state(fn (ApiClient $record) => $record->currentToken()?->expires_at)
                    ->dateTime('d/m/Y')
                    ->placeholder('Aucun jeton'),
                TextColumn::make('token_used')
                    ->label('Dernier appel')
                    ->state(fn (ApiClient $record) => $record->currentToken()?->last_used_at)
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais'),
            ])
            ->recordActions([
                self::issueTokenAction(),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Le jeton de ce client cessera immédiatement de fonctionner.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiClients::route('/'),
            'create' => CreateApiClient::route('/create'),
            'edit' => EditApiClient::route('/{record}/edit'),
        ];
    }

    /** One modal, two steps, as for enrollment tokens: a confirmation, then the token, shown once. */
    private static function issueTokenAction(): Action
    {
        $issued = fn (array $arguments) => isset($arguments['token']);

        return Action::make('issueToken')
            ->label(fn (ApiClient $record) => $record->currentToken() ? 'Régénérer le jeton' : 'Générer le jeton')
            ->icon('heroicon-o-key')
            ->requiresConfirmation(fn (array $arguments) => ! $issued($arguments))
            ->modalHeading(fn (array $arguments, ApiClient $record) => $issued($arguments) ? 'Jeton créé' : "Jeton de {$record->name}")
            ->modalDescription(fn (array $arguments) => $issued($arguments)
                ? 'Ce jeton ne sera plus affiché : en cas de perte, régénérez-en un.'
                : 'Valable '.ApiClient::TOKEN_VALID_DAYS.' jours. Le jeton actuel de ce client, s’il en a un, cesse immédiatement de fonctionner.')
            ->modalContent(fn (array $arguments) => $issued($arguments) ? self::tokenSteps($arguments['token']) : null)
            ->modalSubmitActionLabel('Générer')
            ->modalSubmitAction(fn (Action $action, array $arguments) => $issued($arguments) ? false : $action)
            ->modalCancelActionLabel(fn (array $arguments) => $issued($arguments) ? 'Fermer' : 'Annuler')
            ->action(function (Action $action, ApiClient $record, Component $livewire) {
                $livewire->mergeMountedActionArguments(['token' => $record->issueToken()]);
                $action->halt();
            });
    }

    private static function tokenSteps(string $token): HtmlString
    {
        $code = fn (string $text) => '<code style="user-select:all;overflow-wrap:anywhere">'.e($text).'</code>';
        $url = rtrim(config('app.url'), '/').'/api/v1/servers';

        return new HtmlString(
            '<p>Jeton : '.$code($token).'</p>'
            .'<p style="margin-top:.75rem">À conserver côté serveur du site, jamais dans du code envoyé au navigateur. Essai depuis une adresse autorisée :</p>'
            .'<p style="margin-top:.25rem">'.$code("curl -H 'Authorization: Bearer {$token}' {$url}").'</p>'
        );
    }
}
