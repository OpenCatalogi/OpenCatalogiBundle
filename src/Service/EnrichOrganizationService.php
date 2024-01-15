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
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GitlabApiService
     */
    private GitlabApiService $gitlabApiService;

    /**
     * @var PubliccodeService
     */
    private PubliccodeService $publiccodeService;

    /**
     * @var OpenCatalogiService
     */
    private OpenCatalogiService $openCatalogiService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @param SynchronizationService $syncService The Synchronization Service
     * @param PubliccodeService $publiccodeService The publiccode service
     * @param OpenCatalogiService $openCatalogiService The opencatalogi service
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
        $this->entityManager    = $entityManager;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->syncService = $syncService;
        $this->githubApiService = $githubApiService;
        $this->gitlabApiService = $gitlabApiService;
        $this->publiccodeService = $publiccodeService;
        $this->openCatalogiService = $openCatalogiService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * This function enriches the repository with a organization and/or component.
     *
     * @param ObjectEntity $organization The organization object.
     * @param array        $opencatalogi opencatalogi file array from the github usercontent/github api call.
     * @param Source       $source       The github api source.
     *
     * @return ObjectEntity The repository object
     * @throws Exception
     */
    public function getConnectedComponents(ObjectEntity $organization, array $opencatalogi, Source $source): ObjectEntity
    {
        $repositorySchema   = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

        if (key_exists('softwareOwned', $opencatalogi) === true) {
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
                    $this->entityManager->flush();
                    $this->githubApiService->setConfiguration($this->configuration);
                    $repository = $this->githubApiService->getGithubRepository($repositoryUrl);
                }

                // Set the components of the repository to the array.
                foreach ($repository->getValue('components') as $component) {
                    $ownedComponents[] = $component;
                }
            }

            $organization->hydrate(['owns' => $ownedComponents]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        if (key_exists('softwareSupported', $opencatalogi) === true) {
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
                    $this->entityManager->flush();
                    $this->githubApiService->setConfiguration($this->configuration);
                    $repository = $this->githubApiService->getGithubRepository($supports['software']);
                }

                // Set the components of the repository
                foreach ($repository->getValue('components') as $component) {
                    $supportedComponents[] = $component;
                }
            }//end foreach

            $organization->hydrate(['supports' => $supportedComponents]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        if (key_exists('softwareUsed', $opencatalogi) === true) {
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
                    $this->entityManager->flush();

                    $this->githubApiService->setConfiguration($this->configuration);
                    $repository = $this->githubApiService->getGithubRepository($repositoryUrl);
                }

                // Set the components of the repository
                foreach ($repository->getValue('components') as $component) {
                    $usedComponents[] = $component;
                }
            }

            $organization->hydrate(['uses' => $usedComponents]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

        if (key_exists('members', $opencatalogi) === true) {
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

            $organization->hydrate(['members' => $members]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }//end if

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
     * @return ObjectEntity
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

        $opencatalogiRepo = $organization->getValue('opencatalogiRepo');

        // If the opencatalogiRepo is not null get the file and update the organization.
        if ($opencatalogiRepo !== null) {

            // Get the opencatalogi file from the opencatalogiRepo property.
            $opencatalogi    = $this->githubApiService->getFileFromRawUserContent($opencatalogiRepo);

            // Get the softwareSupported/softwareOwned/softwareUsed repositories.
            $organization = $this->getConnectedComponents($organization, $opencatalogi, $source);

            // Enrich the opencatalogi organization with a logo and description.
            $organization = $this->openCatalogiService->enrichOpencatalogiOrg($organizationArray, $opencatalogi, $organization, $source);

            $this->pluginLogger->info($organization->getValue('name').' succesfully updated the organization with the opencatalogi file.');

            return $organization;
        }

        // If the opencatalogiRepo is null update the logo and description with the organization array.
        if ($opencatalogiRepo === null) {

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
        }

        return $organization;

    }//end enrichOrganization()

    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param string $organizationId The id of the organization in the response.
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity
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
     * Makes sure the action the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
     *
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null dataset at the end of the handler              (not needed here)
     */
    public function enrichOrganizationHandler(?array $data=[], ?array $configuration=[], ?string $organizationId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        // If there is an organization in the response.
        if ($organizationId !== null) {
            $organization = $this->getOrganization($organizationId);

            $this->data['response'] = new Response(json_encode($organization->toArray()), 200, ['Content-Type' => 'application/json']);
        }

        // If there is no organization we get all the organizations and enrich it.
        if ($organizationId === null) {
            $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
            $organizations      = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $organizationSchema]);

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
        }

        return $this->data;

    }//end enrichOrganizationHandler()


}//end class
