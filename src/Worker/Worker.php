<?php
declare(strict_types=1);

namespace App\Worker;

use App\Avito\AvitoApiClient;
use App\Avito\InvalidTokenException;
use App\Avito\OAuthClient;
use App\Config\WorkerConfig;
use App\Logging\FileLogger;
use App\Model\ErrorAd;
use App\Persistence\AvitoAccountsRepository;
use App\Persistence\AvitoErrorAdsRepository;
use App\Persistence\AvitoReportsRepository;
use App\Persistence\Db;
use Throwable;

final class Worker
{
    public function __construct(
        private WorkerConfig $config,
        private FileLogger $logger,
        private Db $db,
        private OAuthClient $oauthClient,
        private AvitoApiClient $apiClient,
    ) {}

    public function run(): int
    {
        $this->logger->info('Worker starting', ['pid' => getmypid()]);

        $conn = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $conn = $this->db->connect();
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logger->warning('DB connect failed (retry)', ['attempt' => $attempt, 'error' => $e->getMessage()]);
                usleep(300_000);
            }
        }

        if ($conn === null) {
            $this->logger->error('DB connection failed', ['error' => $lastError?->getMessage()]);
            return 1;
        }

        try {
            $accountsRepo = new AvitoAccountsRepository($conn);
            $count = $accountsRepo->countAccounts();
            $ids = $accountsRepo->fetchAccountIds(10);

            $this->logger->info('Read avito_accounts ok', [
                'count'      => $count,
                'sample_ids' => $ids,
            ]);

            if ($ids === []) {
                $this->logger->info('No accounts found; skip sync');
                return 0;
            }

            $reportsRepo = new AvitoReportsRepository($conn);
            $errorAdsRepo = new AvitoErrorAdsRepository($conn);

            $processed = 0;
            $skipped = 0;
            $failed = 0;
            $savedErrorAds = 0;

            foreach ($ids as $rawAccountId) {
                $currentAccountId = (int) $rawAccountId;

                try {
                    // Token: reuse or refresh.
                    $account = $accountsRepo->fetchAccount($currentAccountId);
                    $now = new \DateTimeImmutable();
                    $needsToken = $account->accessToken === null
                        || $account->tokenExpiresAt === null
                        || $account->tokenExpiresAt <= $now;

                    if ($needsToken) {
                        $token = $this->oauthClient->fetchToken($account->clientId, $account->clientSecret);
                        $accountsRepo->saveToken($currentAccountId, $token);
                        $currentAccessToken = $token->accessToken;
                        $this->logger->info('OAuth token refreshed', [
                            'account_id' => $currentAccountId,
                            'expires_at' => $token->expiresAt->format('Y-m-d H:i:s'),
                        ]);
                    } else {
                        $currentAccessToken = $account->accessToken;
                        $this->logger->info('OAuth token reused', [
                            'account_id' => $currentAccountId,
                            'expires_at' => $account->tokenExpiresAt->format('Y-m-d H:i:s'),
                        ]);
                    }

                    // Fetch last report; retry once on invalid token (403).
                    try {
                        $report = $this->apiClient->fetchLastReport($currentAccessToken, $this->config->avitoLastReportPath);
                    } catch (InvalidTokenException) {
                        $token = $this->oauthClient->fetchToken($account->clientId, $account->clientSecret);
                        $accountsRepo->saveToken($currentAccountId, $token);
                        $currentAccessToken = $token->accessToken;
                        $this->logger->info('OAuth token force-refreshed on 403', ['account_id' => $currentAccountId]);
                        $report = $this->apiClient->fetchLastReport($currentAccessToken, $this->config->avitoLastReportPath);
                    }

                    $reportExternalId = $report->reportExternalId;

                    if ($reportsRepo->reportExists($currentAccountId, $reportExternalId)) {
                        $skipped++;
                        $this->logger->info('Skip existing last report', [
                            'account_id'         => $currentAccountId,
                            'report_external_id' => $reportExternalId,
                        ]);
                        continue;
                    }

                    // Fetch error ads via HTTP first — outside the transaction.
                    try {
                        $errorAds = $this->apiClient->fetchErrorAds(
                            $currentAccessToken,
                            $this->config->avitoErrorAdsPath,
                            $reportExternalId,
                            $this->config->errorCodes,
                        );
                    } catch (InvalidTokenException) {
                        $token = $this->oauthClient->fetchToken($account->clientId, $account->clientSecret);
                        $accountsRepo->saveToken($currentAccountId, $token);
                        $currentAccessToken = $token->accessToken;
                        $this->logger->info('OAuth token force-refreshed on 403 (error ads)', ['account_id' => $currentAccountId]);
                        $errorAds = $this->apiClient->fetchErrorAds(
                            $currentAccessToken,
                            $this->config->avitoErrorAdsPath,
                            $reportExternalId,
                            $this->config->errorCodes,
                        );
                    }

                    $errorItems = array_map(
                        static fn(ErrorAd $e) => [
                            'ad_external_id' => $e->adExternalId,
                            'error_type'     => $e->errorType,
                            'rx_good_items'  => $e->rxGoodItems,
                        ],
                        $errorAds,
                    );

                    // Persist report + error ads atomically.
                    $conn->beginTransaction();
                    try {
                        $reportsRepo->saveReport($currentAccountId, $reportExternalId);
                        $reportId = $reportsRepo->findReportId($currentAccountId, $reportExternalId);
                        if ($reportId === null) {
                            throw new \RuntimeException('Saved report not found');
                        }

                        $savedNow = $errorAdsRepo->saveMany($reportId, $errorItems);
                        $conn->commit();
                    } catch (\Throwable $e) {
                        $conn->rollBack();
                        throw $e;
                    }

                    $savedErrorAds += $savedNow;
                    $processed++;
                    $this->logger->info('Last report saved', [
                        'account_id'         => $currentAccountId,
                        'report_external_id' => $reportExternalId,
                        'report_id'          => $reportId,
                        'saved_error_ads'    => $savedNow,
                    ]);
                } catch (Throwable $e) {
                    $failed++;
                    $this->logger->warning('Account processing failed', [
                        'account_id' => $currentAccountId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Sync summary', [
                'processed'       => $processed,
                'skipped'         => $skipped,
                'failed'          => $failed,
                'saved_error_ads' => $savedErrorAds,
            ]);

            return $failed > 0 ? 1 : 0;
        } catch (Throwable $e) {
            $this->logger->error('Fatal sync error', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
