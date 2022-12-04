<?php

// src/Service/LarpingService.php
namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Action;
use App\Entity\DashboardCard;
use App\Entity\Cronjob;
use App\Entity\Endpoint;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;

    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io):self
    {
        $this->io = $io;

        return $this;
    }

    public function install(){
        $this->checkDataConsistency();
    }

    public function update(){
        $this->checkDataConsistency();
    }

    public function uninstall(){
        // Do some cleanup
    }

    /**
     * The actionHandlers in OpenCatalogi
     *
     * @return array
     */
    public function actionHandlers(): array
    {
        return [
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\CatalogiHandler',
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\EnrichPubliccodeHandler',
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeCheckRepositoriesForPubliccodeHandler',
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeFindGithubRepositoryThroughOrganizationHandler',
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeFindOrganizationThroughRepositoriesHandler',
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeFindRepositoriesThroughOrganizationHandler',
            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeRatingHandler'
        ];
    }

    /**
     * This function creates default configuration for the action
     *
     * @param $actionHandler The actionHandler for witch the default configuration is set
     * @return array
     */
    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];
        foreach ($actionHandler->getConfiguration()['properties'] as $key => $value) {

            switch ($value['type']) {
                case 'string':
                case 'array':
                    $defaultConfig[$key] = $value['example'];
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (in_array('$ref', $value) &&
                        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$value['$ref']])) {
                        $defaultConfig[$key] = $entity->getId()->toString();
                    }
                    break;
                default:
                    // throw error
            }
        }
        return $defaultConfig;
    }

    /**
     * This function creates a cronjob for all an action
     *
     * @param Action $action The action for witch the cronjob is set
     * @return void
     */
    public function addCronjobForAction(Action $action): void
    {
        if (!$this->entityManager->getRepository('App:Cronjob')->findOneBy(['throws'=>$action->getListens()])) {
            $cronjob = new Cronjob();
            $cronjob->setName($action->getName());
            $cronjob->setDescription($action->getDescription() ?? null);
            $cronjob->setCrontab('*/1 * * * *');
            $cronjob->setThrows($action->getListens());
            $cronjob->setData([]);
            $this->entityManager->persist($cronjob);
            var_dump('cronojb: ' . $cronjob->getName());
        }
    }

    /**
     * This function creates actions for all the actionHandlers in OpenCatalogi
     *
     * @return void
     */
    public function addActions(): void
    {
        $actionHandlers = $this->actionHandlers();
        (isset($this->io)?$this->io->writeln(['','<info>Looking for actions</info>']):'');

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($this->entityManager->getRepository('App:Action')->findOneBy(['class'=> get_class($actionHandler)])) {

                (isset($this->io)?$this->io->writeln(['Action found for '.$handler]):'');
                continue;
            }

            if (!$schema = $actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);

            $action = new Action($actionHandler);
            $action->setListens(['opencatalogi.default.listens']);
            $action->setConfiguration($defaultConfig);

            $this->entityManager->persist($action);

            (isset($this->io)?$this->io->writeln(['Action created for '.$handler]):'');
        }
    }

    public function checkDataConsistency(){

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = ['https://opencatalogi.nl/component.schema.json'];
        (isset($this->io)?$this->io->writeln(['','<info>Looking for cards</info>']):'');

        foreach($objectsThatShouldHaveCards as $object){
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);
            if(
               $dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId'=>$entity->getId()])
            ){
                $dashboardCard = new DashboardCard(
                    $entity->getName(),
                    $entity->getDescription(),
                    'schema',
                    'App:Entity',
                    'App:Entity',
                    $entity->getId(),
                    1
                );
                $this->entityManager->persist($dashboardCard);

                (isset($this->io) ?$this->io->writeln('Dashboard card created: ' . $dashboardCard->getName()):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Dashboard card found  for: '.$object):'');
        }

        // Let create some endpoints
        (isset($this->io)?$this->io->writeln(''):'');
        $objectsThatShouldHaveEndpoints = ['https://opencatalogi.nl/component.schema.json'];
        (isset($this->io)?$this->io->writeln(['','<info>Looking for endpoints</info>']):'');

        foreach($objectsThatShouldHaveEndpoints as $object){
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);

            if(
                count($entity->getEndpoints()) == 0
            ){
                $endpoint = New Endpoint($entity);
                $this->entityManager->persist($endpoint);
                (isset($this->io)?$this->io->writeln('Endpoint created for: ' . $object):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Endpoint found for: '.$object):'');
        }

        // Lets see if there is a generic search endpoint

        // aanmaken van actions met een cronjob
        $this->addActions();

        (isset($this->io)?$this->io->writeln(['','<info>Looking for cronjobs</info>']):'');
        // We only need 1 cronjob so lets set that
        if(!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name'=>'Open Catalogi']))
        {
            $cronjob = new Cronjob();
            $cronjob->setName('Open Catalogi');
            $cronjob->setDescription("This cronjob fires all the open catalogi actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.default.listens']);

            $this->entityManager->persist($cronjob);

            (isset($this->io)?$this->io->writeln(['','Created a cronjob for Open Catalogi']):'');
        }
        else{

            (isset($this->io)?$this->io->writeln(['','There is alreade a cronjob for Open Catalogi']):'');
        }


        $this->entityManager->flush();
    }
}
