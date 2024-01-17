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
 * Gets an organization from the response of the githubEventAction and formInputAction
 * and enriches the organization.
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class EnrichOrganizationService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface $pluginLogger
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService $resourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var SynchronizationService $syncService
     */
    private SynchronizationService $syncService;

    /**
     * @var GithubApiService $githubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GitlabApiService $gitlabApiService
     */
    private GitlabApiService $gitlabApiService;

    /**
     * @var OpenCatalogiService $openCatalogiService
     */
    private OpenCatalogiService $openCatalogiService;

    /**
     * @var array $data
     */
    private array $data;

    /**
     * @var array $configuration
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager       The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger        The plugin version of the logger interface
     * @param GatewayResourceService $resourceService     The Gateway Resource Service.
     * @param SynchronizationService $syncService         The Synchronization Service
     * @param OpenCatalogiService    $openCatalogiService The opencatalogi service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        SynchronizationService $syncService,
        GithubApiService $githubApiService,
        GitlabApiService $gitlabApiService,
        OpenCatalogiService $openCatalogiService
    ) {
        $this->entityManager       = $entityManager;
        $this->pluginLogger        = $pluginLogger;
        $this->resourceService     = $resourceService;
        $this->syncService         = $syncService;
        $this->githubApiService    = $githubApiService;
        $this->gitlabApiService    = $gitlabApiService;
        $this->openCatalogiService = $openCatalogiService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * This function gets the softwareOwned, softwareUsed or softwareSupported repositories in the opencatalogi file.
     *
     * @param Entity $repositorySchema The repository schema.
     * @param array  $opencatalogi     opencatalogi file array from the github usercontent/github api call.
     * @param Source $source           The github api source.
     *
     * @return array The software used owned
     * @throws Exception
     */
    public function getSoftware(Entity $repositorySchema, array $opencatalogi, Source $source, string $type): array
    {
        $softwareComponents = [];
        foreach ($opencatalogi[$type] as $item) {
            if ($type === 'softwareSupported') {
                if (is_array($item) === true && key_exists('software', $item) === false) {
                    continue;
                }

                $item = $item['software'];
            }

            $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $item);

            // Get the object of the sync if there is one.
            if ($repositorySync->getObject() !== null) {
                $repository = $repositorySync->getObject();
            }

            // Get the github repository from the given url if the object is null.
            if ($repositorySync->getObject() === null) {
                $this->entityManager->remove($repositorySync);

                // If the given source is the same as the gitlab source get the gitlab repository.
                if ($source->getReference() === $this->configuration['gitlabSource']) {
                    $this->gitlabApiService->setConfiguration($this->configuration);
                    $repository = $this->gitlabApiService->getGitlabRepository($item);
                }

                // If the given source is the same as the github source get the github repository.
                if ($source->getReference() === $this->configuration['githubSource']) {
                    $this->githubApiService->setConfiguration($this->configuration);
                    $repository = $this->githubApiService->getGithubRepository($item);
                }
            }

            if (isset($repository) && $repository instanceof ObjectEntity === false) {
                continue;
            }

            // Set the components of the repository to the array.
            foreach ($repository->getValue('components') as $component) {
                $softwareComponents[] = $component;
            }
        }//end foreach

        return $softwareComponents;

    }//end getSoftware()


    /**
     * This function gets the members in the opencatalogi file.
     *
     * @param array  $opencatalogi opencatalogi file array from the github usercontent/github api call.
     * @param Source $source       The github api source.
     *
     * @return array The organization objects as array.
     */
    public function getMembers(array $opencatalogi, Source $source): array
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($organizationSchema instanceof Entity === false) {
            return [];
        }

        $members = [];
        foreach ($opencatalogi['members'] as $organizationUrl) {
            $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationUrl);

            if ($organizationSync->getObject() === null) {
                // Do we want to get the organization from the repository
                // $organizationName = \Safe\parse_url($organizationUrl)['path'];
                // $organizationSync = $this->syncService->synchronize($organizationSync, ['github' => $organizationUrl, 'name' => $organizationName]);
                //
                // $members[] = $organizationSync->getObject();
            }

            if ($organizationSync->getObject() !== null) {
                $members[] = $organizationSync->getObject();
            }
        }

        return $members;

    }//end getMembers()


    /**
     * This function enriches the repository with a organization and/or component.
     *
     * @param ObjectEntity $organization The organization object.
     * @param array        $opencatalogi opencatalogi file array from the github usercontent/github api call.
     * @param Source       $source       The github api source.
     *
     * @return array|null The data for updating the organization
     * @throws Exception
     */
    public function getConnectedComponents(ObjectEntity $organization, array $opencatalogi, Source $source): ?array
    {
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false) {
            return $organization;
        }

        // Get the softwareOwned repositories and set it to the array.
        $ownedComponents = [];
        if (key_exists('softwareOwned', $opencatalogi) === true && is_array($opencatalogi['softwareOwned'])) {
            $ownedComponents = $this->getSoftware($repositorySchema, $opencatalogi, $source, 'softwareOwned');
        }//end if

        // Get the softwareSupported repositories and set it to the array.
        $supportedComponents = [];
        if (key_exists('softwareSupported', $opencatalogi) === true && is_array($opencatalogi['softwareOwned'])) {
            $supportedComponents = $this->getSoftware($repositorySchema, $opencatalogi, $source, 'softwareSupported');
        }

        // Get the softwareUsed repositories and set it to the array.
        $usedComponents = [];
        if (key_exists('softwareUsed', $opencatalogi) === true && is_array($opencatalogi['softwareOwned'])) {
            $usedComponents = $this->getSoftware($repositorySchema, $opencatalogi, $source, 'softwareUsed');
        }

        // Get the members repositories and set it to the array.
        $members = [];
        if (key_exists('members', $opencatalogi) === true) {
            $members = $this->getMembers($opencatalogi, $source);
        }//end if


        return [
            'owns'     => $ownedComponents,
            'supports' => $supportedComponents,
            'uses'     => $usedComponents,
            'members'  => $members,
        ];
    }//end getConnectedComponents()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param Source       $source       The github source.
     *
     * @return array|null The organization array from github.
     */
    public function enrichGithubOrganization(ObjectEntity $organization, Source $source): ?array
    {
        // Get the path of the github url.
        $githubPath = \Safe\parse_url($organization->getValue('github'))['path'];

        $organizationArray = null;
        if ($organization->getValue('type') === 'Organization') {
            // Get the organization from the github api.
            $organizationArray = $this->githubApiService->getOrganization(trim($githubPath, '/'), $source);
        }

        if ($organization->getValue('type') === 'User') {
            // Get the organization from the github api.
            $organizationArray = $this->githubApiService->getUser(trim($githubPath, '/'), $source);
        }

        return $organizationArray;

    }//end enrichGithubOrganization()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param Source $source    The gitlab source.
     * @param array  $dataArray The dataArray with keys opencatalogiRepo/organizationArray
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null The opencatalogi file.
     */
    public function getGitlabOpenCatalogiFile(Source $source, array $dataArray): ?array
    {
        $parsedUrl = \Safe\parse_url($dataArray['opencatalogiRepo']);

        // Get the default_branch from the parsed url query.
        $repositoryArray['default_branch'] = explode('ref=', $parsedUrl['query'])[1];

        $explodedPath = explode('/api/v4/projects/', $parsedUrl['path'])[1];
        $explodedPath = explode('/repository/files/', $explodedPath);

        // Get the id and path from the parsed url path.
        $repositoryArray['id'] = $explodedPath[0];
        $directory['path']     = $explodedPath[1];

        // Get the opencatalogi file from the opencatalogiRepo property.
        $this->gitlabApiService->setConfiguration($this->configuration);

        return $this->gitlabApiService->getTheFileContent($source, $repositoryArray, $directory);

    }//end getGitlabOpenCatalogiFile()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param Source       $source       The gitlab source.
     *
     * @throws GuzzleException|Exception
     *
     * @return array The gitlab organization or user as array.
     */
    public function enrichGitlabOrganization(ObjectEntity $organization, Source $source): array
    {
        // Get the path of the gitlab url.
        $gitlabPath = \Safe\parse_url($organization->getValue('gitlab'))['path'];

        $organizationArray = [];
        if ($organization->getValue('type') === 'Organization') {
            $gitlabPath = explode('/groups/', $gitlabPath)[1];
            $gitlabPath = urlencode($gitlabPath);

            // Get the group from the gitlab api.
            $organizationArray = $this->gitlabApiService->getOrganization($gitlabPath, $source);
        }

        if ($organization->getValue('type') === 'User') {
            $gitlabPath = explode('/', $gitlabPath)[1];
            // Get the user from the gitlab api.
            $organizationArray = $this->gitlabApiService->getUser($gitlabPath, $source);
        }

        return $organizationArray;

    }//end enrichGitlabOrganization()


    /**
     * This function enriches the organization with the softwareSupported/softwareOwned/softwareUsed/logo/description.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param Source       $source       The github/gitlab source.
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity The updated organization object.
     */
    public function enrichOrganizationProps(ObjectEntity $organization, Source $source, array $dataArray): ObjectEntity
    {
        // Get the softwareSupported/softwareOwned/softwareUsed repositories.
        $orgData = $this->getConnectedComponents($organization, $dataArray['opencatalogi'], $source);

        // Enrich the opencatalogi organization with a logo and description.
        $this->openCatalogiService->setConfiguration($this->configuration);
        $orgData['logo']        = $this->openCatalogiService->enrichLogo($dataArray['organizationArray'], $dataArray['opencatalogi'], $organization);
        $orgData['description'] = $this->openCatalogiService->enrichDescription($dataArray['organizationArray'], $dataArray['opencatalogi'], $organization);

        // Hydrate the organization with the softwareSupported/softwareOwned/softwareUsed/members/logo/description.
        $organization->hydrate($orgData);
        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $this->pluginLogger->info($organization->getValue('name').' succesfully updated the organization with the opencatalogi file.');

        return $organization;

    }//end enrichOrganizationProps()


    /**
     * This function enriches the organization with the opencatalogiRepo url.
     *
     * @param ObjectEntity $organization      Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param Source       $source            The github/gitlab source.
     * @param array        $organizationArray The data array with keys organizationArray and opencatalogiRepo.
     *
     * @return ObjectEntity The updated organization object.
     * @throws Exception
     */
    public function enrichFromOpenCatalogiRepo(ObjectEntity $organization, Source $source, array $dataArray): ObjectEntity
    {
        if ($source->getReference() === $this->configuration['githubSource']) {
            // Get the opencatalogi file from the opencatalogiRepo property.
            $this->githubApiService->setConfiguration($this->configuration);
            $opencatalogi = $this->githubApiService->getFileFromRawUserContent($opencatalogiRepo);
        }

        if ($source->getReference() === $this->configuration['gitlabSource']) {
            $opencatalogi = $this->getGitlabOpenCatalogiFile($source, $dataArray);
        }

        if ($opencatalogi === null) {
            return $organization;
        }

        $dataArray['opencatalogi'] = $opencatalogi;

        return $this->enrichOrganizationProps($organization, $source, $dataArray);

    }//end enrichFromOpenCatalogiRepo()


    /**
     * This function enriches the github/gitlab organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     * @param Source       $source       The github/gitlab source.
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity The updated organization object.
     */
    public function enrichOrganization(ObjectEntity $organization, Source $source): ObjectEntity
    {
        $organizationArray = null;
        if ($source->getReference() === $this->configuration['gitlabSource']) {
            $organizationArray = $this->enrichGitlabOrganization($organization, $source);
        }

        if ($source->getReference() === $this->configuration['githubSource']) {
            $organizationArray = $this->enrichGithubOrganization($organization, $source);
        }

        if ($organizationArray === null) {
            return $organization;
        }

        // Update the organization with the opencatalogi file.
        $opencatalogiRepo = $organization->getValue('opencatalogiRepo');
        if ($opencatalogiRepo !== null) {
            $dataArray = [
                'opencatalogiRepo'  => $opencatalogiRepo,
                'organizationArray' => $organizationArray,
            ];
            return $this->enrichFromOpenCatalogiRepo($organization, $source, $dataArray);
        }//end if

        // If the opencatalogiRepo is null update the logo and description with the organization array.
        // Set the logo and description if null.
        if ($organization->getValue('logo') === null) {
            $organization->setValue('logo', $organizationArray['avatar_url']);
        }

        // The description only exist for organizations and not for users.
        if ($organization->getValue('description') === null && key_exists('description', $organizationArray)) {
            $organization->setValue('description', $organizationArray['description']);
        }

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $this->pluginLogger->debug($organization->getValue('name').' succesfully updated the organization with the opencatalogi file.');

        return $organization;

    }//end enrichOrganization()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param string $organizationId The id of the organization in the response.
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity The updated github or gitlab organization.
     */
    public function getOrganization(string $organizationId): ObjectEntity
    {
        // Get the organization object.
        $organization = $this->entityManager->find('App:ObjectEntity', $organizationId);

        // Check if the name and github is not null.
        if ($organization instanceof ObjectEntity === true
            && $organization->getValue('name') !== null
            && $organization->getValue('github') !== null
        ) {
            $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
            if ($source instanceof Source === false || $this->githubApiService->checkGithubAuth($source) === false) {
                return $organization;
            }

            // Enrich the organization object.
            return $this->enrichOrganization($organization, $source);
        }//end if

        // Check if the name and gitlab is not null.
        if ($organization instanceof ObjectEntity === true
            && $organization->getValue('name') !== null
            && $organization->getValue('gitlab') !== null
        ) {
            $source = $this->resourceService->getSource($this->configuration['gitlabSource'], 'open-catalogi/open-catalogi-bundle');
            if ($source instanceof Source === false || $this->gitlabApiService->checkGitlabAuth($source) === false) {
                return $organization;
            }

            // Enrich the organization object.
            return $this->enrichOrganization($organization, $source);
        }//end if

        if ($organization instanceof ObjectEntity === false) {
            $this->pluginLogger->error('Could not find given organization');

            return $organization;
        }//end if

        return $organization;

    }//end getOrganization()


    /**
     * Gets the organization id from the response.
     *
     * @return string|null The organization id from the response.
     */
    public function getOrganizationId(): ?string
    {
        try {
            $this->data['response'] = \Safe\json_decode($this->data['response']->getContent(), true);
        } catch (\Exception $exception) {
            $this->pluginLogger->warning('Cannot get the content of the response.');
        }

        // This comes from the GithubEvent or FormInput action.
        // We hava an organization in the response.
        $organizationId = null;
        if (key_exists('response', $this->data) === true
            && key_exists('_self', $this->data['response']) === true
            && key_exists('id', $this->data['response']['_self']) === true
        ) {
            $organizationId = $this->data['response']['_self']['id'];
        }//end if

        return $organizationId;

    }//end getOrganizationId()


    /**
     * Makes sure the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
     *
     * @param ?array $data          data set at the start of the handler
     * @param ?array $configuration configuration of the action
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null dataset at the end of the handler
     */
    public function enrichOrganizationHandler(?array $data=[], ?array $configuration=[]): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        // If there is an organization in the response.
        $organizationId = $this->getOrganizationId();
        if ($organizationId !== null) {
            $organization = $this->getOrganization($organizationId);

            $this->data['response'] = new Response(json_encode($organization->toArray()), 200, ['Content-Type' => 'application/json']);

            return $this->data;
        }

        // If there is no organization we get all the organizations and enrich it.
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($organizationSchema instanceof Entity === false) {
            return $this->data;
        }

        $organizations = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $organizationSchema]);
        foreach ($organizations as $organization) {
            // Check if the name and github is not null.
            if ($organization instanceof ObjectEntity === true
                && $organization->getValue('name') !== null
                && $organization->getValue('github') !== null
            ) {
                $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
                if ($source instanceof Source === false || $this->githubApiService->checkGithubAuth($source) === false) {
                    return $this->data;
                }

                // Enrich the organization object.
                $this->enrichOrganization($organization, $source);
            }//end if

            // Check if the name and gitlab is not null.
            if ($organization instanceof ObjectEntity === true
                && $organization->getValue('name') !== null
                && $organization->getValue('gitlab') !== null
            ) {
                $source = $this->resourceService->getSource($this->configuration['gitlabSource'], 'open-catalogi/open-catalogi-bundle');
                if ($source instanceof Source === false || $this->gitlabApiService->checkGitlabAuth($source) === false) {
                    return $this->data;
                }

                // Enrich the organization object.
                $this->enrichOrganization($organization, $source);
            }//end if
        }//end foreach

        return $this->data;

    }//end enrichOrganizationHandler()


}//end class
