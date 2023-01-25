<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Response;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Service\CallService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finds opencatalogi.yml for organizations and fills the organization with data
 */
class FindGitRepoThroughOrganizationService
{
    private EntityManagerInterface $entityManager;
    private array $configuration;
    private array $data;
    private SymfonyStyle $io;
    private CallService $callService;

    private Entity $organisationEntity;
    private Source $githubApi;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;

        $this->configuration = [];
        $this->data = [];
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $slug
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    private function getOpenCatalogiFromGithubRepo(string $organizationName): ?array
    {
        // if ($this->checkGithubKey()) {
        //     return $this->checkGithubKey();
        // }

        // try {
        //     $response = $this->githubusercontentClient->request('GET', $organizationName . '/.github/main/openCatalogi.yaml');
        // } catch (ClientException $exception) {
        //     var_dump($exception->getMessage());

        //     return null;
        // }

        // if ($response == null) {
        //     try {
        //         $response = $this->githubusercontentClient->request('GET', $organizationName . '/.github/main/openCatalogi.yml');
        //     } catch (ClientException $exception) {
        //         var_dump($exception->getMessage());

        //         return null;
        //     }
        // }

        // try {
        //     $openCatalogi = Yaml::parse($response->getBody()->getContents());
        // } catch (ParseException $exception) {
        //     var_dump($exception->getMessage());

        //     return null;
        // }

        return $openCatalogi ?? [];
    }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $slug
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    private function getGithubRepoFromOrganization(string $organizationName): ?array
    {
        try {
            $response = $this->callService->call($this->githubApi, '/repos/' . $organizationName . '/.github', 'GET');
        } catch (\Exception $e) {
            // @TODO Log error with monolog ?
            var_dump($e->getMessage());
            return null;
        }

        $githubRepo = $this->callService->decodeResponse($this->githubApi, $response, 'application/json');

        return $githubRepo;
    }

    /**
     * @param ObjectEntity $organization
     *
     * @return void
     */
    private function getOrganizationCatalogi(ObjectEntity $organization): void
    {
        if ($this->getGithubRepoFromOrganization($organization->getValue('name'))) {
            if ($catalogi = $this->getOpenCatalogiFromGithubRepo($organization->getValue('name'))) {
                try {
                    $organization->hydrate([
                        'name'         => $catalogi['name'],
                        'description'  => $catalogi['description'],
                        'type'         => $catalogi['type'],
                        'telephone'    => $catalogi['telephone'],
                        'email'        => $catalogi['email'],
                        'website'      => $catalogi['website'],
                        'logo'         => $catalogi['logo'],
                        'catalogusAPI' => $catalogi['catalogusAPI'],
                        'uses'         => $catalogi['uses'],
                        'supports'     => $catalogi['supports']
                    ]);
                    $this->entityManager->persist($organization);
                    $this->entityManager->flush();
                } catch (Exception $exception) {
                    isset($this->io) && $this->io->error("Data error for {$organization->getValue('name')}, {$exception->getMessage()}");
                }

            }
        }
    }

    private function getRequiredGatewayObjects(array $data, array $configuration): ?array
    {
        $githubApi = $this->entityManager->getRepository('App:Gateway')->find($this->configuration['githubApi']);

        !isset($this->organisationEntity) && $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
        if (!isset($this->organisationEntity)) {
            isset($this->io) && $this->io->error('Could not find a entity for https://opencatalogi.nl/oc.organisation.schema.json');
            return $data;
        }

        !isset($this->githubApi) && $this->githubApi = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => 'GitHub API']);
        if (!isset($this->githubApi)) {
            isset($this->io) && $this->io->error('Could not find a Source for Github API');
            return $data;
        };
    }

    /**
     * @param array $data          data set at the start of the handler
     * @param array $configuration configuration of the action
     *
     * @throws GuzzleException
     *
     * @return array dataset at the end of the handler
     */
    public function findGitRepoThroughOrganizationHandler(array $data, array $configuration): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $this->getRequiredGatewayObjects($data, $configuration);

        // If we want to do it for al repositories
        foreach ($this->organisationEntity->getObjectEntities() as $organization) {
            if ($organization->getValue('github')) {
                // get org name and search if the org has an .github repository
                $this->getOrganizationCatalogi($organization);
            }
        }

        return $this->data;
    }
}
