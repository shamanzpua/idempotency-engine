<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Store\Redis;

final class LuaScripts
{
    public const CLAIM = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if raw == false then
    local record = cjson.encode({
        fingerprint = ARGV[1],
        execution_id = ARGV[2],
        status = ARGV[4],
        result_payload = cjson.null,
        error_json = cjson.null,
        created_at = ARGV[5],
        updated_at = ARGV[5],
        expires_at = ARGV[6]
    })
    redis.call('SET', KEYS[1], record, 'EX', ARGV[3])
    return {'claimed', record}
end

local data = cjson.decode(raw)
if data.fingerprint ~= ARGV[1] then return {'fingerprint_mismatch', raw} end
if data.status == 'completed' then return {'completed', raw} end
if data.status == 'failed' then
    data.status = ARGV[4]
    data.execution_id = ARGV[2]
    data.result_payload = cjson.null
    data.error_json = cjson.null
    data.updated_at = ARGV[5]
    data.expires_at = ARGV[6]
    local updated = cjson.encode(data)
    redis.call('SET', KEYS[1], updated, 'EX', ARGV[3])
    return {'claimed', updated}
end

return {'in_progress', raw}
LUA;

    public const COMPLETE = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if raw == false then return 'not_found' end

local data = cjson.decode(raw)
if data.execution_id ~= ARGV[1] then return 'ownership_violation' end
if data.status ~= 'in_progress' then return 'wrong_status' end

data.status = 'completed'
data.result_payload = ARGV[2]
data.error_json = cjson.null
data.updated_at = ARGV[3]

local result_ttl = tonumber(ARGV[4])
if result_ttl and result_ttl > 0 then
    data.expires_at = ARGV[5]
    redis.call('SET', KEYS[1], cjson.encode(data), 'EX', result_ttl)
else
    redis.call('SET', KEYS[1], cjson.encode(data), 'KEEPTTL')
end

return 'ok'
LUA;

    public const FAIL = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if raw == false then return 'not_found' end

local data = cjson.decode(raw)
if data.execution_id ~= ARGV[1] then return 'ownership_violation' end
if data.status ~= 'in_progress' then return 'wrong_status' end

data.status = 'failed'
data.result_payload = cjson.null
data.error_json = ARGV[2]
data.updated_at = ARGV[3]

local result_ttl = tonumber(ARGV[4])
if result_ttl and result_ttl > 0 then
    data.expires_at = ARGV[5]
    redis.call('SET', KEYS[1], cjson.encode(data), 'EX', result_ttl)
else
    redis.call('SET', KEYS[1], cjson.encode(data), 'KEEPTTL')
end

return 'ok'
LUA;
}
