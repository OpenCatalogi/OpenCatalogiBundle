<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Loops through organizations (https://opencatalogi.nl/oc.organisation.schema.json)
 * and tries to find a opencatalogi.yaml on github with its organization name to update the organization object with that fetched opencatalogi.yaml data.
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
    private Source $rawGithubusercontent;

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
            
            return null;
        }

        return $this->githubApi;
    }

    /**
     * Get the github raw.githubusercontent source.
     *
     * @return ?Source
     */
    public function getRawGithubSource(): ?Source
    {
        if (!$this->rawGithubusercontent = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://raw.githubusercontent.com'])) {
            isset($this->io) && $this->io->error('No source found for https://raw.githubusercontent.com');
            
            return null;
        }

        return $this->rawGithubusercontent;
    }

    /**
     * Get the organisation entity.
     *
     * @return ?Entity
     */
    public function getOrganisationEntity(): ?Entity
    {
        if (!$this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');
            
            return null;
        }

        return $this->organisationEntity;
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
        // make sync object
        if (!$source = $this->getRawGithubSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a openCatalogi.yaml from .github file with organisation name: '.$organizationName);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/'.$organizationName.'/.github/main/openCatalogi.yaml');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName.'/github/main/openCatalogi.yaml: '.$e->getMessage());
        }

        if (!isset($response)) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/main/openCatalogi.yml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName.'/github/main/openCatalogi.yml: '.$e->getMessage());
            }
        }

        if (!isset($response)) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/master/openCatalogi.yaml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName.'/github/master/openCatalogi.yaml: '.$e->getMessage());
            }
        }

        if (!isset($response)) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/master/openCatalogi.yml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName.'/github/master/openCatalogi.yml: '.$e->getMessage());
            }
        }

        if (isset($response)) {

            // @TODO use decodeResponse from the callService
            $openCatalogi = Yaml::parse($response->getBody()->getContents());
            isset($this->io) && $this->io->success("Fetch and decode went succesfull '/'.$organizationName.'/.github/master/openCatalogi.yml', '/'.$organizationName.'/.github/master/openCatalogi.yaml'");

            return $openCatalogi;
        }

        return null;
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
        // make sync object
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get an Organisation .github file with name: '.$organizationName);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/repos/'.$organizationName.'/.github');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /repos/'.$organizationName.'/.github: '.$e->getMessage());
        }

        if (isset($response)) {
            $githubRepo = $this->callService->decodeResponse($source, $response, 'application/json');
            isset($this->io) && $this->io->success('Fetch and decode went succesfull for /repos/'.$organizationName.'/.github');

            return $githubRepo;
        }

        return null;
    }

    /**
     * Fetches opencatalogi.yaml info with function getOpenCatalogiFromGithubRepo for an organization and updates the given organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @return void
     */
    private function getOrganizationCatalogi(ObjectEntity $organization): void
    {
        if ($githubRepo = $this->getGithubRepoFromOrganization($organization->getValue('name'))) {
            isset($this->io) && $this->io->success('Github repo found and fetched for '.$organization->getName());
            if ($openCatalogi = $this->getOpenCatalogiFromGithubRepo($organization->getValue('name'))) {
                isset($this->io) && $this->io->success('OpenCatalogi.yml or OpenCatalogi.yaml found and fetched for '.$organization->getName());

                // we dont want to set the name, this has to be the login property from the github api
                $allowedKeys = ['description', 'type', 'telephone', 'email', 'website', 'logo', 'catalogusAPI', 'uses', 'supports'];
                $organization->hydrate(array_intersect_key($openCatalogi, array_flip($allowedKeys)));
                $this->entityManager->persist($organization);
                $this->entityManager->flush();
                isset($this->io) && $this->io->success($organization->getName().' succesfully updated with fetched openCatalogi info');
            }
        }
    }

    /**
     * Makes sure the action the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
     *
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @return array|null dataset at the end of the handler              (not needed here)
     */
    public function findGithubRepositoryThroughOrganizationHandler(?array $data = [], ?array $configuration = [], ?string $organisationId = null): ?array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($organisationId) {
            // If we are testing for one repository
            $organisation = $this->entityManager->find('App:ObjectEntity', $organisationId);
            if ($organisation && $organisation->getValue('name') && $organisation->getValue('github')) {
                $this->getOrganizationCatalogi($organisation);
            } else {
                isset($this->io) && $this->io->error('Could not find given organisation');
                
                return null;
            }
        } else {
            if (!$organisationEntity = $this->getOrganisationEntity()) {
                isset($this->io) && $this->io->error('No OrganisationEntity found when trying to import an Organisation');
                
                return null;
            }

            // If we want to do it for al repositories
            isset($this->io) && $this->io->info('Looping through organisations');
            foreach ($organisationEntity->getObjectEntities() as $organisation) {
                if ($organisation->getValue('name') && $organisation->getValue('github')) {
                    $this->getOrganizationCatalogi($organisation);
                }
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('findRepositoriesThroughOrganisationHandler finished');

        return $this->data;
    }
}
