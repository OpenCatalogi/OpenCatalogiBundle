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

class EnrichPubliccodeService
{
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;
    private CallService $callService;
    private SynchronizationService $synchronizationService;
    private MappingService $mappingService;
    private GithubPubliccodeService $githubPubliccodeService;
    private array $configuration;
    private array $data;

    private Entity $componentEntity;
    private Mapping $componentMapping;
    private Entity $descriptionEntity;
    private Entity $repositoryEntity;
    private Mapping $repositoryMapping;
    private Source $source;

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
        $this->callService->setStyle($io);
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);
        $this->githubPubliccodeService->setStyle($io);

        return $this;
    }

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getGithubSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com'])) {
            isset($this->io) && $this->io->error('No source found for https://api.github.com');
        }

        return $this->source;
    }

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getRepositoryEntity(): ?Entity
    {
        if (!$this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');
        }

        return $this->repositoryEntity;
    }

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        if (!$this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/publiccode/component'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/publiccode/component');

            return null;
        }

        return $this->repositoryMapping;
    }

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        if (!$this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.component.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');
        }

        return $this->componentEntity;
    }

    /**
     * Get the description entity.
     *
     * @return ?Entity
     */
    public function getDescriptionEntity(): ?Entity
    {
        if (!$this->descriptionEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.description.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.description.schema.json');
        }

        return $this->descriptionEntity;
    }

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
        if (!$source = $this->getGithubSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with publiccode url: '.$publiccodeUrl);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/'.$publiccodeUrl);
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch '.$publiccodeUrl.' '.$e->getMessage());
        }

        if (isset($response)) {
            return $this->githubPubliccodeService->parsePubliccode($publiccodeUrl, $response);
        }

        return null;
    }

    /**
     * @param ObjectEntity $repository
     * @param array        $publiccodeUrl
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $publiccodeUrl): ?ObjectEntity
    {
        if (!$repositoryMapping = $this->getRepositoryMapping()) {
            isset($this->io) && $this->io->error('No repositoriesMapping found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        $url = trim(parse_url($publiccodeUrl, PHP_URL_PATH), '/');
        if ($publiccode = $this->getPubliccodeFromUrl($url)) {
            $this->githubPubliccodeService->mapPubliccode($repository, $publiccode, $repositoryMapping);
        }

        return $repository;
    }

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
                isset($this->io) && $this->io->error('Could not find given repository');
            }
        } else {
            if (!$repositoryEntity = $this->getRepositoryEntity()) {
                isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Repository ');
            }

            // If we want to do it for al repositories
            isset($this->io) && $this->io->info('Looping through repositories');
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                if ($publiccodeUrl = $repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $publiccodeUrl);
                }
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('enrichPubliccodeHandler finished');

        return $this->data;
    }
}
