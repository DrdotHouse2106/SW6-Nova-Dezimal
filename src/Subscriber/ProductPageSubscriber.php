<?php declare(strict_types=1);

namespace Nova\Dezimal\Subscriber;

use Nova\Dezimal\Service\DezimalSettingsReader;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelEntityLoadedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die Dezimal-Einstellungen an jedes geladene Produkt (Erweiterung „novaDezimal“),
 * damit Produktseite, Listen und Schnellansicht sie ohne eigene Logik im Template nutzen können.
 */
class ProductPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DezimalSettingsReader $settingsReader,
        private readonly SystemConfigService $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sales_channel.product.loaded' => 'onProductsLoaded',
        ];
    }

    /**
     * @param SalesChannelEntityLoadedEvent<\Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity> $event
     */
    public function onProductsLoaded(SalesChannelEntityLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        if (!$this->config->getBool('NovaDezimal.config.active', $salesChannelId)) {
            return;
        }

        $cartEdit = $this->config->getBool('NovaDezimal.config.cartEdit', $salesChannelId);

        foreach ($event->getEntities() as $product) {
            $settings = $this->settingsReader->forProduct($product);
            if ($settings !== null) {
                $product->addExtension('novaDezimal', new ArrayStruct($settings->toArray() + ['cartEdit' => $cartEdit]));
            }
        }
    }
}
