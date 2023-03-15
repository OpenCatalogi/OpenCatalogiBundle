<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the EnrichPubliccodeCommand.
 */
class EnrichPubliccodeCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:enrichPubliccode:execute';

    /**
     * @var EnrichPubliccodeService
     */
    private EnrichPubliccodeService $enrichService;

    /**
     * @param EnrichPubliccodeService $enrichService enrich Publiccode Service
     */
    public function __construct(EnrichPubliccodeService $enrichService)
    {
        $this->enrichService = $enrichService;
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
     *
     * @return int
     */
    protected function execute(InputInterface $input): int
    {
        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if ($repositoryId === null) {
            $this->enrichService->enrichPubliccodeHandler();
        } 
        
        if ($repositoryId !== null 
            && empty($this->enrichService->enrichPubliccodeHandler([], [], $repositoryId)) === true
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
