<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\Criteria;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\YamlEncoder;

class EnrichPubliccodeFromGithubUrlService
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
     * Sets the global configuration of this service.
     *
     * @param array $configuration The configuration to make the global configuration.
     *
     * @return void
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }//end setConfiguration()


    /**
     * This function gets the publiccode file from the github api.
     *
     * @param string $repositoryUrl The url of the repository
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null
     */
    public function getPubliccodeFromUrl(string $repositoryUrl): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');

        if ($source === null
            || $this->githubApiService->checkGithubAuth($source) === false
        ) {
            return null;
        }//end if

        $possibleEndpoints = [
            '/'.$repositoryUrl.'/contents/publiccode.yaml',
            '/'.$repositoryUrl.'/contents/publiccode.yml',
        ];

        foreach ($possibleEndpoints as $endpoint) {
            try {
                $response = $this->callService->call($source, $endpoint);
            } catch (Exception $e) {
                $this->pluginLogger->error('Error found trying to fetch '.$endpoint.' '.$e->getMessage());
            }

            if (isset($response) === true) {
                return $this->githubService->parsePubliccode($repositoryUrl, $response, $source);
            }
        }

        return null;

    }//end getPubliccodeFromUrl()


    /**
     * This function gets the publiccode file from the github user content.
     *
     * @param string $repositoryUrl The url of the repository
     *
     * @throws GuzzleException
     *
     * @return array|null
     */
    public function getPubliccodeFromRawUserContent(string $repositoryUrl): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');

        if ($source === null) {
            return $this->data;
        }

        $possibleEndpoints = [
            '/'.$repositoryUrl.'/main/publiccode.yaml',
            '/'.$repositoryUrl.'/main/publiccode.yml',
            '/'.$repositoryUrl.'/master/publiccode.yaml',
            '/'.$repositoryUrl.'/master/publiccode.yml',
        ];

        foreach ($possibleEndpoints as $endpoint) {
            try {
                $response = $this->callService->call($source, $endpoint);
            } catch (Exception $e) {
                $this->pluginLogger->error('Error found trying to fetch '.$endpoint.' '.$e->getMessage());
            }

            if (isset($response) === true) {
                $yamlEncoder = new YamlEncoder();

                // @TODO: Use the CallService decodeBody
                $decodedResponse = $yamlEncoder->decode($response->getBody()->getContents(), 'yaml');

                if (is_array($decodedResponse) === true) {
                    return $decodedResponse;
                }
            }
        }

        return null;

    }//end getPubliccodeFromRawUserContent()


    /**
     * This function gets and maps the publiccode file
     *
     * @param ObjectEntity $repository
     * @param string       $repositoryUrl
     *
     * @throws Exception
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $repositoryUrl): ?ObjectEntity
    {
        $url = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');

        // Get the publiccode through the raw.githubusercontent source
        $publiccode = $this->getPubliccodeFromRawUserContent($url);
        if ($publiccode !== null) {
            return $this->githubService->mapPubliccode($repository, $publiccode, $this->configuration);
        }

        // If still not found, get the publiccode through the api.github source
        $publiccode = $this->getPubliccodeFromUrl($url);
        if (is_array($publiccode) === true) {
            return $this->githubService->mapPubliccode($repository, $publiccode, $this->configuration);
        }

        return $repository;

    }//end enrichRepositoryWithPubliccode()


    /**
     * This function gets the publiccode through the repository url and enriches the object.
     *
     * @param array|null  $data          data set at the start of the handler
     * @param array|null  $configuration configuration of the action
     * @param string|null $repositoryId  The given repository id
     *
     * @return array dataset at the end of the handler
     * @throws Exception
     */
    public function enrichPubliccodeFromGithubUrlHandler(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        // If we are testing for one repository.
        if ($repositoryId !== null) {
            $repository = $this->entityManager->find('App:ObjectEntity', $repositoryId);
            if ($repository instanceof ObjectEntity === true
                && $repository->getValue('publiccode_url') === null
            ) {
                $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));

                return $this->data;
            }

            if ($repository instanceof ObjectEntity === false) {
                $this->pluginLogger->error('Could not find given repository');

                return $this->data;
            }
        }

        // Set the memory limit for this function.
        ini_set('memory_limit', $this->configuration['memoryLimit']);
        // Set the criteria to not overload the function.
        $criteria = Criteria::create()->orderBy(['dateModified' => Criteria::ASC])->setMaxResults($this->configuration['maxResults']);

        // If we want to do it for al repositories.
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->debug('Looping through repositories');
        foreach ($repositorySchema->getObjectEntities()->matching($criteria) as $repository) {
            if ($repository->getValue('publiccode_url') === null) {
                $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
            }
        }

        return $this->data;

    }//end enrichPubliccodeFromGithubUrlHandler()


}//end class
