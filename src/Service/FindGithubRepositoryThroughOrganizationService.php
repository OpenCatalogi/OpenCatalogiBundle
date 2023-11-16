<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
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
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var ImportResourcesService
     */
    private ImportResourcesService $importResourcesService;

    /**
     * @var SynchronizationService $syncService
     */
    private SynchronizationService $syncService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager          The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger           The plugin version of the logger interface
     * @param GatewayResourceService $resourceService        The Gateway Resource Service.
     * @param GithubApiService       $githubApiService       The Github API Service
     * @param ImportResourcesService $importResourcesService The Import Resources Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GithubApiService $githubApiService,
        ImportResourcesService $importResourcesService,
        SynchronizationService $syncService
    ) {
        $this->entityManager          = $entityManager;
        $this->pluginLogger           = $pluginLogger;
        $this->resourceService        = $resourceService;
        $this->githubApiService       = $githubApiService;
        $this->importResourcesService = $importResourcesService;
        $this->syncService            = $syncService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * Override configuration from other services.
     *
     * @param array $configuration The new configuration array.
     *
     * @return void
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;

    }//end setConfiguration()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param array  $repositoriesArray The repositories from the github api
     * @param Source $source            The github api source
     *
     * @throws GuzzleException|Exception
     *
     * @return array
     */
    public function getOrganizationRepository(array $repositoriesArray, Source $source): array
    {
        $repositorySchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');

        $ownedRepositories = [];
        // Loop through the repositories array.
        foreach ($repositoriesArray as $repositoryArray) {
            $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryArray['html_url']);
            if ($repositorySync->getObject() !== null) {
                foreach ($repositorySync->getObject()->getValue('components') as $component) {
                    $ownedRepositories[] = $component;
                }
            }

            if ($repositorySync->getObject() === null) {
                // Remove the sync so that we dont create multiple syncs.
                $this->entityManager->remove($repositorySync);
                $this->entityManager->flush();
                $ownedRepositories[] = $this->githubApiService->getGithubRepository($repositoryArray['html_url'], $repositoryArray);
            }
        }

        return $ownedRepositories;

    }//end getOrganizationRepository()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity
     */
    public function getOrganizationCatalogi(ObjectEntity $organization): ObjectEntity
    {
        // Get the github api source.
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null
            || $this->githubApiService->checkGithubAuth($source) === false
        ) {
            return $organization;
        }//end if

        // Get the path of the github url.
        $githubPath = \Safe\parse_url($organization->getValue('github'))['path'];

        // If the type is user get the repos of the user.
        if ($organization->getValue('type') === 'User') {
            // Get the repositories of the organization from the github api.
            $repositoriesArray = $this->githubApiService->getUserRepos($githubPath, $source);
        }

        // If the type is organization get the repos of the organization.
        if ($organization->getValue('type') === 'Organization') {
            // Get the repositories of the organization from the github api.
            $repositoriesArray = $this->githubApiService->getOrganizationRepos($githubPath, $source);
        }

        if (isset($repositoriesArray) === false
            || isset($repositoriesArray) === true
            && $repositoriesArray === null
        ) {
            return $organization;
        }

        // Get the owned repositories.
        $ownedRepositories = $this->getOrganizationRepository($repositoriesArray, $source);

        // Set the repositories to the owns array.
        $organization->hydrate(['owns' => $ownedRepositories]);
        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $this->pluginLogger->debug($organization->getName().' succesfully updated with owned repositorie');

        return $organization;

    }//end getOrganizationCatalogi()


    /**
     * Makes sure the action the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
     *
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null dataset at the end of the handler              (not needed here)
     */
    public function findGithubRepositoryThroughOrganizationHandler(?array $data=[], ?array $configuration=[], ?string $organizationId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        if ($organizationId !== null) {
            // If we are testing for one repository.
            $organization = $this->entityManager->find('App:ObjectEntity', $organizationId);
            if ($organization instanceof ObjectEntity === true
                && $organization->getValue('name') !== null
                && $organization->getValue('github') !== null
            ) {
                $this->getOrganizationCatalogi($organization);
            }//end if

            if ($organization instanceof ObjectEntity === false) {
                $this->pluginLogger->error('Could not find given organisation');

                return null;
            }//end if
        }

        $organisztionSchema = $this->resourceService->getSchema($this->configuration['organisationSchema'], 'open-catalogi/open-catalogi-bundle');

        // If we want to do it for al repositories.
        $this->pluginLogger->info('Looping through organisations');
        foreach ($organisztionSchema->getObjectEntities() as $organization) {
            // Check if the name of the organization is set and it is a github organization.
            if ($organization->getValue('name') !== null
                && $organization->getValue('github') !== null
            ) {
                $this->getOrganizationCatalogi($organization);
            }
        }

        return $this->data;

    }//end findGithubRepositoryThroughOrganizationHandler()


}//end class
