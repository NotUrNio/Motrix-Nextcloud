<?php

declare(strict_types=1);

return [
    'routes' => [
        // Web UI entry point
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // REST API
        ['name' => 'api#get_status', 'url' => '/api/status', 'verb' => 'GET'],
        ['name' => 'api#get_stats', 'url' => '/api/stats', 'verb' => 'GET'],
        ['name' => 'api#get_tasks', 'url' => '/api/tasks', 'verb' => 'GET'],
        ['name' => 'api#get_task', 'url' => '/api/tasks/{taskId}', 'verb' => 'GET'],
        ['name' => 'api#add_task', 'url' => '/api/tasks', 'verb' => 'POST'],
        ['name' => 'api#pause_task', 'url' => '/api/tasks/{taskId}/pause', 'verb' => 'POST'],
        ['name' => 'api#resume_task', 'url' => '/api/tasks/{taskId}/resume', 'verb' => 'POST'],
        ['name' => 'api#delete_task_fallback', 'url' => '/api/tasks/{taskId}/delete', 'verb' => 'POST'],
        ['name' => 'api#sync_task', 'url' => '/api/tasks/{taskId}/sync', 'verb' => 'POST'],
        ['name' => 'api#scan_path', 'url' => '/api/scan', 'verb' => 'POST'],
        ['name' => 'api#get_settings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'api#save_settings', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'api#test_connection', 'url' => '/api/settings/test', 'verb' => 'POST'],
        ['name' => 'api#start_engine', 'url' => '/api/engine/start', 'verb' => 'POST'],

        // Admin Settings
        ['name' => 'settings#save', 'url' => '/settings', 'verb' => 'POST'],
    ]
];
