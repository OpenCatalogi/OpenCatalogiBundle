<?php

// src/Service/LarpingService.php
namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\DashboardCard;
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

    public function checkDataConsistency(){

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = ['https://opencatalogi.nl/component.schema.json'];

        foreach($objectsThatShouldHaveCards as $object){
            (isset($this->io)?$this->io->writeln('Looking for a dashboard card for: '.$object):'');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);
            if(
               !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId'=>$entity->getId()])
            ){
                $dashboardCardArray = [
                    'name' => $entity->getName(),
                    'description' => $entity->getDescription(),
                    'type' => 'schema',
                    'entity' => 'App:Entity',
                    'object' => 'App:Entity',
                    'entityId' => $entity->getId(),
                    'ordering' => 1
                ];
                $dashboardCard = New DashboardCard($dashboardCardArray);
//                $dashboardCard->setType('schema');
//                $dashboardCard->setEntity('App:Entity');
//                $dashboardCard->setObject('App:Entity');
//                $dashboardCard->setName($entity->getName());
//                $dashboardCard->setDescription($entity->getDescription());
//                $dashboardCard->setEntityId($entity->getId());
//                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);

                var_dump($dashboardCard->getName());
                (isset($this->io) ?$this->io->writeln('Dashboard card created: ' . $dashboardCard->getName()):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Dashboard card found'):'');
        }

        // Let create some endpoints
        $objectsThatShouldHaveEndpoints = ['https://opencatalogi.nl/component.schema.json'];

        foreach($objectsThatShouldHaveEndpoints as $object){
            (isset($this->io)?$this->io->writeln('Looking for a endpoint for: '.$object):'');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);

            if(
                count($entity->getEndpoints()) == 0
            ){
                $endpoint = New Endpoint($entity);
                $this->entityManager->persist($endpoint);
                (isset($this->io)?$this->io->writeln('Endpoint created: ' . $endpoint->getName()):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Endpoint found'):'');
        }

        $this->entityManager->flush();

        // Lets see if there is a generic search endpoint

        $actionHandlers = [
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//CatalogiHandler',
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//EnrichPubliccodeHandler',
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//PubliccodeCheckRepositoriesForPubliccodeHandler',
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//PubliccodeFindGithubRepositoryThroughOrganizationHandler',
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//PubliccodeFindOrganizationThroughRepositoriesHandler',
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//PubliccodeFindRepositoriesThroughOrganizationHandler',
            'OpenCatalogi//OpenCatalogiBundle//ActionHandler//PubliccodeRatingHandler'
        ];

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($action = $this->entityManager->getRepository('App:Action')->findOneBy(['class'=> get_class($actionHandler)])) {
                var_dump($action->getName());
                continue;
            }

            var_dump($actionHandler->getConfig());

            $actionArray = [
                'name' => 'Test action' . $handler,
                'description' => 'The action for the actionHandler: '. $handler,
                'class' => $handler,
                'defaultConfiguration' => $actionHandler->getDefaultConfiguration(),
                'configuration' => $actionHandler->getConfig(),
            ];

            $action = new Action($actionArray);
//            $action->setName('Test action' . $handler);
//            $action->setClass($handler);
//            $action->setConfig($actionHandler->getConfig());
            $this->entityManager->persist($action);

            var_dump($action->getName());
        }

        /** Aanmaken actions
        1. array van action classes
         * 2. daar doorheen lopen per item kijke is er een action met die class, zo ja contie
         * 3 bij nee action aanmaken via actie($actionhandler)
         * 4. Daarvoor is loopje nodig op action handlers om default config aan te leveren
        *.
        // Aanmaken 1 cronjob (indien nodig)
         *
         **/


    }
}
