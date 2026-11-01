<?php

$installUrl = env('DISCOVER_AGENT_INSTALL_URL', 'https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh');

return [

    // Script that installs the agent (agent/packaging/install.sh), shown with each enrollment token.
    'agent_install_url' => $installUrl,

    // Latest agent version (one line, published next to install.sh), to flag outdated agents.
    // Empty: no check.
    'agent_version_url' => env('DISCOVER_AGENT_VERSION_URL', dirname($installUrl).'/version.txt'),

    // Reverse proxies in front of the central (Traefik...), comma-separated addresses or ranges.
    // Empty: the address of the connection is the caller's. Never `*`: see App\Support\TrustedProxyList.
    'trusted_proxies' => env('DISCOVER_TRUSTED_PROXIES', ''),

];
