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
     * @var Entity
     */
    private Entity $organisationEntity;

    /**
     * @var Entity|null
     */
    private ?Entity $componentEntity;

    /**
     * @var Source
     */
    private Source $githubApi;

    /**
     * @var Source
     */
    private Source $rawGithubusercontent;

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubPubliccodeService;

    /**
     * @param EntityManagerInterface  $entityManager           EntityManagerInterface
     * @param GithubPubliccodeService $githubPubliccodeService GithubPubliccodeService
     * @param CallService             $callService             CallService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubPubliccodeService $githubPubliccodeService,
        CallService $callService
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
        $this->style = $io;
        $this->githubPubliccodeService->setStyle($io);

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
     * Get the github raw.githubusercontent source.
     *
     * @return ?Source
     */
    public function getRawGithubSource(): ?Source
    {
        $this->rawGithubusercontent = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://raw.githubusercontent.com']);
        if ($this->rawGithubusercontent === false) {
            isset($this->style) && $this->style->error('No source found for https://raw.githubusercontent.com');

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
        $this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.repository.schema.json']);
        if ($this->repositoryEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');

            return null;
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
        if ($source = $this->getRawGithubSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get a openCatalogi.yaml from .github file with organisation name: '.$organizationName);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/'.$organizationName.'/.github/main/openCatalogi.yaml');
        } catch (Exception $e) {
            isset($this->style) && $this->style->error('Error found trying to fetch /'.$organizationName.'/github/main/openCatalogi.yaml: '.$e->getMessage());
        }

        if (isset($response) === false) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/main/openCatalogi.yml');
            } catch (Exception $e) {
                isset($this->style) && $this->style->error('Error found trying to fetch /'.$organizationName.'/github/main/openCatalogi.yml: '.$e->getMessage());
            }
        }

        if (isset($response) === false) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/master/openCatalogi.yaml');
            } catch (Exception $e) {
                isset($this->style) && $this->style->error('Error found trying to fetch /'.$organizationName.'/github/master/openCatalogi.yaml: '.$e->getMessage());
            }
        }

        if (isset($response) === false) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/master/openCatalogi.yml');
            } catch (Exception $e) {
                isset($this->style) && $this->style->error('Error found trying to fetch /'.$organizationName.'/github/master/openCatalogi.yml: '.$e->getMessage());
            }
        }

        if (isset($response)) {

            // @TODO use decodeResponse from the callService
            $openCatalogi = Yaml::parse($response->getBody()->getContents());
            isset($this->style) && $this->style->success("Fetch and decode went succesfull '/'.$organizationName.'/.github/master/openCatalogi.yml', '/'.$organizationName.'/.github/master/openCatalogi.yaml'");

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
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get an Organisation .github file with name: '.$organizationName);

            return null;
        }

        try {
            $response = $this->callService->call($source, '/repos/'.$organizationName.'/.github');
        } catch (Exception $e) {
            isset($this->style) && $this->style->error('Error found trying to fetch /repos/'.$organizationName.'/.github: '.$e->getMessage());
        }

        if (isset($response)) {
            $githubRepo = $this->callService->decodeResponse($source, $response, 'application/json');
            isset($this->style) && $this->style->success('Fetch and decode went succesfull for /repos/'.$organizationName.'/.github');

            return $githubRepo;
        }

        return null;
    }

    /**
     * Get or create a component for the given repository.
     *
     * @param ObjectEntity $repositoryObject
     * @param ObjectEntity $organization
     * @param string       $type
     *
     * @return array|null
     */
    public function setRepositoryComponent(ObjectEntity $repositoryObject, ObjectEntity $organization, string $type): ?ObjectEntity
    {
        if ($component = $repositoryObject->getValue('component')) {
            return $component;
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
            // set the organisation to usedBy if type is uses
            'usedBy' => $type == 'use' ? [$organization] : [],
        ]);
        $repositoryObject->setValue('component', $component);
        $this->entityManager->persist($repositoryObject);
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        return $component;
    }

    /**
     * Get an organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param string       $url
     * @param ObjectEntity $organization
     * @param string       $type
     *
     * @return array|null
     */
    public function getOrganisationRepo(string $url, ObjectEntity $organization, string $type): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get an Organisation with name: '.$url);

            return null;
        }
        if ($this->checkGithubAuth() === false) {
            return null;
        }

        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain == 'github.com') {
            return null;
        }

        $name = trim(parse_url($url, PHP_URL_PATH), '/');

        isset($this->style) && $this->style->info('Getting repo from organisation '.$name);
        $response = $this->callService->call($source, '/repos/'.$name);

        $repository = json_decode($response->getBody()->getContents(), true);

        if ($repository === false) {
            isset($this->style) && $this->style->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }
        $repositoryObject = $this->githubPubliccodeService->importRepository($repository);
        $this->entityManager->persist($repositoryObject);
        $this->entityManager->flush();
        isset($this->style) && $this->style->success('Found repo from organisation with name: '.$name);

        return $this->setRepositoryComponent($repositoryObject, $organization, $type);
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
            isset($this->style) && $this->style->success('Github repo found and fetched for '.$organization->getName());
            if ($openCatalogi = $this->getOpenCatalogiFromGithubRepo($organization->getValue('name'))) {
                isset($this->style) && $this->style->success('OpenCatalogi.yml or OpenCatalogi.yaml found and fetched for '.$organization->getName());

                if ($openCatalogi === false) {
                    return;
                }
                // we dont want to set the name, this has to be the login property from the github api
                $allowedKeys = ['description', 'type', 'telephone', 'email', 'website', 'logo', 'catalogusAPI'];
                $organization->hydrate(array_intersect_key($openCatalogi, array_flip($allowedKeys)));

                $uses = [];
                foreach ($openCatalogi['uses'] as $use) {
                    // get organisation repos and set the property
                    $uses[] = $this->getOrganisationRepo($use, $organization, 'use');
                }
                $organization->setValue('uses', $uses);

                $supports = [];
                foreach ($openCatalogi['supports'] as $supports) {
                    // get organisation component and set the property
                    $supports[] = $this->getOrganisationRepo($supports, $organization, 'supports');
                }
                $organization->setValue('supports', $supports);

                $this->entityManager->persist($organization);
                $this->entityManager->flush();

                isset($this->style) && $this->style->success($organization->getName().' succesfully updated with fetched openCatalogi info');
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
                isset($this->style) && $this->style->error('Could not find given organisation');

                return null;
            }
        } else {
            $organisationEntity = $this->getOrganisationEntity();
            if ($organisationEntity === false) {
                isset($this->style) && $this->style->error('No OrganisationEntity found when trying to import an Organisation');

                return null;
            }

            // If we want to do it for al repositories
            isset($this->style) && $this->style->info('Looping through organisations');
            foreach ($organisationEntity->getObjectEntities() as $organisation) {
                if ($organisation->getValue('name') && $organisation->getValue('github')) {
                    $this->getOrganizationCatalogi($organisation);
                }
            }
        }
        $this->entityManager->flush();

        isset($this->style) && $this->style->success('findRepositoriesThroughOrganisationHandler finished');

        return $this->data;
    }
}
