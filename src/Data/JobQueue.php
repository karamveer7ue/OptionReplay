<?php
declare(strict_types=1);

namespace App\Data;

use App\Core\Database;

/** Persistent, idempotent fetch job queue. */
final class JobQueue
{
    public static function key(string $kind, int $underlyingId, ?int $instrumentId, ?string $from, ?string $to, string $source = 'kotak', ?string $params = null): string
    {
        return sha1(implode('|', [$source, $kind, $underlyingId, $instrumentId ?? 0, $from ?? '', $to ?? '', $params ?? '']));
    }

    /** Enqueue if absent. Returns true when a new job was created. */
    public static function enqueue(string $kind, int $underlyingId, ?int $instrumentId, ?string $from, ?string $to, int $priority = 100, string $source = 'kotak', ?string $params = null): bool
    {
        $stmt = Database::run(
            'INSERT IGNORE INTO fetch_jobs (job_key, kind, source, underlying_id, instrument_id, from_date, to_date, params, priority) VALUES (?,?,?,?,?,?,?,?,?)',
            [self::key($kind, $underlyingId, $instrumentId, $from, $to, $source, $params), $kind, $source, $underlyingId, $instrumentId, $from, $to, $params, $priority],
        );
        return $stmt->rowCount() > 0;
    }

    /** Atomically claim the next runnable job. @return array<string,mixed>|null */
    public static function claimNext(): ?array
    {
        return Database::transaction(function (): ?array {
            $job = Database::one(
                "SELECT * FROM fetch_jobs WHERE status IN ('pending') AND (not_before IS NULL OR not_before <= NOW())
                 ORDER BY priority ASC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
            );
            if ($job === null) {
                return null;
            }
            Database::run("UPDATE fetch_jobs SET status='running', attempts = attempts + 1 WHERE id = ?", [$job['id']]);
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        });
    }

    public static function complete(int $id, int $rows): void
    {
        Database::run("UPDATE fetch_jobs SET status = ?, rows_written = ?, last_error = NULL, finished_at = NOW() WHERE id = ?", [$rows > 0 ? 'done' : 'empty', $rows, $id]);
    }

    public static function defer(int $id, int $seconds, string $reason): void
    {
        Database::run("UPDATE fetch_jobs SET status='pending', attempts = GREATEST(attempts - 1, 0), not_before = DATE_ADD(NOW(), INTERVAL ? SECOND), last_error = ? WHERE id = ?", [$seconds, $reason, $id]);
    }

    public static function fail(int $id, int $attempts, string $error, int $maxAttempts = 4): void
    {
        if ($attempts >= $maxAttempts) {
            Database::run("UPDATE fetch_jobs SET status='failed', last_error = ?, finished_at = NOW() WHERE id = ?", [$error, $id]);
            return;
        }
        $backoff = min(3600, 60 * (2 ** $attempts));
        Database::run("UPDATE fetch_jobs SET status='pending', not_before = DATE_ADD(NOW(), INTERVAL ? SECOND), last_error = ? WHERE id = ?", [$backoff, $error, $id]);
    }

    /** Reset jobs stuck in 'running' (e.g. after a killed process). */
    public static function releaseStale(int $olderThanMinutes = 30): int
    {
        return Database::run("UPDATE fetch_jobs SET status='pending' WHERE status='running' AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$olderThanMinutes])->rowCount();
    }

    /** @return array<string,int> */
    public static function counts(?int $underlyingId = null): array
    {
        $rows = $underlyingId === null
            ? Database::all('SELECT status, COUNT(*) n FROM fetch_jobs GROUP BY status')
            : Database::all('SELECT status, COUNT(*) n FROM fetch_jobs WHERE underlying_id = ? GROUP BY status', [$underlyingId]);
        $out = ['pending' => 0, 'running' => 0, 'done' => 0, 'empty' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
