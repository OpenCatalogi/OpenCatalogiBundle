<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\MappingService;
use App\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\ClientException;
use phpDocumentor\Reflection\Types\This;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\YamlEncoder;

class GithubApiService
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
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var RatingService
     */
    private RatingService $ratingService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var HydrationService
     */
    private HydrationService $hydrationService;


    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface
     * @param CallService            $callService      The Call Service
     * @param SynchronizationService $syncService      The Synchronisation Service
     * @param CacheService           $cacheService     The Cache Service
     * @param MappingService         $mappingService   The Mapping Service
     * @param RatingService          $ratingService    The Rating Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     * @param HydrationService       $hydrationService The Hydration Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        CacheService $cacheService,
        MappingService $mappingService,
        RatingService $ratingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        HydrationService $hydrationService
    ) {
        $this->entityManager    = $entityManager;
        $this->callService      = $callService;
        $this->syncService      = $syncService;
        $this->cacheService     = $cacheService;
        $this->mappingService   = $mappingService;
        $this->ratingService    = $ratingService;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->hydrationService = $hydrationService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * Check the auth of the github source.
     *
     * @param Source $source The given source to check the api key.
     *
     * @return bool|null If the api key is set or not.
     */
    public function checkGithubAuth(Source $source): ?bool
    {
        if ($source->getApiKey() === null) {
            $this->pluginLogger->error('No auth set for Source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return false;
        }//end if

        return true;

    }//end checkGithubAuth()


    /**
     * This function gets the publiccode file from the github user content.
     *
     * @param string $repositoryUrl The url of the repository
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function getGithubRepository(string $repositoryUrl, ?array $repositoryArray=null): ?ObjectEntity
    {
        $repositorySchema  = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');
        $repositoryMapping = $this->resourceService->getMapping('https://api.github.com/oc.githubRepository.mapping.json', 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false
            || $repositoryMapping instanceof Mapping === false
        ) {
            return $this->data;
        }//end if

        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($this->checkGithubAuth($source) === false
        ) {
            return null;
        }//end if

        // Find de sync by source and repository url.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);
        // @TODO: Check if there is already an object then we don't want to do anything.
        // if ($repositorySync->getObject() !== null) {
        // Hier willen we nu geen update doen.
        // return  $repositorySync->getObject();
        // }
        // Set the github repository mapping to the sync object.
        $repositorySync->setMapping($repositoryMapping);

        if ($repositoryArray === null) {
            // Get the repository from the github api.
            $repositoryArray = $this->getRepository($repositoryUrl, $source);
        }

        // Synchronize the github repository.
        $repositorySync = $this->syncService->synchronize($repositorySync, $repositoryArray);
        $this->entityManager->persist($repositorySync);
        $this->entityManager->flush();

        // Get the publiccode/opencatalogi files of the given repository.
        $dataArray = $this->getFilesFromRepo($repositoryUrl, $source);

        if ($dataArray !== null) {
            // Import the publiccode/opencatalogi files and connect it to the repository.
            $repository = $this->importRepoFiles($dataArray, $source, $repositorySync->getObject());
        }

        if (isset($repository) === false) {
            $repository = $repositorySync->getObject();
        }

        // Cleanup the repository.
        $repository = $this->cleanupRepository($repository);

        // Enrich the repository with component and/or organization.
        $repository = $this->enrichRepository($repositorySync->getObject(), $repositoryArray, $source);

        // Rate the component(s) of the repository.
        $repository = $this->ratingService->rateRepoComponents($repository, $source, $repositoryArray);

        // Return the repository object.
        return $repository;

    }//end getGithubRepository()


    /**
     * This function does a cleanup for the repository.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     * @param Source       $source          The github api source.
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null Return the repository
     */
    public function cleanupRepository(ObjectEntity $repository): ?ObjectEntity
    {
        // If the repository has one or less components return.
        if ($repository->getValue('components')->count() <= 1) {
            return null;
        }

        // Loop through the components and remove the component we created.
        foreach ($repository->getValue('components') as $component) {
            $componentSourceId = $component->getSynchronizations()->first()->getSourceId();

            // If the component source id is the same as the repository url remove the component.
            if ($componentSourceId === $repository->getValue('url')) {
                $this->entityManager->remove($component);
                $this->entityManager->flush();
            }
        }

        return $repository;

    }//end cleanupRepository()


    /**
     * This function enriches the repository with a organization.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     * @param Source       $source          The github api source.
     *
     * @throws GuzzleException
     */
    public function enrichWithOrganization(ObjectEntity $repository, array $repositoryArray, Source $source): void
    {
        $organizationSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');

        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $repositoryArray['owner']['html_url']);

        if ($organizationSync->getObject() === null) {
            $organizationMapping = $this->resourceService->getMapping('https://api.github.com/oc.githubOrganisation.mapping.json', 'open-catalogi/open-catalogi-bundle');

            $organizationSync->setMapping($organizationMapping);
            $organizationSync = $this->syncService->synchronize($organizationSync, $repositoryArray['owner']);
            $this->entityManager->persist($organizationSync);
        }

        $repository->hydrate(['organisation' => $organizationSync->getObject()]);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

    }//end enrichWithOrganization()


    /**
     * This function enriches the repository with a component.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     * @param Source       $source          The github api source.
     *
     * @throws GuzzleException
     */
    public function enrichWithComponent(ObjectEntity $repository, array $repositoryArray, Source $source): void
    {
        $componentSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');

        $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryArray['html_url']);
        $componentSync = $this->syncService->synchronize($componentSync, ['url' => $repository, 'name' => $repository->getValue('name')]);
        $this->entityManager->persist($componentSync);
        $this->entityManager->flush();

    }//end enrichWithComponent()


    /**
     * This function enriches the repository with a organization and/or component.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity The repository object
     */
    public function enrichRepository(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        // If there is no organization create one.
        if ($repository->getValue('organisation') === false) {
            $this->enrichWithOrganization($repository, $repositoryArray, $source);
        }

        // If there is no component create one.
        if ($repository->getValue('components')->count() === 0) {
            $this->enrichWithComponent($repository, $repositoryArray, $source);
        }

        // @TODO: enrich the null values with what we have.
        return $repository;

    }//end enrichRepository()


    /**
     * This function enriches the repository with a organization and/or component.
     *
     * @param ObjectEntity $organization    The organization object.
     * @param array        $repositoryArray The repository array from the github api call.
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity The repository object
     */
    public function getConnectedComponents(ObjectEntity $organization, array $opencatalogi, Source $source, array $opencatalogiArray): ObjectEntity
    {
        $repositorySchema    = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');
        $organizationSchema  = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');
        $organizationMapping = $this->resourceService->getMapping('https://api.github.com/oc.githubOrganisation.mapping.json', 'open-catalogi/open-catalogi-bundle');

        if (key_exists('softwareOwned', $opencatalogi) === true) {
            $ownedComponents = [];
            foreach ($opencatalogi['softwareOwned'] as $repositoryUrl) {
                $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

                // Get the object of the sync if there is one.
                if ($repositorySync->getObject() !== null) {
                    $repository = $repositorySync->getObject();
                }

                // Get the github repository from the given url if the object is null.
                if ($repositorySync->getObject() === null) {
                    $repository = $this->getGithubRepository($repositoryUrl);
                }

                // Set the components of the repository to the array.
                foreach ($repository->getValue('components') as $component) {
                    $ownedComponents[] = $component;
                }
            }

            $organization->hydrate(['owns' => $ownedComponents]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        if (key_exists('softwareSupported', $opencatalogi) === true) {
            $supportedComponents = [];
            foreach ($opencatalogi['softwareSupported'] as $supports) {
                if (key_exists('software', $supports) === false) {
                    continue;
                }

                $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $supports['software']);

                // Get the object of the sync if there is one.
                if ($repositorySync->getObject() !== null) {
                    $repository = $repositorySync->getObject();
                }

                // Get the github repository from the given url if the object is null.
                if ($repositorySync->getObject() === null) {
                    $repository = $this->getGithubRepository($supports['software']);
                }

                // Set the components of the repository
                foreach ($repository->getValue('components') as $component) {
                    $supportedComponents[] = $component;
                }
            }//end foreach

            $organization->hydrate(['supports' => $supportedComponents]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        if (key_exists('softwareUsed', $opencatalogi) === true) {
            $usedComponents = [];
            foreach ($opencatalogi['softwareUsed'] as $repositoryUrl) {
                $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

                // Get the object of the sync if there is one.
                if ($repositorySync->getObject() !== null) {
                    $repository = $repositorySync->getObject();
                }

                // Get the github repository from the given url if the object is null.
                if ($repositorySync->getObject() === null) {
                    $repository = $this->getGithubRepository($repositoryUrl);
                }

                // Set the components of the repository
                foreach ($repository->getValue('components') as $component) {
                    $usedComponents[] = $component;
                }
            }

            $organization->hydrate(['uses' => $usedComponents]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        if (key_exists('members', $opencatalogi) === true) {
            $members = [];
            foreach ($opencatalogi['members'] as $organizationUrl) {
                $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationUrl);

                if ($organizationSync->getObject() === null) {
                    // Do we want to get the organization from the repository
                    // $organizationName = \Safe\parse_url($organizationUrl)['path'];
                    // $organizationSync = $this->syncService->synchronize($organizationSync, ['github' => $organizationUrl, 'name' => $organizationName]);
                    //
                    // $members[] = $organizationSync->getObject();
                }

                if ($organizationSync->getObject() !== null) {
                    $members[] = $organizationSync->getObject();
                }
            }

            $organization->hydrate(['members' => $members]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        $this->entityManager->flush();

        return $organization;

    }//end getConnectedComponents()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array $dataArray An array with publiccode/opencatalogi files
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function handleOpencatalogiFile(array $opencatalogiArray, Source $source, ObjectEntity $repository): ?ObjectEntity
    {
        $opencatalogiMapping = $this->resourceService->getMapping('https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json', 'open-catalogi/open-catalogi-bundle');
        $organizationSchema  = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');
        if ($opencatalogiMapping instanceof Mapping === false
            || $organizationSchema instanceof Entity === false
        ) {
            return null;
        }

        // Get the ref query from the url. This way we can get the publiccode file with the raw.gitgubusercontent.
        $publiccodeUrlQuery = \Safe\parse_url($opencatalogiArray['url'])['query'];
        // Remove the ref= part of the query.
        $urlReference = explode('ref=', $publiccodeUrlQuery)[1];
        // Create the publiccode/opencatalogi url
        $opencatalogiUrl = "https://raw.githubusercontent.com/{$opencatalogiArray['repository']['full_name']}/{$urlReference}/{$opencatalogiArray['path']}";

        // Get the file from the usercontent or github api source.
        $opencatalogi = $this->getFileFromRawUserContent($opencatalogiUrl, $opencatalogiArray['git_url']);

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if (key_exists('publiccodeYmlVersion', $opencatalogi) === false) {
            return null;
        }

        $opencatalogi['github'] = $opencatalogiArray['repository']['owner']['html_url'];

        // Find the sync with the source and opencatalogi url.
        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $opencatalogiArray['repository']['owner']['html_url']);

        // Check the sha of the sync with the sha in the array.
        if ($this->syncService->doesShaMatch($organizationSync, $opencatalogiArray['sha']) === true) {
            $repository->setValue('organisation',  $organizationSync->getObject());

            $this->entityManager->persist($repository);
            $this->entityManager->flush();

            return $repository;
        }

        $organizationSync->setMapping($opencatalogiMapping);

        // Synchronize the organization with the opencatalogi file.
        $organizationSync = $this->syncService->synchronize($organizationSync, $opencatalogi, true);
        $this->entityManager->persist($organizationSync);
        $this->entityManager->flush();

        // Get the softwareSupported/softwareOwned/softwareUsed repositories.
        $organization = $this->getConnectedComponents($organizationSync->getObject(), $opencatalogi, $source, $opencatalogiArray);

        // Set the organization to the repository.
        $repository->hydrate(['organisation' => $organization]);

        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;

    }//end handleOpencatalogiFile()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array $dataArray An array with publiccode/opencatalogi files
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function handlePubliccodeFile(array $publiccodeArray, Source $source, ObjectEntity $repository): ?ObjectEntity
    {
        $publiccodeMapping = $this->resourceService->getMapping('https://api.github.com/oc.githubPubliccodeComponent.mapping.json', 'open-catalogi/open-catalogi-bundle');
        $componentSchema   = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');
        if ($publiccodeMapping instanceof Mapping === false
            || $componentSchema instanceof Entity === false
        ) {
            return null;
        }

        // Get the ref query from the url. This way we can get the publiccode file with the raw.gitgubusercontent.
        $publiccodeUrlQuery = \Safe\parse_url($publiccodeArray['url'])['query'];
        // Remove the ref= part of the query.
        $urlReference = explode('ref=', $publiccodeUrlQuery)[1];
        // Create the publiccode/opencatalogi url
        $publiccodeUrl = "https://raw.githubusercontent.com/{$publiccodeArray['repository']['full_name']}/{$urlReference}/{$publiccodeArray['path']}";

        $this->pluginLogger->info('Map the publiccode file with url: '.$publiccodeUrl);

        // Get the file from the usercontent or github api source
        $publiccode = $this->getFileFromRawUserContent($publiccodeUrl, $publiccodeArray['git_url']);

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if (key_exists('publiccodeYmlVersion', $publiccode) === false) {
            return null;
        }

        // Get the forked_from from the repository.
        $forkedFrom = $repository->getValue('forked_from');
        // Set the isBasedOn.
        if ($forkedFrom !== null && isset($publiccode['isBasedOn']) === false) {
            $publiccode['isBasedOn'] = $forkedFrom;
        }

        // Set developmentStatus obsolete when repository is archived.
        if ($repository->getValue('archived') === true) {
            $publiccode['developmentStatus'] = 'obsolete';
        }

        // @TODO: Check the sha of the sync with the sha in the array
        // Map the publiccode file.
        $componentArray = $this->mappingService->mapping($publiccodeMapping, $publiccode);

        // Set the publiccode url as _sourceId.
        $componentArray['_sourceId'] = $publiccodeUrl;

        // Find the sync with the source and publiccode url.
        $component = $this->hydrationService->searchAndReplaceSynchronizations($componentArray, $source, $componentSchema, true, true);

        $component->hydrate(['url' => $repository]);
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        return $repository;

    }//end handlePubliccodeFile()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array $dataArray An array with publiccode/opencatalogi files
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function importRepoFiles(array $dataArray, Source $source, ObjectEntity $repository): ?ObjectEntity
    {
        $opencatalogiNames = [
            'opencatalogi.yaml',
            'opencatalogi.yml',
        ];

        $publiccodeNames = [
            'publiccode.yaml',
            'publiccode.yml',
        ];

        // Loop through the array of publiccode/opencatalogi files.
        $opencatalogiUrls = [];
        $publiccodeUrls   = [];
        foreach ($dataArray as $item) {
            // Check if the item name is the same as the openCatalogiNames array.
            // If so go the the function for the opencatalogi file.
            if (in_array($item['name'], $opencatalogiNames) === true) {
                $this->pluginLogger->info('The item is a opencatalogi file.');
                $repository = $this->handleOpencatalogiFile($item, $source, $repository);
            }

            // Check if the item name is the same as the publiccodeNames array.
            // If so go the the function for the publiccode file.
            if (in_array($item['name'], $publiccodeNames) === true) {
                $this->pluginLogger->info('The item is a publiccode file.');
                $repository = $this->handlePubliccodeFile($item, $source, $repository);
            }
        }

        return $repository;

    }//end importRepoFiles()


    /**
     * This function gets the publiccode/opencatalogi file from the github gitub api.
     *
     * @param string $repositoryUrl The url of the repository
     * @param string $gitUrl        The git url of the repository
     *
     * @throws GuzzleException
     *
     * @return array|null
     */
    public function getFileFromRawGithubApi(string $gitUrl): ?array
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return $this->data;
        }

        // Get the path from the git url to make the call.
        $endpoint = \Safe\parse_url($gitUrl)['path'];

        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$gitUrl.' '.$e->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        // Decode the response from the call.
        $response = $this->callService->decodeResponse($source, $response, 'application/json');

        // Check if there is a key content. This is the base64 of the file.
        if (key_exists('content', $response) === false) {
            return null;
        }

        // Decode the base64 string.
        $content = \Safe\base64_decode($response['content']);

        $yamlEncoder = new YamlEncoder();

        // Decode the string.
        return $yamlEncoder->decode($content, 'yaml');

    }//end getFileFromRawGithubApi()


    /**
     * This function gets the publiccode/opencatalogi file from the github user content.
     *
     * @param string $repositoryUrl The url of the repository
     * @param string $gitUrl        The git url of the repository
     *
     * @throws GuzzleException
     *
     * @return array|null
     */
    public function getFileFromRawUserContent(string $repositoryUrl, string $gitUrl): ?array
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return $this->data;
        }

        // Get the path from the url to make the call.
        $endpoint = \Safe\parse_url($repositoryUrl)['path'];
        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$repositoryUrl.' '.$e->getMessage());
        }

        if (isset($response) === false) {
            // Call the github api for the publiccode/opencatalogi files.
            return $this->getFileFromRawGithubApi($gitUrl);
        }

        return $this->callService->decodeResponse($source, $response, 'text/yaml');

    }//end getFileFromRawUserContent()


    /**
     * Get a repository from github with the given repository url
     *
     * @param string $repositoryUrl The url of the repository.
     * @param Source $source        The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getRepository(string $repositoryUrl, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository with url'.$repositoryUrl.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $path = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');

        // Get the repository from github with the path as endpoint.
        try {
            $response = $this->callService->call($source, '/repos/'.$path);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        // Check if we have a response if not then return null.
        if (isset($response) === false) {
            return null;
        }

        // Decode the response with the source.
        $repository = $this->callService->decodeResponse($source, $response, 'application/json');

        // If we cannot decode the response then return null.
        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repository with url: '.$repositoryUrl.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getRepository()


    /**
     * Get the publiccode/opencatalogi files of the given repository
     *
     * @param string $repositoryUrl The url of the repository.
     * @param Source $source        The source to sync from.
     *
     * @return array|null The publiccode/opencatalogi files as array.
     */
    public function getFilesFromRepo(string $repositoryUrl, Source $source): ?array
    {
        $path = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');

        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:publiccode filename:opencatalogi extension:yaml extension:yml repo:{$path}"];

        // Find the publiccode.yaml file(s).
        try {
            $response = $this->callService->call($source, '/search/code', 'GET', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/search/code'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        $dataArray = $this->callService->decodeResponse($source, $response);

        $this->pluginLogger->debug('Found '.$dataArray['total_count'].' publiccode/opencatalogi file(s).', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $dataArray['items'];

    }//end getFilesFromRepo()


    /**
     * Get the publiccode/opencatalogi files of the given repository
     *
     * @param string $repositoryUrl The url of the repository.
     * @param Source $source        The source to sync from.
     *
     * @return array|null The publiccode/opencatalogi files as array.
     */
    public function getGithubFiles(): ?array
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');

        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:publiccode filename:opencatalogi extension:yaml extension:yml"];

        // Find the publiccode.yaml file(s).
        try {
            $response = $this->callService->getAllResults($source, '/search/code', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/search/code'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        $this->pluginLogger->debug('Found '.count($response).' publiccode/opencatalogi file(s).', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $response;

    }//end getGithubFiles()


    /**
     * Get a repository through the repositories of the given source
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getOrganisation(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/repos/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repository = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repository with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getOrganisation()


    /**
     * Get a repository through the repositories of the given source
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getOrganisationRepos(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/repos/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repository = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repository with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getOrganisationRepos()


    /**
     * This function checks if a github repository is public.
     *
     * @param string $slug The slug of the repository
     *
     * @return bool Boolean for if the repository is public.
     */
    public function checkPublicRepository(string $slug): bool
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');

        $slug = preg_replace('/^https:\/\/github.com\//', '', $slug);
        $slug = rtrim($slug, '/');

        try {
            $response   = $this->callService->call($source, '/repos/'.$slug);
            $repository = $this->callService->decodeResponse($source, $response);
        } catch (Exception $exception) {
            // @TODO Monolog ?
            $this->pluginLogger->error("Exception while checking if public repository: {$exception->getMessage()}");

            return false;
        }

        return $repository['private'] === false;

    }//end checkPublicRepository()


}//end class
