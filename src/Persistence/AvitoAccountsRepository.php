<?php
declare(strict_types=1);

namespace App\Persistence;

use App\Model\Account;
use App\Model\OAuthToken;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class AvitoAccountsRepository
{
    public function __construct(private Connection $conn) {}

    public function countAccounts(): int
    {
        $v = $this->conn->fetchOne('SELECT COUNT(*) FROM avito_accounts');
        return (int) $v;
    }

    /** @return list<int|string> */
    public function fetchAccountIds(int $limit): array
    {
        $limit = max(1, min(100, $limit));

        return $this->conn->fetchFirstColumn(
            'SELECT id FROM avito_accounts LIMIT ' . $limit
        );
    }

    public function fetchAccount(int $id): Account
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, client_id, client_secret, access_token, token_expires_at
             FROM avito_accounts WHERE id = ?',
            [$id]
        );

        if ($row === false) {
            throw new RuntimeException("Account not found: {$id}");
        }

        $tokenExpiresAt = null;
        if (isset($row['token_expires_at']) && $row['token_expires_at'] !== null) {
            $tokenExpiresAt = new \DateTimeImmutable((string) $row['token_expires_at']);
        }

        return new Account(
            id: (int) $row['id'],
            clientId: (string) $row['client_id'],
            clientSecret: (string) $row['client_secret'],
            accessToken: isset($row['access_token']) && $row['access_token'] !== null
                ? (string) $row['access_token']
                : null,
            tokenExpiresAt: $tokenExpiresAt,
        );
    }

    public function saveToken(int $id, OAuthToken $token): void
    {
        $this->conn->executeStatement(
            'UPDATE avito_accounts SET access_token = ?, token_expires_at = ? WHERE id = ?',
            [
                $token->accessToken,
                $token->expiresAt->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }
}
