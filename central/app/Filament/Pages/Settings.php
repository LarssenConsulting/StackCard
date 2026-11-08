<?php

namespace App\Filament\Pages;

use App\Support\DisplaySettings;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Site-wide settings, admins only. */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Réglages';

    protected static ?string $navigationLabel = 'Général';

    protected static ?string $title = 'Réglages généraux';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 91;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'timezone' => DisplaySettings::timezone(),
            'date_format' => DisplaySettings::dateFormat(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $timezones = DateTimeZone::listIdentifiers();

        return $schema
            ->statePath('data')
            ->components([
                Select::make('timezone')
                    ->label('Fuseau horaire')
                    ->helperText('Pour l’affichage uniquement : la base et l’API restent en UTC.')
                    ->options(array_combine($timezones, $timezones))
                    ->searchable()
                    ->required(),
                Select::make('date_format')
                    ->label('Format de date')
                    ->options(DisplaySettings::DATE_FORMATS)
                    ->required(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Enregistrer')->submit('save')]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        DisplaySettings::save($state['timezone'], $state['date_format']);

        Notification::make()->title('Réglages enregistrés')->success()->send();
    }
}
