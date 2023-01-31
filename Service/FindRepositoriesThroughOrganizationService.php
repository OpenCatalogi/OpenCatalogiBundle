<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Service\CallService;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubPubliccodeService;
use Symfony\Component\Console\Style\SymfonyStyle;

class FindRepositoriesThroughOrganizationService
{
    private SymfonyStyle $io;
    private CallService $callService;
    private EntityManagerInterface $entityManager;
    private GithubApiService $githubService;
    private GithubPubliccodeService $githubPubliccodeService;
    private array $configuration;
    private array $data;

    private Entity $organisationEntity;


    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GithubApiService $githubService,
        GithubPubliccodeService $githubPubliccodeService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->githubService = $githubService;
        $this->githubPubliccodeService = $githubPubliccodeService;
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
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with slug: ' . $slug);

            return null;
        }

        $response = $this->callService->call($source, '/repos/'.$slug);


        $repository = $this->callService->decodeResponse($source, $response, 'application/json');
        isset($this->io) && $this->io->success("Fetch and decode went succesfull for /repos/$slug");

        return $repository;
    }

    /**
     * @param string $repositoryUrl
     * @param ObjectEntity $organisation
     * @return ObjectEntity|null
     */
    public function getOrganisationRepos(string $repositoryUrl, ObjectEntity $organisation): ?ObjectEntity
    {
        $source = null;
        $domain = parse_url($repositoryUrl, PHP_URL_HOST);
        $domain == 'github.com' && $source = 'github';
        $domain == 'gitlab.com' && $source = 'gitlab';


        $url = trim(parse_url($repositoryUrl, PHP_URL_PATH), '/');

        switch ($source) {
            case 'github':
                // let's get the repository data
                $github = $this->getRepositoryFromUrl($url);
                $repository = $this->githubPubliccodeService->importRepository($github);

                if ($repository) {
                    $repository->setValue('organisation', $organisation);
                    $this->entityManager->persist($repository);
                }

                return $repository;
            case 'gitlab':
                // hetelfde maar dan voor gitlab
            default:
                // error voor onbeknd type
        }

        return null;
    }

    /**
     * @param ObjectEntity $organisation
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function enrichRepositoryWithOrganisationRepos(ObjectEntity $organisation): ?ObjectEntity
    {
        if ($owns = $organisation->getValue('owns')) {
            foreach ($owns as $repositoryUrl) {
                $repository = $this->getOrganisationRepos($repositoryUrl, $organisation);
            }
        }

        if ($uses = $organisation->getValue('uses')) {
            foreach ($uses as $repositoryUrl) {
                $repository = $this->getOrganisationRepos($repositoryUrl, $organisation);
            }
        }

        if ($supports = $organisation->getValue('supports')) {
            foreach ($supports as $repositoryUrl) {
                $repository = $this->getOrganisationRepos($repositoryUrl, $organisation);
            }
        }

        return $organisation;
    }

    /**
     * @param ?array $data data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     * @param string|null $organisationId
     * @return array dataset at the end of the handler                   (not needed here)
     */
    public function findRepositoriesThroughOrganisationHandler(?array $data = [], ?array $configuration = [], ?string $organisationId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($organisationId) {
            // If we are testing for one repository
            ($organisation = $this->entityManager->find('App:ObjectEntity', $organisationId)) && $this->enrichRepositoryWithOrganisationRepos($organisation);
            !$organisation && isset($this->io) && $this->io->error('Could not find given repository');
        } else {
            if (!$organisationEntity = $this->getOrganisationEntity()) {
                isset($this->io) && $this->io->error('No OrganisationEntity found when trying to import a Organisation');
            }

            // If we want to do it for al repositories
            isset($this->io) && $this->io->info('Looping through organisations');
            foreach ($organisationEntity->getObjectEntities() as $organisation) {
                $this->enrichRepositoryWithOrganisationRepos($organisation);
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('findRepositoriesThroughOrganisationHandler finished');

        return $this->data;
    }
}
