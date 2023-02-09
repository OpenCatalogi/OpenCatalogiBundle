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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

class EnrichPubliccodeService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

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
     * @var Entity
     */
    private Entity $componentEntity;

    /**
     * @var Mapping
     */
    private Mapping $componentMapping;

    /**
     * @var Entity
     */
    private Entity $descriptionEntity;

    /**
     * @var Entity
     */
    private Entity $repositoryEntity;

    /**
     * @var Mapping
     */
    private Mapping $repositoryMapping;

    /**
     * @var Source
     */
    private Source $source;

    /**
     * @param EntityManagerInterface  $entityManager           EntityManagerInterface
     * @param CallService             $callService             CallService
     * @param SynchronizationService  $syncService  SynchronizationService
     * @param MappingService          $mappingService          MappingService
     * @param GithubPubliccodeService $githubPubliccodeService GithubPubliccodeService
     * @param LoggerInterface  $mappingLogger The logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        GithubPubliccodeService $githubPubliccodeService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->githubPubliccodeService = $githubPubliccodeService;
        $this->syncService = $syncService;
        $this->mappingService = $mappingService;
        $this->configuration = [];
        $this->data = [];
        $this->logger = $pluginLogger;
    }//end  __construct()

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getGithubSource(): ?Source
    {
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com']);
        if ($this->source === false) {
            $this->logger->error('No source found for https://api.github.com');
        }

        return $this->source;
    }//end getGithubSource()

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getRepositoryEntity(): ?Entity
    {
        $this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
        if ($this->repositoryEntity === false) {
            $this->logger->error('No source found for https://api.github.com');
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');
        }

        return $this->repositoryEntity;
    }//end getRepositoryEntity()

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        $this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/publiccode/component']);
        if ($this->repositoryMapping === false) {
            $this->logger->error('No source found for https://api.github.com');
            isset($this->style) && $this->style->error('No mapping found for https://api.github.com/publiccode/component');

            return null;
        }

        return $this->repositoryMapping;
    }//end getRepositoryMapping()

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.component.schema.json']);
        if ($this->componentEntity === false) {
            $this->logger->error('No source found for https://api.github.com');
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');
        }

        return $this->componentEntity;
    }//end getComponentEntity()

    /**
     * Get the description entity.
     *
     * @return ?Entity
     */
    public function getDescriptionEntity(): ?Entity
    {
        $this->descriptionEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.description.schema.json']);
        if ($this->descriptionEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.description.schema.json');
        }

        return $this->descriptionEntity;
    }//end getDescriptionEntity()

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
        // make sync object
        if ($source = $this->getGithubSource() === false) {
            $this->logger->error('No source found when trying to get a Repository with publiccode url: '.$publiccodeUrl);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/'.$publiccodeUrl);
        } catch (Exception $e) {
            $this->logger->error('Error found trying to fetch '.$publiccodeUrl.' '.$e->getMessage());
        }

        if (isset($response)) {
            return $this->githubPubliccodeService->parsePubliccode($publiccodeUrl, $response);
        }

        return null;
    }//end enrichRepositoryWithPubliccode()

    /**
     * @param ObjectEntity $repository
     * @param array        $publiccodeUrl
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $publiccodeUrl): ?ObjectEntity
    {
        if ($repositoryMapping = $this->getRepositoryMapping() === false) {
            $this->logger->error('No repositoriesMapping found when trying to import a Repository');

            return null;
        }

        $url = trim(parse_url($publiccodeUrl, PHP_URL_PATH), '/');
        if ($publiccode = $this->getPubliccodeFromUrl($url)) {
            $this->githubPubliccodeService->mapPubliccode($repository, $publiccode, $repositoryMapping);
        }

        return $repository;
    }// enrichRepositoryWithPubliccode()

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

        if ($repositoryId) {
            // If we are testing for one repository
            if ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) {
                if ($publiccodeUrl = $repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);
                }
            } else {
                $this->logger->error('Could not find given repository');
            }
        } else {
            if ($repositoryEntity = $this->getRepositoryEntity() === false) {
                isset($this->style) && $this->style->error('No RepositoryEntity found when trying to import a Repository ');
            }

            // If we want to do it for al repositories
            $this->logger->info('Looping through repositories');
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                if ($publiccodeUrl = $repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);
                }
            }
        }
        $this->entityManager->flush();

        $this->logger->info('enrichPubliccodeHandler finished');

        return $this->data;
    }//end enrichPubliccodeHandler()
}
