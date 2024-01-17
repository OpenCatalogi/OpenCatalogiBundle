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

class GitlabApiService
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
     * @param EntityManagerInterface $entityManager       The Entity Manager Interface
     * @param CallService            $callService         The Call Service
     * @param SynchronizationService $syncService         The Synchronisation Service
     * @param MappingService         $mappingService      The Mapping Service
     * @param RatingService          $ratingService       The Rating Service.
     * @param LoggerInterface        $pluginLogger        The plugin version of the logger interface
     * @param GatewayResourceService $resourceService     The Gateway Resource Service.
     * @param PubliccodeService      $publiccodeService   The publiccode service
     * @param OpenCatalogiService    $openCatalogiService The opencatalogi service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        RatingService $ratingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
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
        $this->publiccodeService   = $publiccodeService;
        $this->openCatalogiService = $openCatalogiService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * Check the auth of the gitlab source.
     *
     * @param Source $source The given source to check the api key.
     *
     * @return bool|null If the api key is set or not.
     */
    public function checkGitlabAuth(Source $source): ?bool
    {
        if ($source->getApiKey() === null) {
            $this->pluginLogger->error('No auth set for Source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return false;
        }//end if

        return true;

    }//end checkGitlabAuth()


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
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source       $source          The source to sync from.
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array.
     * @param array        $tree            The tree of the repository.
     *
     * @return ObjectEntity|null The updated repositories with the opencatalogi and publiccode file
     */
    public function importOpenCatalogiFile(Source $source, ObjectEntity $repository, array $repositoryArray, array $directory): ?array
    {
        $this->pluginLogger->info('The item is a opencatalogi file.', ['open-catalogi/open-catalogi-bundle']);

        // Get the opencatalogi file from the repository directory.
        $opencatalogi = $this->getTheFileContent($source, $repositoryArray, $directory);

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if ($opencatalogi === null
            || $opencatalogi !== null
            && key_exists('publiccodeYmlVersion', $opencatalogi) === false
        ) {
            return $repository;
        }

        // TODO: Get sha from directory.
        // Set the endpoint of the getTheFileContent function as opencatalogiRepo, so it can be found in the enrichOrganizationService.
        $opencatalogi['opencatalogiRepo'] = $source->getLocation().'/api/v4/projects/'.$repositoryArray['id'].'/repository/files/'.$directory['path'].'?ref='.$repositoryArray['default_branch'];

        return $opencatalogi;
    }

    /**
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source       $source          The source to sync from.
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array.
     * @param array        $directory            The directory of the repository.
     *
     * @return ObjectEntity The updated repositories with the opencatalogi and publiccode file
     */
    public function importRepoFiles(Source $source, ObjectEntity $repository, array $repositoryArray, array $directory): ObjectEntity
    {
        $opencatalogiNames = [
            'opencatalogi.yaml',
            'opencatalogi.yml',
        ];

        $publiccodeNames = [
            'publiccode.yml',
            'publiccode.yaml',
        ];

        // If so go the the function for the opencatalogi file.
        if (in_array($directory['name'], $opencatalogiNames) === true
            && $directory['type'] === 'blob'
        ) {
            // Get the opencatalogi file and set the opencatalogiRepo.
            $opencatalogi = $this->importOpenCatalogiFile($source, $repository, $repositoryArray, $directory);

            // Set the data array with publiccode, sourceId and sha.
            $data = ['publiccode' => $opencatalogi, 'sourceId' => $directory['id'], 'sha' => $directory['id']];

            // Handle the opencatalogi file.
            $this->openCatalogiService->setConfiguration($this->configuration);
            $repository = $this->openCatalogiService->handleOpencatalogiFile($source, $repository, $data, $repositoryArray['namespace']);
        }//end if

        // TODO: now only checks in the root of the repo. Also check if there are multiple files.
        // Check if the publiccode file is in the root of the tree.
        // If so go the the function for the publiccode file.
        if (in_array($directory['name'], $publiccodeNames) === true
            && $directory['type'] === 'blob'
        ) {
            $this->pluginLogger->info('The item is a publiccode file. Directory id is: '.$directory['id'], ['open-catalogi/open-catalogi-bundle']);

            // Get the publiccode file from the repository directory.
            $publiccode = $this->getTheFileContent($source, $repositoryArray, $directory);
            if ($publiccode === null && $publiccode !== null && key_exists('publiccodeYmlVersion', $publiccode) === false) {
                return $repository;
            }

            $data = [
                'publiccode' => $publiccode,
                'sourceId' => $directory['id'],
                'sha' => $directory['id']
            ];

            // TODO: Get sha from directory.
            $this->publiccodeService->setConfiguration($this->configuration);
            $repository = $this->publiccodeService->handlePubliccodeFile($directory, $source, $repository, $data, $repositoryArray);
        }

        return $repository;

    }//end importRepoFiles()


    /**
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source $source          The source to sync from.
     * @param array  $repositoryArray The repository array.
     * @param array  $directory       The directory where the file is located.
     *
     * @return array|null The file content.
     */
    public function getTheFileContent(Source $source, array $repositoryArray, array $directory): ?array
    {
        $queryConfig['query'] = ['ref' => $repositoryArray['default_branch']];
        try {
            $response = $this->callService->call($source, '/projects/'.$repositoryArray['id'].'/repository/files/'.$directory['path'], 'GET', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error("Error found trying to fetch {$source->getLocation()}/projects/{$repositoryArray['id']}/repository/files/{$directory['path']}. {$exception->getMessage()}");
        }

        if (isset($response) === false) {
            return null;
        }

        $publiccodeArray = $this->callService->decodeResponse($source, $response);

        $content = \Safe\base64_decode($publiccodeArray['content']);

        $yamlEncoder = new YamlEncoder();

        // Decode the string.
        return $yamlEncoder->decode($content, 'yaml');

    }//end getTheFileContent()


    /**
     * Get the tree of the repository. /projects/{organization}/{repository}
     *
     * @param Source $source          The source to sync from.
     * @param array  $repositoryArray The repository array.

     * @return array|null The tree of the repository.
     */
    public function getRepoTreeFromGitlabApi(Source $source, array $repositoryArray): ?array
    {
        // Find the gitlab repository from the /projects/{organization}/{repository} endpoint.
        try {
            $response = $this->callService->call($source, '/projects/'.$repositoryArray['id'].'/repository/tree', 'GET');
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/projects/'.$repositoryArray['id'].'/repository/tree'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        return $this->callService->decodeResponse($source, $response);

    }//end getRepoTreeFromGitlabApi()


    /**
     * Get the given gitlab repository from the /api/v4/projects/{path} endpoint.
     *
     * @param Source $source        The source to sync from.
     * @param string $repositoryUrl The repository url.
     *
     * @return array|null The repository from gitlab.
     */
    public function getGitlabRepoFromSource(Source $source, string $repositoryUrl): ?array
    {
        // Parse the repository url.
        $path = \Safe\parse_url($repositoryUrl)['path'];
        // Unset the first / from the path.
        $path = trim($path, '/');
        // Url encode the path of the repository.
        $path = urlencode($path);

        // Find the gitlab repository from the /projects/{organization}/{repository} endpoint.
        try {
            $response = $this->callService->call($source, '/projects/'.$path, 'GET');
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/projects/'.$path.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        return $this->callService->decodeResponse($source, $response);

    }//end getGitlabRepoFromSource()


    /**
     * This function does a cleanup for the repository.
     *
     * @param ObjectEntity $repository The repository object.
     *
     * @return ObjectEntity|null The (updated) repository object
     */
    public function cleanupRepository(ObjectEntity $repository): ?ObjectEntity
    {
        // If the repository has one or less components return.
        if ($repository->getValue('components')->count() <= 1) {
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
     * This function enriches the repository with an organization.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api.
     * @param Source       $source          The github api source.
     *
     * @return ObjectEntity The updated repository with an organization.
     */
    public function enrichWithOrganization(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $repositoryArray['namespace']['web_url']);

        if ($organizationSync->getObject() === null) {
            $organizationMapping = $this->resourceService->getMapping('https://api.github.com/oc.gitlabOrganization.mapping.json', 'open-catalogi/open-catalogi-bundle');
            $dataArray           = $this->mappingService->mapping($organizationMapping, $repositoryArray['namespace']);

            // If the kind of the namespace is group set the type to organization.
            if ($repositoryArray['namespace']['kind'] === 'group') {
                $dataArray['type'] = 'Organization';
            }

            // If the kind of the namespace is user set the type to user.
            if ($repositoryArray['namespace']['kind'] === 'user') {
                $dataArray['type'] = 'User';
            }

            // Handle the avatar_url from the namespace.
            if (key_exists('logo', $dataArray) === true) {
                $dataArray['logo'] = $this->publiccodeService->handleLogo($dataArray, $source, $repository->getValue('url'), $repositoryArray['id']);
            }

            $organizationSync = $this->syncService->synchronize($organizationSync, $dataArray);
            $this->entityManager->persist($organizationSync);
        }//end if

        $repository->hydrate(['organisation' => $organizationSync->getObject(), 'type' => 'Organization']);
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
     * @return ObjectEntity The updated repository with a component.
     */
    public function enrichWithComponent(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $componentSchema = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');

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

        $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryArray['web_url']);
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
     * @param Source $source The given gitlab or github source.
     *
     * @return ObjectEntity The updated repository object.
     *
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

        return $repository;

    }//end enrichRepository()


    /**
     * This function cleans the gitlab url.
     *
     * @param string $repositoryUrl The url of the repository
     *
     * @return string|null The clean repository url.
     */
    public function cleanUrl(string $repositoryUrl): ?string
    {
        // Parse the url from the publiccode file to get the host and path.
        $parsedUrl = \Safe\parse_url($repositoryUrl);
        $domain    = $parsedUrl['host'];
        $scheme    = $parsedUrl['scheme'];

        // Check if the path is set.
        if (key_exists('path', $parsedUrl) === false
            || $parsedUrl['path'] === null
        ) {
            $this->pluginLogger->error('The given repository url is not a valid gitlab url. '.$repositoryUrl, ['open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $path = trim($parsedUrl['path'], '/');

        // Unset the .git from the url path if given.
        if (str_contains($path, '.git') === true) {
            $this->pluginLogger->info('Unset the .git from the path: '.$path, ['open-catalogi/open-catalogi-bundle']);

            // Return the url without the .git.
            return $scheme.'://'.$domain.'/'.explode('.git', $path)[0];
        }

        // TODO: check for other ways for the url.
        // Nothing needs to be unset, return the url as given.
        return $repositoryUrl;

    }//end cleanUrl()


    /**
     * This function gets a gitlab repository and enriches it.
     *
     * @param string     $repositoryUrl   The url of the repository
     * @param array|null $repositoryArray The repository array from the gitlab api.
     *
     * @return ObjectEntity|null The gitlab repository.
     * @throws Exception
     */
    public function getGitlabRepository(string $repositoryUrl, ?array $repositoryArray = null): ?ObjectEntity
    {
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        // $repositoryMapping = $this->resourceService->getMapping($this->configuration['gitlabRepository'], 'open-catalogi/open-catalogi-bundle');
        $repositoryMapping = $this->resourceService->getMapping('https://api.github.com/oc.gitlabRepository.mapping.json', 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false || $repositoryMapping instanceof Mapping === false) {
            return null;
        }//end if

         $source = $this->resourceService->getSource($this->configuration['gitlabSource'], 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($source instanceof Source === false || $this->checkGitlabAuth($source) === false) {
            return null;
        }//end if

        // Clean the repository url if needed. If null returned the repository url isn't valid.
        $repositoryUrl = $this->cleanUrl($repositoryUrl);
        if ($repositoryUrl === null) {
            return null;
        }

        // Find de sync by source and repository url.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

        // Set the gitlab repository mapping to the sync object.
        $repositorySync->setMapping($repositoryMapping);

        if ($repositoryArray === null) {
            // Get the repository from the gitlab api.
            $repositoryArray = $this->getGitlabRepoFromSource($source, $repositoryUrl);
        }

        if ($repositoryArray === null) {
            return null;
        }

        // Synchronize the github repository.
        $repositorySync = $this->syncService->synchronize($repositorySync, $repositoryArray);
        $this->entityManager->persist($repositorySync);
        $this->entityManager->flush();

        $repository = $repositorySync->getObject();

        // Get the tree of the repository. (all the files and directories from the root of the repo)
        $tree = $this->getRepoTreeFromGitlabApi($source, $repositoryArray);

        // Tree must be not null for importing the publiccode and/or opencatalogi files.
        if ($tree !== null) {
            // Check in the tree if there is a publiccode file.
            // The params source and repositoryArray will be needed
            foreach ($tree as $directory) {
                $repository = $this->importRepoFiles($source, $repository, $repositoryArray, $directory);
            }//end foreach
        }

        // Cleanup the repository.
        $repository = $this->cleanupRepository($repository);

        // Enrich the repository with component and/or organization.
        $repository = $this->enrichRepository($repository, $repositoryArray, $source);

        // Return the repository object.
        $this->ratingService->setConfiguration($this->configuration);
        return $this->ratingService->rateRepoComponents($repository, $source, $repositoryArray);

    }//end getGitlabRepository()


    /**
     * Get an organization with type Organization from the gitlab api.
     *
     * @param string $name   The id of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization from gitlab as array.
     */
    public function getOrganization(string $name, Source $source): ?array
    {
        $this->pluginLogger->info('Getting organization '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        try {
            $response = $this->callService->call($source, '/groups/'.$name);
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
     * Get an user with type User from the gitlab api.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization from gitlab as array.
     */
    public function getUser(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting user '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        // Use query username to search the user: ?username=:username
        $queryConfig['query'] = ['username' => $name];
        try {
            $response = $this->callService->call($source, '/users', 'GET', $queryConfig);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $users = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($users === null) {
            $this->pluginLogger->error('Could not find a user with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        // Check if the users is an array and if there is one item.
        if (count($users) === 1) {
            return $users[0];
        }

        // The user is found multiple times.
        $this->pluginLogger->debug('The user with name: '.$name.' is found multiple times ('.count($users).') with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return null;

    }//end getUser()


}//end class
