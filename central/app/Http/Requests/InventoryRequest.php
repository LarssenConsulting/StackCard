<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schema v1 of the agent snapshot. Only validated keys are kept, so anything
 * outside this schema is dropped before it reaches the database.
 */
class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $name = ['nullable', 'string', 'max:255'];
        $path = ['nullable', 'string', 'max:1024'];
        // A section that is not null must carry its lists, but they may be
        // empty: Apache without vhosts, MySQL without grants are real cases.
        $listOf = fn (string $section, int $max) => $this->input($section) === null
            ? ['prohibited']
            : ['present', 'array', "max:{$max}"];

        return [
            'schema_version' => ['required', 'integer', Rule::in([1])],
            'collected_at' => ['required', 'date'],
            'agent_version' => ['nullable', 'string', 'max:32'],

            'server' => ['required', 'array'],
            'server.hostname' => ['required', 'string', 'max:255'],
            'server.fqdn' => $name,
            'server.machine_id' => ['nullable', 'string', 'max:64'],
            'server.primary_ip' => ['nullable', 'ip'],
            'server.os' => $name,
            'server.kernel' => $name,
            'server.virtualization' => ['nullable', 'string', 'max:64'],
            'server.interfaces' => ['present', 'array', 'max:64'],
            'server.interfaces.*.name' => ['required', 'string', 'max:64'],
            'server.interfaces.*.addresses' => ['present', 'array', 'max:64'],
            'server.interfaces.*.addresses.*' => ['string', 'max:64'],

            'apache' => ['present', 'nullable', 'array'],
            'apache.version' => $name,
            'apache.sites' => $listOf('apache', 2000),
            'apache.sites.*.server_name' => $name,
            'apache.sites.*.aliases' => ['present', 'array', 'max:200'],
            'apache.sites.*.aliases.*' => ['string', 'max:255'],
            'apache.sites.*.port' => ['nullable', 'integer', 'between:1,65535'],
            'apache.sites.*.https' => ['required', 'boolean'],
            'apache.sites.*.cert_expires_at' => ['nullable', 'date'],
            'apache.sites.*.type' => ['required', Rule::in(array_keys(Site::TYPES))],
            'apache.sites.*.document_root' => $path,
            'apache.sites.*.app_dir' => $path,
            'apache.sites.*.upstream' => $path,
            'apache.sites.*.php_version' => ['nullable', 'string', 'max:16'],
            'apache.sites.*.system_user' => ['nullable', 'string', 'max:64'],
            'apache.sites.*.system_group' => ['nullable', 'string', 'max:64'],
            'apache.sites.*.owner_user' => ['nullable', 'string', 'max:64'],
            'apache.sites.*.owner_group' => ['nullable', 'string', 'max:64'],
            'apache.sites.*.process_manager' => $name,
            'apache.sites.*.config_file' => $path,
            'apache.sites.*.partial' => ['required', 'boolean'],
            'apache.sites.*.db_refs' => ['present', 'array', 'max:20'],
            'apache.sites.*.db_refs.*.db_name' => ['required', 'string', 'max:64'],
            'apache.sites.*.db_refs.*.db_user' => ['nullable', 'string', 'max:80'],
            'apache.sites.*.db_refs.*.db_host' => $name,
            'apache.sites.*.db_refs.*.source' => $path,

            'mysql' => ['present', 'nullable', 'array'],
            'mysql.version' => $name,
            'mysql.databases' => $listOf('mysql', 10000),
            'mysql.databases.*.name' => ['required', 'string', 'max:64'],
            'mysql.databases.*.size_bytes' => ['required', 'integer', 'min:0'],
            'mysql.users' => $listOf('mysql', 10000),
            'mysql.users.*.user' => ['present', 'nullable', 'string', 'max:80'],
            // MariaDB roles live in mysql.user with an empty host.
            'mysql.users.*.host' => ['present', 'nullable', 'string', 'max:255'],
            'mysql.grants' => $listOf('mysql', 50000),
            'mysql.grants.*.user' => ['present', 'nullable', 'string', 'max:80'],
            'mysql.grants.*.host' => ['present', 'nullable', 'string', 'max:255'],
            'mysql.grants.*.database' => ['nullable', 'string', 'max:64'],
            'mysql.grants.*.privileges' => ['required', 'array', 'max:256'],
            'mysql.grants.*.privileges.*' => ['string', 'max:64'],
            'mysql.grants.*.global' => ['required', 'boolean'],

            'errors' => ['present', 'array', 'max:500'],
            'errors.*.collector' => ['required', 'string', 'max:32'],
            'errors.*.message' => ['required', 'string', 'max:4000'],
        ];
    }
}
