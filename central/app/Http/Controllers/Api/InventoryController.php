<?php

namespace App\Http\Controllers\Api;

use App\Actions\IngestSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryRequest;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    public function __invoke(InventoryRequest $request, IngestSnapshot $ingest): JsonResponse
    {
        $server = $request->user();
        abort_unless($server instanceof Server && $server->status === Server::STATUS_ACTIVE, 403);

        $snapshot = $ingest($server, $request->validated(), $request->ip());

        Log::info('discover.inventory.received', [
            'server_id' => $server->id, 'snapshot_id' => $snapshot->id, 'ip' => $request->ip(),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
