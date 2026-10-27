<?php declare(strict_types=1);

namespace Nova\Dezimal\Weclapp;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Schlanker Zugriff auf die weclapp-REST-API (nur Aufträge lesen und eine Auftragsposition ändern).
 */
class WeclappClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $config,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->token() !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSalesOrder(string $orderNumber): ?array
    {
        $data = $this->request('GET', 'salesOrder', ['orderNumber-eq' => $orderNumber, 'pageSize' => 2]);
        $result = $data['result'] ?? [];

        return \is_array($result) && \count($result) === 1 && \is_array($result[0]) ? $result[0] : null;
    }

    /**
     * @param array<string, mixed> $salesOrder
     *
     * @return array<string, mixed>
     */
    public function updateSalesOrder(array $salesOrder): array
    {
        return $this->request('PUT', 'salesOrder/id/' . rawurlencode((string) $salesOrder['id']), ['ignoreMissingProperties' => 'true'], $salesOrder);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('weclapp ist nicht eingerichtet (Adresse oder API-Token fehlt).');
        }

        $options = [
            'headers' => [
                'AuthenticationToken' => $this->token(),
                'Accept' => 'application/json',
            ],
            'query' => $query,
            'timeout' => 20,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, $this->baseUrl() . '/webapp/api/v1/' . $path, $options);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($status >= 300) {
            $message = '';
            $json = json_decode($content, true);
            if (\is_array($json)) {
                $message = (string) ($json['detail'] ?? $json['title'] ?? $json['error'] ?? '');
            }

            throw new \RuntimeException(\sprintf('weclapp antwortet mit HTTP %d%s', $status, $message !== '' ? ': ' . mb_substr($message, 0, 300) : ''));
        }

        $json = json_decode($content, true);

        return \is_array($json) ? $json : [];
    }

    private function baseUrl(): string
    {
        $url = trim($this->config->getString('NovaDezimal.config.weclappUrl'));
        $url = rtrim($url, '/');
        if ($url !== '' && !str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        // Nur die Adresse der Instanz, auch wenn jemand den API-Pfad mit eingetragen hat
        return preg_replace('#/webapp/api/v\d+$#', '', $url) ?? $url;
    }

    private function token(): string
    {
        return trim($this->config->getString('NovaDezimal.config.weclappToken'));
    }
}
