<?php

namespace App\Console\Commands;

use App\Models\Snapshot;
use Illuminate\Console\Command;

class PruneSnapshots extends Command
{
    protected $signature = 'discover:prune-snapshots';

    protected $description = 'Supprime les snapshots de plus de '.Snapshot::RETENTION_DAYS.' jours (le dernier de chaque serveur est toujours conservé)';

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

        return self::SUCCESS;
    }
}
