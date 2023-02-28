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
    private Entity $applicationEntity;
    private Entity $repositoryEntity;
    private Mapping $repositoryMapping;
    private Entity $organizationEntity;
    private Entity $contractorsEntity;
    private Entity $contactsEntity;
    private Entity $dependencyEntity;

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
     * Get the application entity.
     *
     * @return ?Entity
     */
    public function getApplicationEntity(): ?Entity
    {
        if (!$this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.application.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.application.schema.json');
        }

        return $this->applicationEntity;
    }

    /**
     * Get the organisation entity.
     *
     * @return ?Entity
     */
    public function getOrganizationEntity(): ?Entity
    {
        if (!$this->organizationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');
        }

        return $this->organizationEntity;
    }

    /**
     * Get the contractors entity.
     *
     * @return ?Entity
     */
    public function getContractorEntity(): ?Entity
    {
        if (!$this->contractorsEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.contractor.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.contractor.schema.json');
        }

        return $this->contractorsEntity;
    }

    /**
     * Get the contact entity.
     *
     * @return ?Entity
     */
    public function getContactEntity(): ?Entity
    {
        if (!$this->contactsEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.contact.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.contact.schema.json');
        }

        return $this->contactsEntity;
    }

    /**
     * Get the dependency entity.
     *
     * @return ?Entity
     */
    public function getDependencyEntity(): ?Entity
    {
        if (!$this->dependencyEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.dependency.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.dependency.schema.json');
        }

        return $this->dependencyEntity;
    }

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        if (!$this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/oc.githubPubliccodeComponent.mapping.json');

            return null;
        }

        return $this->repositoryMapping;
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
    public function getPubliccodeFromUrl(string $repositoryUrl)
    {
        // make sync object
        if (!$source = $this->getGithubSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with publiccode url: '.$repositoryUrl);

            return null;
        }

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
    }

    /**
     * @param ObjectEntity $repository
     * @param array        $publiccode
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function enrichRepositoryWithPubliccode(ObjectEntity $repository, string $repositoryUrl): ?ObjectEntity
    {
        if (!$repositoryMapping = $this->getRepositoryMapping()) {
            isset($this->io) && $this->io->error('No repositoriesMapping found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        $url = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');
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
    public function enrichPubliccodeFromGithubUrlHandler(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($repositoryId) {
            // If we are testing for one repository
            if ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) {
                if (!$repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
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
                if (!$repository->getValue('publiccode_url')) {
                    $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
                }
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('enrichPubliccodeFromGithubUrlHandler finished');

        return $this->data;
    }
}
