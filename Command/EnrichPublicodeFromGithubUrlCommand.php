<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeFromGithubUrlService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the EnrichPublicodeFromGithubUrlCommand.
 */
class EnrichPublicodeFromGithubUrlCommand extends Command
{
    protected static $defaultName = 'opencatalogi:enrichPublicodeFromGithubUrl:execute';
    private EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService;

    public function __construct(EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService)
    {
        $this->enrichPubliccodeFromGithubUrlService = $enrichPubliccodeFromGithubUrlService;
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
        $this->enrichPubliccodeFromGithubUrlService->setStyle($io);

        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if (!$repositoryId) {
            $this->enrichPubliccodeFromGithubUrlService->enrichPubliccodeFromGithubUrlHandler();
        } elseif (!$this->enrichPubliccodeFromGithubUrlService->enrichPubliccodeFromGithubUrlHandler([], [], $repositoryId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
