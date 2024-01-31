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
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class FindRepositoryThroughOrganizationService
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
     * @var GitlabApiService
     */
    private GitlabApiService $gitlabApiService;

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
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     * @param GithubApiService       $githubApiService The Github API Service
     * @param GitlabApiService       $gitlabApiService The Gitlab API Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GithubApiService $githubApiService,
        SynchronizationService $syncService,
        GitlabApiService $gitlabApiService
    ) {
        $this->entityManager    = $entityManager;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->githubApiService = $githubApiService;
        $this->syncService      = $syncService;
        $this->gitlabApiService = $gitlabApiService;

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
     * This function gets the owned repository from the given organization.
     *
     * @param array  $repositoryArray The repository from the github/gitlab api
     * @param Source $source          The github/gitlab api source
     * @param string $type            The type: github/gitlab
     *
     * @return ObjectEntity The owned repository object.
     * @throws Exception
     */
    public function getOrganizationRepository(array $repositoryArray, Source $source, string $type): ObjectEntity
    {
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');

        if ($type === 'github') {
            $repositoryUrl = $repositoryArray['html_url'];
        }

        if ($type === 'gitlab') {
            $repositoryUrl = $repositoryArray['web_url'];
        }

        // Find the repository sync by the url.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

        // If there isn't an object get the repository.
        if ($repositorySync->getObject() === null) {
            // Remove the sync so that we dont create multiple syncs.
            $this->entityManager->remove($repositorySync);
            $this->entityManager->flush();

            if ($type === 'github') {
                $this->githubApiService->setConfiguration($this->configuration);
                return $this->githubApiService->getGithubRepository($repositoryUrl, $repositoryArray);
            }

            if ($type === 'gitlab') {
                $this->gitlabApiService->setConfiguration($this->configuration);
                return $this->gitlabApiService->getGitlabRepository($repositoryUrl, $repositoryArray);
            }
        }

        return $repositorySync->getObject();

    }//end getOrganizationRepository()


    /**
     * This function gets the endpoint to call.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param string       $type         The type: github/gitlab
     * @param string       $urlPath      The path of the organization github/gitlab url
     *
     * @return string|null The endpoint to call.
     */
    public function getEndpoint(ObjectEntity $organization, string $type, string $urlPath): ?string
    {
        if ($type === 'github') {
            // If the org type is user set the endpoint for github source.
            if ($organization->getValue('type') === 'User') {
                return '/users'.$urlPath.'/repos';
            }

            // If the org type is organization set the endpoint for github source.
            if ($organization->getValue('type') === 'Organization') {
                return '/orgs'.$urlPath.'/repos';
            }
        }

        if ($type === 'gitlab') {
            // If the org type is user set the endpoint for gitlab source.
            if ($organization->getValue('type') === 'User') {
                return '/users'.$urlPath.'/projects';
            }

            // If the org type is organization set the endpoint for gitlab source.
            if ($organization->getValue('type') === 'Organization') {
                $urlPath = explode('/groups/', $urlPath)[1];
                return '/groups/'.urlencode($urlPath);
            }
        }

        return null;

    }//end getEndpoint()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param Source       $source       The github/gitlab source
     * @param string       $type         The type: github/gitlab
     *
     * @return ObjectEntity The updated organization object.
     * @throws Exception
     */
    public function getOrganizationCatalogi(ObjectEntity $organization, Source $source, string $type): ObjectEntity
    {
        // Get the path of the github/gitlab url.
        $urlPath = \Safe\parse_url($organization->getValue($type))['path'];

        // Get the endpoint to call.
        $endpoint = $this->getEndpoint($organization, $type, $urlPath);
        if (empty($endpoint) === true) {
            return $organization;
        }

        // Set the debug and error messages.
        $pluginMessages = [
            'debug' => 'Getting '.strtolower($organization->getValue('type')).' with '.$type.' url '.$organization->getValue($type).'.',
            'error' => 'Could not find the repositories of the '.strtolower($organization->getValue('type')).' with name: '.trim($urlPath, '/').' and with source: '.$source->getName().'.',
        ];

        // Get the repositories of the organization/user from the github/gitlab api.
        $repositoriesArray = $this->githubApiService->callAndDecode($source, $endpoint, $pluginMessages);

        if (empty($repositoriesArray) === true) {
            return $organization;
        }

        // If the type is gitlab and organization type is organization the owned repositories is the projects key.
        if ($type === 'gitlab' && $organization->getValue('type') === 'Organization') {
            $repositoriesArray = $repositoriesArray['projects'];
        }

        $ownedComponents = [];
        // Loop through the repositories array.
        foreach ($repositoriesArray as $repositoryArray) {
            $ownedRepository = $this->getOrganizationRepository($repositoryArray, $source, $type);

            foreach ($ownedRepository->getValue('components') as $component) {
                $ownedComponents[] = $component;
            }
        }

        // Set the repositories to the owns array.
        $organization->hydrate(['owns' => $ownedComponents]);
        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $this->pluginLogger->debug($organization->getName().' succesfully updated with owned repositorie');

        return $organization;

    }//end getOrganizationCatalogi()


    /**
     * This function finds the repositories through the given organization.
     *
     * @param ObjectEntity $organization The organization object
     *
     * @return array|null The updated organization object
     * @throws Exception
     */
    public function findRepositoryThroughOrganization(ObjectEntity $organization): ?ObjectEntity
    {

        // Return null if the name of the org is null.
        if ($organization->getValue('name') === null) {
            return null;
        }//end if

        if ($organization->getValue('github') !== null) {
            // Get the github api source.
            $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
            if ($source === null
                || $this->githubApiService->checkGithubAuth($source) === false
            ) {
                return $organization;
            }//end if

            return $this->getOrganizationCatalogi($organization, $source, 'github');
        }//end if

        if ($organization->getValue('gitlab') !== null) {
            // Get the github api source.
            $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitlabAPI.source.json', 'open-catalogi/open-catalogi-bundle');
            if ($source === null
                || $this->gitlabApiService->checkGitlabAuth($source) === false
            ) {
                return $organization;
            }//end if

            return $this->getOrganizationCatalogi($organization, $source, 'gitlab');
        }//end if

        return null;

    }//end findRepositoryThroughOrganization()


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
    public function findRepositoryThroughOrganizationHandler(?array $data=[], ?array $configuration=[], ?string $organizationId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        if ($organizationId !== null) {
            // If we are testing for one repository.
            $organization = $this->entityManager->find('App:ObjectEntity', $organizationId);
            if ($organization instanceof ObjectEntity === false) {
                $this->pluginLogger->error('Could not find given organisation');

                return null;
            }//end if

            $this->findRepositoryThroughOrganization($organization);

            return $this->data;
        }

        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

        // If we want to do it for al repositories.
        $this->pluginLogger->info('Looping through organisations');
        foreach ($organizationSchema->getObjectEntities() as $organization) {
            $this->findRepositoryThroughOrganization($organization);
        }

        return $this->data;

    }//end findRepositoryThroughOrganizationHandler()


}//end class
