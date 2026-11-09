<?php

declare(strict_types=1);

namespace Skir\Runtime\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Skir\Runtime\DenseJson;
use Skir\Runtime\EnumValue;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Field;
use Skir\Runtime\Type;
use Skir\Runtime\Variant;

final class DenseJsonTest extends TestCase
{
    public function test_it_encodes_primitive_values(): void
    {
        $this->assertSame('1', DenseJson::toJson(Type::bool(), true));
        $this->assertSame('0', DenseJson::toJson(Type::bool(), false));
        $this->assertSame('1234', DenseJson::toJson(Type::int32(), 1234));
        $this->assertSame('"9223372036854775807"', DenseJson::toJson(Type::int64(), '9223372036854775807'));
        $this->assertSame('"18446744073709551615"', DenseJson::toJson(Type::hash64(), '18446744073709551615'));
        $this->assertSame('1743682787000', DenseJson::toJson(Type::timestamp(), 1743682787000));
        $this->assertSame('"Infinity"', DenseJson::toJson(Type::float64(), INF));
        $this->assertSame('"Hello"', DenseJson::toJson(Type::string(), 'Hello'));
        $this->assertSame('"AQID"', DenseJson::toJson(Type::bytes(), "\x01\x02\x03"));
    }

    public function test_it_wraps_and_clamps_integers_like_upstream(): void
    {
        $this->assertSame('1', DenseJson::toJson(Type::int32(), 4_294_967_297));
        $this->assertSame('-2147483648', DenseJson::toJson(Type::int32(), 2_147_483_648));
        $this->assertSame('"9223372036854775807"', DenseJson::toJson(Type::int64(), '99999999999999999999'));
        $this->assertSame('42', DenseJson::toJson(Type::int64(), '0042'));
        $this->assertSame('0', DenseJson::toJson(Type::hash64(), -5));
        $this->assertSame('"18446744073709551615"', DenseJson::toJson(Type::hash64(), '99999999999999999999'));
    }

    public function test_it_rejects_non_integer_int64_values(): void
    {
        $this->expectException(SkirRuntimeException::class);
        $this->expectExceptionMessage('Skir int64/hash64 string values must contain only digits.');

        DenseJson::toJson(Type::int64(), '12abc');
    }

    public function test_it_preserves_permissive_primitive_wire_coercion(): void
    {
        $this->assertTrue(DenseJson::fromJson(Type::bool(), '"false"'));
        $this->assertSame(0, DenseJson::fromJson(Type::int32(), '"not-a-number"'));
        $this->assertSame(PHP_INT_MAX, DenseJson::fromJson(Type::int64(), '"9223372036854775807"'));
        $this->assertSame('18446744073709551615', DenseJson::fromJson(Type::hash64(), '"18446744073709551615"'));
        $this->assertSame(2.5, DenseJson::fromJson(Type::float64(), '"2.5"'));
        $this->assertSame(1743682787000, DenseJson::fromJson(Type::timestamp(), '"1743682787000"'));
        $this->assertSame("\x01\x02\x03", DenseJson::fromJson(Type::bytes(), '"AQID"'));
    }

    public function test_it_encodes_structs_as_field_number_indexed_arrays(): void
    {
        $user = Type::struct([
            Field::value('user_id', 0, Type::int32()),
            Field::removed(1),
            Field::value('name', 2, Type::string()),
            Field::value('nickname', 3, Type::string()),
        ]);

        $json = DenseJson::toJson($user, [
            'user_id' => 400,
            'name' => 'John Doe',
        ]);

        $this->assertSame('[400,0,"John Doe"]', $json);
    }

    public function test_it_preserves_sparse_field_numbers_when_encoding_structs(): void
    {
        $settings = Type::struct([
            Field::value('name', 2, Type::string()),
            Field::value('enabled', 5, Type::bool()),
        ]);

        $json = DenseJson::toJson($settings, [
            'name' => 'notifications',
            'enabled' => true,
        ]);

        $this->assertSame('[0,0,"notifications",0,0,1]', $json);
    }

    public function test_it_trims_trailing_defaults_from_sparse_structs(): void
    {
        $settings = Type::struct([
            Field::value('name', 2, Type::string()),
            Field::value('enabled', 5, Type::bool()),
        ]);

        $json = DenseJson::toJson($settings, [
            'name' => 'notifications',
            'enabled' => false,
        ]);

        $this->assertSame('[0,0,"notifications"]', $json);
    }

    public function test_it_preserves_explicit_null_optional_fields_before_later_values(): void
    {
        $address = Type::struct([
            Field::value('city', 0, Type::string()),
        ]);
        $profile = Type::struct([
            Field::value('addresses', 0, Type::optional(Type::array($address))),
            Field::value('nickname', 1, Type::optional(Type::string())),
            Field::value('address', 2, Type::optional($address)),
            Field::value('name', 4, Type::string()),
        ]);
        $value = [
            'addresses' => null,
            'nickname' => null,
            'address' => null,
            'name' => 'John Doe',
        ];

        $json = DenseJson::toJson($profile, $value);

        $this->assertSame('[null,null,null,0,"John Doe"]', $json);
        $this->assertSame($value, DenseJson::fromJson($profile, $json));
    }

    public function test_it_decodes_structs_with_missing_trailing_fields(): void
    {
        $user = Type::struct([
            Field::value('user_id', 0, Type::int32()),
            Field::removed(1),
            Field::value('name', 2, Type::string()),
            Field::value('nickname', 3, Type::string()),
        ]);

        $decoded = DenseJson::fromJson($user, '[400,0,"John Doe"]');

        $this->assertSame([
            'user_id' => 400,
            'name' => 'John Doe',
            'nickname' => '',
        ], $decoded);
    }

    public function test_it_encodes_and_decodes_enum_variants(): void
    {
        $subscriptionStatus = Type::enum([
            Variant::constant('free', 1),
            Variant::wrapper('premium_since', 2, Type::timestamp()),
        ]);

        $this->assertSame('1', DenseJson::toJson($subscriptionStatus, EnumValue::constant('free')));
        $this->assertSame('[2,1743682787000]', DenseJson::toJson(
            $subscriptionStatus,
            EnumValue::wrapper('premium_since', 1743682787000),
        ));

        $this->assertEquals(
            EnumValue::wrapper('premium_since', 1743682787000),
            DenseJson::fromJson($subscriptionStatus, '[2,1743682787000]'),
        );
    }

    #[DataProviderExternal(UpstreamVectors::class, 'decoding')]
    public function test_it_decodes_both_json_flavors_like_upstream(string $type, string $json, string $expectedDenseJson): void
    {
        $skirType = UpstreamVectors::type($type);

        $this->assertSame($expectedDenseJson, DenseJson::toJson($skirType, DenseJson::fromJson($skirType, $json)));
    }

    #[DataProviderExternal(UpstreamVectors::class, 'encoding')]
    public function test_it_matches_upstream_dense_json_code(string $type, string $denseJson, string $expectedDenseJson, string $expectedReadableJson): void
    {
        $skirType = UpstreamVectors::type($type);

        $this->assertSame($expectedDenseJson, DenseJson::toJson($skirType, DenseJson::fromJson($skirType, $denseJson)));
    }

    public function test_it_decodes_readable_structs_keyed_by_field_name(): void
    {
        $decoded = DenseJson::fromJson(UpstreamVectors::user(), '{"user_id":400,"name":"Jane","unknown":1,"created_at":{"unix_millis":5,"formatted":"x"},"avatar":"hex:0102","status":"free","address":{"city":"Antwerp"}}');

        $this->assertEquals([
            'user_id' => 400,
            'name' => 'Jane',
            'nickname' => null,
            'tags' => [],
            'created_at' => 5,
            'avatar' => "\x01\x02",
            'score' => 0.0,
            'big' => 0,
            'hash' => 0,
            'active' => false,
            'status' => EnumValue::constant('free'),
            'address' => ['city' => 'Antwerp'],
            'matrix' => [],
        ], $decoded);
    }

    public function test_it_decodes_json_objects_given_as_std_class(): void
    {
        $decoded = DenseJson::decode(UpstreamVectors::status(), json_decode('{"kind":"trial","value":{"city":"Paris"}}'));

        $this->assertEquals(EnumValue::wrapper('trial', ['city' => 'Paris']), $decoded);
    }

    public function test_it_decodes_readable_enums(): void
    {
        $status = UpstreamVectors::status();

        $this->assertEquals(EnumValue::constant('free'), DenseJson::fromJson($status, '"free"'));
        $this->assertEquals(EnumValue::constant('free'), DenseJson::fromJson($status, '"FREE"'));
        $this->assertEquals(EnumValue::constant('UNKNOWN'), DenseJson::fromJson($status, '"unknown"'));
        $this->assertEquals(EnumValue::constant('UNKNOWN'), DenseJson::fromJson($status, '"not_a_variant"'));
        $this->assertEquals(EnumValue::wrapper('premium_since', 0), DenseJson::fromJson($status, '"premium_since"'));
        $this->assertEquals(
            EnumValue::wrapper('premium_since', 1_743_682_787_000),
            DenseJson::fromJson($status, '{"kind":"premium_since","value":{"unix_millis":1743682787000,"formatted":"2025-04-03T12:19:47.000Z"}}'),
        );
    }

    public function test_it_decodes_enum_values_that_changed_shape_like_upstream(): void
    {
        $status = UpstreamVectors::status();

        $this->assertEquals(EnumValue::wrapper('premium_since', 0), DenseJson::fromJson($status, '2'));
        $this->assertEquals(EnumValue::constant('free'), DenseJson::fromJson($status, '[1,1743682787000]'));
        $this->assertEquals(EnumValue::constant('UNKNOWN'), DenseJson::fromJson($status, '[99,1743682787000]'));
    }

    #[DataProvider('jsonValuesRejectedByUpstream')]
    public function test_it_rejects_json_values_upstream_rejects(string $type, string $json): void
    {
        $this->expectException(SkirRuntimeException::class);

        DenseJson::fromJson(UpstreamVectors::type($type), $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function jsonValuesRejectedByUpstream(): iterable
    {
        yield 'string from number' => ['string', '5'];
        yield 'string from null' => ['string', 'null'];
        yield 'string from array' => ['string', '[]'];
        yield 'string from bool' => ['string', 'true'];
        yield 'enum from null' => ['status', 'null'];
        yield 'enum from bool' => ['status', 'true'];
        yield 'array from string' => ['matrix', '"x"'];
        yield 'array from object' => ['matrix', '{"a":1}'];
        yield 'array from null' => ['matrix', 'null'];
        yield 'struct from string' => ['address', '"x"'];
        yield 'struct from bool' => ['address', 'true'];
        yield 'struct from number' => ['address', '5'];
        yield 'int64 from text' => ['int64', '"abc"'];
        yield 'int64 from null' => ['int64', 'null'];
        yield 'int64 from decimal string' => ['int64', '"1.5"'];
        yield 'timestamp from text' => ['timestamp', '"abc"'];
        yield 'timestamp from null' => ['timestamp', 'null'];
        yield 'bytes from number' => ['bytes', '5'];
        yield 'bytes from invalid hex' => ['bytes', '"hex:zz"'];
        yield 'bytes from invalid base64' => ['bytes', '"!!!"'];
        yield 'bytes from null' => ['bytes', 'null'];
    }

    public function test_it_decodes_zero_as_default_for_forward_compatibility(): void
    {
        $this->assertSame('', DenseJson::fromJson(Type::string(), '0'));
        $this->assertSame([], DenseJson::fromJson(Type::array(Type::string()), '0'));
        $this->assertSame('', DenseJson::fromJson(Type::optional(Type::string()), '0'));
        $this->assertNull(DenseJson::fromJson(Type::optional(Type::string()), 'null'));
    }

    public function test_it_round_trips_nested_structs_arrays_and_optionals(): void
    {
        $address = Type::struct([
            Field::value('city', 0, Type::string()),
            Field::value('postal_codes', 1, Type::array(Type::string())),
        ]);

        $user = Type::struct([
            Field::value('name', 0, Type::string()),
            Field::value('address', 1, $address),
            Field::value('tags', 2, Type::array(Type::string())),
            Field::value('nickname', 3, Type::optional(Type::string())),
        ]);

        $value = [
            'name' => 'John Doe',
            'address' => [
                'city' => 'Antwerp',
                'postal_codes' => ['2000', '2018'],
            ],
            'tags' => ['admin', 'beta'],
            'nickname' => 'johnny',
        ];

        $json = DenseJson::toJson($user, $value);

        $this->assertSame('["John Doe",["Antwerp",["2000","2018"]],["admin","beta"],"johnny"]', $json);
        $this->assertSame($value, DenseJson::fromJson($user, $json));
    }

    public function test_it_decodes_missing_nested_struct_fields_to_defaults(): void
    {
        $address = Type::struct([
            Field::value('city', 0, Type::string()),
            Field::value('postal_codes', 1, Type::array(Type::string())),
        ]);

        $user = Type::struct([
            Field::value('name', 0, Type::string()),
            Field::value('address', 1, $address),
        ]);

        $this->assertSame([
            'name' => 'John Doe',
            'address' => [
                'city' => '',
                'postal_codes' => [],
            ],
        ], DenseJson::fromJson($user, '["John Doe"]'));
    }

    public function test_it_rejects_invalid_dense_json_values(): void
    {
        $this->expectException(SkirRuntimeException::class);
        $this->expectExceptionMessage('Skir array JSON values must be arrays.');

        DenseJson::fromJson(Type::array(Type::string()), '"not-array"');
    }

    public function test_it_rejects_non_string_values_for_string_and_bytes_types(): void
    {
        foreach ([Type::string(), Type::bytes()] as $type) {
            try {
                DenseJson::toJson($type, ['not', 'a', 'string']);
                $this->fail('Expected a non-string value to be rejected.');
            } catch (SkirRuntimeException $exception) {
                $this->assertSame('Expected a string, got array.', $exception->getMessage());
            }
        }
    }
}
