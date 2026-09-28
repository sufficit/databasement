<?php

use App\Enums\Ability;
use App\Models\DatabaseServer;
use App\Models\MysqlMonitorSample;
use App\Models\User;
use App\Services\Monitoring\MysqlLockMonitor;

test('monitoring samples require authentication and org visibility', function () {
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);

    $this->getJson("/api/v1/database-servers/{$server->id}/mysql-monitor")->assertUnauthorized();

    $user = User::factory()->withAbilities([])->create();
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-servers/{$server->id}/mysql-monitor")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    [$foreignOrg, $foreignServer] = foreignServer('mysql');
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/database-servers/{$foreignServer->id}/mysql-monitor")
        ->assertNotFound();
});

test('collect requires manage permission and only direct mysql servers', function () {
    $viewer = User::factory()->withAbilities([])->create();
    $operator = User::factory()->withAbilities([Ability::ManageDatabaseServers->value])->create();
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $postgres = DatabaseServer::factory()->create(['database_type' => 'postgres']);

    $this->actingAs($viewer, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/mysql-monitor/collect")
        ->assertForbidden();

    $this->actingAs($operator, 'sanctum')
        ->postJson("/api/v1/database-servers/{$postgres->id}/mysql-monitor/collect")
        ->assertUnprocessable();
});

test('collector stores baseline, detects increased waits, and never captures query text', function () {
    $server = DatabaseServer::factory()->create(['database_type' => 'mysql']);
    $pdo = Mockery::mock(\PDO::class);
    $statement = Mockery::mock(\PDOStatement::class);
    $statement->shouldReceive('fetchAll')->once()->andReturn([
        ['Variable_name' => 'Innodb_row_lock_waits', 'Value' => 10],
        ['Variable_name' => 'Innodb_row_lock_time', 'Value' => 25],
        ['Variable_name' => 'Threads_connected', 'Value' => 4],
        ['Variable_name' => 'Threads_running', 'Value' => 1],
    ]);
    $diagnostic = Mockery::mock(\PDOStatement::class);
    $diagnostic->shouldReceive('fetchColumn')->once()->andReturn(2);
    $pdo->shouldReceive('query')->once()->with("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Threads_connected', 'Threads_running')")->andReturn($statement);
    $pdo->shouldReceive('query')->never()->with("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'");

    $collector = app(MysqlLockMonitor::class);
    $first = $collector->sample($server, $pdo);
    expect($first['insight'])->toBe('baseline');
    expect(MysqlMonitorSample::count())->toBe(1);

    $previous = MysqlMonitorSample::latest('id')->first();
    $previous->row_lock_waits = 9;
    $previous->row_lock_time_ms = 20;
    $previous->save();

    $statement2 = Mockery::mock(\PDOStatement::class);
    $statement2->shouldReceive('fetchAll')->once()->andReturn([
        ['Variable_name' => 'Innodb_row_lock_waits', 'Value' => 11],
        ['Variable_name' => 'Innodb_row_lock_time', 'Value' => 80],
        ['Variable_name' => 'Threads_connected', 'Value' => 5],
        ['Variable_name' => 'Threads_running', 'Value' => 2],
    ]);
    $pdo2 = Mockery::mock(\PDO::class);
    $pdo2->shouldReceive('query')->once()->with("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Threads_connected', 'Threads_running')")->andReturn($statement2);
    $pdo2->shouldReceive('query')->once()->with("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->andReturn($diagnostic);

    $second = $collector->sample($server, $pdo2);
    expect($second['insight'])->toBe('lock_contention_detected');
    expect($second['delta_waits'])->toBe(2);
    expect($second['delta_lock_time_ms'])->toBe(60);
    expect($second['sample']['waiting_transactions'])->toBe(2);
    expect(MysqlMonitorSample::count())->toBe(2);
});
