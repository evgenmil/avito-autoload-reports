<?php
declare(strict_types=1);

namespace App\Avito;

use App\Model\OAuthToken;
use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final class OAuthClient
{
    public function __construct(
        private ClientInterface $http,
        private string $tokenUrl,
    ) {}

    public function fetchToken(string $clientId, string $clientSecret): OAuthToken
    {
        $response = $this->http->request('POST', $this->tokenUrl, [
            'form_params' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ],
        ]);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (
            !is_array($data)
            || !isset($data['access_token'])
            || !isset($data['expires_in'])
            || (string) $data['access_token'] === ''
        ) {
            throw new RuntimeException('Unexpected OAuth response: ' . $body);
        }

        $expiresIn = max(0, (int) $data['expires_in'] - 60);
        $expiresAt = new DateTimeImmutable('+' . $expiresIn . ' seconds');

        return new OAuthToken(
            accessToken: (string) $data['access_token'],
            expiresAt: $expiresAt,
        );
    }
}
