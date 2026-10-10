<?php

namespace App\Adapters\Outbound\Security;

use Application\Ports\Clock;
use Application\Ports\TokenIssuer;
use Application\Ports\TokenVerifier;
use Domain\Entities\User;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;
use LogicException;

final class JwtTokenService implements TokenIssuer, TokenVerifier
{
    private const LIFETIME_SECONDS = 28800;

    private readonly string $secret;

    public function __construct(
        ConfigRepository $config,
        private readonly Clock $clock
    ) {
        $secret = $config->get('services.jwt.secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new LogicException('JWT_SECRET debe contener al menos 32 bytes.');
        }
        $this->secret = $secret;
    }

    public function issueToken(User $user): array
    {
        $issuedAt = $this->clock->now()->getTimestamp();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = [
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::LIFETIME_SECONDS,
        ];
        $signingInput = $this->encodeSegment($header).'.'.$this->encodeSegment($claims);
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);

        return [
            'access_token' => $signingInput.'.'.$this->base64UrlEncode($signature),
            'token_type' => 'Bearer',
            'expires_in' => self::LIFETIME_SECONDS,
            'role' => $user->role,
        ];
    }

    public function verify(string $token): ?array
    {
        if ($token === '' || strlen($token) > 4096) {
            return null;
        }

        $segments = explode('.', $token);
        if (count($segments) !== 3 || in_array('', $segments, true)) {
            return null;
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;
        $header = $this->decodeSegment($encodedHeader);
        $claims = $this->decodeSegment($encodedClaims);
        $signature = $this->base64UrlDecode($encodedSignature);
        if (
            $header === null
            || ($header['alg'] ?? null) !== 'HS256'
            || ($header['typ'] ?? null) !== 'JWT'
            || $claims === null
            || $signature === null
        ) {
            return null;
        }

        $signingInput = $encodedHeader.'.'.$encodedClaims;
        $expectedSignature = hash_hmac('sha256', $signingInput, $this->secret, true);
        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        $subject = $claims['sub'] ?? null;
        $role = $claims['role'] ?? null;
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        if (
            ! is_string($subject)
            || $subject === ''
            || ! in_array($role, ['admin', 'librarian'], true)
            || ! is_int($issuedAt)
            || ! is_int($expiresAt)
            || $issuedAt > $now
            || $expiresAt <= $now
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > self::LIFETIME_SECONDS
        ) {
            return null;
        }

        return ['sub' => $subject, 'role' => $role];
    }

    /** @param array<string, mixed> $value */
    private function encodeSegment(array $value): string
    {
        try {
            return $this->base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new LogicException('No fue posible serializar el token JWT.', previous: $exception);
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeSegment(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        try {
            $value = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value) === 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
