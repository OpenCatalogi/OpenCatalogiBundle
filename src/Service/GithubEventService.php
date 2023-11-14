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

        $formInput = $this->data['body'];

        if (key_exists('repository', $formInput) === false
            || key_exists('html_url', $formInput['repository']) === false
        ) {
            $this->pluginLogger->error('The repository html_url is not given.');
        }

        $repository = $this->githubApiService->getGithubRepository($formInput['repository']['html_url']);

        if ($repository === null) {
            $this->data['response'] = new Response('Repository is not created.', 404, ['Content-Type' => 'application/json']);

            return $this->data;
        }

        $this->data['response'] = new Response(json_encode($repository->getValue('organisation')->toArray()), 200, ['Content-Type' => 'application/json']);

        return $this->data;

    }//end updateRepositoryWithEventResponse()


}//end class
