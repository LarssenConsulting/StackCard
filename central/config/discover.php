<?php

return [

    // Script that installs the agent (agent/packaging/install.sh), shown with each enrollment token.
    'agent_install_url' => env('DISCOVER_AGENT_INSTALL_URL', 'https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh'),

];
