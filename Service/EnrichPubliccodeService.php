<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use CommonGateway\CoreBundle\Service\GatewayResourceService;

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
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubPubliccodeService;

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
     * @param EntityManagerInterface  $entityManager           The Entity Manager Interface
     * @param CallService             $callService             The Call Service
     * @param SynchronizationService  $synchronizationService  The Synchronization Service
     * @param MappingService          $mappingService          The Mapping Service
     * @param GithubPubliccodeService $githubPubliccodeService The Github Publiccode Service
     * @param LoggerInterface        $pluginLogger     The plugin version of the loger interface.
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        GithubPubliccodeService $githubPubliccodeService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->githubPubliccodeService = $githubPubliccodeService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->pluginLogger = $pluginLogger;
        $this->resourceService = $resourceService;

        $this->configuration = [];
        $this->data = [];
    }//end __construct()

    /**
     * This function fetches repository data.
     *
     * @param string $publiccodeUrl endpoint to request
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    public function getPubliccodeFromUrl(string $publiccodeUrl)
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');

        try {
            $response = $this->callService->call($source, '/'.$publiccodeUrl);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$publiccodeUrl.' '.$e->getMessage());
        }

        if (isset($response) === true) {
            return $this->githubPubliccodeService->parsePubliccode($publiccodeUrl, $response);
        }

        return null;
    }//end getPubliccodeFromUrl()

    /**
     * @param ObjectEntity $repository
     * @param string       $publiccodeUrl
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $publiccodeUrl): ?ObjectEntity
    {
        $url = trim(\Safe\parse_url($publiccodeUrl, PHP_URL_PATH), '/');
        if (($publiccode = $this->getPubliccodeFromUrl($url)) !== null) {
            $this->githubPubliccodeService->mapPubliccode($repository, $publiccode);
        }

        return $repository;
    }//end enrichRepositoryWithPubliccode()

    /**
     * @param array|null  $data          data set at the start of the handler
     * @param array|null  $configuration configuration of the action
     * @param string|null $repositoryId
     *
     * @return array dataset at the end of the handler
     */
    public function enrichPubliccodeHandler(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($repositoryId !== null) {
            // If we are testing for one repository.
            if (($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) !== null) {
                if (($publiccodeUrl = $repository->getValue('publiccode_url')) !== null) {
                    $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);
                }
            } else {
                $this->pluginLogger->error('Could not find given repository');
            }
            
        } else {
            $repositoryEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');

            // If we want to do it for al repositories.
            $this->pluginLogger->debug('Looping through repositories');
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                if (($publiccodeUrl = $repository->getValue('publiccode_url')) !== null) {
                    $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);
                }
            }
        }

        $this->entityManager->flush();

        $this->pluginLogger->debug('enrichPubliccodeHandler finished');

        return $this->data;
    }//end enrichPubliccodeHandler()
}//end class
