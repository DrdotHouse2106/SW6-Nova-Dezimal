<?php declare(strict_types=1);

namespace Nova\Dezimal;

use Nova\Dezimal\Service\DezimalSettingsReader as Fields;
use Nova\Dezimal\Weclapp\WeclappOrderCorrector;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Nova Dezimal – Verkauf nach Meter/Quadratmeter mit Dezimalwert und Platten mit festem Maß je Stück.
 */
class NovaDezimal extends Plugin
{
    /** Feste IDs, damit Installation und Update dieselben Zusatzfelder pflegen */
    private const PRODUCT_SET_ID = '0d3c1a5e9b7f4e21a6c8d4b2f0e1a901';
    private const PRODUCT_SET_RELATION_ID = '0d3c1a5e9b7f4e21a6c8d4b2f0e1a902';
    private const ORDER_SET_ID = '0d3c1a5e9b7f4e21a6c8d4b2f0e1a911';
    private const ORDER_SET_RELATION_ID = '0d3c1a5e9b7f4e21a6c8d4b2f0e1a912';

    private const PRODUCT_FIELDS = [
        Fields::FIELD_ACTIVE => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a921', 'type' => CustomFieldTypes::BOOL,
            'de' => 'Dezimal-Artikel', 'en' => 'Decimal product',
            'deHelp' => 'An: Verkauf mit Dezimalwert bzw. als Platte. Leer lassen = Wert aus der „Feldzuordnung“ (Konfiguration) gilt, falls eingerichtet.',
            'enHelp' => 'On: sold with a decimal value or as a board. Leave empty = value from the field mapping (configuration) applies, if set up.'],
        Fields::FIELD_MODE => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a922', 'type' => CustomFieldTypes::SELECT,
            'de' => 'Art', 'en' => 'Mode',
            'deHelp' => '„Freie Menge“: Kunde gibt den Wert ein (z. B. 2,35 m²), Preis = Wert × Einheitspreis. „Festes Maß je Stück“: Kunde wählt Stück, der Artikelpreis gilt pro m² und wird mit der Fläche je Stück multipliziert.',
            'enHelp' => '"Free amount": customer enters the value (e.g. 2.35 m²), price = value × unit price. "Fixed size per piece": customer chooses pieces, the product price is per m² and is multiplied by the area per piece.',
            'options' => [
                ['value' => 'menge', 'label' => ['de-DE' => 'Freie Menge (Meter-/Rollenware)', 'en-GB' => 'Free amount (rolls, by the metre)']],
                ['value' => 'stueck', 'label' => ['de-DE' => 'Festes Maß je Stück (Platten)', 'en-GB' => 'Fixed size per piece (boards)']],
            ]],
        Fields::FIELD_UNIT => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a923', 'type' => CustomFieldTypes::TEXT,
            'de' => 'Einheit', 'en' => 'Unit', 'deHelp' => 'z. B. m² oder m. Leer = Maßeinheit des Artikels.', 'enHelp' => 'e.g. m² or m. Empty = product unit.'],
        Fields::FIELD_LABEL => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a924', 'type' => CustomFieldTypes::TEXT,
            'de' => 'Bezeichnung', 'en' => 'Label', 'deHelp' => 'z. B. Quadratmeter oder Länge', 'enHelp' => 'e.g. square metres or length'],
        Fields::FIELD_MIN => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a925', 'type' => CustomFieldTypes::FLOAT,
            'de' => 'Mindestwert', 'en' => 'Minimum', 'deHelp' => 'Bei Rollen: Rollenbreite in m² (1 laufender Meter), mindestens 1', 'enHelp' => 'Rolls: roll width in m² (1 running metre), at least 1'],
        Fields::FIELD_MAX => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a926', 'type' => CustomFieldTypes::FLOAT,
            'de' => 'Höchstwert', 'en' => 'Maximum', 'deHelp' => 'Leer = 100', 'enHelp' => 'Empty = 100'],
        Fields::FIELD_STEP => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a927', 'type' => CustomFieldTypes::FLOAT,
            'de' => 'Schrittweite', 'en' => 'Step', 'deHelp' => 'Leer = 0,01', 'enHelp' => 'Empty = 0.01'],
        Fields::FIELD_DEFAULT => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a928', 'type' => CustomFieldTypes::FLOAT,
            'de' => 'Vorgabewert', 'en' => 'Default value', 'deHelp' => 'Leer = Mindestwert', 'enHelp' => 'Empty = minimum'],
        Fields::FIELD_PIECE_AREA => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a929', 'type' => CustomFieldTypes::FLOAT,
            'de' => 'Fläche je Stück (m²)', 'en' => 'Area per piece (m²)',
            'deHelp' => 'Nur bei „Festes Maß je Stück“, z. B. 1,5 für 1000 × 1500 mm. Leer = Verkaufseinheit des Artikels.',
            'enHelp' => 'Only for "fixed size per piece", e.g. 1.5 for 1000 × 1500 mm. Empty = product purchase unit.'],
    ];

    private const ORDER_FIELDS = [
        WeclappOrderCorrector::FIELD_STATUS => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a931', 'type' => CustomFieldTypes::TEXT,
            'de' => 'weclapp-Korrektur', 'en' => 'weclapp correction',
            'deHelp' => 'ok, wartet, test, uebersprungen oder fehler – wird von Nova Dezimal gesetzt',
            'enHelp' => 'ok, wartet (waiting), test, uebersprungen (skipped) or fehler (error) – set by Nova Decimal'],
        WeclappOrderCorrector::FIELD_INFO => ['id' => '0d3c1a5e9b7f4e21a6c8d4b2f0e1a932', 'type' => CustomFieldTypes::TEXT,
            'de' => 'weclapp-Korrektur Details', 'en' => 'weclapp correction details',
            'deHelp' => 'Was geändert wurde (Menge × Einzelpreis vorher → nachher)', 'enHelp' => 'What was changed (quantity × unit price before → after)'],
    ];

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->upsertCustomFields($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->upsertCustomFields($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        // Startdatum der weclapp-Korrektur: ältere (von Hand korrigierte) Aufträge werden nie angefasst
        $config = $this->container?->get(SystemConfigService::class);
        if ($config instanceof SystemConfigService && trim($config->getString('NovaDezimal.config.weclappSince')) === '') {
            $config->set('NovaDezimal.config.weclappSince', (new \DateTimeImmutable())->format('Y-m-d H:i'));
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Nur die eigenen Zusatzfeld-Sets entfernen, fremde Felder bleiben unberührt.
        $repository = $this->container?->get('custom_field_set.repository');
        if ($repository instanceof EntityRepository) {
            $repository->delete([['id' => self::PRODUCT_SET_ID], ['id' => self::ORDER_SET_ID]], $uninstallContext->getContext());
        }
    }

    private function upsertCustomFields(Context $context): void
    {
        $repository = $this->container?->get('custom_field_set.repository');
        if (!$repository instanceof EntityRepository) {
            return;
        }

        $repository->upsert([
            $this->buildSet(self::PRODUCT_SET_ID, self::PRODUCT_SET_RELATION_ID, 'nova_dezimal', 'product', 'Nova Dezimal', 'Nova Decimal', self::PRODUCT_FIELDS),
            $this->buildSet(self::ORDER_SET_ID, self::ORDER_SET_RELATION_ID, 'nova_dezimal_bestellung', 'order', 'Nova Dezimal (weclapp)', 'Nova Decimal (weclapp)', self::ORDER_FIELDS),
        ], $context);
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     *
     * @return array<string, mixed>
     */
    private function buildSet(string $id, string $relationId, string $name, string $entity, string $de, string $en, array $fields): array
    {
        $customFields = [];
        $position = 1;
        foreach ($fields as $fieldName => $field) {
            $config = [
                'label' => ['de-DE' => $field['de'], 'en-GB' => $field['en']],
                'helpText' => ['de-DE' => $field['deHelp'], 'en-GB' => $field['enHelp']],
                'customFieldPosition' => $position++,
            ];

            switch ($field['type']) {
                case CustomFieldTypes::BOOL:
                    $config += ['type' => 'checkbox', 'componentName' => 'sw-field', 'customFieldType' => 'checkbox'];
                    break;
                case CustomFieldTypes::FLOAT:
                    $config += ['type' => 'number', 'numberType' => 'float', 'componentName' => 'sw-field', 'customFieldType' => 'number'];
                    break;
                case CustomFieldTypes::SELECT:
                    $config += ['componentName' => 'sw-single-select', 'customFieldType' => 'select', 'options' => $field['options']];
                    break;
                default:
                    $config += ['type' => 'text', 'componentName' => 'sw-field', 'customFieldType' => 'text'];
            }

            $customFields[] = [
                'id' => $field['id'],
                'name' => $fieldName,
                'type' => $field['type'],
                'config' => $config,
            ];
        }

        return [
            'id' => $id,
            'name' => $name,
            'config' => ['label' => ['de-DE' => $de, 'en-GB' => $en]],
            'relations' => [['id' => $relationId, 'entityName' => $entity]],
            'customFields' => $customFields,
        ];
    }
}
