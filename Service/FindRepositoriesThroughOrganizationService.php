<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Exception;

class FindRepositoriesThroughOrganizationService
{
    private SymfonyStyle $io;
    private CallService $callService;
    private EntityManagerInterface $entityManager;
    private GithubPubliccodeService $githubPubliccodeService;
    private array $configuration;
    private array $data;

    private Entity $organisationEntity;

    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GithubPubliccodeService $githubPubliccodeService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;
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
        $this->githubPubliccodeService->setStyle($this->io);

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
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with slug: '.$slug);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/repos/'.$slug);
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
        } catch (Exception $e) {
            isset($this->io) && $this->io->success("Fetching or decoding failed for {$source->getLocation()}/repos/$slug");
            
            return null;
        }


        isset($this->io) && $this->io->success("Fetch and decode went succesfull for /repos/$slug");

        return $repository;
    }

    /**
     * @param string       $repositoryUrl
     * @param ObjectEntity $organisation
     *
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
                    isset($this->io) && $this->io->write("A repository created for organization: {$organisation->getName()}");
                } else {
                    isset($this->io) && $this->io->error("Could not create a repository for organization: {$organisation->getName()}");
                }

                return $repository;
            case 'gitlab':
                // hetelfde maar dan voor gitlab
                isset($this->io) && $this->io->error("Could not create a repository for organization: {$organisation->getName()}, we dont support gitlab");
            default:
                // error voor onbeknd type
                isset($this->io) && $this->io->error("Could not create a repository for organization: {$organisation->getName()}, unknown source type");
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
        $repositoriesCreated = 0;
        if ($owns = $organisation->getValue('owns')) {
            isset($this->io) && $this->io->write("Looping through {$organisation->getName()} its 'owns' repositories to create");
            foreach ($owns as $repositoryUrl) {
                $this->getOrganisationRepos($repositoryUrl, $organisation) && $repositoriesCreated = $repositoriesCreated + 1;
            }
        }

        if ($uses = $organisation->getValue('uses')) {
            isset($this->io) && $this->io->write("Looping through {$organisation->getName()} its 'uses' repositories to create");
            foreach ($uses as $repositoryUrl) {
                $this->getOrganisationRepos($repositoryUrl, $organisation) && $repositoriesCreated = $repositoriesCreated + 1;
            }
        }

        if ($supports = $organisation->getValue('supports')) {
            isset($this->io) && $this->io->write("Looping through {$organisation->getName()} its 'supports' repositories to create");
            foreach ($supports as $repositoryUrl) {
                $this->getOrganisationRepos($repositoryUrl, $organisation) && $repositoriesCreated = $repositoriesCreated + 1;
            }
        }
        $repositoriesCreated && isset($this->io) && $this->io->write("$repositoriesCreated repositories created/updated for organization: {$organisation->getName()}", true);

        return $organisation;
    }

    /**
     * @param ?array      $data           data set at the start of the handler (not needed here)
     * @param ?array      $configuration  configuration of the action          (not needed here)
     * @param string|null $organisationId
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */
    public function findRepositoriesThroughOrganisationHandler(?array $data = [], ?array $configuration = [], ?string $organisationId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($organisationId) {
            // If we are testing for one repository
            ($organisation = $this->entityManager->find('App:ObjectEntity', $organisationId)) && $this->enrichRepositoryWithOrganisationRepos($organisation);
            if (!$organisation) {
                isset($this->io) && $this->io->error('Could not find given repository');

                return null;
            }
        } else {
            if (!$organisationEntity = $this->getOrganisationEntity()) {
                isset($this->io) && $this->io->error('No OrganisationEntity found when trying to import a Organisation');

                return null;
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
