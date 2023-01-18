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
                    if (
                        in_array('$ref', $value) &&
                        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $value['$ref']])
                    ) {
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
        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for actions</info>']) : '');

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
            $action->setListens(['opencatalogi.default.listens']);

            $this->entityManager->persist($action);

            (isset($this->io) ? $this->io->writeln(['Action created for ' . $handler]) : '');
        }
    }

    public function
    checkDataConsistency()
    {

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = [
            'https://opencatalogi.nl/component.schema.json',
            'https://opencatalogi.nl/application.schema.json',
            'https://opencatalogi.nl/catalogi.schema.json'
        ];

        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for cards</info>']) : '');

        foreach ($objectsThatShouldHaveCards as $object) {
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            if (
                $dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()])
            ) {
                $dashboardCard = new DashboardCard($object);
                $this->entityManager->persist($dashboardCard);

                (isset($this->io) ? $this->io->writeln('Dashboard card created: ' . $dashboardCard->getName()) : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Dashboard card found  for: ' . $object) : '');
        }
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


        // Let create some endpoints
        $objectsThatShouldHaveEndpoints = [
            'https://opencatalogi.nl/component.schema.json',
            'https://opencatalogi.nl/application.schema.json',
            'https://opencatalogi.nl/organisation.schema.json',
            'https://opencatalogi.nl/catalogi.schema.json'
        ];

        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for endpoints</info>']) : '');

        // foreach($objectsThatShouldHaveEndpoints as $object){
        //     $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);

        //     if(
        //         count($entity->getEndpoints()) == 0
        //     ){
        //         $endpoint = new Endpoint($entity);
        //         $this->entityManager->persist($endpoint);
        //         $entity->addEndpoint($searchEnpoint); // Also make the entity available trough the generic search endpoint
        //         (isset($this->io)?$this->io->writeln('Endpoint created for: ' . $object):'');
        //         continue;
        //     }
        //     (isset($this->io)?$this->io->writeln('Endpoint found for: '.$object):'');
        // }


        // aanmaken van Actions
        // $this->addActions();

        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for cronjobs</info>']) : '');
        // We only need 1 cronjob so lets set that
        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Open Catalogi'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Open Catalogi');
            $cronjob->setDescription("This cronjob fires all the open catalogi actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.default.listens']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for Open Catalogi']) : '');
        } else {

            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for Open Catalogi']) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Github scrapper'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Github scrapper');
            $cronjob->setDescription("This cronjob fires all the open catalogi github actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.github']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for Github']) : '');
        } else {

            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for Open Catalogi']) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Federation'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Federation');
            $cronjob->setDescription("This cronjob fires all the open catalogi federation actions ever 5 minutes");
            $cronjob->setThrows(['opencatalogi.federation']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for Federation
            ']) : '');
        } else {

            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for Federation']) : '');
        }

        // Lets grap the catalogi entity
        $catalogiEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/catalogi.schema.json']);

        $sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $actionRepository = $this->entityManager->getRepository('App:Action');
        $schemaRepository = $this->entityManager->getRepository('App:Entity');

        $applicationSchema = $schemaRepository->findOneBy(['name' => 'Application']);
        $applicationSchemaID = $applicationSchema ? $applicationSchema->getId()->toString() : '';

        $applicationSyncSchema = $schemaRepository->findOneBy(['name' => 'ApplicationSync']);
        $applicationSyncSchemaID = $applicationSyncSchema ? $applicationSyncSchema->getId()->toString() : '';

        $componentSchema = $schemaRepository->findOneBy(['name' => 'Component']);
        $componentSchemaID = $componentSchema ? $componentSchema->getId()->toString() : '';

        $repositorySchema = $schemaRepository->findOneBy(['name' => 'Repository']);
        $repositorySchemaID = $repositorySchema ? $repositorySchema->getId()->toString() : '';

        // Make ApplicationSync.components and owner a 
        foreach ($applicationSyncSchema->getAttributes() as $attr) {
            if ($attr->getName() == 'components' || $attr->getName() == 'owner') {
                $attr->setType('array');
                $attr->setMultiple(false);
                $this->entityManager->persist($attr);
            }
        }

        // componentencatalogus
        $componentenCatalogusSource = $sourceRepository->findOneBy(['name' => 'componentencatalogus']) ?? new Source();
        $componentenCatalogusSource->setName('componentencatalogus');
        $componentenCatalogusSource->setAuth('none');
        $componentenCatalogusSource->setLocation('https://componentencatalogus.commonground.nl/api');
        $componentenCatalogusSource->setIsEnabled(true);
        $this->entityManager->persist($componentenCatalogusSource);
        isset($this->io) && $this->io->writeln('Gateway: \'componentencatalogus\' created');

        // GitHub API
        $gitHubAPI = $sourceRepository->findOneBy(['name' => 'GitHub API']) ?? new Source();
        $gitHubAPI->setName('GitHub API');
        $gitHubAPI->setAuth('none');
        $gitHubAPI->setLocation('https://api.github.com');
        $gitHubAPI->setIsEnabled(true);
        $this->entityManager->persist($gitHubAPI);
        $dashboardCard = new DashboardCard($gitHubAPI);
        $this->entityManager->persist($dashboardCard);
        isset($this->io) && $this->io->writeln('Gateway: \'GitHub API\' created');

        // GitHub usercontent
        $gitHubUserContentSource = $sourceRepository->findOneBy(['name' => 'GitHub usercontent']) ?? new Source();
        $gitHubUserContentSource->setName('GitHub usercontent');
        $gitHubUserContentSource->setAuth('none');
        $gitHubUserContentSource->setLocation('https://raw.githubusercontent.com');
        $gitHubUserContentSource->setIsEnabled(true);
        $this->entityManager->persist($gitHubUserContentSource);
        isset($this->io) && $this->io->writeln('Gateway: \'GitHub usercontent\' created');

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
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'SyncZakenCollectionAction\' created');

        // SyncedApplicationToGatewayAction
        $action = $actionRepository->findOneBy(['name' => 'SyncedApplicationToGatewayAction']) ?? new Action();
        $action->setName('SyncedApplicationToGatewayAction');
        $action->setDescription('This is a action to create objects from the fetched application.');
        $action->setListens(['commongateway.object.create', 'commongateway.object.update']);
        $action->setConditions(['==' => [
            ['var' => 'entity'],
            $applicationSyncSchemaID,
        ]]);
        $action->setConfiguration([
            'source'    => $componentenCatalogusSource->getId()->toString(),
            'entities'  => [
                'Application' => $applicationSchemaID
            ]
        ]);
        $action->setAsync(false);
        $action->setClass('OpenCatalogi\OpenCatalogiBundle\ActionHandler\SyncedApplicationToGatewayHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'MapZaakAction\' created');

        // CreateUpdateRepositoryAction
        $action = $actionRepository->findOneBy(['name' => 'CreateUpdateRepositoryAction']) ?? new Action();
        $action->setName('CreateUpdateRepositoryAction');
        $action->setDescription('This is a action to create or update a component.');
        $action->setListens(['opencatalogi.repository.check']);
        $action->setConditions([[1 => 1]]);
        $action->setConfiguration([
            'source'    => $gitHubAPI->getId()->toString(),
            'entities'  => [
                'Component' => $componentSchemaID,
                'Repository' => $repositorySchemaID
            ]
        ]);
        $action->setAsync(false);
        $action->setClass('OpenCatalogi\OpenCatalogiBundle\ActionHandler\CreateUpdateRepositoryHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'CreateUpdateRepositoryAction\' created');

        // CreateUpdateComponentAction
        $action = $actionRepository->findOneBy(['name' => 'CreateUpdateComponentAction']) ?? new Action();
        $action->setName('CreateUpdateComponentAction');
        $action->setDescription('This is a action to create or update a component.');
        $action->setListens(['opencatalogi.component.check']);
        $action->setConditions([[1 => 1]]);
        $action->setConfiguration([
            'source'    => $gitHubUserContentSource->getId()->toString(),
            'entities'  => [
                'Component' => $componentSchemaID
            ]
        ]);
        $action->setAsync(false);
        $action->setClass('OpenCatalogi\OpenCatalogiBundle\ActionHandler\CreateUpdateComponentHandler');
        $action->setIsEnabled(true);
        $this->entityManager->persist($action);
        isset($this->io) && $this->io->writeln('Action: \'CreateUpdateComponentAction\' created');


        // Now we kan do a first federation
        $this->catalogiService->setStyle($this->io);
        //$this->catalogiService->readCatalogi($opencatalogi);

        /*@todo register this catalogi to the federation*/
        // This requers a post to a pre set webhook


        $this->entityManager->flush();
    }
}
