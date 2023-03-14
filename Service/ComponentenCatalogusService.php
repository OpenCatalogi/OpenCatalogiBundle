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
use Symfony\Component\Console\Style\SymfonyStyle;

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
    private SynchronizationService $synchronizationService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;


    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $developerOverheidService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $gatewayResourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @param EntityManagerInterface   $entityManager            The Entity Manager Interface
     * @param CallService              $callService              The Call Service
     * @param SynchronizationService   $synchronizationService   The Synchronization Service
     * @param MappingService           $mappingService           The Mapping Service
     * @param DeveloperOverheidService $developerOverheidService The Developer Overheid Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        DeveloperOverheidService $developerOverheidService,
        GatewayResourceService $gatewayResourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->developerOverheidService = $developerOverheidService;
        $this->pluginLogger = $pluginLogger;
        $this->gatewayResourceService = $gatewayResourceService;
    }

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
     * @param string $id
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

        if (!$application) {
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
     * @todo
     *
     * @param $application
     *
     * @return ObjectEntity|null
     */
    public function importApplication($application): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle');
        $applicationEntity = $this->gatewayResourceService->getSchema('https://opencatalogi.nl/oc.application.schema.json', 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->gatewayResourceService->getMapping('https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusApplication.mapping.json', 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->synchronizationService->findSyncBySource($source, $applicationEntity, $application['id']);

        $this->pluginLogger->debug('Mapping object'.$application['name'], ['package' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$mapping, ['package' => 'open-catalogi/open-catalogi-bundle']);

        $this->pluginLogger->info('Checking application '.$application['name'], ['package' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $application);

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

        if (!$component) {
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
     * @param array        $componentArray
     * @param ObjectEntity $componentObject
     *
     * @return ObjectEntity|null
     */
    public function importRepositoryThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        $repositoryEntity = $this->gatewayResourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');
        // If the component isn't already set to a repository create or get the repo and set it to the component url.
        if (key_exists('url', $componentArray) &&
            key_exists('url', $componentArray['url']) &&
            key_exists('name', $componentArray['url'])) {
            if (!($repository = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $repositoryEntity, 'name' => $componentArray['url']['name']]))) {
                $repository = new ObjectEntity($repositoryEntity);
                $repository->hydrate([
                    'name' => $componentArray['url']['name'],
                    'url'  => $componentArray['url']['url'],
                ]);
            }//end if
            $this->entityManager->persist($repository);
            if ($componentObject->getValue('url')) {
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
     * @param $component
     *
     * @return ObjectEntity|null
     */
    public function importComponent($component): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->gatewayResourceService->getSource('https://opencatalogi.nl/source/oc.componentencatalogus.source.json', 'open-catalogi/open-catalogi-bundle', 'open-catalogi/open-catalogi-bundle');
        $componentEntity = $this->gatewayResourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->gatewayResourceService->getMapping('https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusComponent.mapping.json', 'open-catalogi/open-catalogi-bundle');

        // Handle sync.
        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);

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

        $synchronization = $this->synchronizationService->synchronize($synchronization, $component);
        $componentObject = $synchronization->getObject();

        $this->importRepositoryThroughComponent($componentArray, $componentObject);
        $this->developerOverheidService->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        $this->entityManager->persist($componentObject);
        $this->entityManager->flush();

        return $componentObject;
    }//end importComponent()
}
