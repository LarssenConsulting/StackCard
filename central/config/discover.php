<?php

$installUrl = env('DISCOVER_AGENT_INSTALL_URL', 'https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh');

return [

    // Script that installs the agent (agent/packaging/install.sh), shown with each enrollment token.
    'agent_install_url' => $installUrl,

    // Latest agent version (one line, published next to install.sh), to flag outdated agents.
    // Empty: no check.
    'agent_version_url' => env('DISCOVER_AGENT_VERSION_URL', dirname($installUrl).'/version.txt'),

];
