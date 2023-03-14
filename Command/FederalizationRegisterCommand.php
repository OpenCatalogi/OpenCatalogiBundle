<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FederalizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FederalizationRegisterCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:fedaralization:register';

    /**
     * @var FederalizationService
     */
    private FederalizationService  $federalizationiService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @param FederalizationService  $federalizationiService The federalization Service
     * @param EntityManagerInterface $entityManager          The entity Manager
     */
    public function __construct(FederalizationService $federalizationiService, EntityManagerInterface $entityManager)
    {
        $this->federalizationiService = $federalizationiService;
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
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$this->cacheService->setStyle(new SymfonyStyle($input, $output));
        $style = new SymfonyStyle($input, $output);
        $this->federalizationService->setStyle($style);

        // Handle the command optiosn
        $catalogusId = $input->getOption('catalogus', false);

        if ($catalogusId === false) {
            $this->federalizationService->catalogiHandler();
        } else {
            $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id'=>$catalogusId]);
            if (!$catalogusObject) {
                $style->debug('Could not find object entity by id, trying on name');
                $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['name'=>$catalogusId]);
            }
            if (!$catalogusObject) {
                $style->error('Could not find object entity by id or name '.$catalogusId);

                return Command::FAILURE;
            }
            $this->federalizationService->readCatalogus($catalogusObject);
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
