<?php declare(strict_types=1);

namespace Nova\Dezimal\Service;

/**
 * Einstellungen eines Dezimal-Artikels, gelesen aus den Produkt-Zusatzfeldern.
 *
 * Modus „menge“: Kunde gibt einen Wert ein (z. B. 2,35 m²). Shopware-Menge bleibt 1, Preis = Wert × Einheitspreis.
 * Modus „stueck“: Kunde wählt Stück (z. B. Platten). Der Artikelpreis gilt pro m², das Plugin rechnet ihn auf
 * den Stückpreis hoch (Preis × Fläche je Stück). Die Gesamtfläche steht im Payload.
 */
final class DezimalSettings
{
    public const MODE_AMOUNT = 'menge';
    public const MODE_PIECE = 'stueck';

    public function __construct(
        public readonly string $mode,
        public readonly string $unit,
        public readonly string $label,
        public readonly float $min,
        public readonly float $max,
        public readonly float $step,
        public readonly float $default,
        public readonly float $basePriceValue,
        public readonly float $pieceArea,
    ) {
    }

    public function isAmountMode(): bool
    {
        return $this->mode === self::MODE_AMOUNT;
    }

    public function isPieceMode(): bool
    {
        return $this->mode === self::MODE_PIECE;
    }

    /**
     * Wert auf Min/Max begrenzen und auf die Schrittweite (ab Mindestwert) runden.
     */
    public function normalize(float $value): float
    {
        if (!is_finite($value) || $value <= 0) {
            $value = $this->default;
        }

        $value = max($this->min, min($this->max, $value));

        if ($this->step > 0) {
            $steps = round(($value - $this->min) / $this->step, 6);
            $value = $this->min + ceil($steps - 1e-6) * $this->step;
            if ($value > $this->max + 1e-9) {
                $value = $this->min + floor(($this->max - $this->min) / $this->step + 1e-9) * $this->step;
            }
        }

        return round($value, 4);
    }

    public function decimals(): int
    {
        $step = rtrim(rtrim(number_format($this->step, 4, '.', ''), '0'), '.');
        $dot = strpos($step, '.');

        return $dot === false ? 0 : \strlen($step) - $dot - 1;
    }

    /**
     * Für Templates und den Storefront-Script.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'unit' => $this->unit,
            'label' => $this->label,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'default' => $this->default,
            'basePriceValue' => $this->basePriceValue,
            'pieceArea' => $this->pieceArea,
            'decimals' => $this->decimals(),
        ];
    }
}
