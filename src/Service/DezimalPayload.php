<?php declare(strict_types=1);

namespace Nova\Dezimal\Service;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Payload einer Warenkorb-/Bestellposition.
 *
 * - `novaDezimal`: maschinenlesbar für ERP und Plugin (Wert, Einheit, Stück, Gesamtmenge, Preis je Einheit).
 * - optional zusätzlich unter einem frei wählbaren Schlüssel (Einstellung „Zusätzlicher Payload-Schlüssel“)
 *   als `dimensions`-Liste, damit vorhandene Mail-Vorlagen und Auswertungen unverändert weiter funktionieren.
 */
final class DezimalPayload
{
    public const KEY = 'novaDezimal';

    /**
     * Vom Kunden eingegebener Wert (Modus „menge“); null, wenn keiner vorhanden ist.
     */
    public static function value(LineItem $lineItem): ?float
    {
        $data = $lineItem->getPayloadValue(self::KEY);
        if (!\is_array($data)) {
            return null;
        }

        return DezimalSettingsReader::float($data['wert'] ?? null);
    }

    public static function has(LineItem $lineItem): bool
    {
        return \is_array($lineItem->getPayloadValue(self::KEY));
    }

    /**
     * Payload schreiben. $amount = Gesamtmenge in der Einheit (Wert bzw. Stück × Fläche).
     */
    public static function write(LineItem $lineItem, DezimalSettings $settings, float $value, float $unitPrice, string $aliasKey = '', ?float $positionPrice = null): void
    {
        $pieces = $settings->isPieceMode() ? $lineItem->getQuantity() : 1;
        $amount = $settings->isPieceMode() ? round($pieces * $settings->pieceArea, 4) : round($value, 4);

        $lineItem->setPayloadValue(self::KEY, [
            'modus' => $settings->mode,
            'wert' => $settings->isPieceMode() ? $settings->pieceArea : round($value, 4),
            'einheit' => $settings->unit,
            'bezeichnung' => $settings->label,
            'stueck' => $pieces,
            'stueckmass' => $settings->isPieceMode() ? $settings->pieceArea : null,
            'gesamtmenge' => $amount,
            'preisJeEinheit' => round($unitPrice, 4),
            'positionsPreis' => $positionPrice !== null ? round($positionPrice, 2) : null,
            'rollenbreite' => $settings->rollWidth > 0 ? $settings->rollWidth : null,
            'status' => 'berechnet',
            'laufmeter' => $settings->isAmountMode() && $settings->runningMetres($amount) > 0 ? $settings->runningMetres($amount) : null,
        ]);

        if ($aliasKey === '' || $aliasKey === self::KEY) {
            return;
        }

        $lineItem->setPayloadValue($aliasKey, [
            'dimensions' => [[
                'dimension' => 1,
                'unitvalue' => $amount,
                'description' => $settings->label !== '' ? $settings->label : $settings->unit,
                'unitlabel' => $settings->unit,
            ]],
            'isQuantityHidden' => $settings->isAmountMode(),
        ]);
    }
}
