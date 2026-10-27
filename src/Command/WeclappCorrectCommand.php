<?php declare(strict_types=1);

namespace Nova\Dezimal\Command;

use Nova\Dezimal\Weclapp\WeclappOrderCorrector;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Korrektur der weclapp-Aufträge von Hand starten, z. B. zum Testen einer einzelnen Bestellung:
 * bin/console nova-dezimal:weclapp --order=11634 --test
 */
#[AsCommand(name: 'nova-dezimal:weclapp', description: 'Dezimal-Positionen in weclapp-Aufträgen korrigieren')]
class WeclappCorrectCommand extends Command
{
    public function __construct(private readonly WeclappOrderCorrector $corrector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'nur diese Bestellnummer (auch ältere oder bereits bearbeitete)')
            ->addOption('test', null, InputOption::VALUE_NONE, 'nur anzeigen, was geändert würde')
            ->addOption('write', null, InputOption::VALUE_NONE, 'wirklich schreiben, auch wenn in der Konfiguration der Testmodus an ist')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'höchstens so viele Bestellungen', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = null;
        if ($input->getOption('test')) {
            $dryRun = true;
        } elseif ($input->getOption('write')) {
            $dryRun = false;
        }

        $order = $input->getOption('order');
        $lines = $this->corrector->run(
            Context::createDefaultContext(),
            max(1, (int) $input->getOption('limit')),
            $dryRun,
            \is_string($order) ? $order : null
        );

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        return self::SUCCESS;
    }
}
