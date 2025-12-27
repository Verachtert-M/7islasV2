<?php

namespace App\Services\SpamProtection;

use App\ValueObjects\SpamProtectionResult;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Google reCAPTCHA spam protection provider
 * Supports both v2 and v3 reCAPTCHA
 */
class RecaptchaProvider extends AbstractSpamProtectionProvider
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function getName(): string
    {
        return 'recaptcha';
    }

    public function getTokenFields(): array
    {
        return ['g-recaptcha-response', 'recaptcha_token', 'captcha_token'];
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
                return $this->createFailureResult('Missing reCAPTCHA token', 'missing_token');
            }

            $response = $this->verifyWithGoogle($token, $config, $request);

            if (!$response['success']) {
                return $this->handleVerificationFailure($response);
            }

            // Handle v3 score threshold if configured
            if (isset($response['score']) && isset($config['score_threshold'])) {
                $score = (float) $response['score'];
                $threshold = (float) $config['score_threshold'];

                if ($score < $threshold) {
                    return $this->createFailureResult(
                        'reCAPTCHA score too low',
                        'score_too_low',
                        ['score' => $score, 'threshold' => $threshold]
                    );
                }
            }

            return $this->createSuccessResult([
                'score' => $response['score'] ?? null,
                'action' => $response['action'] ?? null,
                'hostname' => $response['hostname'] ?? null,
                'challenge_ts' => $response['challenge_ts'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->createFailureResult(
                'reCAPTCHA verification failed: ' . $e->getMessage(),
                'provider_error'
            );
        }
    }

    /**
     * Verify token with Google's reCAPTCHA API
     */
    private function verifyWithGoogle(string $token, array $config, Request $request): array
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
            'missing-input-secret' => 'Missing reCAPTCHA secret key',
            'invalid-input-secret' => 'Invalid reCAPTCHA secret key',
            'missing-input-response' => 'Missing reCAPTCHA response',
            'invalid-input-response' => 'Invalid reCAPTCHA response',
            'bad-request' => 'Bad reCAPTCHA request',
            'timeout-or-duplicate' => 'reCAPTCHA timeout or duplicate'
        ];

        $message = $errorMessages[$primaryError] ?? 'reCAPTCHA verification failed';

        return $this->createFailureResult($message, $primaryError, [
            'error_codes' => $errorCodes
        ]);
    }
}
