<?php

namespace App\Fetchers;

use App\Core\Context;
use App\Services\TransactionService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TransactionFetcher
{
    private Client $httpClient;
    private Context $context;

    const POYNT_ENDPOINT = 'https://services.poynt.net/businesses';

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->httpClient = new Client();
    }

    /**
     * Fetch transactions for a business and store them via the service.
     *
     * @param string $accessToken OAuth token.
     * @param string $businessId Business identifier.
     * @return bool True on success, false otherwise.
     */
    public function fetchAndStore(string $accessToken, string $businessId): bool
    {
        try {
            $url = self::POYNT_ENDPOINT . '/' . $businessId . '/transactions';
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (!$data || !isset($data['transactions'])) {
                return false;
            }

            $service = new TransactionService($this->context);
            foreach ($data['transactions'] as $transaction) {
                $service->upsert($transaction, $transaction['receipt'] ?? null);
            }

            return true;
        } catch (GuzzleException $e) {
            $this->context->getLog()->error('TransactionFetcher::fetchAndStore: ' . $e->getMessage());
            return false;
        }
    }
}

