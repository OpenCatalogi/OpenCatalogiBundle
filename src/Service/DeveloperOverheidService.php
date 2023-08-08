<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway;
use App\Entity\Gateway as Source;
use App\Entity\Gateway as Source;
use App\Entity\Gateway as Source;
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
 *  This class handles the interaction with developer.overheid.nl.
 */
class DeveloperOverheidService
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
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface.
     * @param CallService            $callService      The Call Service.
     * @param CacheService           $cacheService     The Cache Service.
     * @param SynchronizationService $syncService      The Synchronization Service.
     * @param MappingService         $mappingService   The Mapping Service.
     * @param GithubApiService       $githubApiService The Github Api Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface.
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        CacheService $cacheService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager    = $entityManager;
        $this->callService      = $callService;
        $this->cacheService     = $cacheService;
        $this->syncService      = $syncService;
        $this->mappingService   = $mappingService;
        $this->githubApiService = $githubApiService;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->data = [];
        $this->configuration = [];
    }//end __construct()

    /**
     * Turn a repo array into an object we can handle.
     *
     * @param array $repository The repository to synchronise.
     *
     * @return ?ObjectEntity
     */
    public function handleRepositoryArray(array $repository): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.developerOverheid.source.json', 'open-catalogi/open-catalogi-bundle');

        $repositoryEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');

        // Handle sync.
        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $this->pluginLogger->debug('Checking component '.$repository['name'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

        return $synchronization->getObject();

    }//end handleRepositoryArray()

    /**
     * @param array        $componentArray  The component array to import.
     * @param ObjectEntity $componentObject The resulting component object.
     *
     * @return ObjectEntity|null
     */
    public function importLegalRepoOwnerThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');
        $legalEntity        = $this->resourceService->getSchema('https://opencatalogi.nl/oc.legal.schema.json', 'open-catalogi/open-catalogi-bundle');

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $componentArray) === true
            && key_exists('repoOwner', $componentArray['legal']) === true
            && key_exists('name', $componentArray['legal']['repoOwner']) === true
        ) {
            $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $componentArray['legal']['repoOwner']['name']]);

            if ($organisation === null) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate(
                    [
                        'name'    => $componentArray['legal']['repoOwner']['name'],
                        'email'   => key_exists('email', $componentArray['legal']['repoOwner']) === true ? $componentArray['legal']['repoOwner']['email'] : null,
                        'phone'   => key_exists('phone', $componentArray['legal']['repoOwner']) === true ? $componentArray['legal']['repoOwner']['phone'] : null,
                        'website' => key_exists('website', $componentArray['legal']['repoOwner']) === true ? $componentArray['legal']['repoOwner']['website'] : null,
                        'type'    => key_exists('type', $componentArray['legal']['repoOwner']) === true ? $componentArray['legal']['repoOwner']['type'] : null,
                    ]
                );
            }//end if

            $this->entityManager->persist($organisation);

            if (($legal = $componentObject->getValue('legal')) !== null) {
                $legal->setValue('repoOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate(
                ['repoOwner' => $organisation]
            );
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;

    }//end importLegalRepoOwnerThroughComponent()
    
    /**
     * @todo duplicate with ComponentenCatalogusService ?
     *
     * @param array $component The component to import.
     *
     * @return ObjectEntity|null
     */
    public function importComponent(array $component): ?ObjectEntity
    {
        // Get the source, schema and mapping from the configuration array.
        $source  = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $component['id']);

        $this->pluginLogger->debug('Mapping object'.$component['service_name'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$mapping, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $this->pluginLogger->debug('Checking component '.$component['service_name'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        // Do the mapping of the component set two variables.
        $componentMapping = $componentArray = $this->mappingService->mapping($mapping, $component);

        // Unset component legal before creating object, we don't want duplicate organisations.
        if (key_exists('legal', $componentMapping) === true && key_exists('repoOwner', $componentMapping['legal']) === true) {
            unset($componentMapping['legal']['repoOwner']);
        }//end if

        $synchronization = $this->syncService->synchronize($synchronization, $componentMapping);
        $componentObject = $synchronization->getObject();

        $this->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        // @TODO The api is changed, is this still needed
        if (key_exists('related_repositories', $component) === true
            && $component['related_repositories'] !== []
        ) {
            $repository       = $component['related_repositories'][0];
            $repositoryObject = $this->handleRepositoryArray($repository);
            $repositoryObject->setValue('component', $componentObject);
            $componentObject->setValue('url', $repositoryObject);
        }//end if

        $this->entityManager->persist($componentObject);

        return $componentObject;

    }//end importComponent()

    /**
     * Get all components of the given source.
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
    }

    /**
     * Get a component of the given source with the given id.
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
     * Get all components or one component through the products of developer.overheid.nl/apis/{id}.
     *
     * @param array|null $data The data array from the request
     * @param array|null $configuration The configuration array from the request
     * @param string|null $componentId The given component id
     *
     * @return array|null
     */
    public function getDeveloperOverheidComponents(?array $data = [], ?array $configuration = [], ?string $componentId = null): ?array
    {
        $this->data = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($componentId === null) {
            return $this->getComponents($source, $endpoint);
        }

        return $this->getComponent($source, $endpoint, $componentId);
    }//end getDeveloperOverheidComponents()
    
    /**
     * @todo duplicate with GithubPubliccodeService ?
     *
     * @param $repository
     *
     * @return ObjectEntity|null
     */
    public function importRepository($repository): ?ObjectEntity
    {
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'open-catalogi/open-catalogi-bundle');

        if ($repository['source'] === 'github') {
            // Use the github source to import this repository.
            $source           = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
            $name     = trim(\Safe\parse_url($repository['url'], PHP_URL_PATH), '/');
            // Get the repository from github so we can work with the repository id.
            $repository = $this->githubApiService->getRepository($name, $source);
            $repositoryId = $repository['id'];
        } else {
            // Use the source of developer.overheid.
            $source  = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
            // Use the repository name as the id to sync.
            $repositoryId = $repository['name'];
        }

        $this->pluginLogger->info('Checking repository '.$repository['name'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization = $this->syncService->findSyncBySource($source, $schema, $repositoryId);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

        $repositoryObject = $synchronization->getObject();

        $component = $this->githubApiService->connectComponent($repositoryObject);
        if ($component !== null) {
            $repositoryObject->setValue('component', $component);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->flush();
        }//end if

        return $repositoryObject;

    }//end importRepository()

    /**
     * Get all repositories of the given source.
     *
     * @param Source $source The given source
     * @param string $endpoint The endpoint of the source
     *
     * @return array|null
     */
    public function getRepositories(Source $source, string $endpoint): ?array
    {
        $repositories = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($repositories).' repositories from '.$source->getName());

        $result = [];
        foreach ($repositories as $repository) {
            $result[] = $this->importRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a repository of the given source with the given id.
     *
     * @param Source $source The given source
     * @param string $endpoint The endpoint of the source
     * @param string $repositoryId The given repository id
     *
     * @return array|null
     */
    public function getRepository(Source $source, string $endpoint, string $repositoryId): ?array
    {
        $response = $this->callService->call($source, $endpoint .'/'.$repositoryId);
        $repository = json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find an repository with id: '.$repositoryId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found repository with id: '.$repositoryId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $repository->toArray();
    }

    /**
     * Get all repositories or one repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param array|null $data The data array from the request
     * @param array|null $configuration The configuration array from the request
     * @param string|null $repositoryId The given repository id
     *
     * @return array|null
     */
    public function getDeveloperOverheidRepositories(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): ?array
    {
        $this->data = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($repositoryId === null) {
            return $this->getRepositories($source, $endpoint);
        }

        return $this->getRepository($source, $endpoint, $repositoryId);
    }//end getDeveloperOverheidComponents()
    
}//end class
