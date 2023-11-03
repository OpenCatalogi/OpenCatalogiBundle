<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Prophecy\Call\Call;
use Psr\Log\LoggerInterface;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

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

    }//end __construct()


    /**
     * Imports a repository through a component.
     *
     * @param array        $componentArray  The array to translate.
     * @param ObjectEntity $componentObject The resulting component object.
     * @param array        $configuration   The configuration array.
     *
     * @return ObjectEntity|null
     */
    public function importRepositoryThroughComponent(array $componentArray, ObjectEntity $componentObject, array $configuration): ?ObjectEntity
    {
        $repositoryEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');
        if ($repositoryEntity === null) {
            return null;
        }

        if (key_exists('url', $componentArray) === true
            && key_exists('url', $componentArray['url']) === true
            && empty($componentArray['url']['url']) === false
        ) {
            $parsedUrl = \Safe\parse_url($componentArray['url']['url']);

            if (key_exists('host', $parsedUrl) === false) {
                return null;
            }

            $domain = \Safe\parse_url($componentArray['url']['url'])['host'];

            switch ($domain) {
            case 'github.com':

                if (key_exists('path', $parsedUrl) === false) {
                    return null;
                }

                $path = trim(\Safe\parse_url($componentArray['url']['url'])['path'], '/');

                $githubSource = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
                if ($this->githubApiService->checkGithubAuth($githubSource) === false) {
                    return null;
                }//end if

                $this->pluginLogger->debug('Getting repository with url'.$componentArray['url']['url'].'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

                try {
                    $response = $this->callService->call($githubSource, '/repos/'.$path);
                } catch (RequestException $requestException) {
                    $this->pluginLogger->error($requestException->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
                }

                if (isset($response) === true) {
                    try {
                        $repository = $this->callService->decodeResponse($githubSource, $response);
                    } catch (Exception $exception) {
                        $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
                    }
                }

                if (isset($repository) === true) {
                    return $this->importGithubRepository($repository, $configuration);
                }
                break;
            case 'gitlab.com':
                $source = $this->resourceService->getSource($configuration['source'], 'open-catalogi/open-catalogi-bundle');

                $sync = $this->syncService->findSyncBySource($source, $repositoryEntity, $componentArray['url']['url']);
                $sync = $this->syncService->synchronize($sync, ['url' => $componentArray['url']['url'], 'name' => $componentArray['url']['name']]);

                return $sync->getObject();

                    break;
            default:
                // Throw exception
            }//end switch
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
        if ($organisationEntity === null
            || $legalEntity === null
        ) {
            return null;
        }

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
     * This function imports a component
     *
     * @param array $component     The component to import.
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

        if ($source === null
            || $schema === null
            || $mapping === null
        ) {
            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $component['id']);

        // Do the mapping of the component set two variables.
        $componentMapping = $componentArray = $this->mappingService->mapping($mapping, $component);

        $this->pluginLogger->debug('Mapping object '.$componentMapping['name'].' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

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

        $repository = $this->importRepositoryThroughComponent($componentArray, $componentObject, $configuration);
        $this->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        // When the developer.overheid component is imported there is no repository.
        if ($repository === null) {
            return $componentObject;
        }

        $componentObject->setValue('url', $repository);
        $this->entityManager->persist($componentObject);
        $this->entityManager->flush();

        $githubSource = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
        $sync         = $this->syncService->findSyncBySource($githubSource, $schema, $repository->getValue('url'));
        if ($sync->getObject() === null) {
            $sync->setObject($componentObject);
            $this->entityManager->persist($sync);
            $this->entityManager->flush();
        }

        return $componentObject;

    }//end importComponent()


    /**
     * This function imports the repository of developer overheid
     *
     * @param array $repository
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function importDevRepository(array $repository, array $configuration): ?ObjectEntity
    {
        $schema = $this->resourceService->getSchema($configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        if ($schema === null) {
            return null;
        }

        if ($repository['source'] === 'github') {
            // Use the github source to import this repository.
            $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
            // Do we have the api key set of the source.
            if ($source === null
                || $this->githubApiService->checkGithubAuth($source) === false
            ) {
                return null;
            }//end if

            $name = trim(\Safe\parse_url($repository['url'], PHP_URL_PATH), '/');
            // Get the repository from github so we can work with the repository id.
            $repository = $this->githubApiService->getRepository($name, $source);
            if ($repository === null) {
                return null;
            }

            $repositoryId = $repository['id'];
        } else {
            // Use the source of developer.overheid.
            $source = $this->resourceService->getSource($configuration['source'], 'open-catalogi/open-catalogi-bundle');
            if ($source === null) {
                return null;
            }

            // Use the repository name as the id to sync.
            $repositoryId = $repository['url'];
        }//end if

        $this->pluginLogger->info('Checking repository with url'.$repository['url'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization = $this->syncService->findSyncBySource($source, $schema, $repositoryId);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

        $repositoryObject = $synchronization->getObject();

        $componentSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');

        $sync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryObject->getValue('url'));
        $sync = $this->syncService->synchronize($sync, ['name' => $repositoryObject->getValue('name'), 'url' => $repositoryObject]);

        return $repositoryObject;

    }//end importDevRepository()


    /**
     * This function import the repositories from github
     *
     * @param array $repository
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function importGithubRepository(array $repository, array $configuration): ?ObjectEntity
    {
        $schema  = $this->resourceService->getSchema($configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $source  = $this->resourceService->getSource($configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $mapping = $this->resourceService->getMapping($configuration['repositoryMapping'], 'open-catalogi/open-catalogi-bundle');

        if ($source === null
            || $schema === null
            || $mapping === null
        ) {
            return null;
        }

        $this->pluginLogger->info('Checking repository '.$repository['name'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization = $this->syncService->findSyncBySource($source, $schema, $repository['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

        $repositoryObject = $synchronization->getObject();
        
        return $repositoryObject;

    }//end importGithubRepository()


    /**
     * Import the application into the data layer.
     *
     * @param array $application   The application to import.
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

        if ($source === null
            || $schema === null
            || $mapping === null
        ) {
            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $schema, $application['id']);

        $this->pluginLogger->debug('Mapping object '.$application['name'].' with mapping: '.$mapping->getReference(), ['package' => 'open-catalogi/open-catalogi-bundle']);

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


    /**
     * @param array $organisation  The organization that is being imported
     * @param array $configuration The configuration array
     *
     * @return ObjectEntity|null
     */
    public function importOrganisation(array $organisation, array $configuration): ?ObjectEntity
    {
        // Do we have a source?
        $source              = $this->resourceService->getSource($configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $organisationSchema  = $this->resourceService->getSchema($configuration['organisationSchema'], 'open-catalogi/open-catalogi-bundle');
        $organisationMapping = $this->resourceService->getMapping($configuration['organisationMapping'], 'open-catalogi/open-catalogi-bundle');

        if ($source === null
            || $organisationSchema === null
            || $organisationMapping === null
        ) {
            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $organisationSchema, $organisation['id']);

        $this->pluginLogger->debug('Mapping object'.$organisation['login'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$organisationMapping, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $this->pluginLogger->debug('Checking organisation '.$organisation['login'], ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $synchronization->setMapping($organisationMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $organisation);
        $this->pluginLogger->debug('Organisation synchronization created with id: '.$synchronization->getId()->toString(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $synchronization->getObject();

    }//end importOrganisation()


}//end class
