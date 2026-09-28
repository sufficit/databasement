# MySQL/MariaDB lock monitoring

Sufficit/Databasement adds a light, read-only monitor for direct or SSH-tunneled MySQL/MariaDB servers. It is designed for continuous sampling without capturing SQL statements, query parameters, credentials, or connection strings.

## What is collected

- `Innodb_row_lock_waits` and `Innodb_row_lock_time` deltas between samples
- `Threads_connected` and `Threads_running`
- `information_schema.INNODB_TRX` count in `LOCK WAIT`, only when waits increased
- A small status (`baseline`, `no_new_row_lock_waits`, `lock_contention_detected`)

Every observation is stored in `mysql_monitor_samples` with organization scoping.

## API

- `GET /api/v1/database-servers/{database_server}/mysql-monitor` — latest 100 samples.
- `POST /api/v1/database-servers/{database_server}/mysql-monitor/collect` — trigger one read-only sample; requires `manage-database-servers` and is throttled.

For continuous monitoring, call the collect endpoint every 15–30 seconds from the scheduler or an external poller. Keep the cadence conservative: on a quiet database the endpoint performs only `SHOW GLOBAL STATUS`; the InnoDB transaction detail is collected only while lock waits are increasing.

## Recommendations

- Prefer a dedicated read-only MySQL/MariaDB user with only `PROCESS` or, at minimum, privileges needed for `SHOW GLOBAL STATUS` and `information_schema.INNODB_TRX`.
- Do not enable generic full statement capture unless a specific incident requires it; that belongs in MySQL/MariaDB performance schema or application logs, not in Databasement.
- This does not classify `1205 Lock wait timeout` as deadlock, row-lock conflict, Galera contention, or metadata lock. It distinguishes counter movement from detailed lock wait evidence.
