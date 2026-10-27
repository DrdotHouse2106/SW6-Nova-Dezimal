<?php declare(strict_types=1);

namespace Nova\Dezimal\Price;

use Nova\Dezimal\Service\DezimalSettingsReader;
use Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPrice;
use Shopware\Core\Content\Product\SalesChannel\Price\AbstractProductPriceCalculator;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Modus „stueck“ (Platten): Im ERP und in Shopware steht der Preis pro m². Verkauft wird pro Platte.
 * Vor der Preisberechnung wird der Preis deshalb mit der Fläche je Stück multipliziert – überall gleich:
 * Produktseite, Listen, Suche und Warenkorb. Shopwares Grundpreis (Verkaufseinheit = Fläche, Grundeinheit 1 m²)
 * zeigt dann wieder den m²-Preis.
 *
 * Die Multiplikation passiert nur im Arbeitsspeicher der geladenen Produkte, gespeichert wird nichts.
 */
class PieceAreaPriceCalculator extends AbstractProductPriceCalculator
{
    private const MARKER = 'novaDezimalScaled';

    public function __construct(
        private readonly AbstractProductPriceCalculator $decorated,
        private readonly DezimalSettingsReader $settingsReader,
        private readonly SystemConfigService $config,
    ) {
    }

    public function getDecorated(): AbstractProductPriceCalculator
    {
        return $this->decorated;
    }

    public function reset(): void
    {
        $this->decorated->reset();
    }

    /**
     * @param iterable<Entity> $products
     */
    public function calculate(iterable $products, SalesChannelContext $context): void
    {
        if ($this->config->getBool('NovaDezimal.config.active', $context->getSalesChannelId())) {
            foreach ($products as $product) {
                $this->scale($product);
            }
        }

        $this->decorated->calculate($products, $context);
    }

    private function scale(Entity $product): void
    {
        if ($product->hasExtension(self::MARKER)) {
            // bereits umgerechnet (Produkt wird im selben Request erneut berechnet)
            return;
        }

        $settings = $this->settingsReader->forProduct($product);
        if ($settings === null || !$settings->isPieceMode()) {
            return;
        }

        $factor = $settings->pieceArea;
        $product->addExtension(self::MARKER, new ArrayStruct(['factor' => $factor]));

        $price = $product->get('price');
        if ($price instanceof PriceCollection) {
            $product->assign(['price' => $this->scaleCollection($price, $factor)]);
        }

        $prices = $product->get('prices');
        if ($prices instanceof EntityCollection) {
            foreach ($prices as $advanced) {
                $value = $advanced->get('price');
                if ($value instanceof PriceCollection) {
                    $advanced->assign(['price' => $this->scaleCollection($value, $factor)]);
                }
            }
        }

        $cheapest = $product->has('cheapestPrice') ? $product->get('cheapestPrice') : null;
        if ($cheapest instanceof CheapestPrice) {
            $cheapest->setPrice($this->scaleCollection($cheapest->getPrice(), $factor));
        }
    }

    private function scaleCollection(PriceCollection $collection, float $factor): PriceCollection
    {
        $scaled = new PriceCollection();
        foreach ($collection as $key => $price) {
            $scaled->set($key, $this->scalePrice($price, $factor));
        }

        return $scaled;
    }

    private function scalePrice(Price $price, float $factor): Price
    {
        return new Price(
            $price->getCurrencyId(),
            $price->getNet() * $factor,
            $price->getGross() * $factor,
            $price->getLinked(),
            $price->getListPrice() !== null ? $this->scalePrice($price->getListPrice(), $factor) : null,
            $price->getPercentage(),
            $price->getRegulationPrice() !== null ? $this->scalePrice($price->getRegulationPrice(), $factor) : null,
        );
    }
}
