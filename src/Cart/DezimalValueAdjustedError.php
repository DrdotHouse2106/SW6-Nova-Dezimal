<?php declare(strict_types=1);

namespace Nova\Dezimal\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Hinweis im Warenkorb: der eingegebene Wert lag außerhalb von Min/Max oder passte nicht zur Schrittweite
 * und wurde angepasst. Text: Textbaustein „checkout.nova-dezimal-value-adjusted“.
 */
class DezimalValueAdjustedError extends Error
{
    public function __construct(
        private readonly string $productId,
        private readonly string $name,
        private readonly string $value,
        private readonly string $unit,
    ) {
        parent::__construct(\sprintf('The value of %s was changed to %s %s.', $name, $value, $unit));
    }

    public function getId(): string
    {
        return $this->getMessageKey() . $this->productId;
    }

    public function getMessageKey(): string
    {
        return 'nova-dezimal-value-adjusted';
    }

    public function getLevel(): int
    {
        return self::LEVEL_NOTICE;
    }

    public function blockOrder(): bool
    {
        return false;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParameters(): array
    {
        return ['name' => $this->name, 'value' => $this->value, 'unit' => $this->unit];
    }
}
