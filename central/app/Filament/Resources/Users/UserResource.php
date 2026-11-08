<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/** Accounts of the panel; `UserPolicy` keeps it to admins. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $modelLabel = 'utilisateur';

    protected static ?string $pluralModelLabel = 'utilisateurs';

    protected static ?int $navigationSort = 89;

    public static function form(Schema $schema): Schema
    {
        $isSelf = fn (?User $record) => $record !== null && $record->is(auth()->user());

        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(255),
            TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('role')
                ->label('Rôle')
                ->options(User::ROLES)
                ->default(User::ROLE_USER)
                ->required()
                // A disabled field is not saved: an admin cannot demote itself, so one always remains.
                ->disabled($isSelf)
                ->helperText(fn (?User $record) => $isSelf($record) ? 'Vous ne pouvez pas changer votre propre rôle.' : null),
            TextInput::make('password')
                ->label(fn (?User $record) => $record ? 'Nouveau mot de passe' : 'Mot de passe provisoire')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->rule(Password::min(12))
                ->required(fn (?User $record) => $record === null)
                ->dehydrated(fn (?string $state) => filled($state))
                // Changing it here would end the current session: the profile page handles that case.
                ->hidden($isSelf)
                ->helperText(fn (?User $record) => $record
                    ? 'Laisser vide pour le conserver.'
                    : 'À transmettre à la personne, qui le changera dans son profil. L’application d’authentification lui sera demandée à la première connexion.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable()->sortable(),
                TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => User::ROLES[$state] ?? $state)
                    ->color(fn (string $state) => $state === User::ROLE_ADMIN ? 'primary' : 'gray')
                    ->sortable(),
                TextColumn::make('created_at')->label('Créé le')->date()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
