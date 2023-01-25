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
use Symfony\Component\Yaml\Yaml;

/**
 * Loops through organizations (https://opencatalogi.nl/oc.organisation.schema.json)
 * and tries to find a opencatalogi.yaml on github with its organization name to update the organization object with that fetched opencatalogi.yaml data
 */
class FindGithubRepositoryThroughOrganizationService
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
        try {
            $response = $this->callService->call($this->githubApi, '/'.$organizationName . '/.github/main/openCatalogi.yaml');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName . '/.github/main/openCatalogi.yaml: ' . $e->getMessage());
            return null;
        }

        if (!$response) {
            try {
                $response = $this->callService->call($this->githubApi, '/'.$organizationName . '/.github/main/openCatalogi.yml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName . '/.github/main/openCatalogi.yml: ' . $e->getMessage());
                return null;
            }
        }

        try {
            $openCatalogi = Yaml::parse($response->getBody()->getContents());
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to parse fetched /'.$organizationName . '/.github/main/openCatalogi.yml: ' . $e->getMessage());

            return null;
        }

        return $openCatalogi ?? null;
    }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $organizationName used as path to fetch from
     *
     * @return array|null
     */
    private function getGithubRepoFromOrganization(string $organizationName): ?array
    {
        try {
            $response = $this->callService->call($this->githubApi, '/repos/' . $organizationName . '/.github', 'GET');
        } catch (\Exception $e) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Error found trying to fetch ' . $organizationName . '/.github : ' . $e->getMessage());
            return null;
        }

        $githubRepo = $this->callService->decodeResponse($this->githubApi, $response, 'application/json');

        return $githubRepo;
    }

    /**
     * Fetches opencatalogi.yaml info with function getOpenCatalogiFromGithubRepo for a organization and updates the given organization
     * 
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @return void
     */
    private function getOrganizationCatalogi(ObjectEntity $organization): void
    {
        if ($this->getGithubRepoFromOrganization($organization->getValue('name'))) {
            isset($this->io) && $this->io->success('Github repo found and fetched for '.$organization->getName());
            if ($catalogi = $this->getOpenCatalogiFromGithubRepo($organization->getValue('name'))) {
                isset($this->io) && $this->io->success('OpenCatalogi.yml found and fetched for '.$organization->getName());
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
                    isset($this->io) && $this->io->success($organization->getName().' succesfully updated with fetched catalogi info');
                } catch (Exception $exception) {
                    // @TODO Monolog ?
                    isset($this->io) && $this->io->error("Could not hydrate {$organization->getName()} with new catalogi data, {$exception->getMessage()}");
                }
            }
        }
    }

    /**
     * Makes sure this action has all the gateway objects it needs
     */
    private function getRequiredGatewayObjects()
    {
        !isset($this->organisationEntity) && $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
        if (!isset($this->organisationEntity)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for https://opencatalogi.nl/oc.organisation.schema.json');
            return [];
        }

        !isset($this->githubApi) && $this->githubApi = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => 'GitHub API']);
        if (!isset($this->githubApi)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a Source for Github API');
            return [];
        };
    }

    /**
     * Makes sure the action the action can actually runs and then executes functions to update a organization with fetched opencatalogi.yaml info
     * 
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */ 
    public function findGithubRepositoryThroughOrganizationHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $this->getRequiredGatewayObjects($data, $configuration);
        isset($this->io) && $this->io->success('Action config succesfully loaded');

        // If we want to do it for al repositories
        foreach ($this->organisationEntity->getObjectEntities() as $organization) {
            if ($organization->getValue('github')) {
                isset($this->io) && $this->io->success('Github value set for '.$organization->getName());
                // get org name and search if the org has an .github repository
                $this->getOrganizationCatalogi($organization);
            }
        }
        
        isset($this->io) && $this->io->success('findGithubRepositoryThroughOrganizationHandler finished');

        return $this->data;
    }
}
