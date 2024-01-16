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
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class DeveloperOverheidService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface $pluginLogger
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var CallService $callService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService $syncService
     */
    private SynchronizationService $syncService;

    /**
     * @var GatewayResourceService $resourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var GithubApiService $githubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GitlabApiService $gitlabApiService
     */
    private GitlabApiService $gitlabApiService;

    /**
     * @var array $data
     */
    private array $data;

    /**
     * @var array $configuration
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface.
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface.
     * @param CallService            $callService      The Call Service.
     * @param SynchronizationService $syncService      The Synchronization Service.
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     * @param GithubApiService       $githubApiService The Github API Service.
     * @param GitlabApiService $gitlabApiService The Gitlab API Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        CallService $callService,
        SynchronizationService $syncService,
        GatewayResourceService $resourceService,
        GithubApiService $githubApiService,
        GitlabApiService $gitlabApiService
    ) {
        $this->entityManager    = $entityManager;
        $this->pluginLogger     = $pluginLogger;
        $this->callService      = $callService;
        $this->syncService      = $syncService;
        $this->resourceService  = $resourceService;
        $this->githubApiService = $githubApiService;
        $this->gitlabApiService = $gitlabApiService;
        $this->data             = [];
        $this->configuration    = [];

    }//end __construct()


    /**
     * Get all repositories or one repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $repositoryId  The given repository id
     *
     * @return array|null An arry of repositories from the developer.overheid source.
     * @throws \Exception
     */
    public function getRepositories(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($source === null
            && $endpoint === null
        ) {
            return $this->data;
        }

        if ($repositoryId === null) {
            return $this->getRepositoriesFromSource($source, $endpoint);
        }

        return $this->getRepositoryFromSource($source, $endpoint, $repositoryId);

    }//end getRepositories()

    /**
     * Get all components of the given source.
     *
     * @param Source $source The given source
     * @param array $repositoryArray The repository array
     * @param string $domain The domain of the repository url.
     *
     * @return ObjectEntity|null The repository object.
     * @throws \Exception
     */
    public function getRepositoryFromSync(Source $source, array $repositoryArray, string $domain): ?ObjectEntity
    {
        $repositorySchema   = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');

        // Find the repository sync by source.
        $repositorySync     = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryArray['url']);

        // If the repository has a object don't get the repository from the given source.
        if ($repositorySync->getObject() !== null) {
            return $repositorySync->getObject();
        }

        // If there is no repository get the repository from the given source.
        $this->entityManager->remove($repositorySync);
        $this->entityManager->flush();

        if ($domain === 'github.com') {
            // Get the github repository
            $this->githubApiService->setConfiguration($this->configuration);
            $repository = $this->githubApiService->getGithubRepository($repositoryArray['url']);
        }

        if ($domain === 'gitlab.com') {
            // Get the gitlab repository
            $this->gitlabApiService->setConfiguration($this->configuration);
            $repository = $this->gitlabApiService->getGitlabRepository($repositoryArray['url']);
        }

        return $repository;
    }//end handleGithubComponentRepo()


    /**
     * Get all repositories of the given source.
     *
     * @param Source $source The given source
     * @param array $repositoryArray The repository array.
     *
     * @return ObjectEntity|null The repository object.
     * @throws \Exception
     */
    public function handleRepository(Source $source, array $repositoryArray): ?ObjectEntity
    {
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');

        $parsedUrl = \Safe\parse_url($repositoryArray['url']);
        if (key_exists('host', $parsedUrl) === false) {
            return null;
        }

        // Get the domain of the repository url.
        $domain = $parsedUrl['host'];

        // Get the repository from the find sync by source.
        // If there is no object get it from the github source.
        $repository = $this->getRepositoryFromSync($source, $repositoryArray, $domain);

        // Get the developer.overheid sync.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryArray['url']);
        
        // If we don't have a repository we return null. (probabbly the rate limit from github)
        if ($repository === null) {
            return null;
        }

        // Set the developer.overheid sync to the repository object.
        $repository->addSynchronization($repositorySync);

        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;
    }//end handleRepository()


    /**
     * Get all repositories of the given source.
     *
     * @param Source $source   The given source
     * @param string $endpoint The endpoint of the source
     *
     * @return array|null An array of repositories from the developer.overheid source.
     * @throws \Exception
     */
    public function getRepositoriesFromSource(Source $source, string $endpoint): ?array
    {
        $repositoriesArray = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($repositoriesArray).' repositories from '.$source->getName());

        $result = [];
        foreach ($repositoriesArray as $repositoryArray) {
            $result[] = $this->handleRepository($source, $repositoryArray);
        }

        $this->entityManager->flush();

        return $result;

    }//end getRepositoriesFromSource()


    /**
     * Get a repository of the given source with the given id.
     *
     * @param Source $source       The given source
     * @param string $endpoint     The endpoint of the source
     * @param string $repositoryId The given repository id
     *
     * @return array|null The repository from the developer.overheid source.
     * @throws \Exception
     */
    public function getRepositoryFromSource(Source $source, string $endpoint, string $repositoryId): ?array
    {
        $response        = $this->callService->call($source, $endpoint.'/'.$repositoryId);
        $repositoryArray = json_decode($response->getBody()->getContents(), true);

        if ($repositoryArray === null) {
            $this->pluginLogger->error('Could not find an repository with id: '.$repositoryId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $repository = $this->handleRepository($source, $repositoryArray);

        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found repository with id: '.$repositoryId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $repository->toArray();

    }//end getRepositoryFromSource()


}//end class
