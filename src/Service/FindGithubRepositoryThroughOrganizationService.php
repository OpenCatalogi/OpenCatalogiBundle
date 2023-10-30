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
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

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
     * @var Yaml
     */
    private Yaml $yaml;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface  $entityManager          The Entity Manager Interface
     * @param GithubPubliccodeService $githubService          The Github Publiccode Service
     * @param CallService             $callService            The Call Service
     * @param LoggerInterface         $pluginLogger           The plugin version of the logger interface
     * @param GatewayResourceService  $resourceService        The Gateway Resource Service.
     * @param MappingService          $mappingService         The Mapping Service
     * @param GithubApiService        $githubApiService       The Github API Service
     * @param ImportResourcesService  $importResourcesService The Import Resources Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubPubliccodeService $githubService,
        CallService $callService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        ImportResourcesService $importResourcesService,
        SynchronizationService $syncService
    ) {
        $this->callService            = $callService;
        $this->entityManager          = $entityManager;
        $this->githubService          = $githubService;
        $this->pluginLogger           = $pluginLogger;
        $this->resourceService        = $resourceService;
        $this->mappingService         = $mappingService;
        $this->githubApiService       = $githubApiService;
        $this->importResourcesService = $importResourcesService;
        $this->yaml                   = new Yaml();
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
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $organizationName
     * @param Source $source           The given source.
     *
     * @return array|null
     */
    private function getOpenCatalogiFromGithubRepo(string $organizationName, Source $source): ?array
    {
        $possibleEndpoints = [
            '/'.$organizationName.'/.github/main/openCatalogi.yaml',
            '/'.$organizationName.'/.github/main/openCatalogi.yml',
            '/'.$organizationName.'/.github/master/openCatalogi.yaml',
            '/'.$organizationName.'/.github/master/openCatalogi.yml',
            '/'.$organizationName.'/.github/main/opencatalogi.yaml',
            '/'.$organizationName.'/.github/main/opencatalogi.yml',
            '/'.$organizationName.'/.github/master/opencatalogi.yaml',
            '/'.$organizationName.'/.github/master/opencatalogi.yml',
        ];

        foreach ($possibleEndpoints as $endpoint) {
            try {
                $response = $this->callService->call($source, $endpoint);
            } catch (Exception $e) {
                $this->pluginLogger->error('Error found trying to fetch '.$endpoint.' '.$e->getMessage());
            }

            if (isset($response) === true) {
                // @TODO use decodeResponse from the callService
                $openCatalogi = $this->yaml->parse($response->getBody()->getContents());
                $this->pluginLogger->debug("Fetch and decode went succesfull '/'.$organizationName.'/.github/master/openCatalogi.yml', '/'.$organizationName.'/.github/master/openCatalogi.yaml'");

                return $openCatalogi;
            }//end if
        }

        return null;

    }//end getOpenCatalogiFromGithubRepo()


    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $organizationName used as path to fetch from
     * @param Source $source           The given source.
     *
     * @throws Exception
     *
     * @return array|null
     */
    private function getGithubRepoFromOrganization(string $organizationName, Source $source): ?array
    {
        try {
            $response = $this->callService->call($source, '/repos/'.$organizationName.'/.github');
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch /repos/'.$organizationName.'/.github: '.$e->getMessage());
        }

        if (isset($response) === true) {
            $githubRepo = $this->callService->decodeResponse($source, $response, 'application/json');
            $this->pluginLogger->debug('Fetch and decode went succesfull for /repos/'.$organizationName.'/.github');

            return $githubRepo;
        }//end if

        return null;

    }//end getGithubRepoFromOrganization()


    /**
     * Get an organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param string       $url          The url of the repository.
     * @param ObjectEntity $organization The organisation object.
     * @param string       $type         The type of the organisation.
     * @param Source       $source       The given source.
     *
     * @return array|null
     * @throws GuzzleException|LoaderError|SyntaxError|Exception
     */
    public function getOrganisationRepo(string $url, ObjectEntity $organization, string $type, Source $source): ?ObjectEntity
    {
        $domain = \Safe\parse_url($url, PHP_URL_HOST);
        if ($domain !== 'github.com') {
            return null;
        }//end if

        $name = trim(\Safe\parse_url($url, PHP_URL_PATH), '/');

        $this->pluginLogger->debug('Getting repo from organisation '.$name);
        $response = $this->callService->call($source, '/repos/'.$name);

        $repository = json_decode($response->getBody()->getContents(), true);
        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if

        $repositoryObject = $this->importResourcesService->importGithubRepository($repository, $this->configuration);
        $this->pluginLogger->debug('Found repo from organisation with name: '.$name);

        if ($type === 'use') {
            $component = $repositoryObject->getValue('component');
            $component && $component->setValue('usedBy', [$organization]);
        }

        return $repositoryObject;

    }//end getOrganisationRepo()


    /**
     * Fetches opencatalogi.yaml info with function getOpenCatalogiFromGithubRepo for an organization and updates the given organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @throws GuzzleException|Exception
     *
     * @return void
     */
    public function getOrganizationCatalogi(ObjectEntity $organization): void
    {
        // Do we have a source?
        // usercontentSource
        $source            = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $usercontentSource = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null
            || $usercontentSource === null
            || $this->githubApiService->checkGithubAuth($source) === false
        ) {
            return;
        }//end if

        if (($githubRepo = $this->getGithubRepoFromOrganization($organization->getValue('name'), $source)) === null) {
            return;
        }//end if

        $this->pluginLogger->debug('Github repo found and fetched for '.$organization->getName());

        if (($openCatalogi = $this->getOpenCatalogiFromGithubRepo($organization->getValue('name'), $usercontentSource)) === null) {
            return;
        }//end if

        $this->pluginLogger->debug('OpenCatalogi.yml or OpenCatalogi.yaml found and fetched for '.$organization->getName());

        $mapping = $this->resourceService->getMapping($this->configuration['openCatalogiMapping'], 'open-catalogi/open-catalogi-bundle');
        if ($mapping === null) {
            return;
        }

        $organizationArray = $this->mappingService->mapping($mapping, $openCatalogi);
        // Set the values to the array that cannot be overwritten.
        $organizationArray[] = [
            'github'   => $organization->getValue('github'),
            'type'     => $organization->getValue('type'),
            'supports' => $organization->getValue('type'),
            'owns'     => $organization->getValue('owns'),
            'uses'     => $organization->getValue('uses'),
        ];

        $organization->hydrate($organizationArray, true);
        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $uses = [];
        if (key_exists('softwareUsed', $openCatalogi) === true) {
            foreach ($openCatalogi['softwareUsed'] as $use) {
                // Get organisation repos and set the property.
                $uses[] = $this->getOrganisationRepo($use, $organization, 'use', $source);
            }
        }

        $organization->setValue('uses', $uses);

        $supports = [];
        if (key_exists('softwareSupported', $openCatalogi) === true) {
            foreach ($openCatalogi['softwareSupported'] as $support) {
                if (key_exists('software', $support) === false) {
                    continue;
                }

                // Get organisation component and set the property.
                $supports[] = $supportOrganisation = $this->getOrganisationRepo($support['software'], $organization, 'supports', $source);

                if (key_exists('contact', $support) === false) {
                    continue;
                }

                if (key_exists('email', $support['contact']) === true) {
                    $supportOrganisation->setValue('email', $support['contact']['email']);
                }

                if (key_exists('phone', $support['contact']) === true) {
                    $supportOrganisation->setValue('email', $support['contact']['phone']);
                }

                $this->entityManager->persist($supportOrganisation);
            }//end foreach
        }//end if

        $organization->setValue('supports', $supports);

        $members = [];
        if (isset($openCatalogi['members']) === true) {
            foreach ($openCatalogi['members'] as $organizationUrl) {
                $name         = trim(\Safe\parse_url($organizationUrl, PHP_URL_PATH), '/');
                $explodedName = explode('/', $name);

                // Check if the array has 1 item. If so this is an organisation.
                if (count($explodedName) === 1) {
                    $organizationName = $name;
                }

                // Check if this is a .github repository
                foreach ($explodedName as $item) {
                    if ($item === '.github') {
                        $organizationName = $explodedName[0];
                    }
                }

                $members[] = $this->createOrganization($organizationName, $usercontentSource);
            }
        }

        $organization->hydrate(['members' => $members]);

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $this->pluginLogger->debug($organization->getName().' succesfully updated with fetched openCatalogi info');

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
    public function findGithubRepositoryThroughOrganizationHandler(?array $data=[], ?array $configuration=[], ?string $organisationId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        if ($organisationId !== null) {
            // If we are testing for one repository.
            $organisation = $this->entityManager->find('App:ObjectEntity', $organisationId);
            if ($organisation instanceof ObjectEntity === true
                && $organisation->getValue('name') !== null
                && $organisation->getValue('github') !== null
            ) {
                $this->getOrganizationCatalogi($organisation);
            }//end if

            if ($organisation instanceof ObjectEntity === false) {
                $this->pluginLogger->error('Could not find given organisation');

                return null;
            }//end if
        }

        $organisationSchema = $this->resourceService->getSchema($this->configuration['organisationSchema'], 'open-catalogi/open-catalogi-bundle');

        // If we want to do it for al repositories.
        $this->pluginLogger->info('Looping through organisations');
        foreach ($organisationSchema->getObjectEntities() as $organisation) {
            if ($organisation->getValue('name') !== null
                && $organisation->getValue('github') !== null
            ) {
                $this->getOrganizationCatalogi($organisation);
            }
        }

        return $this->data;

    }//end findGithubRepositoryThroughOrganizationHandler()


    /**
     * This function creates/updates the organization with the github event response.
     *
     * @param string $organizationName The name of the organization
     * @param Source $source           The github api source.
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return array|null The data with the repository in the response array.
     *
     * @TODO: move usages in different services here
     */
    public function createOrganization(string $organizationName, Source $source): ?ObjectEntity
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');

        $organizationArray = $this->getOrganization($organizationName, $source);

        // If the organization is null return this->data
        if ($organizationArray === null) {
            $this->data['response'] = new Response('Could not find a organization with name: '.$organizationName.' and with source: '.$source->getName().'.', 404);

            return null;
        }

        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        $mapping            = $this->resourceService->getMapping($this->configuration['organizationMapping'], 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationArray['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->syncService->synchronize($synchronization, $organizationArray);

        $organizationObject = $synchronization->getObject();

        $this->getOrganizationCatalogi($organizationObject);

        return $organizationObject;

    }//end createOrganization()


    /**
     * Get an organization from the given name.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
     */
    public function getOrganization(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting organization '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $response = $this->callService->call($source, '/orgs/'.$name);

        $organization = json_decode($response->getBody()->getContents(), true);

        if ($organization === null) {
            $this->pluginLogger->error('Could not find a organization with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $organization;

    }//end getOrganization()


}//end class
