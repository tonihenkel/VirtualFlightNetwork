<?php

function vfnRuntimeConfigPath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime-config.json';
}

function vfnConfigDefinitions(): array
{
    return [
        'defaultTimezone' => [
            'type' => 'timezone',
            'category' => 'general'
        ],
        'minimumInvisibleOpPermission' => [
            'type' => 'integer',
            'min' => 0,
            'max' => 5,
            'category' => 'permissions'
        ],
        'showRatings' => [
            'type' => 'boolean',
            'category' => 'general'
        ],
        'chatFrequencyRangeNm' => [
            'type' => 'number',
            'min' => 1,
            'max' => 2000,
            'category' => 'chat'
        ],
        'aviationWeatherMetarCacheUrl' => [
            'type' => 'https_url',
            'max_length' => 500,
            'category' => 'weather'
        ],
        'noaaMetarStationBaseUrl' => [
            'type' => 'https_url',
            'max_length' => 500,
            'category' => 'weather'
        ],
        'metarCacheSeconds' => [
            'type' => 'integer',
            'min' => 60,
            'max' => 86400,
            'category' => 'weather'
        ],
        'voiceServiceWebSocketUrl' => [
            'type' => 'wss_url_optional',
            'max_length' => 500,
            'category' => 'voice'
        ],
        'projectName' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 120,
            'category' => 'general'
        ],
        'pluginDownloadEnabled' => [
            'type' => 'boolean',
            'category' => 'download'
        ],
        'pluginDownloadUrl' => [
            'type' => 'relative_url',
            'max_length' => 300,
            'category' => 'download'
        ],
        'pluginDownloadName' => [
            'type' => 'filename',
            'max_length' => 160,
            'category' => 'download'
        ],
        'companyName' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 160,
            'category' => 'legal'
        ],
        'companyOwner' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 160,
            'category' => 'legal'
        ],
        'companyAddress' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 200,
            'category' => 'legal'
        ],
        'companyZipCity' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 160,
            'category' => 'legal'
        ],
        'companyCountry' => [
            'type' => 'string',
            'min_length' => 1,
            'max_length' => 100,
            'category' => 'legal'
        ],
        'companyEmail' => [
            'type' => 'email',
            'max_length' => 190,
            'category' => 'legal'
        ]
    ];
}

function vfnReadRuntimeConfig(): array
{
    $path = vfnRuntimeConfigPath();

    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded)
        ? $decoded
        : [];
}

function vfnValidateConfigValue(string $key, $value)
{
    $definitions = vfnConfigDefinitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('unknown_setting');
    }

    $definition = $definitions[$key];
    $type = (string)$definition['type'];

    if ($type === 'boolean') {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        throw new InvalidArgumentException('invalid_boolean');
    }

    if ($type === 'integer' || $type === 'number') {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('invalid_number');
        }

        $number = $type === 'integer'
            ? (int)$value
            : (float)$value;

        if (
            $number < $definition['min']
            || $number > $definition['max']
        ) {
            throw new InvalidArgumentException('number_out_of_range');
        }

        return $number;
    }

    $text = trim((string)$value);
    $length = mb_strlen($text);

    if (
        isset($definition['min_length'])
        && $length < (int)$definition['min_length']
    ) {
        throw new InvalidArgumentException('text_too_short');
    }

    if (
        isset($definition['max_length'])
        && $length > (int)$definition['max_length']
    ) {
        throw new InvalidArgumentException('text_too_long');
    }

    if ($type === 'timezone') {
        if (!in_array($text, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }
    } elseif ($type === 'https_url') {
        if (
            filter_var($text, FILTER_VALIDATE_URL) === false
            || stripos($text, 'https://') !== 0
        ) {
            throw new InvalidArgumentException('invalid_https_url');
        }
    } elseif ($type === 'wss_url_optional') {
        if (
            $text !== ''
            && (
                filter_var(str_replace('wss://', 'https://', $text), FILTER_VALIDATE_URL) === false
                || stripos($text, 'wss://') !== 0
            )
        ) {
            throw new InvalidArgumentException('invalid_wss_url');
        }
    } elseif ($type === 'relative_url') {
        if (
            $text === ''
            || preg_match('#^(?:[a-z]+:)?//#i', $text)
            || strpos($text, '..') !== false
        ) {
            throw new InvalidArgumentException('invalid_relative_url');
        }
    } elseif ($type === 'filename') {
        if (
            $text === ''
            || basename($text) !== $text
            || preg_match('/[\\\\\/:*?"<>|]/', $text)
        ) {
            throw new InvalidArgumentException('invalid_filename');
        }
    } elseif ($type === 'email') {
        if (filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('invalid_email');
        }
    }

    return $text;
}

function vfnValidatedRuntimeConfig(array $values): array
{
    $validated = [];

    foreach (vfnConfigDefinitions() as $key => $definition) {
        if (!array_key_exists($key, $values)) {
            continue;
        }

        $validated[$key] =
            vfnValidateConfigValue($key, $values[$key]);
    }

    return $validated;
}

function vfnApplyRuntimeConfig(array $values): void
{
    foreach (vfnValidatedRuntimeConfig($values) as $key => $value) {
        $GLOBALS[$key] = $value;
    }
}

function vfnWriteRuntimeConfig(array $values): void
{
    $validated = vfnValidatedRuntimeConfig($values);
    $json = json_encode(
        $validated,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException('json_encode_failed');
    }

    if (
        file_put_contents(
            vfnRuntimeConfigPath(),
            $json . PHP_EOL,
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException('config_write_failed');
    }
}
