<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Guideline Paths
    |--------------------------------------------------------------------------
    |
    | Boost writes Claude Code guidelines into AGENTS.md so a single file holds
    | both the generated guidelines and this project's own rules. CLAUDE.md
    | only imports AGENTS.md. Remaining options come from the package config.
    |
    */

    'agents' => [
        'claude_code' => [
            'guidelines_path' => 'AGENTS.md',
        ],
    ],

];
