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

/**
 *  This class handles the interaction with the github api source.
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class GithubApiService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService $callService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService $syncService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService $mappingService
     */
    private MappingService $mappingService;

    /**
     * @var RatingService $ratingService
     */
    private RatingService $ratingService;

    /**
     * @var LoggerInterface $pluginLogger
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService $resourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var GitlabApiService $gitlabApiService
     */
    private GitlabApiService $gitlabApiService;

    /**
     * @var PubliccodeService $publiccodeService
     */
    private PubliccodeService $publiccodeService;

    /**
     * @var OpenCatalogiService $openCatalogiService
     */
    private OpenCatalogiService $openCatalogiService;

    /**
     * @var array $configuration
     */
    private array $configuration;

    /**
     * @var array $data
     */
    private array $data;


    /**
     * @param EntityManagerInterface $entityManager     The Entity Manager Interface
     * @param CallService            $callService       The Call Service
     * @param SynchronizationService $syncService       The Synchronisation Service
     * @param MappingService         $mappingService    The Mapping Service
     * @param RatingService          $ratingService     The Rating Service.
     * @param LoggerInterface        $pluginLogger      The plugin version of the logger interface
     * @param GatewayResourceService $resourceService   The Gateway Resource Service.
     * @param GitlabApiService       $gitlabApiService  The Gitlab API Service
     * @param PubliccodeService      $publiccodeService The publiccode service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        RatingService $ratingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GitlabApiService $gitlabApiService,
        PubliccodeService $publiccodeService,
        OpenCatalogiService $openCatalogiService
    ) {
        $this->entityManager       = $entityManager;
        $this->callService         = $callService;
        $this->syncService         = $syncService;
        $this->mappingService      = $mappingService;
        $this->ratingService       = $ratingService;
        $this->pluginLogger        = $pluginLogger;
        $this->resourceService     = $resourceService;
        $this->gitlabApiService    = $gitlabApiService;
        $this->publiccodeService   = $publiccodeService;
        $this->openCatalogiService = $openCatalogiService;

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
     * Override configuration from other services.
     *
     * @param array $configuration The new configuration array.
     *
     * @return void
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;

    }//end setConfiguration()


    /**
     * This function gets a github repository and enriches it.
     *
     * @param string     $repositoryUrl   The url of the repository
     * @param array|null $repositoryArray The repository array from the github api.
     *
     * @return ObjectEntity|null The imported github repository.
     * @throws Exception
     */
    public function getGithubRepository(string $repositoryUrl, ?array $repositoryArray=null): ?ObjectEntity
    {
        $repositorySchema  = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $repositoryMapping = $this->resourceService->getMapping($this->configuration['repositoryMapping'], 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false
            || $repositoryMapping instanceof Mapping === false
        ) {
            return null;
        }//end if

        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($this->checkGithubAuth($source) === false
        ) {
            return null;
        }//end if

        // Find de sync by source and repository url.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

        // Set the github repository mapping to the sync object.
        $repositorySync->setMapping($repositoryMapping);

        if ($repositoryArray === null) {
            // Get the repository from the github api.
            $repositoryArray = $this->getRepository($repositoryUrl, $source);
        }

        if ($repositoryArray === null) {
            return null;
        }

        // Synchronize the github repository.
        $repositorySync = $this->syncService->synchronize($repositorySync, $repositoryArray);
        $this->entityManager->persist($repositorySync);
        $this->entityManager->flush();

        $repository = $repositorySync->getObject();

        // Get the publiccode/opencatalogi files of the given repository.
        $path = trim(\Safe\parse_url($repositoryUrl)['path'], '/');
        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:publiccode filename:opencatalogi extension:yaml extension:yml repo:{$path}"];
        $dataArray            = $this->getFilesFromRepo($source, $queryConfig);
        if ($dataArray !== null) {
            // Import the publiccode/opencatalogi files and connect it to the repository.
            $repository = $this->importRepoFiles($dataArray, $source, $repository, $repositoryArray);
        }

        // Cleanup the repository.
        $repository = $this->cleanupRepository($repository);

        // Enrich the repository with component and/or organization.
        $repository = $this->enrichRepository($repository, $repositoryArray, $source);

        // Rate the component(s) of the repository.
        // Return the repository object.
        $this->ratingService->setConfiguration($this->configuration);
        return $this->ratingService->rateRepoComponents($repository, $source, $repositoryArray);

    }//end getGithubRepository()


    /**
     * This function does a cleanup for the repository.
     *
     * @param ObjectEntity $repository The repository object.
     *
     * @return ObjectEntity|null Return the repository
     */
    public function cleanupRepository(ObjectEntity $repository): ?ObjectEntity
    {
        // If the repository has one or less components return.
        if ($repository->getValue('components') === false
            || $repository->getValue('components')->count() <= 1
        ) {
            return $repository;
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
     * @param array        $repositoryArray The repository array from the github api.
     * @param Source       $source          The github api source.
     *
     * @return ObjectEntity The updated repository with organization.
     */
    public function enrichWithOrganization(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($organizationSchema instanceof Entity === false) {
            return $repository;
        }

        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $repositoryArray['owner']['html_url']);

        if ($organizationSync->getObject() === null) {
            $organizationMapping = $this->resourceService->getMapping($this->configuration['organizationMapping'], 'open-catalogi/open-catalogi-bundle');

            $organizationSync->setMapping($organizationMapping);
            $organizationSync = $this->syncService->synchronize($organizationSync, $repositoryArray['owner']);
            $this->entityManager->persist($organizationSync);
        }

        $repository->hydrate(['organisation' => $organizationSync->getObject()]);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;

    }//end enrichWithOrganization()


    /**
     * This function enriches the repository with a component.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     * @param Source       $source          The github api source.
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity The updated repository with component.
     */
    public function enrichWithComponent(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $componentSchema = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($componentSchema instanceof Entity === false) {
            return $repository;
        }

        $forkedFrom = $repository->getValue('forked_from');
        // Set the isBasedOn.
        if ($forkedFrom !== null) {
            $data['isBasedOn'] = $forkedFrom;
        }

        // Set developmentStatus obsolete when repository is archived.
        if ($repository->getValue('archived') === true) {
            $data['developmentStatus'] = 'obsolete';
        }

        $data = [
            'name' => $repository->getValue('name'),
            'url'  => $repository,
        ];

        $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryArray['html_url']);
        $componentSync = $this->syncService->synchronize($componentSync, $data);
        $this->entityManager->persist($componentSync);
        $this->entityManager->flush();

        return $repository;

    }//end enrichWithComponent()


    /**
     * This function enriches the repository with a organization and/or component.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     *
     * @return ObjectEntity The updated repository object with organization and component.
     * @throws GuzzleException
     */
    public function enrichRepository(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        // If there is no organization create one.
        if ($repository->getValue('organisation') === false) {
            $repository = $this->enrichWithOrganization($repository, $repositoryArray, $source);
        }

        // If there is no component create one.
        if ($repository->getValue('components')->count() === 0) {
            $repository = $this->enrichWithComponent($repository, $repositoryArray, $source);
        }

        // @TODO: enrich the null values with what we have.
        return $repository;

    }//end enrichRepository()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array $item An array with opencatalogi file.
     *
     * @return array|null An array with the opencatalogi => imported opencatalogi file /sourceId => The sourceId /sha => The sha (used as sourceId)
     * @throws Exception
     */
    public function importOpenCatalogiFile(array $item): ?array
    {
        // Get the ref query from the url. This way we can get the opencatalogi file with the raw.gitgubusercontent.
        $opencatalogiUrlQuery = \Safe\parse_url($item['url'])['query'];
        // Remove the ref= part of the query.
        $urlReference = explode('ref=', $opencatalogiUrlQuery)[1];
        // Create the publiccode/opencatalogi url
        $opencatalogiUrl = "https://raw.githubusercontent.com/{$item['repository']['full_name']}/{$urlReference}/{$item['path']}";

        // Create an unique sourceId for every opencatalogi that doesn't change.
        // The urlReference and the git_url changes when the file changes.
        $sourceId = "https://raw.githubusercontent.com/{$item['repository']['full_name']}/{$item['path']}";

        // Get the file from the usercontent or github api source.
        $opencatalogi = $this->getFileFromRawUserContent($opencatalogiUrl, $item['git_url']);
        if ($opencatalogi === null) {
            return null;
        }

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if ($opencatalogi === null
            || $opencatalogi !== null
            && key_exists('publiccodeYmlVersion', $opencatalogi) === false
        ) {
            return null;
        }

        // TODO: check if the opencatalogiUrl is needed.
        $opencatalogi['opencatalogiRepo'] = $opencatalogiUrl;

        return [
            'opencatalogi' => $opencatalogi,
            'sourceId'     => $sourceId,
            'sha'          => $urlReference,
        ];

    }//end importOpenCatalogiFile()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array $item An array with opencatalogi file.
     *
     * @return array|null An array with the publiccode => imported publiccode file /sourceId => The sourceId /sha => The sha
     */
    public function importPubliccodeFile(array $item): ?array
    {
        // Get the ref query from the url. This way we can get the publiccode file with the raw.gitgubusercontent.
        $publiccodeUrlQuery = \Safe\parse_url($item['url'])['query'];
        // Remove the ref= part of the query.
        $urlReference = explode('ref=', $publiccodeUrlQuery)[1];
        // Create the publiccode/opencatalogi url
        $publiccodeUrl = "https://raw.githubusercontent.com/{$item['repository']['full_name']}/{$urlReference}/{$item['path']}";

        // Create an unique sourceId for every publiccode that doesn't change.
        // The urlReference and the git_url changes when the file changes.
        $sourceId = "https://raw.githubusercontent.com/{$item['repository']['full_name']}/{$item['path']}";

        $this->pluginLogger->info('Map the publiccode file with url: '.$publiccodeUrl.' and source id: '.$sourceId);

        // Get the file from the usercontent or github api source
        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        $publiccode = $this->getFileFromRawUserContent($publiccodeUrl, $item['git_url']);
        if ($publiccode === null || $publiccode !== null && key_exists('publiccodeYmlVersion', $publiccode) === false) {
            return null;
        }

        return [
            'publiccode' => $publiccode,
            'sourceId'   => $sourceId,
            'sha'        => $urlReference,
        ];

    }//end importPubliccodeFile()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $dataArray       An array with publiccode/opencatalogi files.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array.
     *
     * @return ObjectEntity|null The updated repository with organization and/or component with the opencatalogi and/or publiccode file(s).
     * @throws Exception
     */
    public function importRepoFiles(array $dataArray, Source $source, ObjectEntity $repository, array $repositoryArray): ?ObjectEntity
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
        foreach ($dataArray as $item) {
            // Check if the item name is the same as the openCatalogiNames array.
            // If so go the the function for the opencatalogi file.
            if (in_array($item['name'], $opencatalogiNames) === true) {
                $this->pluginLogger->info('The item is a opencatalogi file.');

                // Import the opencatalogi file and get the needed data. With the keys: opencatalogi/sourceId/sha.
                $data = $this->importOpenCatalogiFile($item);
                if ($data === null) {
                    continue;
                }

                // Handle the opencatalogi file.
                $this->openCatalogiService->setConfiguration($this->configuration);
                $repository = $this->openCatalogiService->handleOpencatalogiFile($item, $source, $repository, $data, $repositoryArray['owner']);

                continue;
            }//end if

            // Check if the item name is the same as the publiccodeNames array.
            // If so go the the function for the publiccode file.
            if (in_array($item['name'], $publiccodeNames) === true) {
                $this->pluginLogger->info('The item is a publiccode file.');

                // Import the publiccode file and get the needed data. With the keys: publiccode/sourceId/sha.
                $data = $this->importPubliccodeFile($item);

                // Handle the publiccode file.
                $this->publiccodeService->setConfiguration($this->configuration);
                $repository = $this->publiccodeService->handlePubliccodeFile($item, $source, $repository, $data, $repositoryArray);
            }//end if
        }//end foreach

        return $repository;

    }//end importRepoFiles()


    /**
     * This function gets the publiccode/opencatalogi file from the github gitub api.
     *
     * @param string $gitUrl The git url of the repository
     *
     * @return array|null The file content of the github api.
     */
    public function getFileFromGithubApi(string $gitUrl): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return null;
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
        try {
            $decodedResponse = $this->callService->decodeResponse($source, $response, 'application/json');
        } catch (Exception $e) {
            $this->pluginLogger->error($e->getMessage());
        }

        // Check if there is a key content. This is the base64 of the file.
        if (isset($decodedResponse) === false
            && key_exists('content', $response) === false
        ) {
            return null;
        }

        // Decode the base64 string.
        $content = \Safe\base64_decode($response['content']);

        $yamlEncoder = new YamlEncoder();

        // Decode the string.
        return $yamlEncoder->decode($content, 'yaml');

    }//end getFileFromGithubApi()


    /**
     * This function gets the publiccode/opencatalogi file from the github user content.
     *
     * @param string      $repositoryUrl The url of the repository
     * @param string|null $gitUrl        The git url of the repository
     *
     * @return array|null The opencatalogi or publiccode file from the raw.usercontent or github api source.
     * @throws GuzzleException
     */
    public function getFileFromRawUserContent(string $repositoryUrl, ?string $gitUrl=null): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return null;
        }

        // Get the path from the url to make the call.
        $endpoint = \Safe\parse_url($repositoryUrl)['path'];
        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$repositoryUrl.' '.$e->getMessage());
        }

        if (isset($response) === false
            && $gitUrl !== null
        ) {
            // Call the github api for the publiccode/opencatalogi files.
            return $this->getFileFromGithubApi($gitUrl);
        }

        if (isset($response) === true) {
            try {
                $decodedResponse = $this->callService->decodeResponse($source, $response, 'text/yaml');
            } catch (Exception $e) {
                $this->pluginLogger->error('Error decoding response of repo: '.$repositoryUrl.' '.$e->getMessage());
            }

            if (isset($decodedResponse) === true
                && is_array($decodedResponse) === true
            ) {
                return $decodedResponse;
            }
        }

        return null;

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
        try {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        // If we cannot decode the response then return null.
        if (isset($repository) === true
            && $repository === null
        ) {
            $this->pluginLogger->error('Could not find a repository with url: '.$repositoryUrl.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getRepository()


    /**
     * Get the publiccode/opencatalogi files of the given repository
     *
     * @param Source $source      The source to sync from.
     * @param array  $queryConfig The query config of the call.
     *
     * @return array|null The publiccode/opencatalogi files as array.
     */
    public function getFilesFromRepo(Source $source, array $queryConfig): ?array
    {
        // Find the publiccode.yaml file(s).
        try {
            $response = $this->callService->call($source, '/search/code', 'GET', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/search/code'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        try {
            $dataArray = $this->callService->decodeResponse($source, $response);
        } catch (Exception $exception) {
            $this->pluginLogger->error($exception->getMessage());
        }

        if (isset($dataArray) === false) {
            return null;
        }

        $this->pluginLogger->debug('Found '.$dataArray['total_count'].' publiccode/opencatalogi file(s).', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $dataArray['items'];

    }//end getFilesFromRepo()


    /**
     * Get the publiccode/opencatalogi files of the given repository
     *
     * @return array|null The publiccode/opencatalogi files as array.
     */
    public function getGithubFiles(): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');

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
     * Get a organization with type Organization from the github api.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
     */
    public function getOrganization(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting organization '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/orgs/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $organization = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($organization === null) {
            $this->pluginLogger->error('Could not find a organization with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $organization;

    }//end getOrganization()


    /**
     * Get a repositories of the organization from the github api.
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization repositories as array.
     */
    public function getOrganizationRepos(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/orgs'.$name.'/repos');
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repositories = \Safe\json_decode($response->getBody()->getContents(), true);

        if (empty($repositories) === true
            || $repositories === null
        ) {
            $this->pluginLogger->error('Could not find the repositories of the organization with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repositories;

    }//end getOrganizationRepos()


    /**
     * Get a organization with type Organization from the github api.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported user as array.
     */
    public function getUser(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting user '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/users/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $organization = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($organization === null) {
            $this->pluginLogger->error('Could not find a user with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $organization;

    }//end getUser()


    /**
     * Get a repositories of the organization with type user from the github api.
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported user repositories as array.
     */
    public function getUserRepos(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/users'.$name.'/repos');
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repositories = \Safe\json_decode($response->getBody()->getContents(), true);

        if (empty($repositories) === true
            || $repositories === null
        ) {
            $this->pluginLogger->error('Could not find the repositories of the user with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repositories;

    }//end getUserRepos()


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

        $source           = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        if ($source instanceof Source === false && $repositorySchema instanceof Entity === false) {
            return $this->data;
        }

        // If we have one repository.
        if ($repositoryId !== null) {
        }

        // If we have all repositories.
        if ($repositoryId === null) {
            // Get all the repositories with a publiccode/opencatalogi file.
            $repositories = $this->getGithubFiles();

            $response = [];
            foreach ($repositories as $repositoryArray) {
                if (key_exists('repository', $repositoryArray) === false
                    || key_exists('html_url', $repositoryArray['repository']) === false
                ) {
                    continue;
                }

                $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryArray['repository']['html_url']);

                if ($repositorySync->getObject() !== null) {
                    $repository = $repositorySync->getObject();
                }

                if ($repositorySync->getObject() === null) {
                    $this->entityManager->remove($repositorySync);
                    $this->entityManager->flush();
                    $repository = $this->getGithubRepository($repositoryArray['repository']['html_url'], $repositoryArray['repository']);
                }

                if ($repository !== null) {
                    $response[] = $repository->toArray();
                }
            }//end foreach

            if (isset($response) === true) {
                $this->data['response'] = new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
            }
        }//end if

        return $this->data;

    }//end findGithubRepositories()


}//end class
