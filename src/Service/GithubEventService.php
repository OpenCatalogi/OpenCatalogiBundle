<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Exception\GatewayException;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\ComponentException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * This class handles the github events.
 *
 * This service handles the incoming github event and creates a repository.
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class GithubEventService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

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
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $publiccodeService;

    /**
     * @var ImportResourcesService
     */
    private ImportResourcesService $importResourcesService;

    /**
     * @var EnrichPubliccodeFromGithubUrlService
     */
    private EnrichPubliccodeFromGithubUrlService $enrichPubliccode;

    /**
     * @var FindGithubRepositoryThroughOrganizationService
     */
    private FindGithubRepositoryThroughOrganizationService $organizationService;

    /**
     * @var FindOrganizationThroughRepositoriesService
     */
    private FindOrganizationThroughRepositoriesService $findOrganization;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;


    /**
     * @param EntityManagerInterface                         $entityManager          The Entity Manager Interface.
     * @param SynchronizationService                         $syncService            The Synchronization Service.
     * @param CallService                                    $callService            The Call Service.
     * @param CacheService                                   $cacheService           The Cache Service.
     * @param GithubApiService                               $githubApiService       The Github Api Service.
     * @param GithubPubliccodeService                        $publiccodeService      The Github Publiccode Service.
     * @param ImportResourcesService                         $importResourcesService The Import Resources Service.
     * @param EnrichPubliccodeFromGithubUrlService           $enrichPubliccode       The Enrich Publiccode From Github Url Service.
     * @param FindGithubRepositoryThroughOrganizationService $organizationService    The find github repository through organization service.
     * @param GatewayResourceService                         $resourceService        The Gateway Resource Service.
     * @param LoggerInterface                                $pluginLogger           The plugin version of the logger interface
     * @param FindOrganizationThroughRepositoriesService     $findOrganization       The find organization through repositories service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $syncService,
        CallService $callService,
        CacheService $cacheService,
        GithubApiService $githubApiService,
        GithubPubliccodeService $publiccodeService,
        ImportResourcesService $importResourcesService,
        EnrichPubliccodeFromGithubUrlService $enrichPubliccode,
        FindGithubRepositoryThroughOrganizationService $organizationService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger,
        FindOrganizationThroughRepositoriesService $findOrganization
    ) {
        $this->entityManager          = $entityManager;
        $this->syncService            = $syncService;
        $this->callService            = $callService;
        $this->cacheService           = $cacheService;
        $this->githubApiService       = $githubApiService;
        $this->publiccodeService      = $publiccodeService;
        $this->importResourcesService = $importResourcesService;
        $this->enrichPubliccode       = $enrichPubliccode;
        $this->organizationService    = $organizationService;
        $this->resourceService        = $resourceService;
        $this->pluginLogger           = $pluginLogger;
        $this->findOrganization       = $findOrganization;
        $this->configuration          = [];
        $this->data                   = [];

    }//end __construct()


    /**
     * This function creates/updates the repository with the github event response.
     *
     * @param Source $source        The github api source
     * @param string $name          The name of the repository
     * @param string $repositoryUrl The url of the repository
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return ObjectEntity|null The organization of the repository.
     */
    public function createRepository(Source $source, string $name, string $repositoryUrl): ?ObjectEntity
    {
        $componentSchema  = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping          = $this->resourceService->getMapping($this->configuration['repositoryMapping'], 'open-catalogi/open-catalogi-bundle');

        $this->configuration['organisationSchema']  = $this->configuration['organizationSchema'];
        $this->configuration['organisationMapping'] = $this->configuration['organizationMapping'];

        // Get the repository from the github api and import it.
        $repositoryArray = $this->githubApiService->getRepository($name, $source);
        $repository      = $this->importResourcesService->importGithubRepository($repositoryArray, $this->configuration);

        // If there is no component create one.
        if ($repository->getValue('components')->count() === 0) {
            $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryUrl);
            $componentSync = $this->syncService->synchronize($componentSync, ['name' => $repository->getValue('name'), 'url' => $repository]);
        }

        // Get the publiccodes of the repository and mapp the components.
        $repositories = $this->githubApiService->getPubliccodesFromRepo($name, $source);
        if ($repositories['total_count'] !== 0) {
            $repository = $this->publiccodeService->mappPubliccodesFromRepo($repositories, $repository);
        }

        $organization = $this->importResourcesService->importOrganisation($repositoryArray['owner'], $this->configuration);
        $repository->hydrate(['organisation' => $organization]);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $organization;

    }//end createRepository()


    /**
     * This function creates/updates the repository with the github event response.
     *
     * @param array $githubEvent The github event data from the request.
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return array|null The data with the repository in the response array.
     */
    public function githubEvent(array $githubEvent): ?array
    {
        $repositoryUrl = $githubEvent['repository']['html_url'];

        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($this->githubApiService->checkGithubAuth($source) === false) {
            $this->data['response'] = new Response('Auth is not set for the source with location: '.$source->getLocation(), 404);

            return $this->data;
        }//end if

        $name         = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');
        $explodedName = explode('/', $name);

        // Check if the array has 1 item. If so this is an organisation.
        if (count($explodedName) === 1) {
            $organizationName = $name;
        }

        // Check if this is a .github repository
        foreach ($explodedName as $item) {
            if ($item === '.github') {
                $organizationName = $explodedName[0];
            }
        }

        // Check if the organizationName is set.
        if (isset($organizationName) === true) {
            $action = $this->resourceService->getAction('https://opencatalogi.nl/action/oc.FindGithubRepositoryThroughOrganizationAction.action.json', 'open-catalogi/open-catalogi-bundle');
            $this->organizationService->setConfiguration($action->getConfiguration());

            $organizationObject = $this->organizationService->createOrganization($organizationName, $source);

            $this->entityManager->persist($organizationObject);
            $this->entityManager->flush();

            $organizationObject = $this->entityManager->find(get_class($organizationObject), $organizationObject->getId());

            $organizatioResponse['organization'] = $organizationObject->toArray();

            $this->data['response'] = new Response(json_encode($organizatioResponse), 200, ['Content-Type' => 'application/json']);

            return $this->data;
        }

        $organization = $this->createRepository($source, $name, $repositoryUrl);

        $this->data['response'] = new Response(json_encode($organization->toArray()), 200, ['Content-Type' => 'application/json']);

        return $this->data;

    }//end githubEvent()


    /**
     * This function creates/updates the repository with the github event response.
     *
     * @param ?array $data          Data set at the start of the handler.
     * @param ?array $configuration Configuration of the action.
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return array|null The data with the repository in the response array.
     */
    public function updateRepositoryWithEventResponse(?array $data=[], ?array $configuration=[]): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        if (key_exists('payload', $this->data) === true) {
            $githubEvent = $this->data['payload'];

            // Create repository with the payload of the request.
            return $this->githubEvent($githubEvent);
        }//end if

        $githubEvent = $this->data['body'];

        // Create repository with the body of the request.
        return $this->githubEvent($githubEvent);

    }//end updateRepositoryWithEventResponse()


}//end class
