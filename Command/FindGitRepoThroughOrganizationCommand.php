<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use LarpingBase\LarpingBundle\Service\LarpingService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindGitRepoThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

class FindGitRepoThroughOrganizationCommand extends Command
{
    protected static $defaultName = 'opencatalogi:findgitrepothroughorganization:execute';
    private FindGitRepoThroughOrganizationService  $findGitRepoThroughOrganizationService;
    private EntityManagerInterface $entityManager;


    public function __construct(FindGitRepoThroughOrganizationService $findGitRepoThroughOrganizationService, EntityManagerInterface $entityManager)
    {
        $this->findGitRepoThroughOrganizationService = $findGitRepoThroughOrganizationService;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command gets al or a single catalogi from the federalized network')
            ->setHelp('This command allows you to run further installation an configuration actions afther installing a plugin');
            // ->addOption('catalogus', 'c', InputOption::VALUE_OPTIONAL, 'Get a singe catalogue by id or name');

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$this->cacheService->setStyle(new SymfonyStyle($input, $output));
        $io = new SymfonyStyle($input, $output);
        $this->findGitRepoThroughOrganizationService->setStyle($io);

        // Handle the command optiosn
        // $catalogusId = $input->getOption('catalogus', false);

        // if(!$catalogusId){
        //     $this->findGitRepoThroughOrganizationService->catalogiHandler();
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
        //     $this->findGitRepoThroughOrganizationService->readCatalogus($catalogusObject);
        // }


        return 0;
    }
}
