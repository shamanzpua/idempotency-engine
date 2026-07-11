<?php

declare(strict_types=1);

/*
 * CLI worker for claim concurrency stress tests.
 *
 * Usage: php worker.php <backend> <key> <scope> <ready-file> <go-file> <result-file>
 *
 * Connects to the backend, signals readiness, waits for the shared go-file
 * barrier, performs a single claim() and writes the outcome to the result file.
 * Connection settings come from the same environment variables as the
 * integration suites (DB_*, PG_DB_*, REDIS_DSN).
 */

use Shamanzpua\Idempotency\Contract\IdempotencyStore;
use Shamanzpua\Idempotency\Core\Model\ErrorDetails;
use Shamanzpua\Idempotency\Core\ValueObject\ExecutionId;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;
use Shamanzpua\Idempotency\Core\ValueObject\Ttl;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\MySqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\Dialect\PostgreSqlDialect;
use Shamanzpua\Idempotency\Infrastructure\Store\Pdo\PdoIdempotencyStore;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\Client\PredisClient;
use Shamanzpua\Idempotency\Infrastructure\Store\Redis\RedisIdempotencyStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var list<string> $args */
$args = $_SERVER['argv'] ?? [];
$backend = $args[1] ?? '';
$key = $args[2] ?? '';
$scope = $args[3] ?? '';
$readyFile = $args[4] ?? '';
$goFile = $args[5] ?? '';
$resultFile = $args[6] ?? '';
$mode = $args[7] ?? 'claim';          // claim | seedfail
$reclaimFailed = ($args[8] ?? '0') === '1';

$createStore = static function (string $backend): IdempotencyStore {
    switch ($backend) {
        case 'mysql':
            $pdo = new \PDO(
                (string) getenv('DB_DSN'),
                (string) getenv('DB_USER'),
                (string) getenv('DB_PASSWORD'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            return new PdoIdempotencyStore($pdo, dialect: new MySqlDialect());
        case 'postgres':
            $pdo = new \PDO(
                (string) getenv('PG_DB_DSN'),
                (string) getenv('PG_DB_USER'),
                (string) getenv('PG_DB_PASSWORD'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            return new PdoIdempotencyStore($pdo, dialect: new PostgreSqlDialect());
        case 'redis':
            return new RedisIdempotencyStore(new PredisClient(new \Predis\Client((string) getenv('REDIS_DSN'))));
        default:
            throw new \InvalidArgumentException(sprintf('Unknown backend "%s".', $backend));
    }
};

try {
    if ($key === '' || $scope === '' || $readyFile === '' || $goFile === '' || $resultFile === '') {
        throw new \InvalidArgumentException('Missing worker arguments.');
    }

    $store = $createStore($backend);
    $fingerprint = Fingerprint::fromString('fp-stress');

    // Seed mode runs before any contention (no barrier): leave a FAILED record
    // so a later reclaim round can contend on the FAILED -> IN_PROGRESS path.
    if ($mode === 'seedfail') {
        $owner = ExecutionId::generate();
        $now = new \DateTimeImmutable();
        $store->claim($key, $scope, $fingerprint, $owner, Ttl::fromSeconds(60), $now);
        $store->fail($key, $scope, $owner, new ErrorDetails('SeedException', 'seed', 0), $now);
        file_put_contents($resultFile, 'seeded');
        exit(0);
    }

    touch($readyFile);

    $deadline = microtime(true) + 15.0;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            throw new \RuntimeException('Barrier timeout: go-file never appeared.');
        }
        usleep(200);
    }

    $claim = $store->claim(
        key: $key,
        scope: $scope,
        fingerprint: $fingerprint,
        executionId: ExecutionId::generate(),
        ttl: Ttl::fromSeconds(60),
        now: new \DateTimeImmutable(),
        reclaimFailed: $reclaimFailed,
    );

    file_put_contents($resultFile, $claim->status->value);
    exit(0);
} catch (\Throwable $throwable) {
    file_put_contents($resultFile, sprintf('exception:%s: %s', $throwable::class, $throwable->getMessage()));
    exit(1);
}
