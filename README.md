# Laravel Skir Runtime

[![Tests](https://github.com/php-skir/runtime/actions/workflows/tests.yml/badge.svg)](https://github.com/php-skir/runtime/actions/workflows/tests.yml)
[![Coverage](https://raw.githubusercontent.com/php-skir/runtime/badges/coverage.svg)](https://github.com/php-skir/runtime/actions/workflows/tests.yml)
[![Composer](https://img.shields.io/packagist/v/php-skir/runtime?label=composer&logo=composer)](https://packagist.org/packages/php-skir/runtime)
[![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4?logo=php&logoColor=white)](https://packagist.org/packages/php-skir/runtime)
[![License](https://img.shields.io/github/license/php-skir/runtime)](LICENSE)

Framework-agnostic PHP runtime support for Skir serialization and descriptors.

This package is intentionally small. Laravel-specific routing and client behavior live in separate packages.

## Installation

```bash
composer require php-skir/runtime
```

## What it provides

- `DenseJson` for encoding Skir dense JSON values and decoding JSON values of either flavor.
- `ReadableJson` for encoding Skir readable JSON values.
- `Type`, `Field`, and `Variant` descriptors for generated DTO metadata.
- `EnumValue` for Skir enum constants and wrapper variants.
- `MethodDescriptor` for generated SkirRPC method metadata.
- Package-local `SkirRuntimeException` exceptions for invalid Skir values.

## Dense JSON

```php
use Skir\Runtime\DenseJson;
use Skir\Runtime\Field;
use Skir\Runtime\Type;

$user = Type::struct([
    Field::value('id', 0, Type::int32()),
    Field::value('name', 1, Type::string()),
]);

$json = DenseJson::toJson($user, [
    'id' => 1,
    'name' => 'Jane',
]);

$data = DenseJson::fromJson($user, $json);
```

Struct values are encoded as field-number indexed arrays. Removed fields and sparse field numbers are preserved so generated DTOs can stay compatible with existing Skir schemas.

`DenseJson::fromJson()` and `DenseJson::decode()` accept both the dense and the readable flavor, like the upstream Skir runtimes.

## Readable JSON

```php
use Skir\Runtime\ReadableJson;

$json = ReadableJson::toJson($user, [
    'id' => 1,
    'name' => 'Jane',
]);
// {
//   "id": 1,
//   "name": "Jane"
// }

$data = DenseJson::fromJson($user, $json);
```

The readable flavor matches upstream `toJsonCode(value, "readable")`: structs become objects keyed by field name with default-valued fields omitted, constant enum variants become lower-case names, wrapper variants become `{"kind": ..., "value": ...}`, timestamps become `{"unix_millis": ..., "formatted": ...}` and bytes become `hex:` strings. Because field names are part of the output, prefer the dense flavor for anything that is persisted.

`ReadableJson::encode()` returns the JSON-compatible PHP value. A struct whose fields all hold their default value is returned as an empty `stdClass` so it still encodes as `{}`.

## Current scope

This runtime does not register routes, discover procedures, or make HTTP calls. Those concerns belong in the `php-skir/server` and `php-skir/client` packages.
