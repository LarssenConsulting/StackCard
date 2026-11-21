<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\Server;
use App\Models\ServerSection;
use App\Support\DisplaySettings;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * « Boot » tab: boot time and kernel from the latest `kernel` section, then the local filesystems
 * mounted at the last run (`mounts` section, agent 0.7). The rows come from an agent, not the database.
 */
class BootRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';

    protected static ?string $title = 'Boot';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $server = $this->getOwnerRecord();

        return $table
            ->heading('Systèmes de fichiers montés')
            ->description(fn () => new HtmlString(view('filament.server-boot.summary', self::summaryData($server))->render()))
            ->emptyStateHeading(fn () => self::emptyLabel($server))
            ->emptyStateIcon('heroicon-o-circle-stack')
            ->paginated(false)
            // Array rows, not models: the relation manager's default row action expects a model.
            ->recordAction(null)
            ->recordUrl(null)
            ->records(fn (?string $search) => self::matching(self::mountRows($server), $search))
            ->columns([
                TextColumn::make('target')->label('Point de montage')->fontFamily('mono')->searchable(),
                TextColumn::make('source')->label('Source')->fontFamily('mono')->wrap(),
                TextColumn::make('fstype')->label('Type')->badge()->color('gray'),
                TextColumn::make('access')
                    ->label('Accès')
                    ->badge()
                    ->color(fn (string $state) => $state === 'lecture seule' ? 'danger' : 'success'),
                TextColumn::make('options')->label('Options')->fontFamily('mono')->wrap()->placeholder('—'),
            ]);
    }

    /** What the tab header shows; the view escapes every string. */
    public static function summaryData(Server $server): array
    {
        $kernel = $server->latestValidSection('kernel')?->data ?? [];
        $latest = self::latestMounts($server);
        $rows = self::mountRows($server);
        $bootTime = is_string($kernel['boot_time'] ?? null) ? self::parseDate($kernel['boot_time']) : null;

        return [
            'bootTime' => $bootTime ? DisplaySettings::formatDateTime($bootTime) : null,
            'uptime' => $bootTime?->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE),
            'kernel' => is_string($kernel['running'] ?? null) ? $kernel['running'] : null,
            'reboot' => match ($server->reboot_required) {
                true => ['label' => 'Requis', 'color' => 'warning'],
                false => ['label' => 'Non requis', 'color' => 'gray'],
                default => ['label' => 'Inconnu', 'color' => 'gray'],
            },
            'mounted' => $rows === null ? null : count($rows),
            'readOnly' => $rows === null ? [] : array_values(array_map(
                fn (array $row) => $row['target'], array_filter($rows, fn (array $row) => $row['access'] === 'lecture seule')
            )),
            'collectedAt' => $latest?->collected_at ? DisplaySettings::formatDateTime($latest->collected_at) : null,
            'error' => self::errorOf($latest),
        ];
    }

    /**
     * Rows of the latest valid `mounts` section, or null when none was received or findmnt was not read.
     *
     * @return list<array{target: string, source: string, fstype: string, access: string, options: string}>|null
     */
    public static function mountRows(Server $server): ?array
    {
        $filesystems = $server->latestValidSection('mounts')?->data['filesystems'] ?? null;
        if (! is_array($filesystems)) {
            return null;
        }

        $rows = [];
        foreach ($filesystems as $fs) {
            if (! is_array($fs) || ! is_string($fs['target'] ?? null)) {
                continue; // malformed agent entry: skipped, never fatal
            }
            $options = array_values(array_filter(is_array($fs['options'] ?? null) ? $fs['options'] : [], 'is_string'));
            $rows[] = [
                'target' => $fs['target'],
                'source' => is_string($fs['source'] ?? null) ? $fs['source'] : '',
                'fstype' => is_string($fs['fstype'] ?? null) ? $fs['fstype'] : '',
                'access' => ($fs['read_only'] ?? false) === true || in_array('ro', $options, true) ? 'lecture seule' : 'lecture-écriture',
                'options' => implode(',', $options),
            ];
        }

        return $rows;
    }

    private static function latestMounts(Server $server): ?ServerSection
    {
        return $server->sections()->where('section', 'mounts')->orderByDesc('id')->first();
    }

    private static function errorOf(?ServerSection $section): ?string
    {
        if ($section === null) {
            return null;
        }
        if (filled($section->error)) {
            return $section->error === 'not_installed' ? 'findmnt n’est pas installé sur ce serveur' : (string) $section->error;
        }
        $source = $section->source_errors['findmnt'] ?? null;

        return match (true) {
            $source === 'not_installed' => 'findmnt n’est pas installé sur ce serveur',
            filled($source) && is_string($source) => $source,
            default => null,
        };
    }

    private static function emptyLabel(Server $server): string
    {
        return match (true) {
            self::latestMounts($server) === null => 'Pas encore remonté par l’agent (agent 0.7 ou plus récent)',
            self::mountRows($server) === null => 'Points de montage non lus à la dernière collecte',
            default => 'Aucun système de fichiers ext4, xfs, btrfs ou vfat monté',
        };
    }

    private static function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Rows holding the search text, each with the unique `key` Filament asks of array records. */
    private static function matching(?array $rows, ?string $search): array
    {
        $found = [];
        foreach ($rows ?? [] as $index => $row) {
            if (blank($search) || str_contains(mb_strtolower(implode(' ', $row)), mb_strtolower($search))) {
                $found[$index] = ['key' => (string) $index, ...$row];
            }
        }

        return $found;
    }
}
