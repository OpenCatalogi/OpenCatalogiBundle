<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the DeveloperOverheidService
 */
class DeveloperOverheidGetRepositoriesCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'opencatalogi:developeroverheid:repositories';
    private DeveloperOverheidService  $developerOverheidService;


    public function __construct(DeveloperOverheidService $developerOverheidService)
    {
        $this->developerOverheidService = $developerOverheidService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi DeveloperOverheidService')
            ->setHelp('This command allows you to get all repositories or one repository from developer.overheid.nl/repositories')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Get a single repository by id or name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->developerOverheidService->setStyle($io);
    
        // Handle the command options
        $repositoryId = $input->getOption('repository', false);
    
        if(!$repositoryId){
            $this->developerOverheidService->getRepositories();
        } elseif (!$this->developerOverheidService->getRepository($repositoryId)) {
            return Command::FAILURE;
        }
        
        return Command::SUCCES;
    }
}
