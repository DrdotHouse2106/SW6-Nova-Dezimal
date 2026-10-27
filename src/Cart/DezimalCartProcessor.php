<?php declare(strict_types=1);

namespace Nova\Dezimal\Cart;

use Nova\Dezimal\Service\DezimalPayload;
use Nova\Dezimal\Service\DezimalSettings;
use Nova\Dezimal\Service\DezimalSettingsReader;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\ReferencePriceDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
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
class DezimalCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    private const DATA_KEY = 'nova-dezimal-product-';

    /** @var SalesChannelRepository<\Shopware\Core\Content\Product\ProductCollection>|null */
    private ?SalesChannelRepository $productRepository = null;

    public function __construct(
        private readonly QuantityPriceCalculator $calculator,
        private readonly DezimalSettingsReader $settingsReader,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * Setter statt Konstruktor-Argument: ein Update mit noch altem kompiliertem Container führt so nie zu einem Fehler 500.
     *
     * @param SalesChannelRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     */
    public function setProductRepository(SalesChannelRepository $productRepository): void
    {
        $this->productRepository = $productRepository;
    }

    /**
     * Shopware lädt Produktdaten nur für neue/geänderte Positionen. Beim erneuten Laden des Warenkorbs fehlen sie –
     * für Dezimal-Positionen werden sie hier nachgeladen, damit Preis und Prüfung immer laufen.
     */
    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        if ($this->productRepository === null || !$this->config->getBool('NovaDezimal.config.active', $context->getSalesChannelId())) {
            return;
        }

        $missing = [];
        foreach ($original->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $id = $lineItem->getReferencedId();
            if ($id === null || !DezimalPayload::has($lineItem)) {
                continue;
            }
            if (!$data->get('product-' . $id) instanceof SalesChannelProductEntity && !$data->has(self::DATA_KEY . $id)) {
                $missing[$id] = $id;
            }
        }

        if ($missing === []) {
            return;
        }

        $criteria = new Criteria(array_values($missing));
        $criteria->setTitle('nova-dezimal::cart-products');
        foreach ($this->productRepository->search($criteria, $context)->getEntities() as $product) {
            $data->set(self::DATA_KEY . $product->getId(), $product);
        }
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
                $product = $data->get(self::DATA_KEY . $lineItem->getReferencedId());
            }
            if (!$product instanceof SalesChannelProductEntity) {
                $this->note($lineItem, 'produktdaten-fehlen:' . get_debug_type($product));
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

    /**
     * Kurzer Status an der Position, falls sie nicht berechnet werden konnte (hilft bei der Fehlersuche).
     */
    private function note(LineItem $lineItem, string $status): void
    {
        $payload = $lineItem->getPayloadValue(DezimalPayload::KEY);
        $payload = \is_array($payload) ? $payload : [];
        $payload['status'] = $status;
        $lineItem->setPayloadValue(DezimalPayload::KEY, $payload);
    }

    private function aliasKey(SalesChannelContext $context): string
    {
        return trim($this->config->getString('NovaDezimal.config.payloadAlias', $context->getSalesChannelId()));
    }

    private function processPiece(LineItem $lineItem, DezimalSettings $settings, SalesChannelContext $context): void
    {
        $price = $lineItem->getPrice();
        $unitPrice = $price !== null && $settings->pieceArea > 0 ? $price->getUnitPrice() / $settings->pieceArea : 0.0;

        DezimalPayload::write($lineItem, $settings, $settings->pieceArea, $unitPrice, $this->aliasKey($context), $price?->getTotalPrice());
    }

    private function processAmount(LineItem $lineItem, DezimalSettings $settings, SalesChannelProductEntity $product, Cart $cart, SalesChannelContext $context): void
    {
        $definition = $lineItem->getPriceDefinition();
        if (!$definition instanceof QuantityPriceDefinition) {
            $this->note($lineItem, 'keine-preisdefinition:' . get_debug_type($definition));

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

        // Einheitspreis immer aus dem Produkt (nicht aus der Preisdefinition der Position), damit der Faktor
        // nie doppelt angewendet wird – auch wenn die Definition bei einer Neuberechnung erhalten bleibt.
        $unitPrice = $this->unitPrice($product, $value, $product->getCalculatedPrice()->getUnitPrice());
        $calculatedDefinition = new QuantityPriceDefinition(
            $unitPrice * $value / $settings->basePriceValue,
            $definition->getTaxRules(),
            1
        );
        $listPrice = $product->getCalculatedPrice()->getListPrice();
        if ($listPrice !== null) {
            $calculatedDefinition->setListPrice($listPrice->getPrice() * $value / $settings->basePriceValue);
        }
        // Grundpreis „je m²“ bleibt korrekt: Inhalt der Position = eingegebener Wert
        $calculatedDefinition->setReferencePriceDefinition(new ReferencePriceDefinition(
            $value,
            $settings->basePriceValue,
            $settings->unit
        ));

        // Definition UND Preis setzen: rechnet später irgendetwas die Position aus ihrer Definition neu,
        // bleibt der Dezimalpreis erhalten
        $lineItem->setPriceDefinition($calculatedDefinition);
        $lineItem->setPrice($this->calculator->calculate($calculatedDefinition, $context));

        $delivery = $lineItem->getDeliveryInformation();
        $weight = $product->getWeight();
        if ($delivery instanceof DeliveryInformation && $weight !== null && $weight > 0) {
            $delivery->setWeight(round($weight * $value / $settings->basePriceValue, 3));
        }

        DezimalPayload::write($lineItem, $settings, $value, $unitPrice / $settings->basePriceValue, $this->aliasKey($context), $lineItem->getPrice()?->getTotalPrice());
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
