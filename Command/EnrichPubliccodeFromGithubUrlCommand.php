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
    private EnrichPubliccodeFromGithubUrlService $enrichGithubService;

    /**
     * @param EnrichPubliccodeFromGithubUrlService $enrichGithubService enrich Publiccode From Github Url Service
     */
    public function __construct(EnrichPubliccodeFromGithubUrlService $enrichGithubService)
    {
        $this->enrichGithubService = $enrichGithubService;
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
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if ($repositoryId === null) {
            $this->enrichGithubService->enrichPubliccodeFromGithubUrlHandler();
        }
        
        if ($repositoryId !== null 
            && empty($this->enrichGithubService->enrichPubliccodeFromGithubUrlHandler([], [], $repositoryId)) === true
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
