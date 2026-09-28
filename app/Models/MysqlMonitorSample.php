<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;

class MysqlMonitorSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'database_server_id', 'organization_id', 'sampled_at', 'row_lock_waits',
        'row_lock_time_ms', 'threads_connected', 'threads_running',
        'waiting_transactions', 'diagnostic_status',
    ];

    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }
}
