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
 *  This class handles the interaction with developer.overheid.nl.
 */
class ImportResourcesService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

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
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface.
     * @param CacheService           $cacheService     The Cache Service.
     * @param SynchronizationService $syncService      The Synchronization Service.
     * @param MappingService         $mappingService   The Mapping Service.
     * @param GithubApiService       $githubApiService The Github Api Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface.
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CacheService $cacheService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager    = $entityManager;
        $this->cacheService     = $cacheService;
        $this->syncService      = $syncService;
        $this->mappingService   = $mappingService;
        $this->githubApiService = $githubApiService;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;

    }//end __construct()

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
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null
     */
    public function importComponent(array $component, array $configuration): ?ObjectEntity
    {
        // Get the source, schema and mapping from the configuration array.
        $source  = $this->resourceService->getSource($configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $schema  = $this->resourceService->getSchema($configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->resourceService->getMapping($configuration['componentMapping'], 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $component['id']);

        // Do the mapping of the component set two variables.
        $componentMapping = $componentArray = $this->mappingService->mapping($mapping, $component);

        $this->pluginLogger->debug('Mapping object '.$componentMapping['name']. ' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

        // Unset component url before creating object, we don't want duplicate repositories.
        if (key_exists('url', $componentMapping) === true) {
            unset($componentMapping['url']);
        }

        // Unset component legal before creating object, we don't want duplicate organisations.
        if (key_exists('legal', $componentMapping) === true
            && key_exists('repoOwner', $componentMapping['legal']) === true
        ) {
            unset($componentMapping['legal']['repoOwner']);
        }//end if

        $synchronization = $this->syncService->synchronize($synchronization, $componentMapping);
        $componentObject = $synchronization->getObject();

        $this->pluginLogger->debug('Synced component: '.$componentObject->getValue('name'), ['package' => 'open-catalogi/open-catalogi-bundle']);

        $this->importRepositoryThroughComponent($componentArray, $componentObject);
        $this->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        $this->entityManager->persist($componentObject);
        $this->entityManager->flush();

        return $componentObject;

    }//end importComponent()

    /**
     * @todo duplicate with GithubPubliccodeService ?
     *
     * @param $repository
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null
     */
    public function importRepository($repository, array $configuration): ?ObjectEntity
    {
        $schema  = $this->resourceService->getSchema($configuration['schema'], 'open-catalogi/open-catalogi-bundle');

        if ($repository['source'] === 'github') {
            // Use the github source to import this repository.
            $source           = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
            // Do we have the api key set of the source.
            if ($this->githubApiService->checkGithubAuth($source) === false) {
                return null;
            }//end if

            $name     = trim(\Safe\parse_url($repository['url'], PHP_URL_PATH), '/');
            // Get the repository from github so we can work with the repository id.
            $repository = $this->githubApiService->getRepository($name, $source);
            $repositoryId = $repository['id'];
        } else {
            // Use the source of developer.overheid.
            $source  = $this->resourceService->getSource($configuration['source'], 'open-catalogi/open-catalogi-bundle');
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
     * Import the application into the data layer.
     *
     * @param array $application The application to import.
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null
     */
    public function importApplication(array $application, array $configuration): ?ObjectEntity
    {
        // Get the source, entity and mapping
        $source  = $this->resourceService->getSource($configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $schema  = $this->resourceService->getSchema($configuration['applicationSchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->resourceService->getMapping($configuration['applicationMapping'], 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $application['id']);

        $this->pluginLogger->debug('Mapping object '.$application['name']. ' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $application);

        $applicationObject = $synchronization->getObject();

        $this->pluginLogger->debug('Synced application: '.$applicationObject->getValue('name'), ['package' => 'open-catalogi/open-catalogi-bundle']);

        if ($application['components'] !== null) {
            $components = [];
            foreach ($application['components'] as $component) {
                $componentObject = $this->importComponent($component, $configuration);
                $components[]    = $componentObject;
            }//end foreach

            $applicationObject->setValue('components', $components);
        }//end if

        $this->entityManager->persist($applicationObject);
        $this->entityManager->flush();

        return $applicationObject;

    }//end importApplication()

}//end class
