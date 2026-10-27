<?php declare(strict_types=1);

namespace Nova\Dezimal\Cart;

use Nova\Dezimal\Service\DezimalPayload;
use Nova\Dezimal\Service\DezimalSettings;
use Nova\Dezimal\Service\DezimalSettingsReader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\ReferencePriceDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Läuft nach Shopwares Produkt-Prozessor (Priorität 5000) und vor den Aktionen (4900).
 *
 * Modus „menge“: berechnet den Positionspreis = Einheitspreis × Wert / „Preis gilt pro“ (Menge bleibt 1).
 * Die Preisdefinition bleibt der Einheitspreis – Shopware setzt sie bei jeder Neuberechnung ohnehin aus dem
 * Produkt neu, so kann der Faktor nie doppelt angewendet werden. Gewicht für den Versand = Gewicht je Einheit × Wert.
 * Modus „stueck“: der Stückpreis kommt bereits richtig aus dem Produkt (siehe PieceAreaPriceCalculator),
 * hier wird nur die Fläche in den Payload geschrieben.
 */
class DezimalCartProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
        private readonly DezimalSettingsReader $settingsReader,
        private readonly SystemConfigService $config,
    ) {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        if (!$this->config->getBool('NovaDezimal.config.active', $context->getSalesChannelId())) {
            return;
        }

        foreach ($toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            if (!DezimalPayload::has($lineItem)) {
                continue;
            }

            $product = $data->get('product-' . $lineItem->getReferencedId());
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            $settings = $this->settingsReader->forProduct($product);
            if ($settings === null) {
                // Artikel ist kein Dezimal-Artikel mehr – Position wie normal behandeln
                $lineItem->removePayloadValue(DezimalPayload::KEY);
                $alias = $this->aliasKey($context);
                if ($alias !== '') {
                    $lineItem->removePayloadValue($alias);
                }
                continue;
            }

            if ($settings->isPieceMode()) {
                $this->processPiece($lineItem, $settings, $context);
                continue;
            }

            $this->processAmount($lineItem, $settings, $product, $toCalculate, $context);
        }
    }

    private function aliasKey(SalesChannelContext $context): string
    {
        return trim($this->config->getString('NovaDezimal.config.payloadAlias', $context->getSalesChannelId()));
    }

    private function processPiece(LineItem $lineItem, DezimalSettings $settings, SalesChannelContext $context): void
    {
        $price = $lineItem->getPrice();
        $unitPrice = $price !== null && $settings->pieceArea > 0 ? $price->getUnitPrice() / $settings->pieceArea : 0.0;

        DezimalPayload::write($lineItem, $settings, $settings->pieceArea, $unitPrice, $this->aliasKey($context));
    }

    private function processAmount(LineItem $lineItem, DezimalSettings $settings, SalesChannelProductEntity $product, Cart $cart, SalesChannelContext $context): void
    {
        $definition = $lineItem->getPriceDefinition();
        if (!$definition instanceof QuantityPriceDefinition) {
            return;
        }

        $entered = DezimalPayload::value($lineItem) ?? $settings->default;
        $value = $settings->normalize($entered);
        if (abs($value - $entered) > 0.00001) {
            $cart->addErrors(new DezimalValueAdjustedError(
                (string) $lineItem->getReferencedId(),
                (string) $lineItem->getLabel(),
                $this->format($value, $settings),
                $settings->unit
            ));
        }

        if ($lineItem->getQuantity() !== 1) {
            $lineItem->setQuantity(1);
        }

        $unitPrice = $this->unitPrice($product, $value, $definition->getPrice());
        $calculatedDefinition = new QuantityPriceDefinition(
            $unitPrice * $value / $settings->basePriceValue,
            $definition->getTaxRules(),
            1
        );
        if ($definition->getListPrice() !== null) {
            $calculatedDefinition->setListPrice($definition->getListPrice() * $value / $settings->basePriceValue);
        }
        // Grundpreis „je m²“ bleibt korrekt: Inhalt der Position = eingegebener Wert
        $calculatedDefinition->setReferencePriceDefinition(new ReferencePriceDefinition(
            $value,
            $settings->basePriceValue,
            $settings->unit
        ));

        $lineItem->setPrice($this->calculator->calculate($calculatedDefinition, $context));

        $delivery = $lineItem->getDeliveryInformation();
        $weight = $product->getWeight();
        if ($delivery instanceof DeliveryInformation && $weight !== null && $weight > 0) {
            $delivery->setWeight(round($weight * $value / $settings->basePriceValue, 3));
        }

        DezimalPayload::write($lineItem, $settings, $value, $unitPrice / $settings->basePriceValue, $this->aliasKey($context));
    }

    /**
     * Einheitspreis inkl. Staffelpreisen – die Staffel richtet sich nach dem Wert (aufgerundet).
     */
    private function unitPrice(SalesChannelProductEntity $product, float $value, float $fallback): float
    {
        $prices = $product->getCalculatedPrices();
        if ($prices->count() === 0) {
            return $fallback;
        }

        $quantity = (int) ceil($value - 1e-9);
        $price = null;
        foreach ($prices as $candidate) {
            $price = $candidate;
            if ($quantity <= $candidate->getQuantity()) {
                break;
            }
        }

        return $price !== null ? $price->getUnitPrice() : $fallback;
    }

    private function format(float $value, DezimalSettings $settings): string
    {
        return number_format($value, max(0, $settings->decimals()), ',', '.');
    }
}
