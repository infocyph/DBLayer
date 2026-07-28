<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Pagination;

use Infocyph\DBLayer\Exceptions\QueryException;
use JsonException;

/**
 * Encodes and validates versioned cursor-pagination positions.
 */
final class CursorCodec
{
    private const int MAX_CURSOR_BYTES = 8_192;

    private const int MAX_ORDER_COLUMNS = 8;

    private const int VERSION = 1;

    /**
     * @param list<array{column:string,direction:string}> $expectedOrders
     * @return array{direction:string,values:list<bool|float|int|string>}
     */
    public static function decode(
        string $cursor,
        array $expectedOrders,
        string $expectedFingerprint,
        ?string $signingKey = null,
    ): array {
        if ($cursor === '' || strlen($cursor) > self::MAX_CURSOR_BYTES) {
            throw QueryException::invalidParameter('cursor', 'Cursor is empty or exceeds the maximum size.');
        }

        self::assertOrders($expectedOrders);
        [$payload, $signature] = self::splitToken($cursor);
        self::verifySignature($payload, $signature, $signingKey);
        $decoded = self::decodePayload($payload);

        if (($decoded['v'] ?? null) !== self::VERSION) {
            throw QueryException::invalidParameter('cursor', 'Cursor version is unsupported.');
        }

        $direction = self::decodeDirection($decoded);
        self::assertEncodedOrders($decoded, $expectedOrders);

        if (($decoded['q'] ?? null) !== $expectedFingerprint) {
            throw QueryException::invalidParameter(
                'cursor',
                'Cursor does not belong to the current query scope.',
            );
        }

        return [
            'direction' => $direction,
            'values' => self::decodeValues($decoded, \count($expectedOrders)),
        ];
    }

    /**
     * @param list<array{column:string,direction:string}> $orders
     * @param list<bool|float|int|string> $values
     */
    public static function encode(
        array $orders,
        array $values,
        string $direction,
        string $fingerprint,
        ?string $signingKey = null,
    ): string {
        self::assertDirection($direction);
        self::assertOrders($orders);

        if (count($values) !== count($orders)) {
            throw QueryException::invalidParameter(
                'cursor',
                'Cursor position does not match its ordering definition.',
            );
        }

        $payload = self::base64UrlEncode(json_encode([
            'v' => self::VERSION,
            'd' => $direction,
            'o' => array_map(
                static fn(array $order): array => [$order['column'], $order['direction']],
                $orders,
            ),
            'p' => $values,
            'q' => $fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($signingKey === null) {
            return $payload;
        }

        return $payload . '.' . self::base64UrlEncode(
            hash_hmac('sha256', $payload, $signingKey, true),
        );
    }

    private static function assertDirection(string $direction): void
    {
        if (!in_array($direction, ['next', 'previous'], true)) {
            throw QueryException::invalidParameter('cursor', 'Cursor direction is invalid.');
        }
    }

    /**
     * @param array<mixed,mixed> $decoded
     * @param list<array{column:string,direction:string}> $expectedOrders
     */
    private static function assertEncodedOrders(array $decoded, array $expectedOrders): void
    {
        $expected = array_map(
            static fn(array $order): array => [$order['column'], $order['direction']],
            $expectedOrders,
        );

        if (($decoded['o'] ?? null) !== $expected) {
            throw QueryException::invalidParameter(
                'cursor',
                'Cursor ordering does not match the current query.',
            );
        }
    }

    /**
     * @param list<array{column:string,direction:string}> $orders
     */
    private static function assertOrders(array $orders): void
    {
        if ($orders === [] || count($orders) > self::MAX_ORDER_COLUMNS) {
            throw QueryException::invalidParameter(
                'cursor',
                sprintf('Cursor pagination requires between 1 and %d order columns.', self::MAX_ORDER_COLUMNS),
            );
        }

        foreach ($orders as $order) {
            if (
                $order['column'] === ''
                || !in_array($order['direction'], ['asc', 'desc'], true)
            ) {
                throw QueryException::invalidParameter('cursor', 'Cursor ordering definition is invalid.');
            }
        }
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw QueryException::invalidParameter('cursor', 'Cursor encoding is invalid.');
        }

        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', $padding),
            true,
        );

        if ($decoded === false) {
            throw QueryException::invalidParameter('cursor', 'Cursor encoding is invalid.');
        }

        return $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<mixed,mixed> $decoded
     */
    private static function decodeDirection(array $decoded): string
    {
        $direction = $decoded['d'] ?? null;
        if (!is_string($direction)) {
            throw QueryException::invalidParameter('cursor', 'Cursor direction is invalid.');
        }

        self::assertDirection($direction);

        return $direction;
    }

    /**
     * @return array<mixed,mixed>
     */
    private static function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode(
                self::base64UrlDecode($payload),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw QueryException::invalidParameter('cursor', 'Cursor payload is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw QueryException::invalidParameter('cursor', 'Cursor payload is invalid.');
        }

        return $decoded;
    }

    /**
     * @param array<mixed,mixed> $decoded
     * @return list<bool|float|int|string>
     */
    private static function decodeValues(array $decoded, int $expectedCount): array
    {
        $values = $decoded['p'] ?? null;
        if (!is_array($values) || !array_is_list($values) || count($values) !== $expectedCount) {
            throw QueryException::invalidParameter('cursor', 'Cursor position is invalid.');
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value)) {
                throw QueryException::invalidParameter(
                    'cursor',
                    'Cursor positions must contain only non-null scalar values.',
                );
            }

            $result[] = $value;
        }

        return $result;
    }

    /**
     * @return array{0:string,1:string|null}
     */
    private static function splitToken(string $cursor): array
    {
        $parts = explode('.', $cursor);

        if (count($parts) > 2) {
            throw QueryException::invalidParameter('cursor', 'Cursor token structure is invalid.');
        }

        return [$parts[0], $parts[1] ?? null];
    }

    private static function verifySignature(
        string $payload,
        ?string $signature,
        ?string $signingKey,
    ): void {
        if ($signingKey === null) {
            if ($signature !== null) {
                throw QueryException::invalidParameter(
                    'cursor',
                    'Signed cursor cannot be used without its signing key.',
                );
            }

            return;
        }

        if ($signature === null) {
            throw QueryException::invalidParameter('cursor', 'Cursor signature is required.');
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $signingKey, true));
        if (!hash_equals($expected, $signature)) {
            throw QueryException::invalidParameter('cursor', 'Cursor signature is invalid.');
        }
    }
}
