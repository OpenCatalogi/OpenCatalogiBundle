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
     * @var EnrichPubliccodeFromGithubUrlService
     */
    private EnrichPubliccodeFromGithubUrlService $enrichPubliccode;

    /**
     * @var FindGithubRepositoryThroughOrganizationService
     */
    private FindGithubRepositoryThroughOrganizationService $organizationService;

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
     * @param EntityManagerInterface                         $entityManager       The Entity Manager Interface.
     * @param SynchronizationService                         $syncService         The Synchronization Service.
     * @param CallService                                    $callService         The Call Service.
     * @param CacheService                                   $cacheService        The Cache Service.
     * @param GithubApiService                               $githubApiService    The Github Api Service.
     * @param EnrichPubliccodeFromGithubUrlService           $enrichPubliccode    The Enrich Publiccode From Github Url Service.
     * @param FindGithubRepositoryThroughOrganizationService $organizationService The find github repository through organization service.
     * @param GatewayResourceService                         $resourceService     The Gateway Resource Service.
     * @param LoggerInterface                                $pluginLogger        The plugin version of the logger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $syncService,
        CallService $callService,
        CacheService $cacheService,
        GithubApiService $githubApiService,
        EnrichPubliccodeFromGithubUrlService $enrichPubliccode,
        FindGithubRepositoryThroughOrganizationService $organizationService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager       = $entityManager;
        $this->syncService         = $syncService;
        $this->callService         = $callService;
        $this->cacheService        = $cacheService;
        $this->githubApiService    = $githubApiService;
        $this->enrichPubliccode    = $enrichPubliccode;
        $this->organizationService = $organizationService;
        $this->resourceService     = $resourceService;
        $this->pluginLogger        = $pluginLogger;
        $this->configuration       = [];
        $this->data                = [];

    }//end __construct()


    /**
     * Get a organization from the given name.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
     */
    public function getOrganization(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting organization '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $response = $this->callService->call($source, '/orgs/'.$name);

        $organization = json_decode($response->getBody()->getContents(), true);

        if ($organization === null) {
            $this->pluginLogger->error('Could not find a organization with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $organization;

    }//end getOrganization()


    /**
     * This function creates/updates the organization with the github event response.
     *
     * @param string $organizationName The name of the organization
     * @param Source $source           The github api source.
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return array|null The data with the repository in the response array.
     */
    public function createOrganization(string $organizationName, Source $source): ?ObjectEntity
    {
        $organizationArray = $this->getOrganization($organizationName, $source);

        // If the organization is null return this->data
        if ($organizationArray === null) {
            $this->data['response'] = new Response('Could not find a organization with name: '.$organizationName.' and with source: '.$source->getName().'.', 404);

            return null;
        }

        $organizationSchema = $this->resourceService->getSchema($this->configuration['organisationSchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping            = $this->resourceService->getMapping($this->configuration['organisationMapping'], 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationArray['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $organizationArray);

        $organizationObject = $synchronization->getObject();

        $this->organizationService->setConfiguration(
            [
                'githubSource'        => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                'usercontentSource'   => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
                'repositorySchema'    => 'https://opencatalogi.nl/oc.repository.schema.json',
                'repositoryMapping'   => 'https://api.github.com/oc.githubRepository.mapping.json',
                'organisationSchema'  => 'https://opencatalogi.nl/oc.organisation.schema.json',
                'componentSchema'     => 'https://opencatalogi.nl/oc.component.schema.json',
                'openCatalogiMapping' => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
            ]
        );

        $this->organizationService->getOrganizationCatalogi($organizationObject);

        return $organizationObject;

    }//end createOrganization()


    /**
     * This function creates/updates the repository with the github event response.
     *
     * @param array $githubEvent The github event data from the request.
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return array|null The data with the repository in the response array.
     */
    public function createRepository(array $githubEvent): ?array
    {
        $repositoryUrl = $githubEvent['repository']['html_url'];

        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($this->githubApiService->checkGithubAuth($source) === false) {
            return null;
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
            $organizationObject = $this->createOrganization($organizationName, $source);

            $organizatioResponse['organization'] = $organizationObject->toArray();

            $this->data['response'] = new Response(json_encode($organizatioResponse), 200);

            return $this->data;
        }

        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping          = $this->resourceService->getMapping($this->configuration['repositoryMapping'], 'open-catalogi/open-catalogi-bundle');

        // Get repository from github.
        $repositoryArray = $this->githubApiService->getRepository($name, $source);
        if ($repositoryArray === null) {
            // Return error if repository is not found.
            $this->data['response'] = new Response('Cannot find repository with url: '.$repositoryUrl, 404);

            return $this->data;
        }//end if

        $repositoryArray['name'] = str_replace('-', ' ', $repositoryArray['name']);

        $synchronization = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryArray['id']);

        $this->pluginLogger->debug('Mapping object '.$repositoryUrl.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$mapping.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('Checking repository '.$repositoryUrl.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repositoryArray);
        $this->pluginLogger->debug('Repository synchronization created with id: '.$synchronization->getId()->toString().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $repository = $synchronization->getObject();

        $repository = $this->enrichPubliccode->enrichRepositoryWithPubliccode($repository, $repositoryUrl);

        $componentResponse['component'] = $repository->getValue('component')->toArray();

        $this->data['response'] = new Response(json_encode($componentResponse), 200);

        return $this->data;

    }//end createRepository()


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
            return $this->createRepository($githubEvent);
        }//end if

        $githubEvent = $this->data['body'];

        // Create repository with the body of the request.
        return $this->createRepository($githubEvent);

    }//end updateRepositoryWithEventResponse()


}//end class
