<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Infrastructure\Fingerprint;

use Shamanzpua\Idempotency\Contract\FingerprintGenerator;
use Shamanzpua\Idempotency\Core\ValueObject\Fingerprint;

/**
 * Fingerprints a payload from a canonical JSON representation so that inputs that
 * are semantically equal but ordered differently produce the same fingerprint.
 *
 * Canonicalization rules (stable across releases for a given version prefix):
 *   - the payload is first resolved to its JSON wire form, so objects encode
 *     exactly as `json_encode` would — `JsonSerializable::jsonSerialize()` is
 *     honoured and only serializable state contributes to the fingerprint;
 *   - JSON objects (from associative arrays or objects) keep their JSON type and
 *     have their keys sorted recursively (byte order), so property/key order never
 *     changes the fingerprint, while a JSON object never collides with a JSON
 *     array — `(object)['0'=>'a']` and `['a']`, or `{}` and `[]`, stay distinct;
 *   - JSON arrays (lists) keep their order;
 *   - scalars keep their JSON type, so 1, 1.0 and "1" are distinct
 *     (JSON_PRESERVE_ZERO_FRACTION keeps 1.0 from collapsing to 1);
 *   - encoding uses unescaped slashes and unicode with a fixed flag set;
 *   - NAN / INF, non-UTF-8 strings and recursive object graphs are rejected
 *     (JsonException) rather than crashing.
 *
 * The result is prefixed with a version tag (`v1:`) so a future canonicalizer can
 * be introduced without silently mixing incompatible fingerprints.
 */
final class CanonicalJsonFingerprintGenerator implements FingerprintGenerator
{
    private const VERSION = 'v1';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function generate(mixed $input): Fingerprint
    {
        $json = json_encode($this->canonicalize($this->normalize($input)), self::JSON_FLAGS);

        return Fingerprint::fromString(self::VERSION . ':' . hash('sha256', $json));
    }

    /**
     * Resolve the payload to its JSON structure before keys are canonically sorted.
     * Encoding first means objects go through `JsonSerializable`/public-property
     * serialization identically to the wire format (so private state behind
     * `jsonSerialize()` is not silently dropped), and a recursive object graph
     * raises a catchable JsonException instead of exhausting memory.
     *
     * Decoding with associative = false keeps JSON objects as `stdClass` and JSON
     * arrays as PHP lists, so the two JSON types stay distinguishable through
     * canonicalization (a JSON object with numeric keys must not collapse onto a
     * JSON array). The decode depth is one deeper than the encode depth so a graph
     * that encodes successfully always decodes successfully.
     */
    private function normalize(mixed $input): mixed
    {
        return json_decode(json_encode($input, self::JSON_FLAGS), false, 513, JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        // A PHP array here is always a JSON array (list); JSON objects are stdClass.
        if (is_array($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);

            // SORT_STRING (not the default SORT_REGULAR): keys are compared as byte
            // strings so the ordering is total and deterministic. SORT_REGULAR is
            // non-transitive for mixed integer/string keys and would make the
            // fingerprint depend on insertion order.
            ksort($properties, SORT_STRING);

            $canonical = new \stdClass();
            foreach ($properties as $key => $item) {
                $canonical->{$key} = $this->canonicalize($item);
            }

            return $canonical;
        }

        return $value;
    }
}
