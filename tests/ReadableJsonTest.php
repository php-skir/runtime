<?php

declare(strict_types=1);

namespace Skir\Runtime\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Skir\Runtime\DenseJson;
use Skir\Runtime\EnumValue;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Field;
use Skir\Runtime\ReadableJson;
use Skir\Runtime\Type;
use Skir\Runtime\Variant;
use stdClass;

final class ReadableJsonTest extends TestCase
{
    #[DataProviderExternal(UpstreamVectors::class, 'encoding')]
    public function test_it_matches_upstream_readable_json_code(string $type, string $denseJson, string $expectedDenseJson, string $expectedReadableJson): void
    {
        $skirType = UpstreamVectors::type($type);
        $value = DenseJson::fromJson($skirType, $denseJson);

        $this->assertSame($expectedReadableJson, ReadableJson::toJson($skirType, $value));
    }

    #[DataProviderExternal(UpstreamVectors::class, 'encoding')]
    public function test_readable_json_round_trips_through_dense_json_decoding(string $type, string $denseJson, string $expectedDenseJson, string $expectedReadableJson): void
    {
        $skirType = UpstreamVectors::type($type);
        $value = DenseJson::fromJson($skirType, $denseJson);

        $fromCode = DenseJson::fromJson($skirType, ReadableJson::toJson($skirType, $value));
        $fromValue = DenseJson::decode($skirType, ReadableJson::encode($skirType, $value));

        $this->assertSame($expectedDenseJson, DenseJson::toJson($skirType, $fromCode));
        $this->assertSame($expectedDenseJson, DenseJson::toJson($skirType, $fromValue));
    }

    public function test_it_encodes_primitives(): void
    {
        $this->assertTrue(ReadableJson::encode(Type::bool(), true));
        $this->assertFalse(ReadableJson::encode(Type::bool(), 0));
        $this->assertSame(-5, ReadableJson::encode(Type::int32(), -5));
        $this->assertSame(1, ReadableJson::encode(Type::int32(), 4_294_967_297));
        $this->assertSame(9_007_199_254_740_991, ReadableJson::encode(Type::int64(), 9_007_199_254_740_991));
        $this->assertSame('9007199254740992', ReadableJson::encode(Type::int64(), 9_007_199_254_740_992));
        $this->assertSame('9223372036854775807', ReadableJson::encode(Type::int64(), '99999999999999999999'));
        $this->assertSame('-9223372036854775808', ReadableJson::encode(Type::int64(), '-99999999999999999999'));
        $this->assertSame('18446744073709551615', ReadableJson::encode(Type::hash64(), '99999999999999999999'));
        $this->assertSame(0, ReadableJson::encode(Type::hash64(), -5));
        $this->assertSame('NaN', ReadableJson::encode(Type::float64(), NAN));
        $this->assertSame('-Infinity', ReadableJson::encode(Type::float32(), -INF));
        $this->assertSame('hex:0102ff', ReadableJson::encode(Type::bytes(), "\x01\x02\xff"));
        $this->assertNull(ReadableJson::encode(Type::optional(Type::string()), null));
    }

    public function test_it_encodes_timestamps_with_unix_millis_and_an_iso_string(): void
    {
        $expected = [
            'unix_millis' => 1_743_682_787_123,
            'formatted' => '2025-04-03T12:19:47.123Z',
        ];

        $this->assertSame($expected, ReadableJson::encode(Type::timestamp(), 1_743_682_787_123));
        $this->assertSame($expected, ReadableJson::encode(
            Type::timestamp(),
            new DateTimeImmutable('2025-04-03T12:19:47.123Z'),
        ));
    }

    public function test_it_clamps_timestamps_to_the_javascript_date_range(): void
    {
        $this->assertSame([
            'unix_millis' => 8_640_000_000_000_000,
            'formatted' => '+275760-09-13T00:00:00.000Z',
        ], ReadableJson::encode(Type::timestamp(), PHP_INT_MAX));
    }

    public function test_it_encodes_structs_as_objects_keyed_by_field_name_without_default_fields(): void
    {
        $encoded = ReadableJson::encode(UpstreamVectors::user(), [
            'user_id' => 400,
            'name' => 'Jane',
            'nickname' => null,
            'tags' => [],
            'status' => EnumValue::constant('free'),
            'address' => ['city' => ''],
        ]);

        $this->assertSame([
            'user_id' => 400,
            'name' => 'Jane',
            'status' => 'free',
        ], $encoded);
    }

    public function test_it_encodes_structs_with_only_default_values_as_empty_objects(): void
    {
        $encoded = ReadableJson::encode(UpstreamVectors::address(), ['city' => '']);

        $this->assertEquals(new stdClass, $encoded);
        $this->assertSame('{}', ReadableJson::toJson(UpstreamVectors::address(), []));
    }

    public function test_it_ignores_values_given_for_removed_fields(): void
    {
        $type = Type::struct([
            Field::value('name', 0, Type::string()),
            Field::removed(1),
        ]);

        $this->assertSame(['name' => 'Jane'], ReadableJson::encode($type, ['name' => 'Jane', 1 => 'removed']));
    }

    public function test_it_encodes_enums(): void
    {
        $status = UpstreamVectors::status();

        $this->assertSame('unknown', ReadableJson::encode($status, EnumValue::constant('UNKNOWN')));
        $this->assertSame('free', ReadableJson::encode($status, EnumValue::constant('free')));
        $this->assertSame(
            ['kind' => 'premium_since', 'value' => ['unix_millis' => 0, 'formatted' => '1970-01-01T00:00:00.000Z']],
            ReadableJson::encode($status, EnumValue::wrapper('premium_since', 0)),
        );
        $this->assertEquals(
            ['kind' => 'trial', 'value' => new stdClass],
            ReadableJson::encode($status, EnumValue::wrapper('trial', [])),
        );
    }

    public function test_it_lowercases_constant_variant_names_but_not_wrapper_variant_names(): void
    {
        $type = Type::enum([
            Variant::constant('ACTIVE', 1),
            Variant::wrapper('Custom', 2, Type::string()),
        ]);

        $this->assertSame('active', ReadableJson::encode($type, EnumValue::constant('ACTIVE')));
        $this->assertSame(['kind' => 'Custom', 'value' => 'x'], ReadableJson::encode($type, EnumValue::wrapper('Custom', 'x')));
        $this->assertEquals(EnumValue::constant('ACTIVE'), DenseJson::fromJson($type, '"active"'));
    }

    public function test_it_rejects_undefined_enum_variants(): void
    {
        $this->expectException(SkirRuntimeException::class);
        $this->expectExceptionMessage('Skir enum variant [missing] is not defined.');

        ReadableJson::encode(UpstreamVectors::status(), EnumValue::constant('missing'));
    }

    public function test_it_uses_package_scoped_exceptions_for_values_that_cannot_be_encoded_as_json(): void
    {
        $this->expectException(SkirRuntimeException::class);
        $this->expectExceptionMessage('Invalid readable JSON: Malformed UTF-8 characters, possibly incorrectly encoded');

        ReadableJson::toJson(Type::string(), "\xB1\x31");
    }
}
