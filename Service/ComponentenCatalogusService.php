<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
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
    private GatewayResourceService $gatewayResourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @param EntityManagerInterface   $entityManager   The Entity Manager Interface
     * @param CallService              $callService     The Call Service
     * @param SynchronizationService   $syncService     The Synchronization Service
     * @param MappingService           $mappingService  The Mapping Service
     * @param DeveloperOverheidService $donService      The Developer Overheid Service
     * @param GatewayResourceService   $resourceService The Gateway Resource Service
     * @param LoggerInterface          $pluginLogger    The Plugin logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        DeveloperOverheidService $donService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->mappingService = $mappingService;
        $this->donService = $donService;
        $this->pluginLogger = $pluginLogger;
        $this->gatewayResourceService = $resourceService;
    }//end __construct()

    /**
     * Get applications through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @return array|null
     */
    public function getApplications(): ?array
    {
        $result = [];
        // Do we have a source?
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle');

        $applications = $this->callService->getAllResults($source, '/products');

        $this->pluginLogger->info('Found '.count($applications).' applications');
        foreach ($applications as $application) {
            $result[] = $this->importApplication($application);
        }

        $this->entityManager->flush();

        return $result;
    }//end getApplications()

    /**
     * Get an application through the products of https://componentencatalogus.commonground.nl/api/products/{id}.
     *
     * @param string $id The id of the application to look for.
     *
     * @return array|null
     */
    public function getApplication(string $id): ?array
    {
        // Do we have a source?
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->info('Getting application '.$id);
        $response = $this->callService->call($source, '/products/'.$id);

        $application = json_decode($response->getBody()->getContents(), true);

        if ($application === null) {
            $this->pluginLogger->error('Could not find an application with id: '.$id.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }
        $application = $this->importApplication($application);
        if ($application === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found application with id: '.$id, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $application->toArray();
    }//end getApplication()

    /**
     * Import the application into the data layer.
     *
     * @param array $application The application to import.
     *
     * @return ObjectEntity|null
     */
    public function importApplication(array $application): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle');
        $applicationEntity = $this->gatewayResourceService->getSchema('https://opencatalogi.nl/oc.application.schema.json', 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->gatewayResourceService->getMapping('https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusApplication.mapping.json', 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $applicationEntity, $application['id']);

        $this->pluginLogger->debug('Mapping object'.$application['name'], ['package' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$mapping, ['package' => 'open-catalogi/open-catalogi-bundle']);

        $this->pluginLogger->info('Checking application '.$application['name'], ['package' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $application);

        $applicationObject = $synchronization->getObject();

        if ($application['components']) {
            $components = [];
            foreach ($application['components'] as $component) {
                $componentObject = $this->importComponent($component);
                $components[] = $componentObject;
            }

            $applicationObject->setValue('components', $components);
        }

        $this->entityManager->persist($applicationObject);
        $this->entityManager->flush();

        return $applicationObject;
    }//end importApplication()

    /**
     * Get components through the components of https://componentencatalogus.commonground.nl/api/components.
     *
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @return array|null
     */
    public function getComponents(): ?array
    {
        $result = [];

        // Do we have a source?
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->debug('Trying to get all components from source '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

        $components = $this->callService->getAllResults($source, '/components');

        $this->pluginLogger->info('Found '.count($components).' components', ['package' => 'open-catalogi/open-catalogi-bundle']);
        foreach ($components as $component) {
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }//end getComponents()

    /**
     * Get a component trough the components of https://componentencatalogus.commonground.nl/api/components/{id}.
     *
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param string $id
     *
     * @return array|null
     */
    public function getComponent(string $id): ?array
    {
        // Do we have a source
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->debug('Trying to get component with id: '.$id, ['package' => 'open-catalogi/open-catalogi-bundle']);
        $response = $this->callService->call($source, '/components/'.$id);

        $component = json_decode($response->getBody()->getContents(), true);

        if ($component === null) {
            $this->pluginLogger->error('Could not find a component with id: '.$id.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if
        $component = $this->importComponent($component);
        if ($component === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        $this->pluginLogger->info('Found component with id: '.$id, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $component->toArray();
    }//end getComponent()

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
        $repositoryEntity = $this->gatewayResourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');
        // If the component isn't already set to a repository create or get the repo and set it to the component url.
        if (key_exists('url', $componentArray) === true
            && key_exists('url', $componentArray['url']) === true
            && key_exists('name', $componentArray['url']) === true) {
            $repository = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $repositoryEntity, 'name' => $componentArray['url']['name']]);
            if (isset($repository) === true) {
                $repository = new ObjectEntity($repositoryEntity);
                $repository->hydrate([
                    'name' => $componentArray['url']['name'],
                    'url'  => $componentArray['url']['url'],
                ]);
            }//end if
            $this->entityManager->persist($repository);
            if ($componentObject->getValue('url') !== false) {
                // If the component is already set to a repository return the component object.
                return $componentObject;
            }//end if
            $componentObject->setValue('url', $repository);
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
        // Do we have a source?
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle', 'open-catalogi/open-catalogi-bundle');
        $componentEntity = $this->gatewayResourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->gatewayResourceService->getMapping('https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusComponent.mapping.json', 'open-catalogi/open-catalogi-bundle');

        // Handle sync.
        $synchronization = $this->syncService->findSyncBySource($source, $componentEntity, $component['id']);

        $this->pluginLogger->debug('Mapping object'.$component['name'], ['package' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$mapping, ['package' => 'open-catalogi/open-catalogi-bundle']);

        $this->pluginLogger->debug('Checking component '.$component['name'], ['package' => 'open-catalogi/open-catalogi-bundle']);

        // Do the mapping of the component set two variables.
        $component = $componentArray = $this->mappingService->mapping($mapping, $component);
        // Unset component url before creating object, we don't want duplicate repositories.
        unset($component['url']);
        if (key_exists('legal', $component) && key_exists('repoOwner', $component['legal'])) {
            unset($component['legal']['repoOwner']);
        }

        $synchronization = $this->syncService->synchronize($synchronization, $component);
        $componentObject = $synchronization->getObject();

        $this->importRepositoryThroughComponent($componentArray, $componentObject);
        $this->donService->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        $this->entityManager->persist($componentObject);
        $this->entityManager->flush();

        return $componentObject;
    }//end importComponent()
}
