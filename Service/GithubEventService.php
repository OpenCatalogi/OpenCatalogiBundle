<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Exception\GatewayException;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\ComponentException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

class GithubEventService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

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
    private LoggerInterface $logger;

    /**
     * @param EntityManagerInterface $entityManager          The Entity Manager Interface
     * @param SynchronizationService $synchronizationService The Synchronization Service
     * @param CallService            $callService            The Call Service
     * @param CacheService           $cacheService           The Cache Service
     * @param GithubApiService       $githubApiService       The Github Api Service
     * @param LoggerInterface        $pluginLogger           The plugin version of the loger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        CallService $callService,
        CacheService $cacheService,
        GithubApiService $githubApiService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->callService = $callService;
        $this->cacheService = $cacheService;
        $this->githubApiService = $githubApiService;
        $this->logger = $pluginLogger;

        $this->configuration = [];
        $this->data = [];
    }//end __construct()

    /**
     * Get a source by reference.
     *
     * @param string $location The location to look for
     *
     * @return Source|null
     */
    public function getSource(string $location): ?Source
    {
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $location]);
        if ($source === null) {
//            $this->logger->error("No source found for $location");
            isset($this->io) && $this->io->error("No source found for $location");
        }//end if

        return $source;
    }//end getSource()

    /**
     * Get an entity by reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Entity|null
     */
    public function getEntity(string $reference): ?Entity
    {
        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($entity === null) {
//            $this->logger->error("No entity found for $reference");
            isset($this->io) && $this->io->error("No entity found for $reference");
        }//end if

        return $entity;
    }//end getEntity()

    /**
     * Get a mapping by reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Mapping|null
     */
    public function getMapping(string $reference): ?Mapping
    {
        $mapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
        if ($mapping === null) {
//            $this->logger->error("No mapping found for $reference");
            isset($this->io) && $this->io->error("No mapping found for $reference");
        }//end if

        return $mapping;
    }//end getMapping()

    /**
     * Check the auth of the github source.
     *
     * @param Source $source The given source to check the api key
     *
     * @return bool|null If the api key is set or not
     */
    public function checkGithubAuth(Source $source): ?bool
    {
        if (!$source->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: '.$source->getName());

            return false;
        }//end if

        return true;
    }//end checkGithubAuth()

    /**
     * Get a repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param string $name
     * @param $source
     *
     * @return array|null The imported repository as array
     */
    public function getRepository(string $name, $source): ?array
    {
        // Do we have the api key set of the source.
        if (!$this->checkGithubAuth($source)) {
            return null;
        }//end if

        isset($this->io) && $this->io->success('Getting repository '.$name);
        $response = $this->callService->call($source, '/repos/'.$name);

        $repository = json_decode($response->getBody()->getContents(), true);

        if (!$repository) {
            isset($this->io) && $this->io->error('Could not find a repository with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if

        return $repository;
    }//end getRepository()

    /**
     * This function creates/updates the repository with the github event response.
     *
     * @param ?array $data          data set at the start of the handler
     * @param ?array $configuration configuration of the action
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError
     *
     * @return array|null The data with the repository in the response array
     */
    public function updateRepositoryWithEventResponse(?array $data = [], ?array $configuration = []): ?array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if (key_exists('payload', $this->data) === true) {
            $githubEvent = $this->data['payload'];
        } else {
            $githubEvent = $this->data['body'];
        }

        $repositoryUrl = $githubEvent['repository']['html_url'];

        $source = $this->getSource('https://api.github.com');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');
        $mapping = $this->getMapping('https://api.github.com/repositories');

        // Get repository from github.
        $repositoryArray = $this->getRepository($githubEvent['repository']['full_name'], $source);
        if ($repositoryArray === null) {
            // Return error if repository is not found.
            $this->data['response'] = new Response('Cannot find repository with url: '.$repositoryUrl, 404);

            return $this->data;
        }//end if

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repositoryArray['id']);

        isset($this->io) && $this->io->comment('Mapping object '.$repositoryUrl);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);
        isset($this->io) && $this->io->comment('Checking repository '.$repositoryUrl);

        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repositoryArray);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

        $repository = $synchronization->getObject();

        $component = $this->githubApiService->connectComponent($repository);
        if ($component !== null) {
            $repository->setValue('component', $component);
            $this->entityManager->persist($repository);
            $this->entityManager->flush();
        }//end if

        $this->data['response'] = new Response(json_encode($repository->toArray()), 200);

        return $this->data;
    }//end updateRepositoryWithEventResponse()
}//end class
