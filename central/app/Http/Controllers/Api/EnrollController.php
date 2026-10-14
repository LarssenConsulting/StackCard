<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentToken;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrollController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:255'],
            'machine_id' => ['nullable', 'string', 'max:64'],
            'hostname' => ['required', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            $enrollment = EnrollmentToken::where('token_hash', EnrollmentToken::hash($data['enrollment_token']))
                ->lockForUpdate()
                ->first();
            $server = $enrollment?->server;

            if (! $enrollment || ! $enrollment->isUsable() || $server->status === Server::STATUS_REVOKED) {
                Log::warning('discover.enroll.refused', ['ip' => $request->ip()]);

                return response()->json(['message' => 'Jeton d’enrôlement invalide, expiré ou déjà utilisé.'], 410);
            }

            $machineId = $data['machine_id'] ?? null;
            $other = $machineId
                ? Server::where('machine_id', $machineId)->whereKeyNot($server->id)->first()
                : null;
            if ($other) {
                return response()->json([
                    'message' => "Cette machine est déjà enrôlée comme « {$other->name} ». Révoquez ou supprimez ce serveur d’abord.",
                ], 409);
            }

            $enrollment->update(['used_at' => now()]);
            $server->tokens()->delete();
            $server->update([
                'status' => Server::STATUS_ACTIVE,
                'machine_id' => $machineId,
                'hostname' => $data['hostname'],
                'last_source_ip' => $request->ip(),
            ]);
            $token = $server->createToken('agent', [Server::AGENT_ABILITY]);

            Log::info('discover.enroll.accepted', ['server_id' => $server->id, 'ip' => $request->ip()]);

            return response()->json(['server_id' => $server->id, 'token' => $token->plainTextToken], 201);
        });
    }
}
