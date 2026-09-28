<?php

namespace App\Services\Monitoring;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Models\MysqlMonitorSample;
use App\Services\SshTunnelService;
use PDO;
use PDOException;

/** Read-only, bounded MySQL/MariaDB sampler. Never retains SQL text or credentials. */
class MysqlLockMonitor
{
    public function __construct(private readonly SshTunnelService $tunnel) {}

    /** @return array<string, mixed> */
    public function collect(DatabaseServer $server): array
    {
        if ($server->database_type !== DatabaseType::MYSQL || $server->agent_id !== null) {
            throw new \InvalidArgumentException('Monitoring requires a directly reachable MySQL/MariaDB server (or SSH tunnel).');
        }

        $endpoint = ['host' => $server->host, 'port' => $server->port];
        $tunneled = $server->requiresSshTunnel();

        try {
            if ($tunneled) {
                $endpoint = $this->tunnel->establish($server);
            }
            $host = $endpoint['host'] === 'localhost' ? '127.0.0.1' : $endpoint['host'];
            $dsn = sprintf('mysql:host=%s;port=%d', $host, $endpoint['port']);
            $pdo = new PDO($dsn, $server->username, $server->getDecryptedPassword(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            return $this->sample($server, $pdo);
        } finally {
            if ($tunneled) {
                $this->tunnel->close();
            }
        }
    }

    /** @return array<string, mixed> */
    public function sample(DatabaseServer $server, PDO $pdo): array
    {
        $previous = MysqlMonitorSample::where('database_server_id', $server->id)->latest('id')->first();
        $status = [];
        $rows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Threads_connected', 'Threads_running')")->fetchAll();
        foreach ($rows as $row) {
            $status[strtolower($row['Variable_name'])] = (int) $row['Value'];
        }
        $waits = $status['innodb_row_lock_waits'] ?? null;
        $time = $status['innodb_row_lock_time'] ?? null;
        $delta = $this->delta($previous?->row_lock_waits, $waits);
        $diagnosticStatus = 'idle';
        $waiting = null;

        // Trigger detailed inspection only when waits increased; never capture query text.
        if ($delta !== null && $delta > 0) {
            try {
                $waiting = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn();
                $diagnosticStatus = 'collected';
            } catch (PDOException) {
                // The read-only account may lack PROCESS; counters still remain useful.
                $diagnosticStatus = 'permission_or_unavailable';
            }
        }

        $sample = MysqlMonitorSample::create([
            'database_server_id' => $server->id,
            'organization_id' => $server->organization_id,
            'sampled_at' => now(),
            'row_lock_waits' => $waits,
            'row_lock_time_ms' => $time,
            'threads_connected' => $status['threads_connected'] ?? null,
            'threads_running' => $status['threads_running'] ?? null,
            'waiting_transactions' => $waiting,
            'diagnostic_status' => $diagnosticStatus,
        ]);

        $waitTimeDelta = $this->delta($previous?->row_lock_time_ms, $time);

        return [
            'sample' => $sample->only(['sampled_at', 'row_lock_waits', 'row_lock_time_ms', 'threads_connected', 'threads_running', 'waiting_transactions', 'diagnostic_status']),
            'delta_waits' => $delta,
            'delta_lock_time_ms' => $waitTimeDelta,
            'insight' => $delta === null ? 'baseline' : ($delta > 0 ? 'lock_contention_detected' : 'no_new_row_lock_waits'),
        ];
    }

    private function delta(?int $previous, ?int $current): ?int
    {
        // Reset/wrap or missing counter: report a new baseline, not a false spike.
        return $previous === null || $current === null || $current < $previous ? null : $current - $previous;
    }
}
