<?php

// src/Service/LarpingService.php
namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Action;
use App\Entity\DashboardCard;
use App\Entity\Cronjob;
use App\Entity\Endpoint;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\CatalogiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;
    private CatalogiService $catalogiService;

    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container, CatalogiService $catalogiService)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
        $this->catalogiService = $catalogiService;
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

            $this->entityManager->persist($action);

            (isset($this->io)?$this->io->writeln(['Action created for '.$handler]):'');
        }
    }

    public function checkDataConsistency(){

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = [
            'https://opencatalogi.nl/component.schema.json',
            'https://opencatalogi.nl/application.schema.json',
            'https://opencatalogi.nl/catalogi.schema.json'
        ];

        (isset($this->io)?$this->io->writeln(['','<info>Looking for cards</info>']):'');

        foreach($objectsThatShouldHaveCards as $object){
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);
            if(
                $dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId'=>$entity->getId()])
            ){
                $dashboardCard = new DashboardCard($object);
                $this->entityManager->persist($dashboardCard);

                (isset($this->io) ?$this->io->writeln('Dashboard card created: ' . $dashboardCard->getName()):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Dashboard card found  for: '.$object):'');
        }
        // Lets see if there is a generic search endpoint
        if(!$searchEnpoint = $this->entityManager->getRepository('App:Endpoint')->findOneBy(['pathRegex'=>'^search'])){
            $searchEnpoint = new Endpoint();
            $searchEnpoint->setName('Search');
            $searchEnpoint->setDescription('Generic Search Endpoint');
            $searchEnpoint->setPathRegex('^search');
            $searchEnpoint->setMethod('GET');
            $searchEnpoint->setMethods(['GET']);
            $searchEnpoint->setOperationType('collection');
            $this->entityManager->persist($searchEnpoint);
        }


        // Let create some endpoints
        $objectsThatShouldHaveEndpoints = [
            'https://opencatalogi.nl/component.schema.json',
            'https://opencatalogi.nl/application.schema.json',
            'https://opencatalogi.nl/organisation.schema.json',
            'https://opencatalogi.nl/catalogi.schema.json'
        ];

        (isset($this->io)?$this->io->writeln(['','<info>Looking for endpoints</info>']):'');

        foreach($objectsThatShouldHaveEndpoints as $object){
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);

            if(
                count($entity->getEndpoints()) == 0
            ){
                $endpoint = new Endpoint($entity);
                $this->entityManager->persist($endpoint);
                $entity->addEndpoint($searchEnpoint); // Also make the entity available trough the generic search endpoint
                (isset($this->io)?$this->io->writeln('Endpoint created for: ' . $object):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Endpoint found for: '.$object):'');
        }


        // aanmaken van Actions
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


        // Lets grap the catalogi entity
        $catalogiEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/catalogi.schema.json']);

        // Setup Github and make a dashboard card
        if(!$github = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://api.github.com'])){
            (isset($this->io)?$this->io->writeln(['Creating GithUB Source']):'');
            $github = new Source();
            $github->setName('GitHub');
            $github->setDescription('A place where repositories of code live');
            $github->setLocation('https://api.github.com');
            $github->setAuth('none');
            $this->entityManager->persist($github);

            $dashboardCard = new DashboardCard($github);
            $this->entityManager->persist($dashboardCard);
        }

        // Lets find the federation  and make a dashboard card
        if(!$opencatalogi = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://opencatalogi.nl/api'])){
            (isset($this->io)?$this->io->writeln(['Creating Opencatalogi Source']):'');
            $opencatalogi = new Source();
            $opencatalogi->setName('OpenCatalogi.nl');
            $opencatalogi->setDescription('The open catalogi federated netwerk');
            $opencatalogi->setLocation('https://opencatalogi.nl/api');
            $opencatalogi->setAuth('none');
            $this->entityManager->persist($opencatalogi);

            $dashboardCard = new DashboardCard($opencatalogi);
            $this->entityManager->persist($dashboardCard);

            $this->entityManager->flush();

            /*
            $opencatalogiCatalog = new ObjectEntity($catalogiEntity);
            $opencatalogiCatalog->setValue('source', (string) $opencatalogi->getId());
            $opencatalogiCatalog->setValue('name', $opencatalogi->getName());
            $opencatalogiCatalog->setValue('description', $opencatalogi->getDescription());
            $opencatalogiCatalog->setValue('location', $opencatalogi->getLocation());
            $this->entityManager->persist($opencatalogiCatalog);
            */
        }
        else {

        }

        // Now we kan do a first federation
        $this->catalogiService->setStyle($this->io);
        $this->catalogiService->readCatalogi($opencatalogi);

        /*@todo register this catalogi to the federation*/
        // This requers a post to a pre set webhook


        $this->entityManager->flush();
    }
}
