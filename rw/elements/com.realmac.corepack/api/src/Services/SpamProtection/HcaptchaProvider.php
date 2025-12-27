<?php

namespace App\Services\SpamProtection;

use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * hCAPTCHA spam protection provider
 */
class HcaptchaProvider extends AbstractSpamProtectionProvider
{
    private const VERIFY_URL = 'https://hcaptcha.com/siteverify';

    public function getName(): string
    {
        return 'hcaptcha';
    }

    public function getTokenFields(): array
    {
        return ['h-captcha-response', 'hcaptcha_token', 'captcha_token'];
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
                return $this->createFailureResult('Missing hCAPTCHA token', 'missing_token');
            }

            $response = $this->verifyWithHcaptcha($token, $config, $request);

            if (!$response['success']) {
                return $this->handleVerificationFailure($response);
            }

            return $this->createSuccessResult([
                'hostname' => $response['hostname'] ?? null,
                'challenge_ts' => $response['challenge_ts'] ?? null,
                'credit' => $response['credit'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->createFailureResult(
                'hCAPTCHA verification failed: ' . $e->getMessage(),
                'provider_error'
            );
        }
    }

    /**
     * Verify token with hCAPTCHA API
     */
    private function verifyWithHcaptcha(string $token, array $config, Request $request): array
    {
        $data = [
            'secret' => $config['secret_key'],
            'response' => $token,
            'remoteip' => $this->getClientIp($request)
        ];

        // Add optional site key if provided
        if (!empty($config['site_key'])) {
            $data['sitekey'] = $config['site_key'];
        }

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
            'missing-input-secret' => 'Missing hCAPTCHA secret key',
            'invalid-input-secret' => 'Invalid hCAPTCHA secret key',
            'missing-input-response' => 'Missing hCAPTCHA response',
            'invalid-input-response' => 'Invalid hCAPTCHA response',
            'bad-request' => 'Bad hCAPTCHA request',
            'invalid-or-already-seen-response' => 'hCAPTCHA response already used',
            'not-using-dummy-passcode' => 'hCAPTCHA dummy passcode not allowed',
            'sitekey-secret-mismatch' => 'hCAPTCHA site key and secret key mismatch'
        ];

        $message = $errorMessages[$primaryError] ?? 'hCAPTCHA verification failed';

        return $this->createFailureResult($message, $primaryError, [
            'error_codes' => $errorCodes
        ]);
    }
}
