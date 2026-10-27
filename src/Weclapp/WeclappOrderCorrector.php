<?php declare(strict_types=1);

namespace Nova\Dezimal\Weclapp;

use Nova\Dezimal\Service\DezimalPayload;
use Nova\Dezimal\Service\DezimalSettingsReader;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Korrigiert Dezimal-Positionen im weclapp-Auftrag.
 *
 * weclapp übernimmt aus Shopware nur ganze Mengen: aus „2,35 m² × 19,90 €“ wird „1 × 46,77 €“, bei Platten
 * „2 Stück × Plattenpreis“. Hier wird die Position auf die echte Menge in der Einheit gesetzt
 * (2,35 bzw. 2 × 1,5 = 3 m²) und der Einzelpreis so umgerechnet, dass der Positionsbetrag gleich bleibt.
 *
 * Sicherheitsregeln:
 * - nur Bestellungen ab dem Startdatum (Einstellung), nie ältere, von Hand korrigierte Aufträge
 * - nur Aufträge in den freigegebenen weclapp-Status (Standard: in Erfassung, Auftragsbestätigung gedruckt)
 * - eine Position wird nur geändert, wenn ihre Menge noch der Shopware-Menge entspricht
 * - Testmodus: es wird nur protokolliert, was geändert würde
 *
 * Ergebnis steht an der Bestellung in den Zusatzfeldern nova_dezimal_weclapp_status / _info.
 */
class WeclappOrderCorrector
{
    public const FIELD_STATUS = 'nova_dezimal_weclapp_status';
    public const FIELD_INFO = 'nova_dezimal_weclapp_info';

    public const STATUS_OK = 'ok';
    public const STATUS_WAITING = 'wartet';
    public const STATUS_TEST = 'test';
    public const STATUS_ERROR = 'fehler';
    public const STATUS_SKIPPED = 'uebersprungen';

    private const DEFAULT_STATUSES = 'ORDER_ENTRY_IN_PROGRESS,ORDER_CONFIRMATION_PRINTED';

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly WeclappClient $client,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool('NovaDezimal.config.weclappEnabled') && $this->client->isConfigured();
    }

    /**
     * @return list<string> Protokollzeilen
     */
    public function run(Context $context, int $limit = 30, ?bool $dryRun = null, ?string $orderNumber = null): array
    {
        if (!$this->client->isConfigured()) {
            return ['weclapp ist nicht eingerichtet (Adresse oder API-Token fehlt).'];
        }

        $dryRun ??= $this->config->getBool('NovaDezimal.config.weclappDryRun');
        $lines = [];

        foreach ($this->loadOrders($context, $limit, $orderNumber) as $order) {
            [$status, $info] = $this->correctOrder($order, $dryRun);

            $line = \sprintf('Bestellung %s: %s – %s', $order->getOrderNumber(), $status, $info);
            $lines[] = $line;
            $this->logger->info('[Nova Dezimal] ' . $line);

            $this->orderRepository->update([[
                'id' => $order->getId(),
                'customFields' => [
                    self::FIELD_STATUS => $status,
                    self::FIELD_INFO => mb_substr(date('d.m.Y H:i') . ': ' . $info, 0, 1000),
                ],
            ]], $context);
        }

        if ($lines === []) {
            $lines[] = 'Keine offenen Bestellungen mit Dezimal-Positionen.';
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function correctOrder(OrderEntity $order, bool $dryRun): array
    {
        $positions = $this->dezimalPositions($order);
        if ($positions === []) {
            return [self::STATUS_SKIPPED, 'keine Dezimal-Position'];
        }

        try {
            $salesOrder = $this->client->findSalesOrder((string) $order->getOrderNumber());
        } catch (\Throwable $e) {
            return [self::STATUS_WAITING, 'weclapp nicht erreichbar: ' . $e->getMessage()];
        }

        if ($salesOrder === null) {
            $days = max(1, $this->config->getInt('NovaDezimal.config.weclappDays') ?: 14);
            $age = $order->getOrderDateTime()->diff(new \DateTimeImmutable())->days;

            return $age > $days
                ? [self::STATUS_SKIPPED, \sprintf('Auftrag nach %d Tagen nicht in weclapp gefunden', $days)]
                : [self::STATUS_WAITING, 'Auftrag noch nicht in weclapp'];
        }

        $allowed = array_filter(array_map('trim', explode(',', $this->config->getString('NovaDezimal.config.weclappStatuses') ?: self::DEFAULT_STATUSES)));
        $state = (string) ($salesOrder['status'] ?? '');
        if (!\in_array($state, $allowed, true)) {
            return [self::STATUS_SKIPPED, 'weclapp-Status ' . $state . ' – nicht mehr änderbar, bitte von Hand prüfen'];
        }

        $decimals = $this->config->getInt('NovaDezimal.config.weclappPriceDecimals') ?: 4;
        $items = \is_array($salesOrder['orderItems'] ?? null) ? $salesOrder['orderItems'] : [];
        $used = [];
        $changes = [];
        $notes = [];

        foreach ($positions as $position) {
            $index = $this->findItem($items, $position, $used);
            if ($index === null) {
                $notes[] = $position['number'] . ': keine passende Position in weclapp';
                continue;
            }
            $used[] = $index;

            $item = $items[$index];
            $quantity = (float) $item['quantity'];
            $target = $position['amount'];

            if (abs($quantity - $target) < 0.0001) {
                $notes[] = $position['number'] . ': stimmt bereits (' . $this->num($target) . ' ' . $position['unit'] . ')';
                continue;
            }

            $unitPrice = (float) ($item['unitPrice'] ?? 0);
            $newUnitPrice = round($unitPrice * $quantity / $target, $decimals);

            $items[$index]['quantity'] = $this->num($target, 4, '.');
            $items[$index]['unitPrice'] = $this->num($newUnitPrice, $decimals, '.');
            $changes[] = \sprintf(
                '%s: %s × %s → %s %s × %s',
                $position['number'],
                $this->num($quantity),
                $this->num($unitPrice, 2),
                $this->num($target),
                $position['unit'],
                $this->num($newUnitPrice, $decimals)
            );
        }

        $info = implode('; ', array_merge($changes, $notes));
        if ($changes === []) {
            return [$notes !== [] && str_contains($info, 'keine passende') ? self::STATUS_ERROR : self::STATUS_OK, $info];
        }

        if ($dryRun) {
            return [self::STATUS_TEST, 'Testmodus, würde ändern: ' . $info];
        }

        $before = (float) ($salesOrder['grossAmount'] ?? 0);
        $salesOrder['orderItems'] = $items;

        try {
            $updated = $this->client->updateSalesOrder($salesOrder);
        } catch (\Throwable $e) {
            return [self::STATUS_ERROR, 'Speichern in weclapp fehlgeschlagen: ' . $e->getMessage() . ' (' . $info . ')'];
        }

        $after = (float) ($updated['grossAmount'] ?? $before);
        if (abs($after - $before) >= 0.01) {
            $info .= \sprintf('; Hinweis: Bruttobetrag %s → %s (Rundung, bitte prüfen)', $this->num($before, 2), $this->num($after, 2));
        }

        return [self::STATUS_OK, $info];
    }

    /**
     * @return list<array{number: string, quantity: int, amount: float, unit: string}>
     */
    private function dezimalPositions(OrderEntity $order): array
    {
        $positions = [];
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }
            $payload = $lineItem->getPayload() ?? [];
            $data = $payload[DezimalPayload::KEY] ?? null;
            if (!\is_array($data)) {
                continue;
            }
            $amount = DezimalSettingsReader::float($data['gesamtmenge'] ?? null);
            $number = (string) ($payload['productNumber'] ?? '');
            if ($amount === null || $amount <= 0 || $number === '') {
                continue;
            }

            $positions[] = [
                'number' => $number,
                'quantity' => $lineItem->getQuantity(),
                'amount' => round($amount, 4),
                'unit' => (string) ($data['einheit'] ?? ''),
            ];
        }

        return $positions;
    }

    /**
     * Passende weclapp-Position: gleiche Artikelnummer, Menge = Shopware-Menge (noch nicht korrigiert)
     * oder bereits = Zielmenge.
     *
     * @param array<int|string, mixed> $items
     * @param array{number: string, quantity: int, amount: float, unit: string} $position
     * @param list<int|string> $used
     */
    private function findItem(array $items, array $position, array $used): int|string|null
    {
        $fallback = null;
        foreach ($items as $index => $item) {
            if (\in_array($index, $used, true) || !\is_array($item) || (string) ($item['articleNumber'] ?? '') !== $position['number']) {
                continue;
            }
            $quantity = (float) ($item['quantity'] ?? 0);
            if (abs($quantity - $position['quantity']) < 0.0001) {
                return $index;
            }
            if (abs($quantity - $position['amount']) < 0.0001) {
                $fallback ??= $index;
            }
        }

        return $fallback;
    }

    /**
     * @return iterable<OrderEntity>
     */
    private function loadOrders(Context $context, int $limit, ?string $orderNumber): iterable
    {
        $criteria = new Criteria();
        $criteria->addAssociation('lineItems');
        $criteria->setLimit($limit);
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::ASCENDING));

        if ($orderNumber !== null && $orderNumber !== '') {
            $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));

            return $this->orderRepository->search($criteria, $context)->getEntities();
        }

        $since = $this->since();
        $criteria->addFilter(new RangeFilter('orderDateTime', [RangeFilter::GTE => $since->format('Y-m-d H:i:s')]));
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('customFields.' . self::FIELD_STATUS, null),
            new EqualsAnyFilter('customFields.' . self::FIELD_STATUS, [self::STATUS_WAITING, self::STATUS_TEST]),
        ]));
        // nur Bestellungen, in denen überhaupt eine Dezimal-Position steckt (Suche im Payload-JSON)
        $criteria->addFilter(new ContainsFilter('lineItems.payload', '"' . DezimalPayload::KEY . '"'));

        $orders = [];
        foreach ($this->orderRepository->search($criteria, $context)->getEntities() as $order) {
            if ($this->dezimalPositions($order) !== []) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    private function since(): \DateTimeImmutable
    {
        $days = max(1, $this->config->getInt('NovaDezimal.config.weclappDays') ?: 14);
        $limit = new \DateTimeImmutable('-' . ($days + 1) . ' days');

        $configured = trim($this->config->getString('NovaDezimal.config.weclappSince'));
        try {
            $since = $configured !== '' ? new \DateTimeImmutable($configured) : $limit;
        } catch (\Exception) {
            $since = $limit;
        }

        return $since > $limit ? $since : $limit;
    }

    private function num(float $value, int $decimals = 4, string $separator = ','): string
    {
        $formatted = number_format($value, $decimals, $separator, '');
        if ($decimals > 2 && str_contains($formatted, $separator)) {
            $formatted = rtrim(rtrim($formatted, '0'), $separator);
        }

        return $formatted;
    }
}
