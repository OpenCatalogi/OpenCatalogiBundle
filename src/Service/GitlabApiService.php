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
     * @var RatingService
     */
    private RatingService $ratingService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var PubliccodeService
     */
    private PubliccodeService $publiccodeService;

    /**
     * @var OpenCatalogiService
     */
    private OpenCatalogiService $openCatalogiService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param CallService            $callService     The Call Service
     * @param SynchronizationService $syncService     The Synchronisation Service
     * @param MappingService         $mappingService  The Mapping Service
     * @param RatingService          $ratingService   The Rating Service.
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @param PubliccodeService $publiccodeService The publiccode service
     * @param OpenCatalogiService $openCatalogiService The opencatalogi service
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
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->syncService     = $syncService;
        $this->mappingService  = $mappingService;
        $this->ratingService   = $ratingService;
        $this->pluginLogger    = $pluginLogger;
        $this->resourceService = $resourceService;
        $this->publiccodeService = $publiccodeService;
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
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source $source The source to sync from.
     * @param array $repositoryArray
     * @param array $tree
     * @return ObjectEntity|null The repositories that where found.
     */
    public function importRepoFiles(Source $source, ObjectEntity $repository, array $repositoryArray, array $tree): ?ObjectEntity
    {
        $opencatalogiNames = [
            'opencatalogi.yaml',
            'opencatalogi.yml',
        ];

        $publiccodeNames = [
            'publiccode.yml',
            'publiccode.yaml'
        ];

        // TODO: Check if there are multiple files in a other directory.
        // The params source and repositoryArray will be needed
        foreach ($tree as $directory) {

            // TODO: Check if the opencatalogi file is in the root of the tree.
            // If so go the the function for the opencatalogi file.
            if (in_array($directory['name'], $opencatalogiNames) === true
                && $directory['type'] === 'blob'
            ) {
                $this->pluginLogger->info('The item is a opencatalogi file.', ['open-catalogi/open-catalogi-bundle']);

                // Get the opencatalogi file from the repository directory.
                $opencatalogi = $this->getTheFileContent($source, $repositoryArray, $directory);

                // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
                if ($opencatalogi === null ||
                    $opencatalogi !== null
                    && key_exists('publiccodeYmlVersion', $opencatalogi) === false
                ) {
                    return $repository;
                }

                $data['publiccode'] = $opencatalogi;
                $data['sourceId'] = $directory['id'];
                $data['sha'] = $directory['id']; // TODO: Get sha from directory.

                // Set the endpoint of the getTheFileContent function as opencatalogiRepo, so it can be found in the enrichOrganizationService.
                $opencatalogi['opencatalogiRepo'] = $source->getLocation().'/api/v4/projects/'.$repositoryArray['id'].'/repository/files/'.$directory['path'].'?ref='.$repositoryArray['default_branch'];

                $this->openCatalogiService->setConfiguration($this->configuration);
                $repository = $this->openCatalogiService->handleOpencatalogiFile($source, $repository, $data, $repositoryArray['namespace']);
            }

            // Check if the publiccode file is in the root of the tree.
            // If so go the the function for the publiccode file.
            if (in_array($directory['name'], $publiccodeNames) === true
                && $directory['type'] === 'blob'
            ) {
                var_dump('The item is a publiccode file. Directory id is: '.$directory['id']);
                $this->pluginLogger->info('The item is a publiccode file. Directory id is: '.$directory['id'], ['open-catalogi/open-catalogi-bundle']);

                // Get the publiccode file from the repository directory.
                $publiccode = $this->getTheFileContent($source, $repositoryArray, $directory);
                if ($publiccode === null && $publiccode !== null && key_exists('publiccodeYmlVersion', $publiccode) === false) {
                    continue;
                }

                $data['publiccode'] = $publiccode;
                $data['sourceId'] = $directory['id'];
                $data['sha'] = $directory['id']; // TODO: Get sha from directory.

                $this->publiccodeService->setConfiguration($this->configuration);
                $repository = $this->publiccodeService->handlePubliccodeFile($directory, $source, $repository, $data, $repositoryArray);
            }
        }

        return $repository;
    }

    /**
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source $source The source to sync from.
     * @param array $repositoryArray
     * @param array $directory
     * @return array|null The repositories that where found.
     */
    public function getTheFileContent(Source $source, array $repositoryArray, array $directory): ?array
    {
        $queryConfig['query'] = ['ref' => $repositoryArray['default_branch']];
        try {
            $response = $this->callService->call($source, '/projects/'.$repositoryArray['id'].'/repository/files/'.$directory['path'], 'GET', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/projects/'.$repositoryArray['id'].'/repository/files/'.$directory['path'].' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        $publiccodeArray = $this->callService->decodeResponse($source, $response);

        $content = \Safe\base64_decode($publiccodeArray['content']);

        $yamlEncoder = new YamlEncoder();

        // Decode the string.
        return $yamlEncoder->decode($content, 'yaml');
    }

    /**
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source $source The source to sync from.
     * @param array $repositoryArray
     * @param ObjectEntity $repository
     * @param string $monoRepoUrl
     * @return array|null The updated repository.
     */
    public function getRepoTreeFromGitlabApi(Source $source, array $repositoryArray, ObjectEntity $repository, string $monoRepoUrl): ?array
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
    }

    /**
     * Get the given gitlab repository from the /api/v4/search endpoint.
     *
     * @param Source $source The source to sync from.
     * @param string $path
     * @return array|null The repositories that where found.
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

    }//end getFilesFromRepo()

    /**
     * This function handles the logo.
     *
     * The logo can be given in multiple ways. (what we have seen)
     * 1. An url to the logo. Here we don't validate the avatar url. TODO: validate the given avatar url. (https://gitlab.com/uploads/-/system/project/avatar/33855802/760205.png)
     * 2. TODO: Check if there is a raw gitlab ur. A raw gitlab url of the logo. (....)
     * 3. A gitlab url to where the logo is placed in a repository. (https://gitlab.com/discipl/RON/regels.overheid.nl/-/blob/master/images/WORK_PACKAGE_ISSUE.png)
     * 4. A gitlab url to where the logo is placed in a repository as permalink. (https://gitlab.com/discipl/RON/regels.overheid.nl/-/blob/15445d8381ab5218d1b7b7e232be829bf037e4e5/images/WORK_PACKAGE_ISSUE.png)
     * 5. A relative path. From the root of the repository to the image. (/images/WORK_PACKAGE_ISSUE.png)
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $repository      The repository object.
     *
     * @return string|null The logo from the publiccode
     */
    public function handleLogo(array $publiccodeArray, Source $source, ObjectEntity $repository): ?string
    {
        // Parse url to get the path (organization and repository) from the repository url.
        // The repositoryName is used for option 2, 3 and 4.
        $repositoryName = \Safe\parse_url($repository->getValue('url'))['path'];

        // The logo can be given in multiple ways. (what we have seen). Check the function tekst for explanation about the types we handle.
        // Check if the logo is a valid url.
        if (filter_var($publiccodeArray['logo'], FILTER_VALIDATE_URL) !== false) {
            $this->pluginLogger->info('The logo is a valid url. Check whether the logo comes from source https://avatars.githubusercontent.com or whether the logo must be retrieved from the github api with the given logo URL.');

            // Parse url to get the host and path of the logo url.
            $parsedLogo = \Safe\parse_url($publiccodeArray['logo']);

            // There should always be a host because we checked if it is a valid url.
            $domain = $parsedLogo['host'];
            switch ($domain) {
                // Check if the logo is as option 2, a logo from https://raw.githubusercontent.com.
                // Check if the domain is https://raw.githubusercontent.com. If so, the user content source must be called with the path of the given logo URL as endpoint.
                case 'raw.githubusercontent.com':
                    // Get the usercontent source.
                    $usercontentSource = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');
                    // Check if the given source is not an instance of a Source return null and create a log.
                    if ($usercontentSource instanceof Source === false) {
                        $this->pluginLogger->error('The source with reference: '.$usercontentSource->getReference().' cannot be found.', ['open-catalogi/open-catalogi-bundle']);

                        // Cannot validate the raw usercontent url if the source cannot be found.
                        return null;
                    }

                    // Handle the logo if the logo is as option 2, the raw github link for the logo.
                    return $this->handleRawLogo($publiccodeArray, $usercontentSource, 'raw');
                    break;
                // Check if the domain is https://github.com, the key path exist in the parsed logo url and if the parsed logo url path is not null.
                // If so we need to get an url that the frontend can use.
                case 'gitlab.com':
                    // TODO: 3 types are needed to be validated. Option 1, 3 and 4.
                    if (key_exists('path', $parsedLogo) === true
                        && $parsedLogo['path'] !== null
                    ) {
                        // The beginning of the path of gitlab is /uploads/. TODO: check if it always starts with uploads.
                        // TODO: Handle gitlab avatar url.

                        // For option 3 and 4:
                        // Get the path after the /-/. TODO: check if this is always the format of the urls.
                        // TODO: Handle the gitlab url. Do a call with the path after /-/.

                        // Handle the logo if the logo is as option 3 or 4, the file fom github where the image can be found.
                        return $this->handleLogoFromGithub($publiccodeArray, $source, $parsedLogo, $repositoryName);
                    }
                    break;
                default:
                    $this->pluginLogger->warning('The domain: '.$domain.' is not valid. The logo url can be from https://avatars.githubusercontent.com, https://raw.githubusercontent.com and https://github.com. It can also be a relative path from the root of the repository from github can be given.', ['open-catalogi/open-catalogi-bundle']);
            }//end switch
        }//end if

        // Check if the logo is not a valid url. The logo is as option 5 a relative path.
        // A relative path of the logo should start from the root of the repository from github.
        if (filter_var($publiccodeArray['logo'], FILTER_VALIDATE_URL) === false) {

            if (str_contains($publiccodeArray['logo'], '/uploads/') === true) {
                $this->pluginLogger->info('The logo is a gitlab uploads logo: '.$publiccodeArray['logo'], ['open-catalogi/open-catalogi-bundle']);

                return 'https://gitlab.com'.$publiccodeArray['logo'];
            }

            // Set the type param to relative so that the correct error log is created.
            return $this->getLogoFileContent($publiccodeArray, $source, '/repos'.$repositoryName.'/contents'.$publiccodeArray['logo'], 'relative');
        }

        // Got an other type of url. If the url comes here we need to check if we handle all the ways we want to validate.
        $this->pluginLogger->warning('the logo is checked in 4 different ways. The specified logo does not match the 5 ways. Check if we need to add an extra option.', ['open-catalogi/open-catalogi-bundle']);

        // Return null, because the given url is not from avatars.githubusercontent.com/raw.githubusercontent.com or github.com.
        // Or the given url isn't a valid relative url.
        return null;

    }//end handleLogo()

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
     * This function enriches the repository with a organization.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api.
     * @param Source       $source          The github api source.
     *
     * @return ObjectEntity
     */
    public function enrichWithOrganization(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $repositoryArray['namespace']['web_url']);

        if ($organizationSync->getObject() === null) {
            $organizationMapping = $this->resourceService->getMapping('https://api.github.com/oc.gitlabOrganization.mapping.json', 'open-catalogi/open-catalogi-bundle');
            $dataArray = $this->mappingService->mapping($organizationMapping, $repositoryArray['namespace']);

            // If the kind of the namespace is group set the type to organization.
            if ($repositoryArray['namespace']['kind'] === 'group') {
                $dataArray['type'] = 'Organization';
            }

            // If the kind of the namespace is user set the type to user.
            if ($repositoryArray['namespace']['kind'] === 'user') {
                $dataArray['type'] = 'User';
            }

            // Handle the avatar_url from the namespace.
            if (key_exists('logo', $dataArray) === true){
                $dataArray['logo'] = $this->publiccodeService->handleLogo($dataArray, $source, $repository->getValue('url'), $repositoryArray['id']);
            }

            $organizationSync = $this->syncService->synchronize($organizationSync, $dataArray);
            $this->entityManager->persist($organizationSync);
        }

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
     * @return ObjectEntity
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
     *
     * @return ObjectEntity The repository object
     * @throws GuzzleException
     */
    public function enrichRepository(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        var_dump($repositoryArray['namespace']);die();
        
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
     * This function cleans the gitlab url.
     *
     * @param string     $repositoryUrl   The url of the repository
     * @param array|null $repositoryArray The repository array from the gitlab api.
     *
     * @return string|null
     * @throws Exception
     */
    public function cleanUrl(string $repositoryUrl): ?string
    {
        // Parse the url from the publiccode file to get the host and path.
        $parsedUrl = \Safe\parse_url($repositoryUrl);
        $domain = $parsedUrl['host'];
        $scheme = $parsedUrl['scheme'];

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
    }

    /**
     * This function gets a gitlab repository and enriches it.
     *
     * @param string     $repositoryUrl   The url of the repository
     * @param array|null $repositoryArray The repository array from the gitlab api.
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function getGitlabRepository(string $repositoryUrl, ?array $repositoryArray=null): ?ObjectEntity
    {
        $repositorySchema  = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
//        $repositoryMapping = $this->resourceService->getMapping($this->configuration['gitlabRepository'], 'open-catalogi/open-catalogi-bundle');
        $repositoryMapping = $this->resourceService->getMapping('https://api.github.com/oc.gitlabRepository.mapping.json', 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false
            || $repositoryMapping instanceof Mapping === false
        ) {
            return null;
        }//end if

        // TODO: Set the gitlab source to the configuration.
//        $source = $this->resourceService->getSource($this->configuration['gitlabSource'], 'open-catalogi/open-catalogi-bundle');
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitlabAPI.source.json', 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($this->checkGitlabAuth($source) === false) {
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
        $tree = $this->getRepoTreeFromGitlabApi($source, $repositoryArray, $repository, $repositoryUrl);

        // Check in the tree if there is a publiccode file.
        // TODO: now only checks in the root of the repo. Also check if there are multiple files.
        $repository = $this->importRepoFiles($source, $repository, $repositoryArray, $tree);

        // Cleanup the repository.
        $repository = $this->cleanupRepository($repository);

        // Enrich the repository with component and/or organization.
        $repository = $this->enrichRepository($repository, $repositoryArray, $source);

        // TODO: Rate the component(s) of the repository.
        // Return the repository object.
        $this->ratingService->setConfiguration($this->configuration);
        return $this->ratingService->rateRepoComponents($repository, $source, $repositoryArray);
    }//end getGitlabRepository()

    /**
     * Get a organization with type Organization from the github api.
     *
     * @param string $name   The id of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
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
     * Get a organization with type Organization from the github api.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
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




}//end class
