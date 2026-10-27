<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DatabaseType;
use App\Http\Controllers\Controller;
use App\Models\DatabaseServer;
use App\Models\MysqlMonitorSample;
use App\Services\Monitoring\MysqlLockMonitor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class MysqlMonitorController extends Controller
{
    use AuthorizesRequests;

    public function index(DatabaseServer $databaseServer): JsonResponse
    {
        $this->authorize('view', $databaseServer);
        abort_unless($databaseServer->database_type === DatabaseType::MYSQL, 422, 'MySQL/MariaDB only.');

        return response()->json(['data' => MysqlMonitorSample::where('database_server_id', $databaseServer->id)
            ->orderByDesc('id')->limit(100)->get()]);
    }

    public function collect(DatabaseServer $databaseServer, MysqlLockMonitor $monitor): JsonResponse
    {
        $this->authorize('update', $databaseServer);
        abort_unless($databaseServer->database_type === DatabaseType::MYSQL && $databaseServer->agent_id === null, 422, 'Direct/SSH MySQL/MariaDB only.');

        try {
            return response()->json($monitor->collect($databaseServer), 201);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Monitoring sample could not be collected. Verify connectivity and database privileges.'], 503);
        }
    }
}
