<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService.
 */
class FindGithubRepositoryThroughOrganizationCommand extends Command
{
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:github:discoverrepository';

    /**
     * @var FindGithubRepositoryThroughOrganizationService
     */
    private FindGithubRepositoryThroughOrganizationService $findGitService;


    /**
     * @param FindGithubRepositoryThroughOrganizationService $findGitService find Github Repository Through Organization Service
     */
    public function __construct(FindGithubRepositoryThroughOrganizationService $findGitService)
    {
        $this->findGitService = $findGitService;
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindGithubRepositoryThroughOrganizationService')
            ->setHelp('This command allows you to update create owned repositories from organisation')
            ->addOption('organisationId', 'o', InputOption::VALUE_OPTIONAL, 'Find owned repositories for a specific organisation by id');

    }//end configure()


    /**
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        // Handle the command options
        $organisationId = $input->getOption('organisationId', false);

        $style->info('Execute findGithubRepositoryThroughOrganizationHandler');

        if ($organisationId === null) {
            if (empty($this->findGitService->findGithubRepositoryThroughOrganizationHandler()) === true) {
                return Command::FAILURE;
            }
        }

        if ($organisationId !== null
            && empty($this->findGitService->findGithubRepositoryThroughOrganizationHandler([], [], $organisationId)) === true
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
