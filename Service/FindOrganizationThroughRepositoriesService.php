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
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubPubliccodeService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var Entity
     */
    private Entity $organisationEntity;

    /**
     * @var Mapping
     */
    private Mapping $organisationMapping;

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
    private Source $githubApi;

    /**
     * @var Entity|null
     */
    private ?Entity $componentEntity;

    /**
     * @param CallService             $callService             CallService
     * @param EntityManagerInterface  $entityManager           EntityManagerInterface
     * @param GithubApiService        $githubApiService        GithubApiService
     * @param GithubPubliccodeService $githubPubliccodeService GithubPubliccodeService
     * @param SynchronizationService  $synchronizationService  SynchronizationService
     * @param MappingService          $mappingService          MappingService
     */
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
        $this->syncService = $synchronizationService;
        $this->mappingService = $mappingService;

        $this->configuration = [];
        $this->data = [];
    }

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style The symfony style
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;
        $this->githubPubliccodeService->setStyle($style);
        $this->syncService->setStyle($style);
        $this->mappingService->setStyle($style);

        return $this;
    }

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        $this->githubApi = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com']);
        if ($this->githubApi === false) {
            isset($this->style) && $this->style->error('No source found for https://api.github.com');

            return null;
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
        $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
        if ($this->organisationEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');

            return null;
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
        $this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
        if ($this->repositoryEntity) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');

            return null;
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
        $this->organisationMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/organisation']);
        if ($this->organisationMapping === false) {
            isset($this->style) && $this->style->error('No mapping found for https://api.github.com/organisation');

            return null;
        }

        return $this->organisationMapping;
    }

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.component.schema.json']);
        if ($this->componentEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');

            return null;
        }

        return $this->componentEntity;
    }

    /**
     * Get the repository mapping.
     *
     * @return ?bool
     */
    public function checkGithubAuth(): ?bool
    {
        if ($this->githubApi->getApiKey() === false) {
            isset($this->style) && $this->style->error('No auth set for Source: GitHub API');

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
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get a Repository with slug: '.$slug);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/repos/'.$slug);
        } catch (Exception $e) {
            isset($this->style) && $this->style->error('Error found trying to fetch /repos/'.$slug.' '.$e->getMessage());
        }

        if (isset($response)) {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
            isset($this->style) && $this->style->info("Fetch and decode went succesfull for /repos/$slug");

            return $repository;
        }

        return null;
    }

    /**
     * Get an organisation from https://api.github.com/orgs/{org}.
     *
     * @param string $name
     *
     * @return ObjectEntity|null
     */
    public function getOrganisation(string $name): ?ObjectEntity
    {
        // Do we have a source
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get an Organisation with name: '.$name);

            return null;
        }

        if ($this->checkGithubAuth() === false) {
            return null;
        }

        isset($this->style) && $this->style->info('Getting organisation '.$name);
        $response = $this->callService->call($source, '/orgs/'.$name);

        $organisation = json_decode($response->getBody()->getContents(), true);

        if ($organisation === false) {
            isset($this->style) && $this->style->error('Could not find an organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }
        $organisation = $this->importOrganisation($organisation);
        if ($organisation === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->style) && $this->style->success('Found organisation with name: '.$name);

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
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to import an Organisation '.isset($organisation['login']) ? $organisation['login'] : '');

            return null;
        }
        if ($organisationEntity = $this->getOrganisationEntity() === false) {
            isset($this->style) && $this->style->error('No organisationEntity found when trying to import an Organisation '.isset($organisation['login']) ? $organisation['login'] : '');

            return null;
        }
        if ($organisationMapping = $this->getOrganisationMapping() === false) {
            isset($this->style) && $this->style->error('No organisationMapping found when trying to import an Organisation '.isset($organisation['login']) ? $organisation['login'] : '');

            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $organisationEntity, $organisation['id']);

        isset($this->style) && $this->style->comment('Mapping object'.$organisation['login']);
        isset($this->style) && $this->style->comment('The mapping object '.$organisationMapping);

        isset($this->style) && $this->style->comment('Checking organisation '.$organisation['login']);
        $synchronization->setMapping($organisationMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $organisation);
        isset($this->style) && $this->style->comment('Organisation synchronization created with id: '.$synchronization->getId()->toString());

        return $synchronization->getObject();
    }

    /**
     * Get an organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param string $name
     *
     * @return array|null
     */
    public function getOrganisationRepos(string $name): ?array
    {
        // Do we have a source
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get an Organisation with name: '.$name);

            return null;
        }

        $repositoryEntity = $this->getRepositoryEntity();
        if ($repositoryEntity === false) {
            isset($this->style) && $this->style->error('No RepositoryEntity found when trying to import a Component '.$name);

            return null;
        }

        if ($this->checkGithubAuth() === false) {
            return null;
        }

        isset($this->style) && $this->style->info('Getting repos from organisation '.$name);
        $response = $this->callService->call($source, '/orgs/'.$name.'/repos');

        $repositories = json_decode($response->getBody()->getContents(), true);

        if ($repositories === false) {
            isset($this->style) && $this->style->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }

        $owns = [];
        foreach ($repositories as $repository) {
            $repositoryObject = $this->githubPubliccodeService->importRepository($repository);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->flush();

            if ($component = $repositoryObject->getValue('component')) {
                $owns[] = $component;
                continue;
            }

            $componentEntity = $this->getComponentEntity();
            if ($componentEntity === false) {
                isset($this->style) && $this->style->error('No ComponentEntity found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

                return null;
            }

            $component = new ObjectEntity($componentEntity);
            $component->hydrate([
                'name' => $repositoryObject->getValue('name'),
                'url'  => $repositoryObject,
            ]);
            $repositoryObject->setValue('component', $component);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->persist($component);
            $this->entityManager->flush();

            $owns[] = $component;
        }

        isset($this->style) && $this->style->success('Found '.count($owns).' repos from organisation with name: '.$name);

        return $owns;
    }

    /**
     * @param ObjectEntity $repository           the repository where we want to find an organisation for
     * @param ?array       $createdOrganizations the already created organizations during a parent loop so we dont waste time/performance on the same organizations
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository, array &$createdOrganizations = []): ?ObjectEntity
    {
        if ($repository->getValue('url') === false) {
            isset($this->style) && $this->style->error('Repository url not set');

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
                isset($this->style) && $this->style->info("Trying to fetch repository from: $url");

                $github = $this->getRepositoryFromUrl($url);
                if ($github === false) {
                    return null;
                }

                // Check if we didnt already loop through this organization during this loop
                if (isset($github['owner']['login']) && in_array($github['owner']['login'], $createdOrganizations)) {
                    isset($this->style) && $this->style->info('Organization already created/updated during this loop, continuing.');

                    return null;
                }

                $repository = $this->githubPubliccodeService->importRepository($github);

                if ($github['owner']['type'] === 'Organization') {

                    // get organisation from github and set the property
                    $organisation = $this->getOrganisation($github['owner']['login']);

                    $repository->setValue('organisation', $organisation);
                    $this->entityManager->persist($repository);

                    // get organisation component and set the property
                    $owns = $this->getOrganisationRepos($github['owner']['login']);
                    $organisation->setValue('owns', $owns);

                    $this->entityManager->persist($organisation);
                    $this->entityManager->flush();

                    $createdOrganizations[] = $github['owner']['login'];
                } else {
                    isset($this->style) && $this->style->error('No organisation found for fetched repository');
                }
                break;
            case 'gitlab':
                // hetzelfde maar dan voor gitlab
                // @TODO code for gitlab as we do for github repositories
                isset($this->style) && $this->style->error("We dont do gitlab yet ($url)");
                break;
            default:
                isset($this->style) && $this->style->error("We dont know this type source yet ($source)");
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
            !$repository && $this->style->error('Could not find given repository');
        } else {
            $repositoryEntity = $this->getRepositoryEntity();
            if ($repositoryEntity === false) {
                isset($this->style) && $this->style->error('No RepositoryEntity found when trying to import a Repository ');
            }

            // If we want to do it for al repositories
            isset($this->style) && $this->style->info('Looping through repositories');
            $createdOrganizations = [];
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                $this->enrichRepositoryWithOrganisation($repository, $createdOrganizations);
            }
        }
        $this->entityManager->flush();

        isset($this->style) && $this->style->success('findOrganizationThroughRepositoriesHandler finished');

        return $this->data;
    }
}
