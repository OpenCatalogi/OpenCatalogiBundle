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
    private SymfonyStyle $symfonyStyle;

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
    private Entity $applicationEntity;

    /**
     * @var Entity
     */
    private Entity $repositoryEntity;

    /**
     * @var Mapping
     */
    private Mapping $repositoryMapping;

    /**
     * @var Entity
     */
    private Entity $organizationEntity;

    /**
     * @var Entity
     */
    private Entity $contractorsEntity;

    /**
     * @var Entity
     */
    private Entity $contactsEntity;

    /**
     * @var Entity
     */
    private Entity $dependencyEntity;

    /**
     * @var Source
     */
    private Source $source;

    /**
     * @param EntityManagerInterface  $entityManager           EntityManagerInterface
     * @param CallService             $callService             CallService
     * @param SynchronizationService  $synchronizationService  SynchronizationService
     * @param MappingService          $mappingService          MappingService
     * @param GithubPubliccodeService $githubPubliccodeService GithubPubliccodeService
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
        $this->symfonyStyle = $io;
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
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com']);
        if ($this->source === false) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No source found for https://api.github.com');
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
        $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
        if ($this->repositoryEntity === false) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');
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
        $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.component.schema.json']);
        if ($this->componentEntity === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');
        }

        return $this->componentEntity;
    }//end getComponentEntity()

    /**
     * Get the application entity.
     *
     * @return ?Entity
     */
    public function getApplicationEntity(): ?Entity
    {
        $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.application.schema.json']);
        if ($this->applicationEntity === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.application.schema.json');
        }

        return $this->applicationEntity;
    }//end getApplicationEntity()

    /**
     * Get the organisation entity.
     *
     * @return ?Entity
     */
    public function getOrganizationEntity(): ?Entity
    {
        $this->organizationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
        if ($this->organizationEntity === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');
        }

        return $this->organizationEntity;
    }//end getOrganizationEntity()

    /**
     * Get the contractors entity.
     *
     * @return ?Entity
     */
    public function getContractorEntity(): ?Entity
    {
        $this->contractorsEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.contractor.schema.json']);
        if ($this->contractorsEntity === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.contractor.schema.json');
        }

        return $this->contractorsEntity;
    }//end getContractorEntity()

    /**
     * Get the contact entity.
     *
     * @return ?Entity
     */
    public function getContactEntity(): ?Entity
    {
        $this->contactsEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.contact.schema.json']);
        if ($this->contactsEntity === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.contact.schema.json');
        }

        return $this->contactsEntity;
    }//end getContactEntity()

    /**
     * Get the dependency entity.
     *
     * @return ?Entity
     */
    public function getDependencyEntity(): ?Entity
    {
        $this->dependencyEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.dependency.schema.json']);
        if ($this->dependencyEntity === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://opencatalogi.nl/oc.dependency.schema.json');
        }

        return $this->dependencyEntity;
    }//end getDependencyEntity()

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        $this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/publiccode/component']);
        if ($this->repositoryMapping === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No mapping found for https://api.github.com/publiccode/component');

            return null;
        }

        return $this->repositoryMapping;
    }//end getRepositoryMapping()

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
        // Make sync object.
        $source = $this->getGithubSource();
        if ($source === false) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No source found when trying to get a Repository with publiccode url: '.$repositoryUrl);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/repos/'.$repositoryUrl.'/contents/publiccode.yaml');
        } catch (Exception $e) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('Error found trying to fetch /repos/'.$repositoryUrl.'/contents/publiccode.yaml '.$e->getMessage());
        }

        if (isset($response) === false) {
            try {
                $response = $this->callService->call($source, '/repos/'.$repositoryUrl.'/contents/publiccode.yml');
            } catch (Exception $e) {
                isset($this->symfonyStyle) && $this->symfonyStyle->error('Error found trying to fetch /repos/'.$repositoryUrl.'/contents/publiccode.yml '.$e->getMessage());
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
        $repositoryMapping = $this->getRepositoryMapping();
        if ($repositoryMapping === null) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No repositoriesMapping found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        $url = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');
        $publiccode = $this->getPubliccodeFromUrl($url);
        if ($publiccode === true) {
            $this->githubPubliccodeService->mapPubliccode($repository, $publiccode, $repositoryMapping);
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
            // If we are testing for one repository
            if ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) {
                if ($repository->getValue('publiccode_url') === false) {
                    $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
                }
            } else {
                isset($this->symfonyStyle) && $this->symfonyStyle->error('Could not find given repository');
            }
        } else {
            if ($repositoryEntity = $this->getRepositoryEntity() === false) {
                isset($this->symfonyStyle) && $this->symfonyStyle->error('No RepositoryEntity found when trying to import a Repository ');
            }

            // If we want to do it for al repositories
            isset($this->symfonyStyle) && $this->symfonyStyle->info('Looping through repositories');
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                if ($repository->getValue('publiccode_url') === false) {
                    $this->enrichRepositoryWithPubliccode($repository, $repository->getValue('url'));
                }
            }
        }
        $this->entityManager->flush();

        isset($this->symfonyStyle) && $this->symfonyStyle->success('enrichPubliccodeFromGithubUrlHandler finished');

        return $this->data;
    }//end enrichPubliccodeFromGithubUrlHandler()
}
