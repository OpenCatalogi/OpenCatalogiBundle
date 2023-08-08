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
 */
class ComponentenCatalogusService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $donService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface   $entityManager   The Entity Manager Interface.
     * @param CallService              $callService     The Call Service.
     * @param CacheService             $cacheService    The Cache Service.
     * @param SynchronizationService   $syncService     The Synchronization Service.
     * @param MappingService           $mappingService  The Mapping Service.
     * @param DeveloperOverheidService $donService      The Developer Overheid Service.
     * @param GatewayResourceService   $resourceService The Gateway Resource Service.
     * @param LoggerInterface          $pluginLogger    The Plugin logger.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        CacheService $cacheService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        DeveloperOverheidService $donService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->cacheService    = $cacheService;
        $this->syncService     = $syncService;
        $this->mappingService  = $mappingService;
        $this->donService      = $donService;
        $this->pluginLogger    = $pluginLogger;
        $this->resourceService = $resourceService;
        $this->data = [];
        $this->configuration = [];

    }//end __construct()

    /**
     * Import the application into the data layer.
     *
     * @param array $application The application to import.
     *
     * @return ObjectEntity|null
     */
    public function importApplication(array $application): ?ObjectEntity
    {
        // Get the source, entity and mapping
        $source  = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $application['id']);

        $this->pluginLogger->debug('Mapping object '.$application['name']. ' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $application);

        $applicationObject = $synchronization->getObject();

        $this->pluginLogger->debug('Synced application: '.$applicationObject->getValue('name'), ['package' => 'open-catalogi/open-catalogi-bundle']);

        if ($application['components'] !== null) {
            $components = [];
            foreach ($application['components'] as $component) {
                $componentObject = $this->importComponent($component);
                $components[]    = $componentObject;
            }//end foreach

            $applicationObject->setValue('components', $components);
        }//end if

        $this->entityManager->persist($applicationObject);
        $this->entityManager->flush();

        return $applicationObject;

    }//end importApplication()


    /**
     * Get all applications of the given source.
     *
     * @param Source $source The given source
     * @param string $endpoint The endpoint of the source
     *
     * @return array|null
     */
    public function getApplications(Source $source, string $endpoint): ?array
    {
        $applications = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($applications).' applications from '.$source->getName());

        $result = [];
        foreach ($applications as $application) {
            $result[] = $this->importApplication($application);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get an applications of the given source with the given id.
     *
     * @param Source $source The given source
     * @param string $endpoint The endpoint of the source
     * @param string $applicationId The given application id
     *
     * @return array|null
     */
    public function getApplication(Source $source, string $endpoint, string $applicationId): ?array
    {
        $response = $this->callService->call($source, $endpoint .'/'.$applicationId);
        $application = json_decode($response->getBody()->getContents(), true);

        if ($application === null) {
            $this->pluginLogger->error('Could not find an application with id: '.$applicationId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $application = $this->importApplication($application);
        if ($application === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found application with id: '.$applicationId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $application->toArray();
    }


    /**
     * Get all applications or one application through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @param array|null $data The data array from the request
     * @param array|null $configuration The configuration array from the request
     * @param string|null $applicationId The given application id
     *
     * @return array|null
     */
    public function getComponentenCatalogusApplications(?array $data = [], ?array $configuration = [], ?string $applicationId = null): ?array
    {
        $this->data = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($applicationId === null) {
            return $this->getApplications($source, $endpoint);
        }

        return $this->getApplication($source, $endpoint, $applicationId);
    }//end getApplications()

    /**
     * Imports a repository through a component.
     *
     * @param array        $componentArray  The array to translate.
     * @param ObjectEntity $componentObject The resulting component object.
     *
     * @return ObjectEntity|null
     */
    public function importRepositoryThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        $repositoryEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');
        // If the component isn't already set to a repository create or get the repo and set it to the component url.
        if (key_exists('url', $componentArray) === true
            && key_exists('url', $componentArray['url']) === true
            && key_exists('name', $componentArray['url']) === true
        ) {
            $repositories = $this->cacheService->searchObjects(null, ['url' => $componentArray['url']['url']], [$repositoryEntity->getId()->toString()])['results'];
            if ($repositories === []) {
                $repository = new ObjectEntity($repositoryEntity);
                $repository->hydrate(
                    [
                        'name' => $componentArray['url']['name'],
                        'url'  => $componentArray['url']['url'],
                    ]
                );
            }//end if

            if (count($repositories) === 1) {
                $repository = $this->entityManager->find('App:ObjectEntity', $repositories[0]['_self']['id']);
                $this->entityManager->persist($repository);
            }//end if

            if ($componentObject->getValue('url') !== false) {
                // If the component is already set to a repository return the component object.
                return $componentObject;
            }//end if

            if (isset($repository) === true) {
                $componentObject->setValue('url', $repository);
            }
        }//end if

        return null;

    }//end importRepositoryThroughComponent()


    /**
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param array $component The component to import
     *
     * @return ObjectEntity|null
     */
    public function importComponent(array $component): ?ObjectEntity
    {
        // Get the source, schema and mapping from the configuration array.
        $source  = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle', 'open-catalogi/open-catalogi-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'open-catalogi/open-catalogi-bundle');

        // Handle sync.
        $synchronization = $this->syncService->findSyncBySource($source, $schema, $component['id']);

        $this->pluginLogger->debug('Mapping object '.$component['name']. ' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

        // Do the mapping of the component set two variables.
        $component = $componentArray = $this->mappingService->mapping($mapping, $component);
        // Unset component url before creating object, we don't want duplicate repositories.
        unset($component['url']);
        if (key_exists('legal', $component) === true
            && key_exists('repoOwner', $component['legal']) === true
        ) {
            unset($component['legal']['repoOwner']);
        }//end if

        $synchronization = $this->syncService->synchronize($synchronization, $component);
        $componentObject = $synchronization->getObject();

        $this->pluginLogger->debug('Synced component: '.$componentObject->getValue('name'), ['package' => 'open-catalogi/open-catalogi-bundle']);

        $this->importRepositoryThroughComponent($componentArray, $componentObject);
        $this->donService->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        $this->entityManager->persist($componentObject);
        $this->entityManager->flush();

        return $componentObject;

    }//end importComponent()

    /**
     * Get all components of the given source.
     *
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param Source $source The given source
     * @param string $endpoint The endpoint of the source
     *
     * @return array|null
     */
    public function getComponents(Source $source, string $endpoint): ?array
    {
        $components = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($components).' components from '.$source->getName());

        $result = [];
        foreach ($components as $component) {
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }//end getComponents()

    /**
     * Get an applications of the given source with the given id.
     *
     * @param Source $source The given source
     * @param string $endpoint The endpoint of the source
     * @param string $componentId The given component id
     *
     * @return array|null
     */
    public function getComponent(Source $source, string $endpoint, string $componentId): ?array
    {
        $response = $this->callService->call($source, $endpoint .'/'.$componentId);
        $component = json_decode($response->getBody()->getContents(), true);

        if ($component === null) {
            $this->pluginLogger->error('Could not find an component with id: '.$componentId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $component = $this->importComponent($component);
        if ($component === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found component with id: '.$componentId, ['package' => 'open-catalogi/open-catalogi-bundle']);
        
        return $component->toArray();
    }

    /**
     * Get all the components or one component through the components of https://componentencatalogus.commonground.nl/api/components.
     *
     * @param array|null $data The data array from the request
     * @param array|null $configuration The configuration array from the request
     * @param string|null $componentId The given component id
     *
     * @return array|null
     */
    public function getComponentenCatalogusComponents(?array $data = [], ?array $configuration = [], ?string $componentId = null): ?array
    {
        $this->data = $data;
        $this->configuration = $configuration;

        // Get the source and endpoint from the configuration array.
        $source = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($componentId === null) {
            return $this->getComponents($source, $endpoint);
        }

        return $this->getComponent($source, $endpoint, $componentId);
    }//end getApplications()

}//end class
