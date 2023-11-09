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
     * This function gets and maps the publiccode file
     *
     * @param ObjectEntity $repository    The repository object.
     * @param string       $repositoryUrl The repository url.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $repositoryUrl): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($this->githubApiService->checkGithubAuth($source) === false) {
            return null;
        }//end if
        
        $repositories = $this->githubApiService->getPubliccodesFromRepo($repositoryUrl, $source);

        return $this->githubService->mappPubliccodesFromRepo($repositories, $repository);

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
                && $repository->getValue('publiccode_urls') === []
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
            if ($repository->getValue('publiccode_urls') === []) {
                $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
            }
        }

        return $this->data;

    }//end enrichPubliccodeFromGithubUrlHandler()


}//end class
