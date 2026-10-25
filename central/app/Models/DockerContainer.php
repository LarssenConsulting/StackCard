<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Docker container of a server, running or not, with the host ports it publishes. */
class DockerContainer extends Model
{
    public const STATES = [
        'running' => 'En marche',
        'restarting' => 'Redémarre',
        'paused' => 'En pause',
        'created' => 'Créé',
        'exited' => 'Arrêté',
        'dead' => 'Mort',
    ];

    protected $fillable = [
        'server_id', 'container_id', 'name', 'image', 'image_version', 'state', 'ports',
        'compose_project', 'compose_service', 'compose_dir', 'restart_policy', 'started_at',
    ];

    protected function casts(): array
    {
        return [
            'ports' => 'array',
            'started_at' => 'datetime',
        ];
    }

    public function ports(): array
    {
        return array_values(array_unique(array_column($this->ports, 'port')));
    }

    /** Published on an address other machines can reach, not only behind a local reverse proxy. */
    public function isExposed(): bool
    {
        return collect($this->ports)->contains(fn (array $port) => ! in_array($port['address'], NodeApp::LOOPBACK, true));
    }

    /** "project / service" for a Compose container, else its name. */
    public function label(): string
    {
        return $this->compose_project && $this->compose_service
            ? "{$this->compose_project} / {$this->compose_service}"
            : $this->name;
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? (string) $this->state;
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
