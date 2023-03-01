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
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

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
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubPubliccodeService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @param EntityManagerInterface $entityManager The Entity Manager Interface
     * @param GithubPubliccodeService $githubPubliccodeService The Github Publiccode Service
     * @param CallService $callService The Call Service
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
        $this->io = $io;
        $this->githubPubliccodeService->setStyle($io);

        return $this;
    }

    /**
     * Get a source by reference.
     *
     * @param string $location The location to look for
     *
     * @return Source|null
     */
    public function getSource(string $location): ?Source
    {
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $location]);
        if ($source === null) {
//            $this->logger->error("No source found for $location");
            isset($this->io) && $this->io->error("No source found for $location");
        }//end if

        return $source;
    }//end getSource()

    /**
     * Get an entity by reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Entity|null
     */
    public function getEntity(string $reference): ?Entity
    {
        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($entity === null) {
//            $this->logger->error("No entity found for $reference");
            isset($this->io) && $this->io->error("No entity found for $reference");
        }//end if

        return $entity;
    }//end getEntity()

    /**
     * Check the auth of the github source
     *
     * @param Source $source The given source to check the api key
     * @return bool|null If the api key is set or not
     */
    public function checkGithubAuth(Source $source): ?bool
    {
        if (!$source->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: '.$source->getName());

            return false;
        }//end if

        return true;
    }//end checkGithubAuth()

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $organizationName
     * @return array|null|Response
     */
    private function getOpenCatalogiFromGithubRepo(string $organizationName): ?array
    {
        // make sync object
        $source = $this->getSource('https://raw.githubusercontent.com');

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
        }//end if

        if (!isset($response)) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/master/openCatalogi.yaml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName.'/github/master/openCatalogi.yaml: '.$e->getMessage());
            }
        }//end if

        if (!isset($response)) {
            try {
                $response = $this->callService->call($source, '/'.$organizationName.'/.github/master/openCatalogi.yml');
            } catch (Exception $e) {
                isset($this->io) && $this->io->error('Error found trying to fetch /'.$organizationName.'/github/master/openCatalogi.yml: '.$e->getMessage());
            }
        }//end if

        if (isset($response)) {

            // @TODO use decodeResponse from the callService
            $openCatalogi = Yaml::parse($response->getBody()->getContents());
            isset($this->io) && $this->io->success("Fetch and decode went succesfull '/'.$organizationName.'/.github/master/openCatalogi.yml', '/'.$organizationName.'/.github/master/openCatalogi.yaml'");

            return $openCatalogi;
        }//end if

        return null;
    }//end getOpenCatalogiFromGithubRepo()

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $organizationName used as path to fetch from
     *
     * @return array|null
     * @throws Exception
     */
    private function getGithubRepoFromOrganization(string $organizationName): ?array
    {
        $source = $this->getSource('https://api.github.com');

        try {
            $response = $this->callService->call($source, '/repos/'.$organizationName.'/.github');
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /repos/'.$organizationName.'/.github: '.$e->getMessage());
        }

        if (isset($response)) {
            $githubRepo = $this->callService->decodeResponse($source, $response, 'application/json');
            isset($this->io) && $this->io->success('Fetch and decode went succesfull for /repos/'.$organizationName.'/.github');

            return $githubRepo;
        }//end if

        return null;
    }//end getGithubRepoFromOrganization()

    /**
     * Get or create a component for the given repository.
     *
     * @param ObjectEntity $repositoryObject
     * @param ObjectEntity $organization
     * @param string $type
     *
     * @return array|null
     * @throws Exception
     */
    public function setRepositoryComponent(ObjectEntity $repositoryObject, ObjectEntity $organization, string $type): ?ObjectEntity
    {
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');

        $component = $repositoryObject->getValue('component');
        if ($component === false) {
            $component = new ObjectEntity($componentEntity);
        }//end if

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
    }//end setRepositoryComponent()

    /**
     * Get an organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param string $url
     * @param ObjectEntity $organization
     * @param string $type
     *
     * @return array|null
     * @throws GuzzleException|LoaderError|SyntaxError
     */
    public function getOrganisationRepo(string $url, ObjectEntity $organization, string $type): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if (!$this->checkGithubAuth($source)) {
            return null;
        }//end if

        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain == 'github.com') {
            return null;
        }//end if

        $name = trim(parse_url($url, PHP_URL_PATH), '/');

        isset($this->io) && $this->io->info('Getting repo from organisation '.$name);
        $response = $this->callService->call($source, '/repos/'.$name);

        $repository = json_decode($response->getBody()->getContents(), true);

        if (!$repository) {
            isset($this->io) && $this->io->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if
        $repositoryObject = $this->githubPubliccodeService->importRepository($repository);
        $this->entityManager->persist($repositoryObject);
        $this->entityManager->flush();
        isset($this->io) && $this->io->success('Found repo from organisation with name: '.$name);

        return $this->setRepositoryComponent($repositoryObject, $organization, $type);
    }//end getOrganisationRepo()

    /**
     * Fetches opencatalogi.yaml info with function getOpenCatalogiFromGithubRepo for an organization and updates the given organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @return void
     * @throws GuzzleException
     */
    private function getOrganizationCatalogi(ObjectEntity $organization): void
    {
        if ($githubRepo = $this->getGithubRepoFromOrganization($organization->getValue('name'))) {
            isset($this->io) && $this->io->success('Github repo found and fetched for '.$organization->getName());
            if ($openCatalogi = $this->getOpenCatalogiFromGithubRepo($organization->getValue('name'))) {
                isset($this->io) && $this->io->success('OpenCatalogi.yml or OpenCatalogi.yaml found and fetched for '.$organization->getName());

                if (!$openCatalogi) {
                    return;
                }//end if
                // We don't want to set the name, this has to be the login property from the github api.
                $allowedKeys = ['description', 'type', 'telephone', 'email', 'website', 'logo', 'catalogusAPI'];
                $organization->hydrate(array_intersect_key($openCatalogi, array_flip($allowedKeys)));

                $uses = [];
                foreach ($openCatalogi['uses'] as $use) {
                    // Get organisation repos and set the property.
                    $uses[] = $this->getOrganisationRepo($use, $organization, 'use');
                }
                $organization->setValue('uses', $uses);

                $supports = [];
                foreach ($openCatalogi['supports'] as $supports) {
                    // Get organisation component and set the property.
                    $supports[] = $this->getOrganisationRepo($supports, $organization, 'supports');
                }
                $organization->setValue('supports', $supports);

                $this->entityManager->persist($organization);
                $this->entityManager->flush();

                isset($this->io) && $this->io->success($organization->getName().' succesfully updated with fetched openCatalogi info');
            }//end if
        }//end if
    }//end getOrganizationCatalogi()

    /**
     * Makes sure the action the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
     *
     * @param ?array $data data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @return array|null dataset at the end of the handler              (not needed here)
     * @throws GuzzleException
     */
    public function findGithubRepositoryThroughOrganizationHandler(?array $data = [], ?array $configuration = [], ?string $organisationId = null): ?array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($organisationId) {
            // If we are testing for one repository.
            $organisation = $this->entityManager->find('App:ObjectEntity', $organisationId);
            if ($organisation && $organisation->getValue('name') && $organisation->getValue('github')) {
                $this->getOrganizationCatalogi($organisation);
            } else {
                isset($this->io) && $this->io->error('Could not find given organisation');

                return null;
            }
        } else {
            $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');

            // If we want to do it for al repositories.
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
    }//end findGithubRepositoryThroughOrganizationHandler()
}
