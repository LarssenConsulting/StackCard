<?php

namespace Database\Seeders;

use App\Actions\IngestSnapshot;
use App\Http\Requests\InventoryRequest;
use App\Models\Server;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

/**
 * A fake server (PHP sites on several versions, Node apps with and without a vhost) to see the
 * admin panel without a real agent: php artisan db:seed --class=DemoServerSeeder
 * Running it again refreshes the same server.
 */
class DemoServerSeeder extends Seeder
{
    public const NAME = 'demo-web01';

    public function run(IngestSnapshot $ingest): void
    {
        $snapshot = json_decode(file_get_contents(__DIR__.'/demo/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['collected_at'] = now()->toIso8601ZuluString();

        // Same checks as an agent's snapshot, so the demo never drifts from the contract.
        $rules = InventoryRequest::create('/', 'POST', $snapshot)->rules();
        $snapshot = Validator::make($snapshot, $rules)->validate();

        $server = Server::firstOrCreate(['name' => self::NAME], [
            'machine_id' => $snapshot['server']['machine_id'],
            'status' => Server::STATUS_ACTIVE,
            'notes' => 'Serveur fictif créé par DemoServerSeeder.',
        ]);

        $ingest($server, $snapshot, '10.0.0.50');
    }
}
