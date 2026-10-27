<?php declare(strict_types=1);

namespace Nova\Dezimal\Subscriber;

use Nova\Dezimal\Service\DezimalPayload;
use Nova\Dezimal\Service\DezimalSettingsReader;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Übernimmt beim Hinzufügen den eingegebenen Wert (Payload `novaDezimal.wert` aus dem Formular).
 *
 * Modus „menge“: Die Position hat immer Menge 1. Wird derselbe Artikel erneut hinzugefügt, werden die Werte
 * addiert (2 m² + 1,5 m² = 3,5 m²). Fehlt ein Wert (z. B. aus einer Liste), gilt der Vorgabewert.
 * Modus „stueck“: normale Stückmenge, die Fläche rechnet der Warenkorb-Prozessor.
 */
class LineItemAddedSubscriber implements EventSubscriberInterface
{
    /**
     * @param SalesChannelRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     */
    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly DezimalSettingsReader $settingsReader,
        private readonly SystemConfigService $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [BeforeLineItemAddedEvent::class => 'onLineItemAdded'];
    }

    public function onLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $lineItem = $event->getLineItem();
        if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE || $lineItem->getReferencedId() === null) {
            return;
        }

        $context = $event->getSalesChannelContext();
        if (!$this->config->getBool('NovaDezimal.config.active', $context->getSalesChannelId())) {
            return;
        }

        $product = $this->productRepository->search(new Criteria([$lineItem->getReferencedId()]), $context)->first();
        if (!$product instanceof SalesChannelProductEntity) {
            // nicht sichtbar oder nicht vorhanden – Shopware meldet das selbst
            return;
        }

        $settings = $this->settingsReader->forProduct($product);
        if ($settings === null) {
            return;
        }

        if ($settings->isPieceMode()) {
            // Fläche wird im Warenkorb-Prozessor aus der Stückzahl berechnet
            $target = $event->isMerged() ? $event->getCart()->get($lineItem->getId()) : $lineItem;
            $target?->setPayloadValue(DezimalPayload::KEY, ['modus' => $settings->mode]);

            return;
        }

        $value = DezimalPayload::value($lineItem) ?? $settings->default;

        if ($event->isMerged()) {
            // Shopware hat die Mengen der vorhandenen Position addiert – stattdessen die Werte addieren
            $existing = $event->getCart()->get($lineItem->getId());
            if ($existing === null) {
                return;
            }
            $previous = DezimalPayload::value($existing) ?? $settings->default;
            $existing->setQuantity(1);
            $existing->setPayloadValue(DezimalPayload::KEY, ['modus' => $settings->mode, 'wert' => $previous + $value]);

            return;
        }

        $lineItem->setQuantity(1);
        $lineItem->setPayloadValue(DezimalPayload::KEY, ['modus' => $settings->mode, 'wert' => $value]);
    }
}
