<?php

/**
 * Wait for the database to accept TCP connections, then get out of the way.
 *
 * Railway's private network is not resolvable the instant a container starts,
 * so `migrate` on the first boot can lose a race with DNS and take the whole
 * deploy with it.
 *
 * This is a plain socket probe rather than a PDO connect on purpose: a failed
 * PDO connect can sit for 30 seconds before it gives up, so a retry loop built
 * on one can stall a deploy for a quarter of an hour and hide the real error.
 * Two seconds per attempt, capped at about two minutes, then it exits 0 either
 * way - `migrate` is the authority on whether the database really works, and
 * its error message is far more useful than anything this could print.
 */
$url = getenv('DB_URL') ?: getenv('DATABASE_URL');

if ($url) {
    $parts = parse_url($url);
    $host = $parts['host'] ?? null;
    $port = $parts['port'] ?? 5432;
} else {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: 5432;
}

if (! $host) {
    fwrite(STDERR, "No database host configured; letting migrate report it.\n");
    exit(0);
}

for ($attempt = 1; $attempt <= 30; $attempt++) {
    $socket = @fsockopen($host, (int) $port, $errno, $errstr, 2);

    if ($socket) {
        fclose($socket);
        fwrite(STDERR, "Database reachable at {$host}:{$port}.\n");
        exit(0);
    }

    sleep(2);
}

fwrite(STDERR, "Database at {$host}:{$port} unreachable after ~2 minutes; continuing so migrate reports the real error.\n");
exit(0);
