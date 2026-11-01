<?php

namespace App\Livewire\Firewall;

use App\Models\ServerSection;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One firewall of the server's « Pare-feu » tab, as a read-only table over the rows of the
 * latest `network` section. These rows are not database records: they come from an agent.
 */
abstract class FirewallTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $serverId;

    abstract protected function heading(): string;

    /** @return array{status: string, rows: list<array<string, mixed>>} */
    abstract protected function engine(?ServerSection $network): array;

    /** @return list<TextColumn> */
    abstract protected function columns(): array;

    public function table(Table $table): Table
    {
        $engine = $this->engine(
            ServerSection::query()->where('server_id', $this->serverId)->where('section', 'network')->orderByDesc('id')->first()
        );

        return $table
            ->heading($this->heading())
            ->description($engine['status'])
            ->emptyStateHeading($engine['status'])
            ->emptyStateIcon('heroicon-o-shield-exclamation')
            ->paginated(false)
            ->records(fn (?string $search) => $this->matching($engine['rows'], $search))
            ->columns($this->columns());
    }

    public function render(): View
    {
        return view('livewire.firewall.table');
    }

    protected static function actionColumn(): TextColumn
    {
        return TextColumn::make('action')
            ->label('Action')
            ->badge()
            ->placeholder('—')
            ->color(fn (?string $state) => match (strtolower((string) strtok((string) $state, ' '))) {
                'accept' => 'success',
                'drop', 'reject' => 'danger',
                default => 'gray',
            });
    }

    /** Rows holding the search text, each with the unique `key` Filament asks of array records. */
    private function matching(array $rows, ?string $search): array
    {
        $found = [];
        foreach ($rows as $index => $row) {
            if (blank($search) || str_contains(mb_strtolower(implode(' ', $row)), mb_strtolower($search))) {
                $found[$index] = ['key' => (string) $index, ...$row];
            }
        }

        return $found;
    }
}
