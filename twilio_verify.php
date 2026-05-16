<?php

function getTwilioCredentials(): array
{
    $sid        = 'secret_sid';
    $token      = 'secret_token';
    $serviceSid = 'secret_service_sid';

    if ($sid === '' || $token === '' || $serviceSid === '') {
        throw new RuntimeException('Twilio credentials are incomplete.');
    }

    return [$sid, $token, $serviceSid];
}

function normalizePhilippinePhoneForTwilio(string $phoneNumber): ?string
{
    $phoneNumber = preg_replace('/[\s\-()]/', '', trim($phoneNumber));

    if (preg_match('/^09\d{9}$/', $phoneNumber)) {
        return '+63' . substr($phoneNumber, 1);
    }
    if (preg_match('/^639\d{9}$/', $phoneNumber)) {
        return '+' . $phoneNumber;
    }
    if (preg_match('/^\+639\d{9}$/', $phoneNumber)) {
        return $phoneNumber;
    }

    return null;
}

function twilioVerifyRequest(string $endpoint, array $fields): array
{
    [$sid, $token, $serviceSid] = getTwilioCredentials();

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Twilio SMS.');
    }

    $url = 'https://verify.twilio.com/v2/Services/' . rawurlencode($serviceSid) . '/' . $endpoint;
    $ch  = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $rawResponse = curl_exec($ch);
    $curlError   = curl_error($ch);
    $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== '' || $rawResponse === false) {
        throw new RuntimeException('Twilio request failed: ' . $curlError);
    }

    $result = json_decode($rawResponse, true);
    if (!is_array($result)) {
        throw new RuntimeException('Twilio returned an invalid response.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $result['message'] ?? 'Twilio rejected the request.';
        throw new RuntimeException($message);
    }

    return $result;
}

function sendOtpViaTwilio(string $phoneNumber): bool
{
    $phoneNumber = normalizePhilippinePhoneForTwilio($phoneNumber);
    if ($phoneNumber === null) {
        throw new InvalidArgumentException('Invalid Philippine phone number.');
    }

    $verification = twilioVerifyRequest('Verifications', [
        'To'      => $phoneNumber,
        'Channel' => 'sms',
    ]);

    return !empty($verification['sid']);
}

function verifyOtpViaTwilio(string $phoneNumber, string $otpCode): bool
{
    $phoneNumber = normalizePhilippinePhoneForTwilio($phoneNumber);
    if ($phoneNumber === null) {
        throw new InvalidArgumentException('Invalid Philippine phone number.');
    }

    $verificationCheck = twilioVerifyRequest('VerificationCheck', [
        'To'   => $phoneNumber,
        'Code' => $otpCode,
    ]);

    return ($verificationCheck['status'] ?? '') === 'approved';
}