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

/**
 * Loops through repositories (https://opencatalogi.nl/oc.repository.schema.json) and updates it with fetched organization info.
 */
class FindOrganizationThroughRepositoriesService
{
    private EntityManagerInterface $entityManager;
    private array $configuration;
    private array $data;
    private SymfonyStyle $io;
    private CallService $callService;
    private GithubApiService $githubApiService;
    private GithubPubliccodeService $githubPubliccodeService;
    private SynchronizationService $synchronizationService;
    private MappingService $mappingService;

    private Entity $organisationEntity;
    private Mapping $organisationMapping;
    private Entity $repositoryEntity;
    private Mapping $repositoryMapping;
    private Source $githubApi;

    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        GithubPubliccodeService $githubPubliccodeService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
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

        return $this;
    }

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->githubApi = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com'])) {
            isset($this->io) && $this->io->error('No source found for https://api.github.com');
        }

        return $this->githubApi;
    }

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getOrganisationEntity(): ?Entity
    {
        if (!$this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');
        }

        return $this->organisationEntity;
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
     * Get the repositories mapping.
     *
     * @return ?Mapping
     */
    public function getOrganisationMapping(): ?Mapping
    {
        if (!$this->organisationMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/organisation'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/organisation');
        }

        return $this->organisationMapping;
    }

    /**
     * Get the repository mapping.
     *
     * @return ?bool
     */
    public function checkGithubAuth(): ?bool
    {
        if (!$this->githubApi->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: GitHub API');

            return false;
        }

        return true;
    }

    /**
     * This function fetches repository data.
     *
     * @param string $slug endpoint to request
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    public function getRepositoryFromUrl(string $slug)
    {
        // make sync object
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with slug: '.$slug);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/repos/'.$slug);
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /repos/'.$slug.' '.$e->getMessage());
        }

        if (isset($response)) {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
            isset($this->io) && $this->io->success("Fetch and decode went succesfull for /repos/$slug");

            return $repository;
        }

        return null;
    }

    /**
     * Get a organisation from https://api.github.com/orgs/{org}.
     *
     * @param string $name
     *
     * @return ObjectEntity|null
     */
    public function getOrganisation(string $name): ?ObjectEntity
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Organisation with name: '.$name);

            return null;
        }

        if (!$this->checkGithubAuth()) {
            return null;
        }

        isset($this->io) && $this->io->success('Getting organisation '.$name);
        $response = $this->callService->call($source, '/orgs/'.$name);

        $organisation = json_decode($response->getBody()->getContents(), true);

        if (!$organisation) {
            isset($this->io) && $this->io->error('Could not find a organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }
        $organisation = $this->importOrganisation($organisation);
        if ($organisation === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found organisation with name: '.$name);

        return $organisation;
    }

    /**
     * @param $organisation
     *
     * @return ObjectEntity|null
     */
    public function importOrganisation($organisation): ?ObjectEntity
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import a Organisation '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }
        if (!$organisationEntity = $this->getOrganisationEntity()) {
            isset($this->io) && $this->io->error('No organisationEntity found when trying to import a Organisation '.isset($github['owner']['login']) ? $github['owner']['login'] : '');

            return null;
        }
        if (!$organisationMapping = $this->getOrganisationMapping()) {
            isset($this->io) && $this->io->error('No organisationMapping found when trying to import a Organisation '.isset($github['owner']['login']) ? $github['owner']['login'] : '');

            return null;
        }

        $synchronization = $this->synchronizationService->findSyncBySource($source, $organisationEntity, $organisation['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$organisation['login']);
        isset($this->io) && $this->io->comment('The mapping object '.$organisationMapping);

        isset($this->io) && $this->io->comment('Checking organisation '.$organisation['login']);
        $synchronization->setMapping($organisationMapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $organisation);
        isset($this->io) && $this->io->comment('Organisation synchronization created with id: '.$synchronization->getId()->toString());

        return $synchronization->getObject();
    }

    /**
     * Get a organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param string $name
     *
     * @return array|null
     */
    public function getOrganisationRepos(string $name): ?array
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Organisation with name: '.$name);

            return null;
        }

        if (!$this->checkGithubAuth()) {
            return null;
        }

        isset($this->io) && $this->io->success('Getting repos from organisation '.$name);
        $response = $this->callService->call($source, '/orgs/'.$name.'/repos');

        $repositories = json_decode($response->getBody()->getContents(), true);

        if (!$repositories) {
            isset($this->io) && $this->io->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }

        $owns = [];
        foreach ($repositories as $repository) {
            $owns[] = $repository['html_url'];
        }

        isset($this->io) && $this->io->success('Found '.count($owns).' repos from organisation with name: '.$name);

        return $owns;
    }

    /**
     * @param ObjectEntity $repository the repository where we want to find an organisation for
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository): ?ObjectEntity
    {
        if (!$repository->getValue('url')) {
            isset($this->io) && $this->io->error('Repository url not set');

            return null;
        }
        $source = $repository->getValue('source');
        $url = $repository->getValue('url');

        if ($source == null) {
            $domain = parse_url($url, PHP_URL_HOST);
            $domain == 'github.com' && $source = 'github';
            $domain == 'gitlab.com' && $source = 'gitlab';
        }

        $url = trim(parse_url($url, PHP_URL_PATH), '/');

        switch ($source) {
            case 'github':
                // let's get the repository datar
                isset($this->io) && $this->io->info("Trying to fetch repository from: $url");
                $github = $this->getRepositoryFromUrl($url);
                $repository = $this->githubPubliccodeService->importRepository($github);

                if ($github['owner']['type'] === 'Organization') {

                    // get organisation from github and set the property
                    $organisation = $this->getOrganisation($github['owner']['login']);

                    $repository->setValue('organisation', $organisation);
                    $this->entityManager->persist($repository);

                    // get organisation repos and set the property
                    $owns = $this->getOrganisationRepos($github['owner']['login']);
                    $organisation->setValue('owns', $owns);

                    $this->entityManager->persist($organisation);
                    $this->entityManager->flush();
                } else {
                    isset($this->io) && $this->io->error('No organisation found for fetched repository');
                }
                break;
            case 'gitlab':
                // hetzelfde maar dan voor gitlab
                // @TODO code for gitlab as we do for github repositories
                isset($this->io) && $this->io->error("We dont do gitlab yet ($url)");
                break;
            default:
                isset($this->io) && $this->io->error("We dont know this type source yet ($source)");
                break;
        }

        isset($repositoryObject) && $this->entityManager->persist($repositoryObject);

        return null;
    }

    /**
     * Makes sure the action the action can actually runs and then executes functions to update a repository with fetched organization info.
     *
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     * @param ?array $repositoryId  optional repository id for testing for a single repository
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */
    public function findOrganizationThroughRepositoriesHandler(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($repositoryId) {
            // If we are testing for one repository
            ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) && $this->enrichRepositoryWithOrganisation($repository);
            !$repository && $this->io->error('Could not find given repository');
        } else {
            if (!$repositoryEntity = $this->getRepositoryEntity()) {
                isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Repository ');
            }

            // If we want to do it for al repositories
            isset($this->io) && $this->io->info('Looping through repositories');
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                $this->enrichRepositoryWithOrganisation($repository);
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('findOrganizationThroughRepositoriesHandler finished');

        return $this->data;
    }
}
