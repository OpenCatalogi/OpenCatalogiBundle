<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * This class handles the interaction with github.com.
 *
 * This service get repositories from api.github.com.
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class GithubPubliccodeService
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
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var Yaml
     */
    private Yaml $yaml;

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
     * @param SynchronizationService $syncService      The Synchronization Service.
     * @param MappingService         $mappingService   The Mapping Service.
     * @param GithubApiService       $githubApiService The Github Api Service.
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager    = $entityManager;
        $this->callService      = $callService;
        $this->syncService      = $syncService;
        $this->mappingService   = $mappingService;
        $this->githubApiService = $githubApiService;
        $this->resourceService  = $resourceService;
        $this->pluginLogger     = $pluginLogger;
        $this->yaml             = new Yaml();

        $this->data          = [];
        $this->configuration = [];

    }//end __construct()


    /**
     * This function searches for all repositories with a publiccode or one repository
     *
     * @param array|null  $data          data set at the start of the handler
     * @param array|null  $configuration configuration of the action
     * @param string|null $repositoryId  The given repository id
     *
     * @return array|null dataset at the end of the handler
     * @throws Exception
     */
    public function findGithubRepositories(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        // If we are testing for one repository.
        if ($repositoryId !== null) {
            return $this->getRepository($repositoryId);
        }

        return $this->getRepositories();

    }//end findGithubRepositories()


    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=filename:publiccode extension:yaml extension:yml.
     *
     * @throws Exception
     *
     * @return array|null All imported repositories.
     */
    public function getRepositories(): ?array
    {
        // Do we have a source?
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($this->githubApiService->checkGithubAuth($source) === false) {
            return null;
        }//end if

        $result      = [];
        $queryConfig = [];

        $queryConfig['query'] = ['q' => 'filename:publiccode extension:yaml extension:yml repo:OpenCatalogi/OpenCatalogiBundle'];

        // Find on publiccode.yaml.
        $repositories = $this->callService->getAllResults($source, '/search/code', $queryConfig);

        $this->pluginLogger->debug('Found '.count($repositories).' repositories.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $repositoriesMapping = $this->resourceService->getMapping($this->configuration['repositoriesMapping'], 'open-catalogi/open-catalogi-bundle');
        foreach ($repositories as $repository) {
            // Get the ref query from the url. This way we can get the publiccode file with the raw.gitgubusercontent
            $publiccodeUrlQuery               = \Safe\parse_url($repository['url'])['query'];
            $repository['urlReference']       = explode('ref=', $publiccodeUrlQuery)[1];
            $repository['repository']['name'] = str_replace('-', ' ', $repository['repository']['name']);
            $publiccodeUrl                    = "https://raw.githubusercontent.com/{$repository['repository']['full_name']}/{$repository['urlReference']}/{$repository['path']}";

            $repositoryObject = $this->importRepository($repository, $repository['repository']['id'], $repositoriesMapping, $publiccodeUrl);

            $result[] = $repositoryObject->toArray();
        }

        $this->entityManager->flush();

        return $result;

    }//end getRepositories()


    /**
     * Get a repository from github.
     *
     * @param string $repositoryId The id of the repository.
     *
     * @throws GuzzleException|LoaderError|SyntaxError|Exception
     *
     * @return array|null The imported repository as array.
     */
    public function getRepository(string $repositoryId): ?array
    {
        // Do we have a source?
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($this->githubApiService->checkGithubAuth($source) === false) {
            return null;
        }//end if

        $this->pluginLogger->debug('Getting repository '.$repositoryId.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response   = $this->callService->call($source, '/repositories/'.$repositoryId.'.');
            $repository = json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $requestException) {
            $this->pluginLogger->error($requestException->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($repository) === false) {
            $this->pluginLogger->error('Could not find a repository with id: '.$repositoryId.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        $repositoryMapping  = $this->resourceService->getMapping($this->configuration['repositoryMapping'], 'open-catalogi/open-catalogi-bundle');
        $repository['name'] = str_replace('-', ' ', $repository['name']);

        return $this->importRepository($repository, $repository['id'], $repositoryMapping)->toArray();

    }//end getRepository()


    /**
     * Maps a repository object and creates/updates a Synchronization.
     *
     * @param array       $repository        The repository array that will be imported
     * @param string      $repositoryId      The id of the repository to find the sync object
     * @param Mapping     $repositoryMapping The mapping of the repository
     * @param string|null $publiccodeUrl     The publiccode url
     *
     * @throws Exception
     *
     * @return ObjectEntity The repository object as array
     */
    public function importRepository(array $repository, string $repositoryId, Mapping $repositoryMapping, ?string $publiccodeUrl=null): ObjectEntity
    {
        // Do we have a source
        $source           = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $usercontentSource = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $componentSchema  = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryId);
        $synchronization->setMapping($repositoryMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);
        $this->pluginLogger->debug('Repository synchronization created with id: '.$synchronization->getId()->toString().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $repositoryObject = $synchronization->getObject();

        $this->pluginLogger->debug('Mapped object'.$repositoryObject->getValue('name').'. '.'With the mapping object '.$repositoryMapping->getName(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        if ($publiccodeUrl === null) {
            $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryObject->getValue('url'));

            if ($componentSync->getObject() !== null
            ) {
                $publiccodeUrl = $componentSync->getObject()->getValue('publiccodeUrl');
            }
        }

        if ($publiccodeUrl !== null) {
            $repositoryObject->setValue('publiccode_urls', [$publiccodeUrl]);
            $componentSync = $this->syncService->findSyncBySource($usercontentSource, $componentSchema, $publiccodeUrl);

            if ($componentSync->getObject() !== null
            ) {
                $publiccodeUrl = $componentSync->getObject()->getValue('publiccodeUrl');
            }
        }

        $componentSync = $this->syncService->synchronize($componentSync, ['name' => $repositoryObject->getValue('name'), 'url' => $repositoryObject, 'publiccodeUrl' => $publiccodeUrl]);

        return $repositoryObject;

    }//end importRepository()


    /**
     * @param array        $publiccode The publiccode array for updating the component object.
     * @param ObjectEntity $component  The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createApplicationSuite(array $publiccode, ObjectEntity $component): ?ObjectEntity
    {
        $applicationEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.application.schema.json', 'open-catalogi/open-catalogi-bundle');

        if (key_exists('applicationSuite', $publiccode) === true) {
            $application = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $applicationEntity, 'name' => $publiccode['applicationSuite']]);
            if ($application === null) {
                $application = new ObjectEntity($applicationEntity);
                $application->hydrate(['name' => $publiccode['applicationSuite']]);
            }//end if

            $this->entityManager->persist($application);
            $component->setValue('applicationSuite', $application);
            $this->entityManager->persist($component);
            $this->entityManager->flush();

            return $component;
        }//end if

        return null;

    }//end createApplicationSuite()


    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createMainCopyrightOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');
        $legalEntity        = $this->resourceService->getSchema('https://opencatalogi.nl/oc.legal.schema.json', 'open-catalogi/open-catalogi-bundle');

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $publiccode) === true
            && key_exists('mainCopyrightOwner', $publiccode['legal']) === true
            && is_array($publiccode['legal']['mainCopyrightOwner']) === false
        ) {
            $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['mainCopyrightOwner']]);
            if ($organisation === null) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate(['name' => $publiccode['legal']['mainCopyrightOwner']]);
            }//end if

            $this->entityManager->persist($organisation);

            if (($legal = $componentObject->getValue('legal')) !== null) {
                if ($legal->getValue('mainCopyrightOwner') !== null) {
                    // If the component is already set to a repoOwner return the component object.
                    return $componentObject;
                }//end if

                $legal->setValue('mainCopyrightOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate(['mainCopyrightOwner' => $organisation]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;

    }//end createMainCopyrightOwner()


    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createRepoOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');
        $legalEntity        = $this->resourceService->getSchema('https://opencatalogi.nl/oc.legal.schema.json', 'open-catalogi/open-catalogi-bundle');

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $publiccode) === true
            && key_exists('repoOwner', $publiccode['legal']) === true
            && is_array($publiccode['legal']['repoOwner']) === false
        ) {
            $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['repoOwner']]);
            if ($organisation === null) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate(['name' => $publiccode['legal']['repoOwner']]);
            }//end if

            $this->entityManager->persist($organisation);

            if (($legal = $componentObject->getValue('legal')) !== null) {
                if ($legal->getValue('repoOwner') !== null) {
                    // If the component is already set to a repoOwner return the component object.
                    return $componentObject;
                }//end if

                $legal->setValue('repoOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate(['repoOwner' => $organisation]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;

    }//end createRepoOwner()


    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createContractors(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.maintenance.schema.json', 'open-catalogi/open-catalogi-bundle');
        $contractorsEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.contractor.schema.json', 'open-catalogi/open-catalogi-bundle');

        if (key_exists('maintenance', $publiccode) === true
            && key_exists('contractors', $publiccode['maintenance']) === true
        ) {
            $contractors = [];
            foreach ($publiccode['maintenance']['contractors'] as $contractor) {
                if (key_exists('name', $contractor) === true) {
                    if (($contractor = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contractorsEntity, 'name' => $contractor['name']])) === false) {
                        $contractor = new ObjectEntity($contractorsEntity);
                        $contractor->hydrate(['name' => $contractor['name']]);
                    }//end if

                    $this->entityManager->persist($contractor);
                    $contractors[] = $contractor;
                }//end if
            }

            if (($maintenance = $componentObject->getValue('maintenance')) === true) {
                if ($maintenance->getValue('contractors') !== false) {
                    // If the component is already set to a contractors return the component object.
                    return $componentObject;
                }//end if

                $maintenance->setValue('contractors', $contractors);
                $this->entityManager->persist($maintenance);

                $componentObject->setValue('maintenance', $maintenance);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $maintenance = new ObjectEntity($maintenanceEntity);
            $maintenance->hydrate(['contractors' => $contractors]);
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;

    }//end createContractors()


    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createContacts(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.maintenance.schema.json', 'open-catalogi/open-catalogi-bundle');
        $contactEntity     = $this->resourceService->getSchema('https://opencatalogi.nl/oc.contact.schema.json', 'open-catalogi/open-catalogi-bundle');

        if (key_exists('maintenance', $publiccode) === true
            && key_exists('contacts', $publiccode['maintenance']) === true
        ) {
            $contacts = [];
            foreach ($publiccode['maintenance']['contacts'] as $contact) {
                if (key_exists('name', $contact) === true) {
                    if (($contact = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contactEntity, 'name' => $contact['name']])) === false) {
                        $contact = new ObjectEntity($contactEntity);
                        $contact->hydrate(['name' => $contact['name']]);
                    }//end if

                    $this->entityManager->persist($contact);
                    $contacts[] = $contact;
                }//end if
            }

            if (($maintenance = $componentObject->getValue('maintenance')) === true) {
                if ($maintenance->getValue('contacts') !== false) {
                    // If the component is already set to a contractors return the component object.
                    return $componentObject;
                }//end if

                $maintenance->setValue('contacts', $contacts);
                $this->entityManager->persist($maintenance);

                $componentObject->setValue('maintenance', $maintenance);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $maintenance = new ObjectEntity($maintenanceEntity);
            $maintenance->hydrate(['contacts' => $contacts]);
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;

    }//end createContacts()

    /**
     * This function maps the publiccode to a component.
     *
     * @param ObjectEntity $repository    The repository object.
     * @param array        $publiccode    The publiccode array for updating the component object.
     * @param array        $configuration The configuration array
     * @param string  $publiccodeUrl The publicce url
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The repository with the updated component from the publiccode url.
     */
    public function findPubliccodeSync(ObjectEntity $repository, array $configuration, string $publiccodeUrl): ?Synchronization
    {
        $usercontentSource = $this->resourceService->getSource($configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');
        $componentEntity  = $this->resourceService->getSchema($configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');

        foreach ($repository->getValue('components') as $component) {

            if ($component->getValue('publiccodeUrl') === $publiccodeUrl) {
                return $this->syncService->findSyncBySource($usercontentSource, $componentEntity, $publiccodeUrl);
            }

            if ($component->getValue('publiccodeUrl') !== $publiccodeUrl
                && $component->getValue('publiccodeUrl') === null
            ) {
                $component->setValue('publiccodeUrl', $publiccodeUrl);
                $this->entityManager->persist($component);

                $sync = $this->syncService->findSyncBySource($usercontentSource, $componentEntity, $publiccodeUrl);
                $sync->setObject($component);
                $this->entityManager->persist($sync);
                $this->entityManager->flush();

                return $sync;
            }

            if ($component->getValue('publiccodeUrl') !== $publiccodeUrl
                && $component->getValue('publiccodeUrl') !== null
            ) {
                $sync = $this->syncService->findSyncBySource($usercontentSource, $componentEntity, $publiccodeUrl);

                return $this->syncService->synchronize($sync, ['name' => $repository->getValue('name'), 'url' => $repository]);
            }
        }

        return null;
    }

    /**
     * This function maps the publiccode to a component.
     *
     * @param ObjectEntity $repository    The repository object.
     * @param array        $publiccode    The publiccode array for updating the component object.
     * @param array        $configuration The configuration array
     * @param string  $publiccodeUrl The publicce url
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The repository with the updated component from the publiccode url.
     */
    public function mapPubliccode(ObjectEntity $repository, array $publiccode, array $configuration, string $publiccodeUrl): ?ObjectEntity
    {
        $githubSource = $this->resourceService->getSource($configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $componentMapping = $this->resourceService->getMapping($configuration['componentMapping'], 'open-catalogi/open-catalogi-bundle');

        $sync = $this->findPubliccodeSync($repository, $configuration, $publiccodeUrl);

        if (isset($sync) === false) {
            return $repository;
        }
        $component = $sync->getObject();

        $this->pluginLogger->debug('Mapping object'.$repository->getValue('name'), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$componentMapping, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $forkedFrom = $repository->getValue('forked_from');
        if ($forkedFrom !== null && isset($publiccode['isBasedOn']) === false) {
            $publiccode['isBasedOn'] = $forkedFrom;
        }

        // Set developmentStatus obsolete when repository is archived.
        if ($repository->getValue('archived') === true) {
            $publiccode['developmentStatus'] = 'obsolete';
        }

        $componentArray = $this->mappingService->mapping($componentMapping, $publiccode);

        $component->hydrate($componentArray);
        // set the name
        $component->hydrate(['name' => key_exists('name', $publiccode) ? $publiccode['name'] : $repository->getValue('name'), 'url' => $repository]);

        $this->createApplicationSuite($publiccode, $component);
        $this->createMainCopyrightOwner($publiccode, $component);
        $this->createRepoOwner($publiccode, $component);

        // @TODO These to functions aren't working.
        // contracts and contacts are not set to the component
        // $component = $this->createContractors($publiccode, $component);
        // $component = $this->createContacts($publiccode, $component);
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        return $repository;

    }//end mapPubliccode()


}//end class
