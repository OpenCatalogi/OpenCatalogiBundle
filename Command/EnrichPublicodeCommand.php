<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the EnrichPublicodeCommand.
 */
class EnrichPublicodeCommand extends Command
{
    protected static $defaultName = 'opencatalogi:enrichPublicode:execute';
    private EnrichPubliccodeService $enrichPubliccodeService;

    public function __construct(EnrichPubliccodeService $enrichPubliccodeService)
    {
        $this->enrichPubliccodeService = $enrichPubliccodeService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Find repositories containing publiccode')
            ->setHelp('This command finds repositories on github that contain an publiccode file')
            ->addOption('repositoryId', 'r', InputOption::VALUE_OPTIONAL, 'Find a organization for a specific repository by id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->enrichPubliccodeService->setStyle($io);

        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if (!$repositoryId) {
            $this->enrichPubliccodeService->enrichPubliccodeHandler();
        } elseif (!$this->enrichPubliccodeService->enrichPubliccodeHandler([], [], $repositoryId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
