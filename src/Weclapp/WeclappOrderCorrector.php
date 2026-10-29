<?php declare(strict_types=1);

namespace Nova\Dezimal\Weclapp;

use Nova\Dezimal\Service\DezimalPayload;
use Nova\Dezimal\Service\DezimalSettings;
use Nova\Dezimal\Service\DezimalSettingsReader;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
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
 * - nur Bestellungen aus Verkaufskanälen, in denen Nova Dezimal aktiv ist, und nur Positionen, die der
 *   Warenkorb berechnet hat und deren Menge zu den Einstellungen des Artikels passt
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

    /** @var EntityRepository<ProductCollection>|null */
    private ?EntityRepository $productRepository = null;

    private ?DezimalSettingsReader $settingsReader = null;

    /** @var array<string, ProductEntity|null> */
    private array $products = [];

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

    /**
     * Setter statt Konstruktor-Argument: ein Update mit noch altem kompiliertem Container führt so nie zu einem Fehler 500.
     *
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function setProductCheck(EntityRepository $productRepository, DezimalSettingsReader $settingsReader): void
    {
        $this->productRepository = $productRepository;
        $this->settingsReader = $settingsReader;
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

        $this->products = [];

        foreach ($this->loadOrders($context, $limit, $orderNumber) as $order) {
            [$status, $info] = $this->correctOrder($order, $dryRun, $context);

            $line = \sprintf('Bestellung %s: %s – %s', $order->getOrderNumber(), $status, $info);
            $lines[] = $line;

            // Nur schreiben, wenn sich etwas geändert hat (sonst alle 10 Minuten „Bestellung geändert“)
            $fields = $order->getCustomFields() ?? [];
            $previous = (string) ($fields[self::FIELD_INFO] ?? '');
            $previous = preg_replace('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}: /', '', $previous) ?? $previous;
            if (($fields[self::FIELD_STATUS] ?? null) === $status && $previous === mb_substr($info, 0, 982)) {
                continue;
            }

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
    private function correctOrder(OrderEntity $order, bool $dryRun, Context $context): array
    {
        if (!$this->config->getBool('NovaDezimal.config.active', $order->getSalesChannelId())) {
            return [self::STATUS_SKIPPED, 'Nova Dezimal ist im Verkaufskanal der Bestellung nicht aktiv'];
        }

        $rejected = [];
        $positions = $this->dezimalPositions($order, $context, $rejected);
        if ($positions === []) {
            return $rejected !== []
                ? [self::STATUS_ERROR, 'nicht plausibel, bitte von Hand prüfen: ' . implode('; ', $rejected)]
                : [self::STATUS_SKIPPED, 'keine Dezimal-Position'];
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
            $quantity = (float) ($item['quantity'] ?? 0);
            $target = $position['amount'];
            if ($quantity <= 0) {
                $notes[] = $position['number'] . ': Menge in weclapp fehlt';
                continue;
            }

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

        $notes = array_merge($notes, array_map(static fn (string $note): string => $note . ' (nicht plausibel, nicht geändert)', $rejected));
        $info = implode('; ', array_merge($changes, $notes));
        if ($changes === []) {
            return [$notes !== [] && (str_contains($info, 'keine passende') || $rejected !== []) ? self::STATUS_ERROR : self::STATUS_OK, $info];
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
     * @param list<string>|null $rejected Positionen, deren Payload nicht zu Warenkorb und Artikel passt
     *
     * @param-out list<string> $rejected
     *
     * @return list<array{number: string, quantity: int, amount: float, unit: string}>
     */
    private function dezimalPositions(OrderEntity $order, ?Context $context = null, ?array &$rejected = null): array
    {
        $positions = [];
        $rejected = [];
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

            if ($context !== null) {
                $problem = $this->implausible($lineItem, $data, $amount, $context);
                if ($problem !== null) {
                    $rejected[] = $number . ': ' . $problem;
                    continue;
                }
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
     * Prüft den Payload gegen den berechneten Warenkorb-Status und die Einstellungen des Artikels.
     *
     * @param array<mixed> $data
     */
    private function implausible(OrderLineItemEntity $lineItem, array $data, float $amount, Context $context): ?string
    {
        if (($data['status'] ?? null) !== 'berechnet') {
            return 'Position wurde nicht vom Warenkorb berechnet';
        }

        $positionPrice = DezimalSettingsReader::float($data['positionsPreis'] ?? null);
        if ($positionPrice !== null && abs($positionPrice - $lineItem->getTotalPrice()) > 0.02) {
            return 'Positionspreis weicht ab';
        }

        if ($this->productRepository === null || $this->settingsReader === null) {
            return null;
        }

        $productId = $lineItem->getProductId() ?? $lineItem->getReferencedId();
        $product = \is_string($productId) && $productId !== '' ? $this->product($productId, $context) : null;
        $settings = $product !== null ? $this->settingsReader->forProduct($product) : null;
        if (!$settings instanceof DezimalSettings) {
            return 'Artikel ist kein Dezimal-Artikel';
        }

        $quantity = max(1, $lineItem->getQuantity());
        if ($settings->isPieceMode()) {
            return abs($amount - $quantity * $settings->pieceArea) > 0.001 ? 'Menge passt nicht zum Stückmaß' : null;
        }

        return $amount > $settings->max * $quantity + 0.0001 ? 'Menge über dem Höchstwert des Artikels' : null;
    }

    private function product(string $productId, Context $context): ?ProductEntity
    {
        if (!\array_key_exists($productId, $this->products) && $this->productRepository !== null) {
            $inner = clone $context;
            $inner->setConsiderInheritance(true);
            $criteria = new Criteria([$productId]);
            $criteria->addAssociation('unit');
            $product = $this->productRepository->search($criteria, $inner)->getEntities()->first();
            $this->products[$productId] = $product instanceof ProductEntity ? $product : null;
        }

        return $this->products[$productId] ?? null;
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
