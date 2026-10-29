<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Assert;

/** Decodes request bodies recorded by a MockHttpClient, failing the test on anything but a JSON object or array. */
trait DecodesJson
{
    /**
     * Decodes $json, which must be a string holding a JSON object or array.
     *
     * @param mixed $json The recorded request body.
     * @return array<mixed> the decoded body
     */
    private static function decodeJson(mixed $json): array
    {
        Assert::assertIsString($json);
        $decoded = json_decode($json, true);
        Assert::assertIsArray($decoded);

        return $decoded;
    }
}
