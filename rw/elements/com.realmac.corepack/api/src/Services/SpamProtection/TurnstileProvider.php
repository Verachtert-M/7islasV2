<?php

namespace App\Services\SpamProtection;

use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cloudflare Turnstile spam protection provider
 */
class TurnstileProvider extends AbstractSpamProtectionProvider
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function getName(): string
    {
        return 'turnstile';
    }

    public function getTokenFields(): array
    {
        return ['cf-turnstile-response', 'turnstile_token', 'captcha_token'];
    }

    public function isConfigured(array $config): bool
    {
        return !empty($config['secret_key']);
    }

    public function verify(Request $request, array $config): SpamProtectionResult
    {
        try {
            $this->validateRequiredConfig($config, ['secret_key']);

            $token = $this->extractToken($request);
            if (!$token) {
                return $this->createFailureResult('Missing Turnstile token', 'missing_token');
            }

            $response = $this->verifyWithTurnstile($token, $config, $request);

            if (!$response['success']) {
                return $this->handleVerificationFailure($response);
            }

            return $this->createSuccessResult([
                'hostname' => $response['hostname'] ?? null,
                'challenge_ts' => $response['challenge_ts'] ?? null,
                'action' => $response['action'] ?? null,
                'cdata' => $response['cdata'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->createFailureResult(
                'Turnstile verification failed: ' . $e->getMessage(),
                'provider_error'
            );
        }
    }

    /**
     * Verify token with Cloudflare Turnstile API
     */
    private function verifyWithTurnstile(string $token, array $config, Request $request): array
    {
        $data = [
            'secret' => $config['secret_key'],
            'response' => $token,
            'remoteip' => $this->getClientIp($request)
        ];

        $timeout = $config['timeout'] ?? 5;
        return $this->makeHttpRequest(self::VERIFY_URL, $data, $timeout);
    }

    /**
     * Handle verification failure and map error codes
     */
    private function handleVerificationFailure(array $response): SpamProtectionResult
    {
        $errorCodes = $response['error-codes'] ?? [];
        $primaryError = $errorCodes[0] ?? 'unknown';

        $errorMessages = [
            'missing-input-secret' => 'Missing Turnstile secret key',
            'invalid-input-secret' => 'Invalid Turnstile secret key',
            'missing-input-response' => 'Missing Turnstile response',
            'invalid-input-response' => 'Invalid Turnstile response',
            'bad-request' => 'Bad Turnstile request',
            'timeout-or-duplicate' => 'Turnstile timeout or duplicate',
            'internal-error' => 'Turnstile internal error'
        ];

        $message = $errorMessages[$primaryError] ?? 'Turnstile verification failed';

        return $this->createFailureResult($message, $primaryError, [
            'error_codes' => $errorCodes
        ]);
    }
}
