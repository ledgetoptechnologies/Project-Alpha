<?php

declare(strict_types=1);

namespace App\Services;

/** Process-local transport boundary: tests exercise the real senders without network. */
final class ValidatedAddressTransportFixture
{
    public static array $dns = [];
    public static array $options = [];
    public static int $opened = 0;
    public static int $sent = 0;
    public static bool $fail = false;
}

function dns_get_record(string $host, int $type): array
{
    return ValidatedAddressTransportFixture::$dns;
}

function curl_init(string $url): object
{
    ValidatedAddressTransportFixture::$opened++;
    return (object)['url' => $url];
}

function curl_setopt_array(object $handle, array $options): bool
{
    ValidatedAddressTransportFixture::$options = $options;
    return true;
}

function curl_setopt(object $handle, int $option, mixed $value): bool
{
    ValidatedAddressTransportFixture::$options[$option] = $value;
    return true;
}

function curl_exec(object $handle): bool
{
    ValidatedAddressTransportFixture::$sent++;
    if (ValidatedAddressTransportFixture::$fail) return false;
    (ValidatedAddressTransportFixture::$options[CURLOPT_WRITEFUNCTION])($handle, '{"ok":true}');
    return true;
}

function curl_getinfo(object $handle, int $option): int
{
    return ValidatedAddressTransportFixture::$fail ? 0 : 200;
}

function curl_error(object $handle): string
{
    return 'synthetic connection failure';
}

function curl_close(object $handle): void {}
