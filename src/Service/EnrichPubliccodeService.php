<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class EnrichPubliccodeService
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
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

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
     * @param EntityManagerInterface  $entityManager    The Entity Manager Interface
     * @param CallService             $callService      The Call Service
     * @param GithubPubliccodeService $githubService    The Github Publiccode Service
     * @param LoggerInterface         $pluginLogger     The plugin version of the logger interface.
     * @param GatewayResourceService  $resourceService  The Gateway Resource Service.
     * @param GithubApiService        $githubApiService The Github API Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        GithubPubliccodeService $githubService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GithubApiService $githubApiService
    ) {
        $this->entityManager    = $entityManager;
        $this->callService      = $callService;
        $this->githubService    = $githubService;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->githubApiService = $githubApiService;
        $this->configuration    = [];
        $this->data             = [];

    }//end __construct()


    /**
     * This function gets the publiccode through the publiccode url
     *
     * @param string $publiccodeUrl the publiccode url
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null
     */
    public function getPubliccodeFromUrl(string $publiccodeUrl): ?array
    {
        // Get the path from the url to make the call.
        $endpoint = \Safe\parse_url($publiccodeUrl)['path'];
        $source = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');

        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$publiccodeUrl.' '.$e->getMessage());
        }

        return $this->callService->decodeResponse($source, $response, 'text/yaml');
    }//end getPubliccodeFromUrl()


    /**
     * @param ObjectEntity $repository    The repository object.
     * @param string       $publiccodeUrl The publiccode url.
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $publiccodeUrl): ?ObjectEntity
    {
        if (($publiccode = $this->getPubliccodeFromUrl($publiccodeUrl)) !== null) {
            $this->githubService->mapPubliccode($repository, $publiccode, $this->configuration);
        }

        $this->entityManager->flush();

        return $repository;

    }//end enrichRepositoryWithPubliccode()


    /**
     * @param array|null  $data          Data set at the start of the handler.
     * @param array|null  $configuration Configuration of the action.
     * @param string|null $repositoryId  The repository id.
     *
     * @throws Exception
     *
     * @return array dataset at the end of the handler
     */
    public function enrichPubliccodeHandler(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        if ($repositoryId !== null) {
            // If we are testing for one repository.
            if (($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) !== null) {
                if (($publiccodeUrl = $repository->getValue('publiccode_url')) !== null) {
                    $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);

                    return $this->data;
                }
            }

            if ($repository === null) {
                $this->pluginLogger->error('Could not find given repository');

                return $this->data;
            }
        }

        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');

        // If we want to do it for al repositories.
        $this->pluginLogger->debug('Looping through repositories');
        foreach ($repositorySchema->getObjectEntities() as $repository) {
            if (($publiccodeUrl = $repository->getValue('publiccode_url')) !== null) {
                $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);
            }
        }

        return $this->data;

    }//end enrichPubliccodeHandler()


}//end class
