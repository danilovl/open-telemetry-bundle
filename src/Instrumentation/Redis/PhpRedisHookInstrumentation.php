<?php declare(strict_types=1);

namespace Danilovl\OpenTelemetryBundle\Instrumentation\Redis;

final class PhpRedisHookInstrumentation extends AbstractRedisHookInstrumentation
{
    /**
     * @var list<string>
     */
    public const array HOOKED_CLASSES = ['Redis', 'RedisCluster'];

    /**
     * @var list<string>
     */
    public const array COMMANDS = [
        'get', 'set', 'setex', 'psetex', 'setnx', 'getset', 'getdel', 'getex', 'mget', 'mset', 'msetnx',
        'incr', 'incrby', 'incrbyfloat', 'decr', 'decrby', 'append', 'strlen', 'getrange', 'setrange',
        'del', 'unlink', 'exists', 'expire', 'expireat', 'pexpire', 'pexpireat', 'ttl', 'pttl', 'persist',
        'type', 'rename', 'renamenx', 'keys', 'scan', 'touch', 'copy',
        'hget', 'hset', 'hsetnx', 'hmget', 'hmset', 'hgetall', 'hdel', 'hexists', 'hincrby', 'hincrbyfloat',
        'hkeys', 'hvals', 'hlen', 'hscan', 'hstrlen',
        'lpush', 'rpush', 'lpop', 'rpop', 'blpop', 'brpop', 'brpoplpush', 'rpoplpush', 'lmove', 'blmove',
        'llen', 'lrange', 'lindex', 'lset', 'lrem', 'ltrim', 'linsert', 'lpos',
        'sadd', 'srem', 'smembers', 'sismember', 'smismember', 'scard', 'spop', 'srandmember', 'sscan',
        'sinter', 'sunion', 'sdiff', 'sinterstore', 'sunionstore', 'sdiffstore', 'smove',
        'zadd', 'zrem', 'zrange', 'zrevrange', 'zrangebyscore', 'zrevrangebyscore', 'zscore', 'zrank',
        'zrevrank', 'zcard', 'zcount', 'zincrby', 'zscan', 'zremrangebyscore', 'zremrangebyrank', 'zpopmin',
        'zpopmax', 'bzpopmin', 'bzpopmax', 'zmscore',
        'xadd', 'xread', 'xreadgroup', 'xack', 'xlen', 'xrange', 'xrevrange', 'xdel', 'xtrim', 'xgroup',
        'xpending', 'xclaim', 'xautoclaim', 'xinfo',
        'eval', 'evalsha', 'script', 'multi', 'exec', 'discard', 'watch', 'unwatch', 'publish',
        'flushdb', 'flushall', 'select', 'ping', 'info', 'dbsize',
        'pfadd', 'pfcount', 'pfmerge', 'setbit', 'getbit', 'bitcount', 'geoadd', 'geodist', 'geosearch',
    ];

    protected function resolveCommand(array $params, string $function): array
    {
        $command = mb_strtoupper($function !== '' ? $function : 'unknown');

        return [$command, self::extractKey($command, $params[0] ?? null)];
    }

    protected function getHookTargets(): array
    {
        $targets = [];

        foreach (self::HOOKED_CLASSES as $class) {
            if (!class_exists($class, false)) {
                continue;
            }

            foreach (self::COMMANDS as $method) {
                if (method_exists($class, $method)) {
                    $targets[] = [$class, $method];
                }
            }
        }

        return $targets;
    }

    protected function getClientName(): string
    {
        return 'phpredis';
    }
}
