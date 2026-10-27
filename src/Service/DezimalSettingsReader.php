<?php declare(strict_types=1);

namespace Nova\Dezimal\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Liest die Dezimal-Einstellungen eines Produkts.
 *
 * Eigene Zusatzfelder (nova_dezimal_*) haben Vorrang. Optional können vorhandene Zusatzfelder als zweite Quelle
 * dienen (Einstellung „Feldzuordnung“), z. B. wenn ein ERP die Werte bereits in eigene Felder schreibt.
 */
class DezimalSettingsReader implements ResetInterface
{
    public const FIELD_ACTIVE = 'nova_dezimal_aktiv';
    public const FIELD_MODE = 'nova_dezimal_modus';
    public const FIELD_UNIT = 'nova_dezimal_einheit';
    public const FIELD_LABEL = 'nova_dezimal_bezeichnung';
    public const FIELD_MIN = 'nova_dezimal_min';
    public const FIELD_MAX = 'nova_dezimal_max';
    public const FIELD_STEP = 'nova_dezimal_schritt';
    public const FIELD_DEFAULT = 'nova_dezimal_vorgabe';
    public const FIELD_PIECE_AREA = 'nova_dezimal_stueckmass';
    public const FIELD_ROLL_WIDTH = 'nova_dezimal_rollenbreite';

    /** Schlüssel der Einstellung „Feldzuordnung“ → eigenes Feld */
    private const MAP_KEYS = [
        'einheit' => self::FIELD_UNIT,
        'bezeichnung' => self::FIELD_LABEL,
        'min' => self::FIELD_MIN,
        'max' => self::FIELD_MAX,
        'schritt' => self::FIELD_STEP,
        'vorgabe' => self::FIELD_DEFAULT,
        'rollenbreite' => self::FIELD_ROLL_WIDTH,
    ];

    private ?bool $blocked = null;

    /** @var array<string, string>|null */
    private ?array $fieldMap = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
    }

    public function reset(): void
    {
        $this->blocked = null;
        $this->fieldMap = null;
    }

    /**
     * Ist ein Plugin aktiv, das in „Nicht gleichzeitig mit“ steht, hält sich Nova Dezimal komplett heraus.
     */
    public function isBlockedByOtherPlugin(): bool
    {
        if ($this->blocked === null) {
            $names = array_values(array_filter(array_map('trim', explode(',', $this->config->getString('NovaDezimal.config.exclusiveWith')))));
            try {
                $this->blocked = $names !== [] && (bool) $this->connection->fetchOne(
                    'SELECT 1 FROM plugin WHERE active = 1 AND name IN (:names)',
                    ['names' => $names],
                    ['names' => \Doctrine\DBAL\ArrayParameterType::STRING]
                );
            } catch (\Throwable) {
                $this->blocked = false;
            }
        }

        return $this->blocked;
    }

    /**
     * Einstellung „Feldzuordnung“: je Zeile „schlüssel=zusatzfeld“, z. B. „min=erp_mindestmenge“.
     * Schlüssel: aktiv, einheit, bezeichnung, min, max, schritt, vorgabe, rollenbreite, preisJe
     *
     * @return array<string, string>
     */
    public function fieldMap(): array
    {
        if ($this->fieldMap === null) {
            $this->fieldMap = [];
            foreach (preg_split('/[\r\n,;]+/', $this->config->getString('NovaDezimal.config.fieldMap')) ?: [] as $line) {
                $parts = array_map('trim', explode('=', $line, 2));
                if (\count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $this->fieldMap[$parts[0]] = $parts[1];
                }
            }
        }

        return $this->fieldMap;
    }

    public function forProduct(Entity $product): ?DezimalSettings
    {
        if ($this->isBlockedByOtherPlugin()) {
            return null;
        }

        $fields = $product->get('translated')['customFields'] ?? null;
        if (!\is_array($fields) || $fields === []) {
            $fields = $product->get('customFields');
        }
        if (!\is_array($fields)) {
            return null;
        }

        return $this->fromCustomFields($fields, $this->purchaseUnit($product), $this->unitName($product), $this->productName($product));
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function fromCustomFields(array $fields, ?float $purchaseUnit = null, ?string $productUnit = null, string $productName = ''): ?DezimalSettings
    {
        $map = $this->fieldMap();
        $mapped = [];
        foreach (self::MAP_KEYS as $mapKey => $own) {
            if (isset($map[$mapKey])) {
                $mapped[$own] = $map[$mapKey];
            }
        }

        $own = $fields[self::FIELD_ACTIVE] ?? null;
        if ($own !== null) {
            if (!self::bool($own)) {
                return null;
            }
        } elseif (!isset($map['aktiv']) || !self::bool($fields[$map['aktiv']] ?? false)) {
            return null;
        }

        $value = static function (string $key) use ($fields, $mapped): mixed {
            $v = $fields[$key] ?? null;
            if (($v === null || $v === '') && isset($mapped[$key])) {
                $v = $fields[$mapped[$key]] ?? null;
            }

            return $v === '' ? null : $v;
        };

        $mode = (string) ($fields[self::FIELD_MODE] ?? '');
        $mode = $mode === DezimalSettings::MODE_PIECE ? DezimalSettings::MODE_PIECE : DezimalSettings::MODE_AMOUNT;

        $unit = trim((string) ($value(self::FIELD_UNIT) ?? ''));
        if ($unit === '') {
            $unit = $productUnit !== null && $productUnit !== '' ? $productUnit : ($mode === DezimalSettings::MODE_PIECE ? 'm²' : 'm');
        }
        $label = trim((string) ($value(self::FIELD_LABEL) ?? ''));

        if ($mode === DezimalSettings::MODE_PIECE) {
            $area = self::float($fields[self::FIELD_PIECE_AREA] ?? null) ?? $purchaseUnit;
            if ($area === null || $area <= 0) {
                // Ohne Stückmaß kann nicht gerechnet werden – Artikel verhält sich wie ein normaler Artikel
                return null;
            }

            return new DezimalSettings($mode, $unit, $label, 1, 1, 1, 1, 1, round($area, 4));
        }

        $min = self::float($value(self::FIELD_MIN)) ?? 1.0;
        $min = $min > 0 ? $min : 0.01;
        $max = self::float($value(self::FIELD_MAX)) ?? 100.0;
        $max = max($max, $min);
        $step = self::float($value(self::FIELD_STEP)) ?? 0.01;
        $step = $step > 0 ? $step : 0.01;
        $default = self::float($value(self::FIELD_DEFAULT)) ?? $min;
        $default = max($min, min($max, $default));
        $base = isset($map['preisJe']) ? (self::float($fields[$map['preisJe']] ?? null) ?? 1.0) : 1.0;
        $base = $base > 0 ? $base : 1.0;

        // Rollenbreite in mm: Feld bzw. Feldzuordnung, sonst aus dem Artikelnamen („Rollenbreite 1500mm“, „RB 1500mm“)
        $rollWidth = self::float($value(self::FIELD_ROLL_WIDTH)) ?? 0.0;
        if ($rollWidth <= 0 && preg_match('/(?:Rollenbreite|\bRB|Breite)\s*:?\s*(?:ca\.\s*)?(\d{3,4})(?:\s*-\s*\d{3,4})?\s*mm/iu', $productName, $m) === 1) {
            $rollWidth = (float) $m[1];
        }

        return new DezimalSettings($mode, $unit, $label, $min, $max, $step, $default, $base, 0, $rollWidth);
    }

    public static function float(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (\is_string($value)) {
            $value = trim(str_replace(',', '.', $value));
            if ($value !== '' && is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private static function bool(mixed $value): bool
    {
        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['1', 'true', 'ja', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private function productName(Entity $product): string
    {
        $translated = $product->get('translated');
        $name = \is_array($translated) ? ($translated['name'] ?? null) : null;
        if (!\is_string($name) || $name === '') {
            $name = $product->get('name');
        }

        return \is_string($name) ? $name : '';
    }

    private function purchaseUnit(Entity $product): ?float
    {
        $purchaseUnit = $product->get('purchaseUnit');

        return \is_int($purchaseUnit) || \is_float($purchaseUnit) ? (float) $purchaseUnit : null;
    }

    private function unitName(Entity $product): ?string
    {
        $unit = $product->has('unit') ? $product->get('unit') : null;
        if ($unit instanceof Entity) {
            $translated = $unit->get('translated');
            $short = \is_array($translated) ? ($translated['shortCode'] ?? null) : null;

            return \is_string($short) && $short !== '' ? $short : null;
        }

        return null;
    }
}
