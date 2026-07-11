<?php

declare(strict_types=1);

namespace Shamanzpua\Idempotency\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shamanzpua\Idempotency\Infrastructure\Fingerprint\CanonicalJsonFingerprintGenerator;

final class CanonicalJsonFingerprintGeneratorTest extends TestCase
{
    public function testKeyOrderDoesNotAffectFingerprint(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $a = $generator->generate(['amount' => 100, 'currency' => 'USD']);
        $b = $generator->generate(['currency' => 'USD', 'amount' => 100]);

        self::assertTrue($a->equals($b));
    }

    public function testNestedKeyOrderDoesNotAffectFingerprint(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $a = $generator->generate(['meta' => ['b' => 2, 'a' => 1], 'id' => 7]);
        $b = $generator->generate(['id' => 7, 'meta' => ['a' => 1, 'b' => 2]]);

        self::assertTrue($a->equals($b));
    }

    public function testMixedIntAndStringKeyOrderDoesNotAffectFingerprint(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        // Keys "2"/"10" become int keys, "1x" stays a string key: a mix that makes
        // the default SORT_REGULAR comparator non-transitive (order-dependent).
        $a = $generator->generate(['2' => 'a', '10' => 'b', '1x' => 'c']);
        $b = $generator->generate(['1x' => 'c', '2' => 'a', '10' => 'b']);
        $c = $generator->generate(['10' => 'b', '1x' => 'c', '2' => 'a']);

        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($c));
    }

    public function testListOrderIsSignificant(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $a = $generator->generate([1, 2, 3]);
        $b = $generator->generate([3, 2, 1]);

        self::assertFalse($a->equals($b));
    }

    public function testFingerprintIsVersionPrefixed(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $fingerprint = $generator->generate(['a' => 1])->toString();

        self::assertStringStartsWith('v1:', $fingerprint);
        // v1: + 64 hex chars.
        self::assertSame(67, strlen($fingerprint));
    }

    public function testScalarTypesAreDistinct(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $int = $generator->generate(1);
        $string = $generator->generate('1');

        self::assertFalse($int->equals($string));
    }

    public function testDifferentPayloadsProduceDifferentFingerprints(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $a = $generator->generate(['amount' => 100]);
        $b = $generator->generate(['amount' => 200]);

        self::assertFalse($a->equals($b));
    }

    public function testObjectPropertyOrderDoesNotAffectFingerprint(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        // stdClass is the typical shape of json_decode($body, false); property
        // order must not change the fingerprint, matching the array behaviour.
        $a = $generator->generate((object) ['amount' => 100, 'currency' => 'USD']);
        $b = $generator->generate((object) ['currency' => 'USD', 'amount' => 100]);

        self::assertTrue($a->equals($b));
    }

    public function testNestedObjectsAreCanonicalized(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $a = $generator->generate((object) ['meta' => (object) ['b' => 2, 'a' => 1], 'id' => 7]);
        $b = $generator->generate(['id' => 7, 'meta' => ['a' => 1, 'b' => 2]]);

        // An object payload and the equivalent array payload canonicalize identically.
        self::assertTrue($a->equals($b));
    }

    public function testIntAndFloatAreDistinct(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        // JSON_PRESERVE_ZERO_FRACTION keeps 1.0 from collapsing onto 1, honouring
        // the documented "1, 1.0 and \"1\" are distinct" contract.
        $int = $generator->generate(['n' => 1]);
        $float = $generator->generate(['n' => 1.0]);

        self::assertFalse($int->equals($float));
    }

    public function testJsonSerializablePrivateStateProducesDistinctFingerprints(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        // A JsonSerializable payload with only private state must fingerprint by
        // its jsonSerialize() output — not collapse to an empty object (which
        // would let two different requests share one key and replay each other's
        // result). Regression guard for the get_object_vars() flattening bug.
        $a = $generator->generate(new MoneyFixture(100));
        $b = $generator->generate(new MoneyFixture(999));

        self::assertFalse($a->equals($b));
    }

    public function testJsonSerializableMatchesEquivalentArray(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $object = $generator->generate(new MoneyFixture(999));
        $array = $generator->generate(['amount' => 9.99]);

        self::assertTrue($object->equals($array));
    }

    public function testJsonObjectAndListAreDistinct(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        // A JSON object with sequential numeric keys must not collide with a JSON
        // array of the same values — JSON distinguishes the two types, so an
        // idempotency key reused with a different-typed payload must mismatch.
        $object = $generator->generate((object) ['0' => 'a', '1' => 'b']);
        $list = $generator->generate(['a', 'b']);

        self::assertFalse($object->equals($list));
    }

    public function testEmptyObjectAndEmptyListAreDistinct(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $emptyObject = $generator->generate(new \stdClass());
        $emptyList = $generator->generate([]);

        self::assertFalse($emptyObject->equals($emptyList));
    }

    public function testRejectsRecursiveObjectGraph(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $node = new \stdClass();
        $node->self = $node;

        // A cyclic graph must raise a catchable JsonException, not exhaust memory
        // with an uncatchable fatal.
        $this->expectException(\JsonException::class);

        $generator->generate($node);
    }

    public function testRejectsNonFiniteFloats(): void
    {
        $generator = new CanonicalJsonFingerprintGenerator();

        $this->expectException(\JsonException::class);

        $generator->generate(['x' => INF]);
    }
}

final class MoneyFixture implements \JsonSerializable
{
    public function __construct(private readonly int $cents) {}

    /**
     * @return array{amount: int|float}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->cents / 100];
    }
}
