<?php

namespace App\Console\Commands;

use App\Models\ServerSection;
use App\Models\Snapshot;
use Illuminate\Console\Command;

class PruneSnapshots extends Command
{
    protected $signature = 'discover:prune-snapshots';

    protected $description = 'Supprime les snapshots et l’historique des sections de plus de '.Snapshot::RETENTION_DAYS.' jours (le dernier de chaque serveur ou section est toujours conservé)';

    public function handle(): int
    {
        // Fetched first: MySQL refuses a DELETE whose subquery reads the same table.
        $latestPerServer = Snapshot::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('server_id')
            ->pluck('id')
            ->all();

        $deleted = Snapshot::query()
            ->where('received_at', '<', now()->subDays(Snapshot::RETENTION_DAYS))
            ->whereNotIn('id', $latestPerServer)
            ->delete();

        $this->info("{$deleted} snapshot(s) supprimé(s).");

        // Keep the latest row of each (server, section), and the latest row that still has data.
        $latestSections = ServerSection::query()->selectRaw('MAX(id) as id')->groupBy('server_id', 'section')->pluck('id')->all();
        $latestWithData = ServerSection::query()->whereNotNull('data')->selectRaw('MAX(id) as id')->groupBy('server_id', 'section')->pluck('id')->all();

        $deletedSections = 0;
        ServerSection::query()
            ->where('last_seen_at', '<', now()->subDays(Snapshot::RETENTION_DAYS))
            ->whereNotIn('id', [...$latestSections, ...$latestWithData])
            ->select('id')
            ->chunkById(500, function ($rows) use (&$deletedSections) {
                $deletedSections += ServerSection::whereIn('id', $rows->pluck('id'))->delete();
            });
        $this->info("{$deletedSections} état(s) de section supprimé(s).");

        return self::SUCCESS;
    }
}
