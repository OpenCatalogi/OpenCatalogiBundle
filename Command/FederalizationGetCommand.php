<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FederalizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FederalizationGetCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:fedaralization:get';

    /**
     * @var FederalizationService
     */
    private FederalizationService  $federalizationService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @param FederalizationService $federalizationService
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(FederalizationService $federalizationService, EntityManagerInterface $entityManager)
    {
        $this->federalizationService = $federalizationService;
        $this->entityManager = $entityManager;
        parent::__construct();
    }//end __construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command gets al or a single catalogi from the federalized network')
            ->setHelp('This command allows you to run further installation an configuration actions afther installing a plugin')
            ->addOption('catalogus', 'c', InputOption::VALUE_OPTIONAL, 'Get a single catalogue by id or name');
    }//end configure()

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$this->cacheService->setStyle(new SymfonyStyle($input, $output));
        $io = new SymfonyStyle($input, $output);
        $this->federalizationService->setStyle($io);

        // Handle the command optiosn
        $catalogusId = $input->getOption('catalogus', false);

        if (!$catalogusId) {
            $this->federalizationService->catalogiHandler();
        } else {
            $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id'=>$catalogusId]);
            if (!$catalogusObject) {
                $io->debug('Could not find object entity by id, trying on name');
                $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['name'=>$catalogusId]);
            }
            if (!$catalogusObject) {
                $io->error('Could not find object entity by id or name '.$catalogusId);

                return 1;
            }
            $this->federalizationService->readCatalogus($catalogusObject);
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
