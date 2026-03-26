<?php
declare(strict_types=1);

namespace App\Avito;

use App\Model\ErrorAd;
use App\Model\Report;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final class AvitoApiClient
{
    public function __construct(
        private ClientInterface $http,
        private string $baseUrl,
    ) {}

    public function fetchLastReport(string $accessToken, string $path): Report
    {
        $response = $this->http->request('GET', $this->baseUrl . $path, [
            'http_errors' => false,
            'headers'     => ['Authorization' => 'Bearer ' . $accessToken],
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($statusCode === 403) {
            $data = json_decode($body, true);
            if (is_array($data) && ($data['result']['message'] ?? '') === 'invalid access token') {
                throw new InvalidTokenException('Invalid access token');
            }
            throw new RuntimeException("API error 403: {$body}");
        }

        if ($statusCode !== 200) {
            throw new RuntimeException("API error {$statusCode}: {$body}");
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['report_id'])) {
            throw new RuntimeException("Unexpected last report response: {$body}");
        }

        return new Report(reportExternalId: (string) $data['report_id']);
    }

    /**
     * @param list<int> $allowedCodes Коды из AVITO_ERROR_CODES. Пустой массив — вернуть [].
     * @return list<ErrorAd>
     */
    public function fetchErrorAds(string $accessToken, string $pathTemplate, string $reportId, array $allowedCodes): array
    {
        if ($allowedCodes === []) {
            return [];
        }

        $basePath = str_replace('{report_id}', $reportId, $pathTemplate);
        $allowedSet = array_flip($allowedCodes);
        $items = [];
        $page = 0;

        do {
            $response = $this->http->request('GET', $this->baseUrl . $basePath . '&page=' . $page, [
                'http_errors' => false,
                'headers'     => ['Authorization' => 'Bearer ' . $accessToken],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode === 403) {
                $data = json_decode($body, true);
                if (is_array($data) && ($data['result']['message'] ?? '') === 'invalid access token') {
                    throw new InvalidTokenException('Invalid access token');
                }
                throw new RuntimeException("API error 403: {$body}");
            }

            if ($statusCode !== 200) {
                throw new RuntimeException("API error {$statusCode}: {$body}");
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                throw new RuntimeException("Unexpected error ads response: {$body}");
            }

            $totalPages = (int) ($data['meta']['pages'] ?? 1);

            foreach ($data['items'] ?? [] as $item) {
                $errorType = null;
                $hasMatch = false;

                foreach ($item['messages'] ?? [] as $msg) {
                    $code = (int) ($msg['code'] ?? 0);
                    if (isset($allowedSet[$code])) {
                        if (!$hasMatch) {
                            $title = trim((string) ($msg['title'] ?? ''));
                            $errorType = $title !== '' ? $title : null;
                            $hasMatch = true;
                        }
                        // продолжаем цикл только ради первого совпадения — break после назначения
                        break;
                    }
                }

                if (!$hasMatch) {
                    continue;
                }

                $items[] = new ErrorAd(
                    adExternalId: (string) $item['ad_id'],
                    errorType: $errorType,
                );
            }

            $page++;
        } while ($page < $totalPages);

        return $items;
    }
}
