<?php

// src/Service/LarpingService.php
namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Action;
use App\Entity\DashboardCard;
use App\Entity\Cronjob;
use App\Entity\Endpoint;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\CollectionEntity;
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

    public const OBJECTS_THAT_SHOULD_HAVE_CARDS = [
        'https://opencatalogi.nl/oc.component.schema.json',
        'https://opencatalogi.nl/oc.application.schema.json',
        'https://opencatalogi.nl/oc.catalogi.schema.json'
    ];

    public const SCHEMAS_THAT_SHOULD_HAVE_ENDPOINTS = [
        ['reference' => 'https://opencatalogi.nl/oc.component.schema.json',        'path' => '/components',        'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json',     'path' => '/organisations',     'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.application.schema.json',      'path' => '/applications',      'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.catalogi.schema.json',         'path' => '/catalogi',        'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.repository.schema.json',       'path' => '/repositories',        'methods' => []],
    ];

    public const ACTION_HANDLERS = [
        //            'OpenCatalogi\OpenCatalogiBundle\ActionHandler\CatalogiHandler',
//        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\EnrichPubliccodeHandler',
//        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeCheckRepositoriesForPubliccodeHandler',
//        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeFindGithubRepositoryThroughOrganizationHandler',
//        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeFindOrganizationThroughRepositoriesHandler',
//        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeFindRepositoriesThroughOrganizationHandler',
//        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\PubliccodeRatingHandler'
        "OpenCatalogi\OpenCatalogiBundle\ActionHandler\CreateUpdateComponentHandler",
        "OpenCatalogi\OpenCatalogiBundle\ActionHandler\SyncedApplicationToGatewayHandler"
    ];

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
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    public function install()
    {
        $this->checkDataConsistency();
    }

    public function update()
    {
        $this->checkDataConsistency();
    }

    public function uninstall()
    {
        // Do some cleanup
    }

    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];

        // What if there are no properties?
        if (!isset($actionHandler->getConfiguration()['properties'])) {
            return $defaultConfig;
        }

        foreach ($actionHandler->getConfiguration()['properties'] as $key => $value) {

            switch ($value['type']) {
                case 'string':
                case 'array':
                    $defaultConfig[$key] = $value['example'];
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (key_exists('$ref', $value)) {
                        if ($entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=> $value['$ref']])) {
                            $defaultConfig[$key] = $entity->getId()->toString();
                        }
                    }
                    break;
                default:
                    return $defaultConfig;
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
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');

        $actionHandlers = $this::ACTION_HANDLERS;
        (isset($this->io)?$this->io->writeln(['','<info>Looking for actions</info>']):'');

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($this->entityManager->getRepository('App:Action')->findOneBy(['class' => get_class($actionHandler)])) {
                (isset($this->io) ? $this->io->writeln(['Action found for ' . $handler]) : '');
                continue;
            }

            if (!$schema = $actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            $action = new Action($actionHandler);

            if($schema['$id'] == 'https://opencatalogi.nl/oc.component.schema.json') {
                $action->setName('CreateUpdateComponentAction');
                $action->setDescription('This is a action to create or update a component.');
                $action->setListens(['opencatalogi.component.check']);
                $action->setConditions(["==" => [1, 1]]);

                // set source to the defaultConfig array
                $gitHubAPI = $sourceRepository->findOneBy(['name' => 'GitHub API']);
                $defaultConfig['source'] = $gitHubAPI->getId()->toString();
            } elseif($schema['$id'] == 'https://opencatalogi.nl/oc.application.schema.json') {
                $action->setName('SyncedApplicationToGatewayAction');
                $action->setDescription('This is a action to create objects from the fetched application.');
                $action->setListens(['commongateway.object.create', 'commongateway.object.update']);

                $applicationSyncSchemaID = $this->setApplicationSchemaId();
                $action->setConditions(['==' => [
                    ['var' => 'entity'],
                    $applicationSyncSchemaID,
                ]]);


                // set source to the defaultConfig array
                $componentenCatalogusSource = $sourceRepository->findOneBy(['name' => 'componentencatalogus']);
                $defaultConfig['source'] = $componentenCatalogusSource->getId()->toString();
            } else {
                $action->setListens(['opencatalogi.default.listens']);
            }

            // set the configuration of the action
            $action->setConfiguration($defaultConfig);
            $action->setAsync(true);

            $this->entityManager->persist($action);

            (isset($this->io) ? $this->io->writeln(['Action created for ' . $handler]) : '');
        }
    }

    private function createEndpoints($objectsThatShouldHaveEndpoints): array
    {
        $endpointRepository = $this->entityManager->getRepository('App:Endpoint');
        $endpoints = [];
        foreach($objectsThatShouldHaveEndpoints as $objectThatShouldHaveEndpoint) {
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $objectThatShouldHaveEndpoint['reference']]);
            if (!$endpointRepository->findOneBy(['name' => $entity->getName()])) {
                $endpoint = new Endpoint($entity, $objectThatShouldHaveEndpoint['path'], $objectThatShouldHaveEndpoint['methods']);

                $this->entityManager->persist($endpoint);
                $this->entityManager->flush();
                $endpoints[] = $endpoint;
            }
        }
        (isset($this->io) ? $this->io->writeln(count($endpoints).' Endpoints Created'): '');

        return $endpoints;
    }

    private function addSchemasToCollection(CollectionEntity $collection, string $schemaPrefix): CollectionEntity
    {
        $entities = $this->entityManager->getRepository('App:Entity')->findByReferencePrefix($schemaPrefix);
        foreach($entities as $entity) {
            $entity->addCollection($collection);
        }
        return $collection;
    }

    private function createCollections(): array
    {
        $collectionConfigs = [
            ['name' => 'OpenCatalogi',  'prefix' => 'oc', 'schemaPrefix' => 'https://opencatalogi.nl'],
        ];
        $collections = [];
        foreach($collectionConfigs as $collectionConfig) {
            $collectionsFromEntityManager = $this->entityManager->getRepository('App:CollectionEntity')->findBy(['name' => $collectionConfig['name']]);
            if(count($collectionsFromEntityManager) == 0){
                $collection = new CollectionEntity($collectionConfig['name'], $collectionConfig['prefix'], 'OpenCatalogiBundle');
            } else {
                $collection = $collectionsFromEntityManager[0];
            }
            $collection = $this->addSchemasToCollection($collection, $collectionConfig['schemaPrefix']);
            $this->entityManager->persist($collection);
            $this->entityManager->flush();
            $collections[$collectionConfig['name']] = $collection;
        }
        (isset($this->io) ? $this->io->writeln(count($collections).' Collections Created'): '');
        return $collections;
    }

    public function createDashboardCards($objectsThatShouldHaveCards)
    {
        foreach ($objectsThatShouldHaveCards as $object) {
            (isset($this->io) ? $this->io->writeln('Looking for a dashboard card for: ' . $object) : '');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            if (
                !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()])
            ) {
                $dashboardCard = new DashboardCard();
                $dashboardCard->setType('schema');
                $dashboardCard->setEntity('App:Entity');
                $dashboardCard->setObject('App:Entity');
                $dashboardCard->setName($entity->getName());
                $dashboardCard->setDescription($entity->getDescription());
                $dashboardCard->setEntityId($entity->getId());
                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);
                (isset($this->io) ? $this->io->writeln('Dashboard card created') : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Dashboard card found') : '');
        }
    }

    public function createCronjobs()
    {
        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for cronjobs</info>']) : '');
        // We only need 1 cronjob so lets set that
        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Open Catalogi'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Open Catalogi');
            $cronjob->setDescription("This cronjob fires all the open catalogi actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.default.listens']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '. $cronjob->getName()]) : '');
        } else {

            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '. $cronjob->getName()]) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Github scrapper'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Github scrapper');
            $cronjob->setDescription("This cronjob fires all the open catalogi github actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.github']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '. $cronjob->getName()]) : '');
        } else {

            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '. $cronjob->getName()]) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Federation'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Federation');
            $cronjob->setDescription("This cronjob fires all the open catalogi federation actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.federation']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '. $cronjob->getName()]) : '');
        } else {

            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '. $cronjob->getName()]) : '');
        }
    }

    public function createSources()
    {
        // Setup Github and make a dashboard card
        if (!$github = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com'])) {
            (isset($this->io) ? $this->io->writeln(['Creating GitHub Source']) : '');
            $github = new Source();
            $github->setName('GitHub');
            $github->setDescription('A place where repositories of code live');
            $github->setLocation('https://api.github.com');
            $github->setAuth('none');
            $this->entityManager->persist($github);

            $dashboardCard = new DashboardCard($github);
            $this->entityManager->persist($dashboardCard);
        }

        $sourceRepository = $this->entityManager->getRepository('App:Gateway');

        // componentencatalogus
        $componentenCatalogusSource = $sourceRepository->findOneBy(['name' => 'componentencatalogus']) ?? new Source();
        $componentenCatalogusSource->setName('componentencatalogus');
        $componentenCatalogusSource->setAuth('none');
        $componentenCatalogusSource->setLocation('https://componentencatalogus.commonground.nl/api');
        $componentenCatalogusSource->setIsEnabled(true);
        $this->entityManager->persist($componentenCatalogusSource);
        isset($this->io) && $this->io->writeln('Gateway: '. $componentenCatalogusSource->getName().' created');

        // GitHub API
        $gitHubAPI = $sourceRepository->findOneBy(['name' => 'GitHub API']) ?? new Source();
        $gitHubAPI->setName('GitHub API');
        $gitHubAPI->setAuth('jwt');
        $gitHubAPI->setLocation('https://api.github.com');
        $gitHubAPI->setIsEnabled(true);
        $this->entityManager->persist($gitHubAPI);
        isset($this->io) && $this->io->writeln('Gateway: \'GitHub API\' created');

        // GitHub usercontent
        $gitHubUserContentSource = $sourceRepository->findOneBy(['name' => 'GitHub usercontent']) ?? new Source();
        $gitHubUserContentSource->setName('GitHub usercontent');
        $gitHubUserContentSource->setAuth('none');
        $gitHubUserContentSource->setLocation('https://raw.githubusercontent.com');
        $gitHubUserContentSource->setIsEnabled(true);
        $this->entityManager->persist($gitHubUserContentSource);
        isset($this->io) && $this->io->writeln('Gateway: '. $gitHubUserContentSource->getName().' created');

        // flush the sources before adding actions via the addActions function
        // we need the id of the sources
        $this->entityManager->flush();
    }

    public function createSyncCollectionAction()
    {
        $actionRepository = $this->entityManager->getRepository('App:Action');
        $applicationSyncSchemaID = $this->setApplicationSchemaId();
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $componentenCatalogusSource = $sourceRepository->findOneBy(['name' => 'componentencatalogus']);

        // SyncZakenCollectionAction
        $action = $actionRepository->findOneBy(['name' => 'SyncApplicationCollectionAction']) ?? new Action();
        $action->setName('SyncApplicationCollectionAction');
        $action->setDescription('This is a synchronization action from the componentencatalogus to the gateway.');
        // Cronjob runs every 5 min?
        $action->setListens(['opencatalogi.default.listens']);
        $action->setConditions(['==' => [1, 1]]);
        $action->setConfiguration([
            'entity'    => $applicationSyncSchemaID,
            'source'    => $componentenCatalogusSource->getId()->toString(),
            'location'  => '/products?limit=1',
            'apiSource' => [
                'location'        => [
                    'objects' => '',
                    'idField' => 'id',
                ],
                'queryMethod'           => 'page',
                'syncFromList'          => true,
                'sourceLeading'         => true,
                'useDataFromCollection' => false,
                'mappingIn'             => [],
                'mappingOut'            => [],
                'translationsIn'        => [],
                'translationsOut'       => [],
                'skeletonIn'            => [],
            ],
        ]);
        $action->setAsync(false);
        $action->setClass('App\ActionHandler\SynchronizationCollectionHandler');
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: '. $action->getName().' created');
    }

    public function
    setApplicationSchemaId()
    {
        $schemaRepository = $this->entityManager->getRepository('App:Entity');

        $applicationSyncSchema = $schemaRepository->findOneBy(['name' => 'ApplicationSync']);
        $applicationSyncSchemaID = $applicationSyncSchema ? $applicationSyncSchema->getId()->toString() : '';

        // Make ApplicationSync.components and owner a
        foreach ($applicationSyncSchema->getAttributes() as $attr) {
            if ($attr->getName() == 'components' || $attr->getName() == 'owner') {
                $attr->setType('array');
                $attr->setMultiple(false);
                $this->entityManager->persist($attr);
            }
        }

        return $applicationSyncSchemaID;
    }

    public function
    checkDataConsistency()
    {
        // Lets create some genneric dashboard cards
        $this->createDashboardCards($this::OBJECTS_THAT_SHOULD_HAVE_CARDS);

        // create collection prefix
        $this->createCollections();

        // cretae endpoints
        $this->createEndpoints($this::SCHEMAS_THAT_SHOULD_HAVE_ENDPOINTS);

        // Lets see if there is a generic search endpoint
        if (!$searchEnpoint = $this->entityManager->getRepository('App:Endpoint')->findOneBy(['pathRegex' => '^search'])) {
            // $searchEnpoint = new Endpoint();
            // $searchEnpoint->setName('Search');
            // $searchEnpoint->setDescription('Generic Search Endpoint');
            // $searchEnpoint->setPathRegex('^search');
            // $searchEnpoint->setMethod('GET');
            // $searchEnpoint->setMethods(['GET']);
            // $searchEnpoint->setOperationType('collection');
            // $this->entityManager->persist($searchEnpoint);
        }

        // create cronjobs
        $this->createCronjobs();

        // create sources
        $this->createSources();

        // create sync collection action
        $this->createSyncCollectionAction();
        // create actions from the given actionHandlers
        $this->addActions();

        // Now we kan do a first federation
        $this->catalogiService->setStyle($this->io);
        //$this->catalogiService->readCatalogi($opencatalogi);

        /*@todo register this catalogi to the federation*/
        // This requers a post to a pre set webhook

        $this->entityManager->flush();
    }
}
