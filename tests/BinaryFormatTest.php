<?php

declare(strict_types=1);

namespace Skir\Runtime\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Skir\Runtime\BinaryFormat;
use Skir\Runtime\DenseJson;
use Skir\Runtime\EnumValue;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\Type;

final class BinaryFormatTest extends TestCase
{
    #[DataProviderExternal(UpstreamVectors::class, 'binaryEncoding')]
    public function test_it_encodes_like_upstream(string $type, string $denseJson, string $expectedHex): void
    {
        $skirType = UpstreamVectors::type($type);

        $bytes = BinaryFormat::encode($skirType, DenseJson::fromJson($skirType, $denseJson));

        $this->assertSame($expectedHex, bin2hex($bytes));
    }

    #[DataProviderExternal(UpstreamVectors::class, 'binaryEncoding')]
    public function test_it_round_trips_upstream_bytes(string $type, string $denseJson, string $expectedHex): void
    {
        $skirType = UpstreamVectors::type($type);

        $decoded = BinaryFormat::decode($skirType, (string) hex2bin($expectedHex));

        $this->assertSame($expectedHex, bin2hex(BinaryFormat::encode($skirType, $decoded)));
    }

    #[DataProviderExternal(UpstreamVectors::class, 'binaryDecoding')]
    public function test_it_decodes_like_upstream(string $type, string $hex, string $expected): void
    {
        $skirType = UpstreamVectors::type($type);

        if (str_starts_with($expected, 'THROWS ')) {
            $this->expectException(SkirRuntimeException::class);
            $this->expectExceptionMessage(substr($expected, strlen('THROWS ')));
        }

        $decoded = BinaryFormat::decode($skirType, (string) hex2bin($hex));

        $this->assertSame($expected, DenseJson::toJson($skirType, $decoded));
    }

    public function test_it_sizes_string_length_prefixes_from_the_utf16_length_like_upstream(): void
    {
        $this->assertStringStartsWith('736b6972f3e855557878', bin2hex(BinaryFormat::encode(Type::string(), str_repeat('x', 21_845))));
        $this->assertStringStartsWith('736b6972f3e956550000', bin2hex(BinaryFormat::encode(Type::string(), str_repeat('x', 21_846))));
    }

    public function test_it_round_trips_php_values(): void
    {
        $user = UpstreamVectors::user();
        $value = DenseJson::fromJson($user, '[400,0,"Jane","jj",["a"],1743682787123,"AQID",2.5,"9007199254740993","18446744073709551615",1,[2,1743682787000],["Antwerp"],[[1,2],[],[3]]]');

        $this->assertEquals($value, BinaryFormat::decode($user, BinaryFormat::encode($user, $value)));
        $this->assertEquals(
            EnumValue::wrapper('big', 's'),
            BinaryFormat::decode(UpstreamVectors::status(), BinaryFormat::encode(UpstreamVectors::status(), EnumValue::wrapper('big', 's'))),
        );
    }

    #[DataProvider('invalidBinaryPayloads')]
    public function test_it_rejects_invalid_binary_payloads(string $type, string $hex, string $message): void
    {
        $this->expectException(SkirRuntimeException::class);
        $this->expectExceptionMessage($message);

        BinaryFormat::decode(UpstreamVectors::type($type), (string) hex2bin($hex));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidBinaryPayloads(): iterable
    {
        yield 'empty payload' => ['bool', '', 'Invalid skir binary header'];
        yield 'wrong header' => ['bool', '736b6971', 'Invalid skir binary header'];
        yield 'header only' => ['bool', '736b6972', 'Truncated binary payload'];
        yield 'trailing data' => ['bool', '736b69720000', 'Trailing or truncated binary data'];
        yield 'string wire for a number' => ['bool', '736b6972f2', 'Invalid numeric wire tag: 242'];
        yield 'truncated string' => ['string', '736b6972f305', 'Truncated binary payload'];
        yield 'number wire for a string' => ['string', '736b6972f1', 'Invalid string wire tag: 241'];
        yield 'truncated array' => ['matrix', '736b6972fa05', 'Truncated binary array'];
        yield 'array wire for an enum' => ['status', '736b6972f9', 'Invalid enum wire tag: 249'];
        yield 'hash wire for an enum number' => ['status', '736b6972ea', 'Invalid unsigned length or variant tag: 234'];
        yield 'string wire for bytes' => ['bytes', '736b6972f3', 'Invalid bytes wire tag: 243'];
        yield 'truncated number' => ['int32', '736b6972e8', 'Truncated binary payload'];
        yield 'invalid utf-8' => ['string', '736b6972f301ff', 'Skir string values must be valid UTF-8.'];
    }

    public function test_it_rejects_invalid_utf8_strings_when_encoding(): void
    {
        $this->expectException(SkirRuntimeException::class);
        $this->expectExceptionMessage('Skir string values must be valid UTF-8.');

        BinaryFormat::encode(Type::string(), "\xB1\x31");
    }
}
