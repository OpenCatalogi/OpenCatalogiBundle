<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeFromGithubUrlService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the EnrichPubliccodeFromGithubUrlCommand.
 */
class EnrichPubliccodeFromGithubUrlCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:enrichPubliccodeFromGithubUrl:execute';

    /**
     * @var EnrichPubliccodeFromGithubUrlService
     */
    private EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService;

    /**
     * @param EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService
     */
    public function __construct(EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService)
    {
        $this->enrichPubliccodeFromGithubUrlService = $enrichPubliccodeFromGithubUrlService;
        parent::__construct();
    }//end __construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Find repositories containing publiccode')
            ->setHelp('This command finds repositories on github that contain an publiccode file')
            ->addOption('repositoryId', 'r', InputOption::VALUE_OPTIONAL, 'Find a organization for a specific repository by id');
    }//end configure()

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
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
    }//end execute()
}//end class
