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
     * @var PubliccodeService $publiccodeService
     */
    private PubliccodeService $publiccodeService;

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
     * @param PubliccodeService      $publiccodeService   The publiccode service
     * @param OpenCatalogiService    $openCatalogiService The opencatalogi service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        SynchronizationService $syncService,
        GithubApiService $githubApiService,
        GitlabApiService $gitlabApiService,
        PubliccodeService $publiccodeService,
        OpenCatalogiService $openCatalogiService
    ) {
        $this->entityManager       = $entityManager;
        $this->pluginLogger        = $pluginLogger;
        $this->resourceService     = $resourceService;
        $this->syncService         = $syncService;
        $this->githubApiService    = $githubApiService;
        $this->gitlabApiService    = $gitlabApiService;
        $this->publiccodeService   = $publiccodeService;
        $this->openCatalogiService = $openCatalogiService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * This function gets the softwareOwned repositories in the opencatalogi file.
     *
     * @param Entity $repositorySchema The repository schema.
     * @param array  $opencatalogi     opencatalogi file array from the github usercontent/github api call.
     * @param Source $source           The github api source.
     *
     * @return array The software used owned
     * @throws Exception
     */
    public function getSoftwareOwned(Entity $repositorySchema, array $opencatalogi, Source $source): array
    {
        $ownedComponents = [];
        foreach ($opencatalogi['softwareOwned'] as $repositoryUrl) {
            $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

            // Get the object of the sync if there is one.
            if ($repositorySync->getObject() !== null) {
                $repository = $repositorySync->getObject();
            }

            // Get the github repository from the given url if the object is null.
            if ($repositorySync->getObject() === null) {
                $this->entityManager->remove($repositorySync);

                $this->githubApiService->setConfiguration($this->configuration);
                $repository = $this->githubApiService->getGithubRepository($repositoryUrl);
            }

            if (isset($repository) && $repository instanceof ObjectEntity === false) {
                continue;
            }

            // Set the components of the repository to the array.
            foreach ($repository->getValue('components') as $component) {
                $ownedComponents[] = $component;
            }
        }//end foreach

        return $ownedComponents;

    }//end getSoftwareOwned()


    /**
     * This function gets the softwareSupported repositories in the opencatalogi file.
     *
     * @param Entity $repositorySchema The repository schema.
     * @param array  $opencatalogi     opencatalogi file array from the github usercontent/github api call.
     * @param Source $source           The github api source.
     *
     * @return array The software supported components
     * @throws Exception
     */
    public function getSoftwareSupported(Entity $repositorySchema, array $opencatalogi, Source $source): array
    {
        $supportedComponents = [];
        foreach ($opencatalogi['softwareSupported'] as $supports) {
            if (key_exists('software', $supports) === false) {
                continue;
            }

            $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $supports['software']);

            // Get the object of the sync if there is one.
            if ($repositorySync->getObject() !== null) {
                $repository = $repositorySync->getObject();
            }

            // Get the github repository from the given url if the object is null.
            if ($repositorySync->getObject() === null) {
                $this->entityManager->remove($repositorySync);

                $this->githubApiService->setConfiguration($this->configuration);
                $repository = $this->githubApiService->getGithubRepository($supports['software']);
            }

            if (isset($repository) && $repository instanceof ObjectEntity === false) {
                continue;
            }

            // Set the components of the repository
            foreach ($repository->getValue('components') as $component) {
                $supportedComponents[] = $component;
            }
        }//end foreach

        return $supportedComponents;

    }//end getSoftwareSupported()


    /**
     * This function gets the softwareUsed repositories in the opencatalogi file.
     *
     * @param Entity $repositorySchema The repository schema.
     * @param array  $opencatalogi     opencatalogi file array from the github usercontent/github api call.
     * @param Source $source           The github api source.
     *
     * @return array The software used components
     * @throws Exception
     */
    public function getSoftwareUsed(Entity $repositorySchema, array $opencatalogi, Source $source): array
    {
        $usedComponents = [];
        foreach ($opencatalogi['softwareUsed'] as $repositoryUrl) {
            $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);

            // Get the object of the sync if there is one.
            if ($repositorySync->getObject() !== null) {
                $repository = $repositorySync->getObject();
            }

            // Get the github repository from the given url if the object is null.
            if ($repositorySync->getObject() === null) {
                $this->entityManager->remove($repositorySync);

                $this->githubApiService->setConfiguration($this->configuration);
                $repository = $this->githubApiService->getGithubRepository($repositoryUrl);
            }

            if (isset($repository) && $repository instanceof ObjectEntity === false) {
                continue;
            }

            // Set the components of the repository
            foreach ($repository->getValue('components') as $component) {
                $usedComponents[] = $component;
            }
        }//end foreach

        return $usedComponents;

    }//end getSoftwareUsed()


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
     * @return ObjectEntity|null The updated organization object
     * @throws Exception
     */
    public function getConnectedComponents(ObjectEntity $organization, array $opencatalogi, Source $source): ?ObjectEntity
    {
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false) {
            return $organization;
        }

        // Get the softwareOwned repositories and set it to the array.
        $ownedComponents = [];
        if (key_exists('softwareOwned', $opencatalogi) === true) {
            $ownedComponents = $this->getSoftwareOwned($repositorySchema, $opencatalogi, $source);
        }//end if

        // Get the softwareSupported repositories and set it to the array.
        $supportedComponents = [];
        if (key_exists('softwareSupported', $opencatalogi) === true) {
            $supportedComponents = $this->getSoftwareSupported($repositorySchema, $opencatalogi, $source);
        }

        // Get the softwareUsed repositories and set it to the array.
        $usedComponents = [];
        if (key_exists('softwareUsed', $opencatalogi) === true) {
            $usedComponents = $this->getSoftwareUsed($repositorySchema, $opencatalogi, $source);
        }

        // Get the members repositories and set it to the array.
        $members = [];
        if (key_exists('members', $opencatalogi) === true) {
            $members = $this->getMembers($opencatalogi, $source);
        }//end if

        // Hydrate the organization with the arrays.
        $organization->hydrate(
            [
                'owns'     => $ownedComponents,
                'supports' => $supportedComponents,
                'uses'     => $usedComponents,
                'members'  => $members,
            ]
        );

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        return $organization;

    }//end getConnectedComponents()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity The updated github organization.
     */
    public function enrichGithubOrganization(ObjectEntity $organization): ObjectEntity
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

        if ($organization->getValue('type') === 'Organization') {
            // Get the organization from the github api.
            $organizationArray = $this->githubApiService->getOrganization(trim($githubPath, '/'), $source);
        }

        if ($organization->getValue('type') === 'User') {
            // Get the organization from the github api.
            $organizationArray = $this->githubApiService->getUser(trim($githubPath, '/'), $source);
        }

        if ($organizationArray === null) {
            return $organization;
        }

        $opencatalogiRepo = $organization->getValue('opencatalogiRepo');

        // If the opencatalogiRepo is not null get the file and update the organization.
        if ($opencatalogiRepo !== null) {
            // Get the opencatalogi file from the opencatalogiRepo property.
            $this->githubApiService->setConfiguration($this->configuration);
            $opencatalogi = $this->githubApiService->getFileFromRawUserContent($opencatalogiRepo);

            // Get the softwareSupported/softwareOwned/softwareUsed repositories.
            $organization = $this->getConnectedComponents($organization, $opencatalogi, $source);

            // Enrich the opencatalogi organization with a logo and description.
            $this->openCatalogiService->setConfiguration($this->configuration);
            $logo        = $this->openCatalogiService->enrichLogo($organizationArray, $opencatalogi, $organization);
            $description = $this->openCatalogiService->enrichDescription($organizationArray, $opencatalogi, $organization);

            // Hydrate the logo and description.
            $organization->hydrate(['logo' => $logo, 'description' => $description]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();

            $this->pluginLogger->info($organization->getValue('name').' succesfully updated the organization with the opencatalogi file.');

            return $organization;
        }//end if

        // If the opencatalogiRepo is null update the logo and description with the organization array.
        // Set the logo and description if null.
        if ($organization->getValue('logo') === null) {
            $organization->setValue('logo', $organizationArray['avatar_url']);
        }

        if ($organization->getValue('description') === null) {
            $organization->setValue('description', $organizationArray['description']);
        }

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $this->pluginLogger->info($organization->getValue('name').' succesfully updated the organization with a logo and/or description.');

        return $organization;

    }//end enrichGithubOrganization()


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
            // Enrich the organization object.
            return $this->enrichGithubOrganization($organization);
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
     * Makes sure the action the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
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
                // Enrich the organization object.
                $this->enrichGithubOrganization($organization);
            }//end if
        }

        return $this->data;

    }//end enrichOrganizationHandler()


}//end class
