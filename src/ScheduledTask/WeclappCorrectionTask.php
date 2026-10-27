<?php declare(strict_types=1);

namespace Nova\Dezimal\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Alle 10 Minuten: Dezimal-Positionen neuer Bestellungen im weclapp-Auftrag korrigieren (wenn eingeschaltet).
 */
class WeclappCorrectionTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nova_dezimal.weclapp_correction';
    }

    public static function getDefaultInterval(): int
    {
        return 600;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
