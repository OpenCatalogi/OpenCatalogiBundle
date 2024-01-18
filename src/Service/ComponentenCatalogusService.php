<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class ComponentenCatalogusService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var GatewayResourceService $resourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CallService $callService
     */
    private CallService $callService;

    /**
     * @var MappingService $mappingService
     */
    private MappingService $mappingService;

    /**
     * @var LoggerInterface $pluginLogger
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var SynchronizationService $syncService
     */
    private SynchronizationService $syncService;

    /**
     * @var GithubApiService $githubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GitlabApiService $gitlabApiService
     */
    private GitlabApiService $gitlabApiService;

    /**
     * @var array $data
     */
    private array $data;

    /**
     * @var array $configuration
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     * @param CallService            $callService      The call Service.
     * @param MappingService         $mappingService   The mapping service.
     * @param LoggerInterface        $pluginLogger     The Plugin logger.
     * @param SynchronizationService $syncService      The Synchronization Service.
     * @param GithubApiService       $githubApiService The Github API Service.
     * @param GitlabApiService       $gitlabApiService The Gitlab API Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GatewayResourceService $resourceService,
        CallService $callService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        SynchronizationService $syncService,
        GithubApiService $githubApiService,
        GitlabApiService $gitlabApiService
    ) {
        $this->entityManager    = $entityManager;
        $this->pluginLogger     = $pluginLogger;
        $this->callService      = $callService;
        $this->mappingService   = $mappingService;
        $this->resourceService  = $resourceService;
        $this->syncService      = $syncService;
        $this->githubApiService = $githubApiService;
        $this->gitlabApiService = $gitlabApiService;
        $this->data             = [];
        $this->configuration    = [];

    }//end __construct()


    /**
     * Get all applications or one application through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $applicationId The given application id
     *
     * @throws \Exception
     *
     * @return array|null The application as array.
     */
    public function getApplications(?array $data=[], ?array $configuration=[], ?string $applicationId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($source instanceof Source === false && $endpoint === null) {
            return $this->data;
        }

        if ($applicationId === null) {
            return $this->handleApplications($source, $endpoint, $this->configuration);
        }

        return $this->handleApplication($source, $endpoint, $applicationId, $this->configuration);

    }//end getApplications()


    /**
     * Get all applications of the given source.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param array  $configuration The configuration array
     *
     * @return array|null The applications as array.
     * @throws \Exception
     */
    public function handleApplications(Source $source, string $endpoint, array $configuration): ?array
    {
        $applications = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($applications).' applications from '.$source->getName());

        $result = [];
        foreach ($applications as $application) {
            $result[] = $this->importApplication($application, $configuration);
        }

        $this->entityManager->flush();

        return $result;

    }//end handleApplications()


    /**
     * Get an applications of the given source with the given id.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param string $applicationId The given application id
     * @param array  $configuration The configuration array
     *
     * @return array|null The application as array.
     * @throws \Exception
     */
    public function handleApplication(Source $source, string $endpoint, string $applicationId, array $configuration): ?array
    {
        $response    = $this->callService->call($source, $endpoint.'/'.$applicationId);
        $application = json_decode($response->getBody()->getContents(), true);

        if ($application === null) {
            $this->pluginLogger->error('Could not find an application with id: '.$applicationId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $application = $this->importApplication($application, $configuration);
        if ($application === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found application with id: '.$applicationId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $application->toArray();

    }//end handleApplication()


    /**
     * Import the application into the data layer.
     *
     * @param array $application   The application to import.
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null The imported application object.
     * @throws \Exception
     */
    public function importApplication(array $application, array $configuration): ?ObjectEntity
    {
        // Get the source, entity and mapping
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        $githubSource       = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $source             = $this->resourceService->getSource($configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $schema             = $this->resourceService->getSchema($configuration['applicationSchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping            = $this->resourceService->getMapping($configuration['applicationMapping'], 'open-catalogi/open-catalogi-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Entity === false
            || $mapping instanceof Mapping === false
            || $organizationSchema instanceof Entity === false
            || $githubSource instanceof Source === false
        ) {
            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $application['id']);

        $this->pluginLogger->debug('Mapping object '.$application['name'].' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

        // Unset the owner of the application so we don't make duplicates.
        $owner = $application['owner'];
        unset($application['owner']);

        $synchronization->setMapping($mapping);
        $synchronization   = $this->syncService->synchronize($synchronization, $application);
        $applicationObject = $synchronization->getObject();

        // Sync the owner and add it to the application.
        if ($owner !== null) {
            $ownerSync = $this->syncService->findSyncBySource($githubSource, $organizationSchema, $owner['fullName']);
            $ownerSync = $this->syncService->synchronize($ownerSync, ['name' => $owner['fullName'], 'email' => $owner['email'], 'logo' => $owner['pictureUrl'], 'type' => 'Owner']);

            $applicationObject->setValue('owner', $ownerSync->getObject());
            $this->entityManager->persist($applicationObject);
        }

        $this->pluginLogger->debug('Synced application: '.$applicationObject->getValue('name'), ['package' => 'open-catalogi/open-catalogi-bundle']);

        if ($application['components'] !== null) {
            $components = [];
            foreach ($application['components'] as $componentArray) {
                $repositoryObject = $this->handleComponent($source, $componentArray);

                // If there is no component continue.
                if ($repositoryObject === null) {
                    continue;
                }

                // Set the components of the repository to the components array.
                foreach ($repositoryObject->getValue('components') as $component) {
                    $components[] = $component;
                }
            }//end foreach

            $applicationObject->setValue('components', $components);
        }//end if

        $this->entityManager->persist($applicationObject);
        $this->entityManager->flush();

        return $applicationObject;

    }//end importApplication()


    /**
     * Get all the components or one component through the components of https://componentencatalogus.commonground.nl/api/components.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $componentId   The given component id
     *
     * @return array|null The components as array.
     * @throws \Exception
     */
    public function getComponents(?array $data=[], ?array $configuration=[], ?string $componentId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source and endpoint from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($source === null && $endpoint === null) {
            return $this->data;
        }

        if ($componentId === null) {
            return $this->getComponentsFromSource($source, $endpoint);
        }

        return $this->getComponentFromSource($source, $endpoint, $componentId);

    }//end getComponents()


    /**
     * Get all components of the given source.
     *
     * @param ObjectEntity $repository     The repository object
     * @param array        $componentArray The component array
     * @param Source       $source         The given source
     *
     * @return ObjectEntity|null The created component as object.
     * @throws \Exception
     */
    public function createComponentWithData(ObjectEntity $repository, array $componentArray, Source $source): ?ObjectEntity
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        $componentSchema    = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        $componentMapping   = $this->resourceService->getMapping($this->configuration['componentencatalogusMapping'], 'open-catalogi/open-catalogi-bundle');
        if ($componentSchema instanceof Entity === false
            || $componentMapping instanceof Mapping === false
            || $organizationSchema instanceof Entity === false
        ) {
            return null;
        }

        // Add values from the componenten catalogus array.
        $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $componentArray['repositoryUrl']);
        $componentSync->setMapping($componentMapping);

        // Unset the repo owner so we don't make duplicates.
        $owner = $componentArray['owner'];
        unset($componentArray['owner']);

        $componentSync = $this->syncService->synchronize($componentSync, $componentArray);
        $componentSync->getObject()->hydrate(['url' => $repository]);
        $this->entityManager->persist($componentSync->getObject());

        // Sync the owner and add it to the component.
        if ($owner !== null) {
            $ownerSync = $this->syncService->findSyncBySource($source, $organizationSchema, $owner['fullName']);
            $ownerSync = $this->syncService->synchronize($ownerSync, ['name' => $owner['fullName'], 'email' => $owner['email'], 'logo' => $owner['pictureUrl'], 'type' => 'Owner']);

            $componentSync->getObject()->getValue('legal')->hydrate(['repoOwner' => $ownerSync->getObject()]);
            $this->entityManager->persist($componentSync->getObject());
        }

        return $componentSync->getObject();

    }//end createComponentWithData()


    /**
     * Get all components of the given source.
     *
     * @param ObjectEntity $component      The component object
     * @param array        $componentArray The component array
     * @param Source       $source         The given source
     *
     * @return ObjectEntity|null The updated component as object.
     * @throws \Exception
     */
    public function updateComponentWithData(ObjectEntity $component, array $componentArray, Source $source): ?ObjectEntity
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        $componentMapping   = $this->resourceService->getMapping($this->configuration['componentencatalogusMapping'], 'open-catalogi/open-catalogi-bundle');
        if ($componentMapping instanceof Mapping === false || $organizationSchema instanceof Entity === false) {
            return null;
        }

        // Map the componenten catalogus data.
        $dataArray = $this->mappingService->mapping($componentMapping, $componentArray);

        // Unset the repo owner so we don't make duplicates.
        unset($dataArray['legal']['repoOwner']);

        // Hydrate the component with the mapped componenten catalogus data.
        $component->hydrate($dataArray);
        $this->entityManager->persist($component);

        // Sync the owner and add it to the component.
        if ($componentArray['owner'] !== null) {
            $ownerSync = $this->syncService->findSyncBySource($source, $organizationSchema, $componentArray['owner']['fullName']);
            $ownerSync = $this->syncService->synchronize($ownerSync, ['name' => $componentArray['owner']['fullName'], 'email' => $componentArray['owner']['email'], 'logo' => $componentArray['owner']['pictureUrl'], 'type' => 'Owner']);

            $component->getValue('legal')->setValue('repoOwner', $ownerSync->getObject());
            $this->entityManager->persist($component);
        }

        $this->entityManager->flush();

        return $component;

    }//end updateComponentWithData()


    /**
     * Get all components of the given source.
     *
     * @param  Source $source         The github/gitlab source.
     * @param  array  $componentArray The component array
     * @param  string $domain         The domain of the repository url.
     * @return ObjectEntity|null The repository as object.
     * @throws \Exception
     */
    public function getRepositoryFromSync(Source $source, array $componentArray, string $domain): ?ObjectEntity
    {
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false) {
            return null;
        }

        // Find the repository sync by source.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $componentArray['repositoryUrl']);

        // If the repository has a object don't get the repository from github.
        if ($repositorySync->getObject() !== null) {
            return $repositorySync->getObject();
        }

        // If there is no repository get the repository from github.
        $this->entityManager->remove($repositorySync);
        $this->entityManager->flush();

        if ($domain === 'github.com') {
            // Get the github repository
            $this->githubApiService->setConfiguration($this->configuration);
            $repository = $this->githubApiService->getGithubRepository($componentArray['repositoryUrl']);
        }

        if ($domain === 'gitlab.com') {
            // Get the gitlab repository
            $this->gitlabApiService->setConfiguration($this->configuration);
            $repository = $this->gitlabApiService->getGitlabRepository($componentArray['repositoryUrl']);
        }

        return $repository;

    }//end getRepositoryFromSync()


    /**
     * Get all components of the given source.
     *
     * @param Source       $defaultSource  The componenten catalogus source
     * @param array        $componentArray The component array
     * @param ObjectEntity $repository     The repository object
     * @param Source       $source         The given source
     *
     * @return ObjectEntity|null The repository with the created/updated component(s).
     * @throws \Exception
     */
    public function handleComponentRepo(Source $defaultSource, array $componentArray, ObjectEntity $repository, Source $source): ?ObjectEntity
    {
        // Get the github api source.
        $componentSchema = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($componentSchema instanceof Entity === false) {
            return null;
        }

        // Get the components of the repository.
        $components = $repository->getValue('components');

        // If there are more then one components we know that there is a publiccode file, then we don't do anything.
        // If there is no component we want to add a component with the componenten catalogus data.
        if ($components->count() === 0) {
            $component = $this->createComponentWithData($repository, $componentArray, $source);
        }

        // If there is no publiccode file found enrich the component with the componenten catalogus data.
        // Check if there is a publiccode file through the property publiccodeUrl.
        if ($components->count() === 1 && $components[0] !== null && $components[0]->getValue('publiccodeUrl') === null
        ) {
            $component = $this->updateComponentWithData($components[0], $componentArray, $source);
        }

        // If the component is created/updated the componenten catalogus source is added to the sync.
        if (isset($component) === true) {
            // Create a sync object for the componenten catalogus source and add it to the object.
            $sync = $this->syncService->findSyncBySource($defaultSource, $componentSchema, $componentArray['repositoryUrl']);
            $component->addSynchronization($sync);
            $this->entityManager->persist($sync);
            $this->entityManager->persist($component);
            $this->entityManager->flush();
        }

        return $repository;

    }//end handleComponentRepo()


    /**
     * Get all components of the given source.
     *
     * @param Source $defaultSource  The (default source) componenten catalogus.
     * @param array  $componentArray The component array
     *
     * @return ObjectEntity|null
     * @throws \Exception
     */
    public function handleComponent(Source $defaultSource, array $componentArray): ?array
    {
        $parsedUrl = \Safe\parse_url($componentArray['repositoryUrl']);
        if (key_exists('host', $parsedUrl) === false) {
            return null;
        }

        // Get the domain of the repository url.
        $domain = $parsedUrl['host'];

        switch ($domain) {
            // If the domain is from github get the github source.
        case 'github.com':
            $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
            break;
            // If the domain is from gitlab get the gitlab source.
        case 'gitlab.com':
            $source = $this->resourceService->getSource($this->configuration['gitlabSource'], 'open-catalogi/open-catalogi-bundle');
            break;
        default:
            $source = null;
            $this->pluginLogger->info('We don\'t support this repository with domain: '.$domain);
            break;
        }

        if ($source instanceof Source === false) {
            return null;
        }

        // Get the repository from the find sync by source.
        // If there is no object get it from the github/gitlab source.
        $repository = $this->getRepositoryFromSync($source, $componentArray, $domain);

        // If we don't have a repository we return null. (probabbly the rate limit from github/gitlab)
        if ($repository === null) {
            return null;
        }

        // Handle the component of the repository.
        return $this->handleComponentRepo($defaultSource, $componentArray, $repository, $source);

    }//end handleComponent()


    /**
     * Get all components of the given source.
     *
     * @param  Source $source      The given source
     * @param  string $endpoint    The endpoint of the source
     * @param  string $componentId The component id.
     * @return array|null The components of the repository.
     * @throws \Exception
     */
    public function getComponentFromSource(Source $source, string $endpoint, string $componentId): ?array
    {
        try {
            $response = $this->callService->call($source, $endpoint.'/'.$componentId);
        } catch (\Exception $exception) {
            $this->pluginLogger->error($exception->getMessage());
        }

        if (isset($response) === true) {
            $componentArray = $this->callService->decodeResponse($source, $response, 'application/json');

            $repository = $this->handleComponent($source, $componentArray);

            if ($repository === null) {
                return null;
            }

            $components = [];
            foreach ($repository->getValue('components') as $component) {
                $components[] = $component->toArray();
            }

            return $components;
        }

        return null;

    }//end getComponentFromSource()


    /**
     * Get all components of the given source.
     *
     * @param Source $source   The given source
     * @param string $endpoint The endpoint of the source
     *
     * @return array|null
     * @throws \Exception
     */
    public function getComponentsFromSource(Source $source, string $endpoint): ?array
    {
        $components = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($components).' components from '.$source->getName());

        $result = [];
        foreach ($components as $componentArray) {
            $repository = $this->handleComponent($source, $componentArray);

            if ($repository !== null) {
                $result[] = $repository->toArray();
            }
        }

        $this->entityManager->flush();

        return $result;

    }//end getComponentsFromSource()


}//end class
