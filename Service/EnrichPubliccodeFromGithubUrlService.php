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

class EnrichPubliccodeFromGithubUrlService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

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
     * @param EntityManagerInterface  $entityManager           The Entity Manager Interface
     * @param CallService             $callService             The Call Service
     * @param SynchronizationService  $synchronizationService  The Synchronization Service
     * @param MappingService          $mappingService          The Mapping Service
     * @param GithubPubliccodeService $githubPubliccodeService The Github Publiccode Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        GithubPubliccodeService $githubPubliccodeService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->githubPubliccodeService = $githubPubliccodeService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->configuration = [];
        $this->data = [];
    }

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);
        $this->githubPubliccodeService->setStyle($io);

        return $this;
    }

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
    }//end getMapping()

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
     * This function fetches repository data.
     *
     * @param string $publiccodeUrl endpoint to request
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    public function getPubliccodeFromUrl(string $repositoryUrl)
    {
        $source = $this->getSource('https://api.github.com');

        try {
            $response = $this->callService->call($source, '/repos/'.$repositoryUrl.'/contents/publiccode.yaml');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /repos/'.$repositoryUrl.'/contents/publiccode.yaml '.$e->getMessage());
        }

        if (!isset($response)) {
            try {
                $response = $this->callService->call($source, '/repos/'.$repositoryUrl.'/contents/publiccode.yml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /repos/'.$repositoryUrl.'/contents/publiccode.yml '.$e->getMessage());
            }
        }

        if (isset($response)) {
            return $this->githubPubliccodeService->parsePubliccode($repositoryUrl, $response);
        }

        return null;
    }//end getPubliccodeFromUrl()

    /**
     * @param ObjectEntity $repository
     * @param array        $publiccode
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $repositoryUrl): ?ObjectEntity
    {
        $url = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');
        if ($publiccode = $this->getPubliccodeFromUrl($url)) {
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
    public function enrichPubliccodeFromGithubUrlHandler(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($repositoryId) {
            // If we are testing for one repository.
            if ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) {
                if (!$repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
                }
            } else {
                isset($this->io) && $this->io->error('Could not find given repository');
            }
        } else {
            $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');

            // If we want to do it for al repositories.
            isset($this->io) && $this->io->info('Looping through repositories');
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                if (!$repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
                }
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('enrichPubliccodeFromGithubUrlHandler finished');

        return $this->data;
    }//end enrichPubliccodeFromGithubUrlHandler()
}
