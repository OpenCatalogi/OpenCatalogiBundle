<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use LarpingBase\LarpingBundle\Service\LarpingService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

class FindGithubRepositoryThroughOrganizationCommand extends Command
{
    protected static $defaultName = 'opencatalogi:findGithubRepositoryThroughOrganization:execute';
    private FindGithubRepositoryThroughOrganizationService  $findGithubRepositoryThroughOrganizationService;
    private EntityManagerInterface $entityManager;


    public function __construct(FindGithubRepositoryThroughOrganizationService $findGithubRepositoryThroughOrganizationService, EntityManagerInterface $entityManager)
    {
        $this->findGithubRepositoryThroughOrganizationService = $findGithubRepositoryThroughOrganizationService;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindGithubRepositoryThroughOrganizationService')
            ->setHelp('This command allows you to update a organizations with found opencatalogi.yml info');
        // ->addOption('catalogus', 'c', InputOption::VALUE_OPTIONAL, 'Get a singe catalogue by id or name');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$this->cacheService->setStyle(new SymfonyStyle($input, $output));
        $io = new SymfonyStyle($input, $output);
        $this->findGithubRepositoryThroughOrganizationService->setStyle($io);

        // Handle the command optiosn
        // $catalogusId = $input->getOption('catalogus', false);

        // if(!$catalogusId){
        //     $this->findGithubRepositoryThroughOrganizationService->catalogiHandler();
        // }
        // else{
        //     $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id'=>$catalogusId]);
        //     if(!$catalogusObject){
        //         $io->debug('Could not find object entity by id, trying on name');
        //         $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['name'=>$catalogusId]);
        //     }
        //     if(!$catalogusObject) {
        //         $io->error('Could not find object entity by id or name ' . $catalogusId);
        //         return 1;
        //     }
        //     $this->findGithubRepositoryThroughOrganizationService->readCatalogus($catalogusObject);
        // }


        return 0;
    }
}
