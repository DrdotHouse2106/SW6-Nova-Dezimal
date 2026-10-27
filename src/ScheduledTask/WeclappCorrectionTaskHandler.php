<?php declare(strict_types=1);

namespace Nova\Dezimal\ScheduledTask;

use Nova\Dezimal\Weclapp\WeclappOrderCorrector;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: WeclappCorrectionTask::class)]
final class WeclappCorrectionTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly WeclappOrderCorrector $corrector,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        if ($this->corrector->isEnabled()) {
            $this->corrector->run(Context::createDefaultContext());
        }
    }
}
