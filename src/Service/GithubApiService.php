<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\MappingService;
use App\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\ClientException;
use phpDocumentor\Reflection\Types\This;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\YamlEncoder;

class GithubApiService
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
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var RatingService
     */
    private RatingService $ratingService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;


    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface
     * @param CallService            $callService      The Call Service
     * @param SynchronizationService $syncService      The Synchronisation Service
     * @param MappingService         $mappingService   The Mapping Service
     * @param RatingService          $ratingService    The Rating Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the logger interface
     * @param GatewayResourceService $resourceService  The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        RatingService $ratingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager    = $entityManager;
        $this->callService      = $callService;
        $this->syncService      = $syncService;
        $this->mappingService   = $mappingService;
        $this->ratingService    = $ratingService;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * Check the auth of the github source.
     *
     * @param Source $source The given source to check the api key.
     *
     * @return bool|null If the api key is set or not.
     */
    public function checkGithubAuth(Source $source): ?bool
    {
        if ($source->getApiKey() === null) {
            $this->pluginLogger->error('No auth set for Source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return false;
        }//end if

        return true;

    }//end checkGithubAuth()

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
     * This function gets repository and enriches it.
     *
     * @param string     $repositoryUrl   The url of the repository
     * @param array|null $repositoryArray The repository array from the github api.
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function getGithubRepository(string $repositoryUrl, ?array $repositoryArray=null): ?ObjectEntity
    {
        $repositorySchema  = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $repositoryMapping = $this->resourceService->getMapping($this->configuration['repositoryMapping'], 'open-catalogi/open-catalogi-bundle');
        if ($repositorySchema instanceof Entity === false
            || $repositoryMapping instanceof Mapping === false
        ) {
            return $this->data;
        }//end if

        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        // Do we have the api key set of the source.
        if ($this->checkGithubAuth($source) === false
        ) {
            return null;
        }//end if

        // Find de sync by source and repository url.
        $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryUrl);
        // @TODO: Check if there is already an object then we don't want to do anything.
        // if ($repositorySync->getObject() !== null) {
        // Hier willen we nu geen update doen.
        // return  $repositorySync->getObject();
        // }
        // Set the github repository mapping to the sync object.
        $repositorySync->setMapping($repositoryMapping);

        if ($repositoryArray === null) {
            // Get the repository from the github api.
            $repositoryArray = $this->getRepository($repositoryUrl, $source);
        }

        // Synchronize the github repository.
        $repositorySync = $this->syncService->synchronize($repositorySync, $repositoryArray);
        $this->entityManager->persist($repositorySync);
        $this->entityManager->flush();

        $repository = $repositorySync->getObject();

        // Get the publiccode/opencatalogi files of the given repository.
        $path = trim(\Safe\parse_url($repositoryUrl)['path'], '/');
        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:publiccode filename:opencatalogi extension:yaml extension:yml repo:{$path}"];
        $dataArray            = $this->getFilesFromRepo($source, $queryConfig);
        if ($dataArray !== null) {
            // Import the publiccode/opencatalogi files and connect it to the repository.
            $repository = $this->importRepoFiles($dataArray, $source, $repository);
        }

        // Cleanup the repository.
        $repository = $this->cleanupRepository($repository);

        // Enrich the repository with component and/or organization.
        $repository = $this->enrichRepository($repository, $repositoryArray, $source);

        // Rate the component(s) of the repository.
        // Return the repository object.
        $this->ratingService->setConfiguration($this->configuration);
        return $this->ratingService->rateRepoComponents($repository, $source, $repositoryArray);

    }//end getGithubRepository()


    /**
     * This function does a cleanup for the repository.
     *
     * @param ObjectEntity $repository The repository object.
     *
     * @return ObjectEntity|null Return the repository
     */
    public function cleanupRepository(ObjectEntity $repository): ?ObjectEntity
    {
        // If the repository has one or less components return.
        if ($repository->getValue('components')->count() <= 1) {
            return $repository;
        }

        // Loop through the components and remove the component we created.
        foreach ($repository->getValue('components') as $component) {
            $componentSourceId = $component->getSynchronizations()->first()->getSourceId();

            // If the component source id is the same as the repository url remove the component.
            if ($componentSourceId === $repository->getValue('url')) {
                $this->entityManager->remove($component);
                $this->entityManager->flush();
            }
        }

        return $repository;

    }//end cleanupRepository()


    /**
     * This function enriches the repository with a organization.
     *
     * @param ObjectEntity $repository The repository object.
     * @param array $organizationArray The organization array from the github api.
     * @param Source $source The github api source.
     *
     * @return ObjectEntity
     */
    public function enrichWithOrganization(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $repositoryArray['owner']['html_url']);

        if ($organizationSync->getObject() === null) {
            $organizationMapping = $this->resourceService->getMapping($this->configuration['organizationMapping'], 'open-catalogi/open-catalogi-bundle');

            $organizationSync->setMapping($organizationMapping);
            $organizationSync = $this->syncService->synchronize($organizationSync, $repositoryArray['owner']);
            $this->entityManager->persist($organizationSync);
        }

        $repository->hydrate(['organisation' => $organizationSync->getObject()]);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;

    }//end enrichWithOrganization()


    /**
     * This function enriches the repository with a component.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     * @param Source       $source          The github api source.
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity
     */
    public function enrichWithComponent(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        $componentSchema = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');

        $forkedFrom = $repository->getValue('forked_from');
        // Set the isBasedOn.
        if ($forkedFrom !== null) {
            $data['isBasedOn'] = $forkedFrom;
        }

        // Set developmentStatus obsolete when repository is archived.
        if ($repository->getValue('archived') === true) {
            $data['developmentStatus'] = 'obsolete';
        }

        $data = [
            'name' => $repository->getValue('name'),
            'url'  => $repository,
        ];

        $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryArray['html_url']);
        $componentSync = $this->syncService->synchronize($componentSync, $data);
        $this->entityManager->persist($componentSync);
        $this->entityManager->flush();

        return $repository;

    }//end enrichWithComponent()


    /**
     * This function enriches the repository with a organization and/or component.
     *
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api call.
     *
     * @return ObjectEntity The repository object
     * @throws GuzzleException
     */
    public function enrichRepository(ObjectEntity $repository, array $repositoryArray, Source $source): ObjectEntity
    {
        // If there is no organization create one.
        if ($repository->getValue('organisation') === false) {
            $repository = $this->enrichWithOrganization($repository, $repositoryArray, $source);
        }

        // If there is no component create one.
        if ($repository->getValue('components')->count() === 0) {
            $repository = $this->enrichWithComponent($repository, $repositoryArray, $source);
        }

        // @TODO: enrich the null values with what we have.
        return $repository;

    }//end enrichRepository()


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
        $repositorySchema    = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');
        $organizationSchema  = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

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
                    $repository = $this->getGithubRepository($repositoryUrl);
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
                    $repository = $this->getGithubRepository($supports['software']);
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
                    $repository = $this->getGithubRepository($repositoryUrl);
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
     * This function enriches the opencatalogi file organization.
     *
     * @param array $opencatalogiArray The opencatalogi array from the github api.
     * @param array $opencatalogi The opencatalogi file as array.
     * @param ObjectEntity $organization The organization object.
     * @param Source $source The github api source.
     * @return ObjectEntity
     */
    public function enrichOpencatalogiOrg(array $organizationArray, array $opencatalogi, ObjectEntity $organization, Source $source): ObjectEntity
    {

        // If the opencatalogi logo is set to null or false we set the organization logo to null.
        if (key_exists('logo', $opencatalogi) === true
            && $opencatalogi['logo'] === false
            || key_exists('logo', $opencatalogi) === true
            && $opencatalogi['logo'] === null
        ) {
            $organization->hydrate(['logo' => null]);
        }

        // If we get an empty string we set the logo from the github api.
        if (key_exists('logo', $opencatalogi) === true
            && $opencatalogi['logo'] === ''
        ) {
            $organization->hydrate(['logo' => $organizationArray['avatar_url']]);
        }

        // If we don't get a opencatalogi logo we set the logo from the github api.
        if (key_exists('logo', $opencatalogi) === false) {
            $organization->hydrate(['logo' => $organizationArray['avatar_url']]);
        }

        // If the opencatalogi description is set to null or false we set the organization description to null.
        if (key_exists('description', $opencatalogi) === true
            && $opencatalogi['description'] === false
            || key_exists('description', $opencatalogi) === true
            && $opencatalogi['description'] === null
        ) {
            $organization->hydrate(['description' => null]);
        }

        // If we get an empty string we set the description from the github api.
        if (key_exists('description', $opencatalogi) === true
            && $opencatalogi['description'] === ''
        ) {
            $organization->hydrate(['description' => $organizationArray['description']]);
        }

        // If we don't get a opencatalogi description we set the description from the github api.
        if (key_exists('description', $opencatalogi) === false) {
            $organization->hydrate(['description' => $organizationArray['description']]);
        }

        return $organization;

    }//end enrichOpencatalogiOrg()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $opencatalogiArray The opencatalogi array from the github api.
     * @param Source       $source            The github api source.
     * @param ObjectEntity $repository        The repository object.
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function handleOpencatalogiFile(array $organizationArray, Source $source, array $opencatalogiArray): ?ObjectEntity
    {
        $opencatalogiMapping = $this->resourceService->getMapping($this->configuration['opencatalogiMapping'], 'open-catalogi/open-catalogi-bundle');
        $organizationSchema  = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($opencatalogiMapping instanceof Mapping === false
            || $organizationSchema instanceof Entity === false
        ) {
            return null;
        }

        // Get the ref query from the url. This way we can get the publiccode file with the raw.gitgubusercontent.
        $opencatalogiUrlQuery = \Safe\parse_url($opencatalogiArray['url'])['query'];
        // Remove the ref= part of the query.
        $urlReference = explode('ref=', $opencatalogiUrlQuery)[1];
        // Create the publiccode/opencatalogi url
        $opencatalogiUrl = "https://raw.githubusercontent.com/{$opencatalogiArray['repository']['full_name']}/{$urlReference}/{$opencatalogiArray['path']}";

        // Get the file from the usercontent or github api source.
        $opencatalogi = $this->getFileFromRawUserContent($opencatalogiUrl, $opencatalogiArray['git_url']);

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if ($opencatalogi === null
            || $opencatalogi !== null
            && key_exists('publiccodeYmlVersion', $opencatalogi) === false
        ) {
            return $organization;
        }

        // Find the sync with the source and organization html_url.
        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationArray['html_url']);
        // Check the sha of the sync with the url reference in the array.
        if ($this->syncService->doesShaMatch($organizationSync, $urlReference) === true) {
            return $organizationSync->getObject();
        }

        $opencatalogi['github']           = $organizationArray['html_url'];
        $opencatalogi['type']             = $organizationArray['type'];
        $opencatalogi['opencatalogiRepo'] = $organizationSync->getObject()->getValue('opencatalogiRepo');

        $organizationSync->setMapping($opencatalogiMapping);

        // Synchronize the organization with the opencatalogi file.
        $organizationSync = $this->syncService->synchronize($organizationSync, $opencatalogi);

        $this->entityManager->persist($organizationSync);
        $this->entityManager->flush();

        // Get the softwareSupported/softwareOwned/softwareUsed repositories.
        $organization = $this->getConnectedComponents($organizationSync->getObject(), $opencatalogi, $source);

        // Enrich the opencatalogi organization with a logo and description.
        $organization = $this->enrichOpencatalogiOrg($organizationArray, $opencatalogi, $organization, $source);

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        return $organization;

    }//end handleOpencatalogiFile()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $publiccodeArray The publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $repository      The repository object.
     *
     * @return ObjectEntity
     */
    public function handlePubliccodeSubObjects(array $publiccodeArray, Source $source, ObjectEntity $component): ObjectEntity
    {
        if (key_exists('legal', $publiccodeArray) === true) {
            $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

            if (key_exists('repoOwner', $publiccodeArray) === true
                && key_exists('name', $publiccodeArray['repoOwner']) === true
                && is_string($publiccodeArray['repoOwner']['name']) === true
            ) {
                $repoOwnerSync = $this->syncService->findSyncBySource($source, $organizationSchema, $publiccodeArray['repoOwner']['name']);
                $repoOwnerSync = $this->syncService->synchronize($repoOwnerSync, $publiccodeArray['repoOwner']);

                $component->hydrate(['repoOwner' => $repoOwnerSync->getObject()]);
            }

            if (key_exists('mainCopyrightOwner', $publiccodeArray) === true
                && key_exists('name', $publiccodeArray['mainCopyrightOwner']) === true
                && is_string($publiccodeArray['mainCopyrightOwner']['name']) === true
            ) {
                $mainCopyrightOwnerSync = $this->syncService->findSyncBySource($source, $organizationSchema, $publiccodeArray['mainCopyrightOwner']['name']);
                $mainCopyrightOwnerSync = $this->syncService->synchronize($mainCopyrightOwnerSync, $publiccodeArray['mainCopyrightOwner']);

                $component->hydrate(['mainCopyrightOwner' => $mainCopyrightOwnerSync->getObject()]);
            }
        }//end if

        if (key_exists('applicationSuite', $publiccodeArray) === true
            && key_exists('name', $publiccodeArray['applicationSuite']) === true
            && is_string($publiccodeArray['applicationSuite']['name']) === true
        ) {
            $applicationSchema = $this->resourceService->getSchema($this->configuration['applicationSchema'], 'open-catalogi/open-catalogi-bundle');

            $applicationSuiteSync = $this->syncService->findSyncBySource($source, $applicationSchema, $publiccodeArray['applicationSuite']['name']);
            $applicationSuiteSync = $this->syncService->synchronize($applicationSuiteSync, $publiccodeArray['applicationSuite']);

            $component->hydrate(['applicationSuite' => $applicationSuiteSync->getObject()]);
        }

        return $component;

    }//end handlePubliccodeSubObjects()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $publiccodeArray The publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $repository      The repository object.
     *
     * @return ObjectEntity|null
     */
    public function handlePubliccodeFile(array $publiccodeArray, Source $source, ObjectEntity $repository): ?ObjectEntity
    {
        $publiccodeMapping = $this->resourceService->getMapping($this->configuration['publiccodeMapping'], 'open-catalogi/open-catalogi-bundle');
        $componentSchema   = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($publiccodeMapping instanceof Mapping === false
            || $componentSchema instanceof Entity === false
        ) {
            return $repository;
        }

        // Get the ref query from the url. This way we can get the publiccode file with the raw.gitgubusercontent.
        $publiccodeUrlQuery = \Safe\parse_url($publiccodeArray['url'])['query'];
        // Remove the ref= part of the query.
        $urlReference = explode('ref=', $publiccodeUrlQuery)[1];
        // Create the publiccode/opencatalogi url
        $publiccodeUrl = "https://raw.githubusercontent.com/{$publiccodeArray['repository']['full_name']}/{$urlReference}/{$publiccodeArray['path']}";

        // Create an unique sourceId for every publiccode that doesn't change.
        // The urlReference and the git_url changes when the file changes.
        $sourceId = "https://raw.githubusercontent.com/{$publiccodeArray['repository']['full_name']}/{$publiccodeArray['path']}";

        $this->pluginLogger->info('Map the publiccode file with url: '.$publiccodeUrl.' and source id: '.$sourceId);

        // Get the file from the usercontent or github api source
        $publiccode = $this->getFileFromRawUserContent($publiccodeUrl, $publiccodeArray['git_url']);

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if ($publiccode === null
            || $publiccode !== null
            && key_exists('publiccodeYmlVersion', $publiccode) === false
        ) {
            return $repository;
        }

        // Get the forked_from from the repository.
        $forkedFrom = $repository->getValue('forked_from');
        // Set the isBasedOn.
        if ($forkedFrom !== null && isset($publiccode['isBasedOn']) === false) {
            $publiccode['isBasedOn'] = $forkedFrom;
        }

        // Set developmentStatus obsolete when repository is archived.
        if ($repository->getValue('archived') === true) {
            $publiccode['developmentStatus'] = 'obsolete';
        }

        $componentSync = $this->syncService->findSyncBySource($source, $componentSchema, $sourceId);

        // Check the sha of the sync with the sha in the array.
        if ($this->syncService->doesShaMatch($componentSync, $urlReference) === true) {
            $componentSync->getObject()->hydrate(['url' => $repository]);

            $this->entityManager->persist($componentSync->getObject());
            $this->entityManager->flush();

            return $repository;
        }

        // Map the publiccode file.
        $componentArray = $dataArray = $this->mappingService->mapping($publiccodeMapping, $publiccode);

        unset($componentArray['legal']['repoOwner']);
        unset($componentArray['legal']['mainCopyrightOwner']);
        unset($componentArray['applicationSuite']);

        // Find the sync with the source and publiccode url.
        $componentSync = $this->syncService->synchronize($componentSync, $componentArray, true);

        // Handle the sub objects of the array.
        $component = $this->handlePubliccodeSubObjects($dataArray, $source, $componentSync->getObject());

        $component->hydrate(['url' => $repository]);
        $this->entityManager->persist($componentSync->getObject());
        $this->entityManager->flush();

        return $repository;

    }//end handlePubliccodeFile()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $dataArray  An array with publiccode/opencatalogi files.
     * @param Source       $source     The github api source.
     * @param ObjectEntity $repository The repository object.
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function importRepoFiles(array $dataArray, Source $source, ObjectEntity $repository): ?ObjectEntity
    {
        $opencatalogiNames = [
            'opencatalogi.yaml',
            'opencatalogi.yml',
        ];

        $publiccodeNames = [
            'publiccode.yaml',
            'publiccode.yml',
        ];

        // Loop through the array of publiccode/opencatalogi files.
        foreach ($dataArray as $item) {
            // Check if the item name is the same as the openCatalogiNames array.
            // If so go the the function for the opencatalogi file.
            if (in_array($item['name'], $opencatalogiNames) === true) {
                $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
                $this->pluginLogger->info('The item is a opencatalogi file.');

                $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $item['repository']['owner']['html_url']);
                $this->entityManager->persist($organizationSync);
                $this->entityManager->flush();

                // Only set these values if the object is null.
                if ($organizationSync->getObject() === null) {
                    $data             = [
                        'name'             => $item['repository']['owner']['login'],
                        'type'             => $item['repository']['owner']['type'],
                        'github'           => $item['repository']['owner']['html_url'],
                        'logo'             => $item['repository']['owner']['avatar_url'],
                        'opencatalogiRepo' => $item['repository']['html_url'],
                    ];
                    $organizationSync = $this->syncService->synchronize($organizationSync, $data);
                    $this->entityManager->persist($organizationSync->getObject());
                    $this->entityManager->persist($organizationSync);
                    $this->entityManager->flush();
                }

                $repository->hydrate(['organisation' => $organizationSync->getObject()]);
                $this->entityManager->persist($repository);
                $this->entityManager->flush();
            }//end if

            // Check if the item name is the same as the publiccodeNames array.
            // If so go the the function for the publiccode file.
            if (in_array($item['name'], $publiccodeNames) === true) {
                $this->pluginLogger->info('The item is a publiccode file.');
                $repository = $this->handlePubliccodeFile($item, $source, $repository);
            }
        }//end foreach

        return $repository;

    }//end importRepoFiles()


    /**
     * This function gets the publiccode/opencatalogi file from the github gitub api.
     *
     * @param string $gitUrl The git url of the repository
     *
     * @return array|null
     */
    public function getFileFromGithubApi(string $gitUrl, ?string $query=null): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return null;
        }

        // Get the path from the git url to make the call.
        $endpoint = \Safe\parse_url($gitUrl)['path'];

        try {
            $response = $this->callService->call($source, $endpoint, 'GET', $query);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$gitUrl.' '.$e->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        // Decode the response from the call.
        try {
            $decodedResponse = $this->callService->decodeResponse($source, $response, 'application/json');
        } catch (Exception $e) {
            $this->pluginLogger->error($e->getMessage());
        }

        // Check if there is a key content. This is the base64 of the file.
        if (isset($decodedResponse) === false
            && key_exists('content', $response) === false
        ) {
            return null;
        }

        // Decode the base64 string.
        $content = \Safe\base64_decode($response['content']);

        $yamlEncoder = new YamlEncoder();

        // Decode the string.
        return $yamlEncoder->decode($content, 'yaml');

    }//end getFileFromGithubApi()


    /**
     * This function gets the publiccode/opencatalogi file from the github user content.
     *
     * @param string      $repositoryUrl The url of the repository
     * @param string|null $gitUrl        The git url of the repository
     *
     * @return array|null
     * @throws GuzzleException
     */
    public function getFileFromRawUserContent(string $repositoryUrl, ?string $gitUrl=null): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return null;
        }

        // Get the path from the url to make the call.
        $endpoint = \Safe\parse_url($repositoryUrl)['path'];
        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$repositoryUrl.' '.$e->getMessage());
        }

        if (isset($response) === false
            && $gitUrl !== null
        ) {
            // Call the github api for the publiccode/opencatalogi files.
            return $this->getFileFromGithubApi($gitUrl);
        }

        if (isset($response) === true) {
            try {
                $decodedResponse = $this->callService->decodeResponse($source, $response, 'text/yaml');
            } catch (Exception $e) {
                $this->pluginLogger->error('Error decoding response of repo: '.$repositoryUrl.' '.$e->getMessage());
            }

            if (isset($decodedResponse) === true
                && is_array($decodedResponse) === true
            ){
                return $decodedResponse;
            }
        }

        return null;

    }//end getFileFromRawUserContent()


    /**
     * Get a repository from github with the given repository url
     *
     * @param string $repositoryUrl The url of the repository.
     * @param Source $source        The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getRepository(string $repositoryUrl, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository with url'.$repositoryUrl.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $path = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');

        // Get the repository from github with the path as endpoint.
        try {
            $response = $this->callService->call($source, '/repos/'.$path);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        // Check if we have a response if not then return null.
        if (isset($response) === false) {
            return null;
        }

        // Decode the response with the source.
        try {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        // If we cannot decode the response then return null.
        if (isset($repository) === true
            && $repository === null
        ) {
            $this->pluginLogger->error('Could not find a repository with url: '.$repositoryUrl.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getRepository()


    /**
     * Get the publiccode/opencatalogi files of the given repository
     *
     * @param Source $source      The source to sync from.
     * @param array  $queryConfig The query config of the call.
     *
     * @return array|null The publiccode/opencatalogi files as array.
     */
    public function getFilesFromRepo(Source $source, array $queryConfig): ?array
    {
        // Find the publiccode.yaml file(s).
        try {
            $response = $this->callService->call($source, '/search/code', 'GET', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/search/code'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        try {
            $dataArray = $this->callService->decodeResponse($source, $response);
        } catch (Exception $exception) {
            $this->pluginLogger->error($exception->getMessage());
        }

        if (isset($dataArray) === false){
            return null;
        }

        $this->pluginLogger->debug('Found '.$dataArray['total_count'].' publiccode/opencatalogi file(s).', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $dataArray['items'];

    }//end getFilesFromRepo()


    /**
     * Get the publiccode/opencatalogi files of the given repository
     *
     * @return array|null The publiccode/opencatalogi files as array.
     */
    public function getGithubFiles(): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');

        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:publiccode filename:opencatalogi extension:yaml extension:yml"];

        // Find the publiccode.yaml file(s).
        try {
            $response = $this->callService->getAllResults($source, '/search/code', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/search/code'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        $this->pluginLogger->debug('Found '.count($response).' publiccode/opencatalogi file(s).', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $response;

    }//end getGithubFiles()


    /**
     * Get a organization with type Organization from the github api.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
     */
    public function getOrganization(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting organization '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/orgs/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $organization = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($organization === null) {
            $this->pluginLogger->error('Could not find a organization with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $organization;

    }//end getOrganization()


    /**
     * Get a repositories of the organization from the github api.
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getOrganizationRepos(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/orgs'.$name.'/repos');
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repositories = \Safe\json_decode($response->getBody()->getContents(), true);

        if (empty($repositories) === true
            || $repositories === null
        ) {
            $this->pluginLogger->error('Could not find the repositories of the organization with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repositories;

    }//end getOrganizationRepos()


    /**
     * Get a organization with type Organization from the github api.
     *
     * @param string $name   The name of the organization.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported organization as array.
     */
    public function getUser(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting user '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/users/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $organization = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($organization === null) {
            $this->pluginLogger->error('Could not find a user with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $organization;

    }//end getUser()


    /**
     * Get a repositories of the organization with type user from the github api.
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getUserRepos(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/users'.$name.'/repos');
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repositories = \Safe\json_decode($response->getBody()->getContents(), true);

        if (empty($repositories) === true
            || $repositories === null
        ) {
            $this->pluginLogger->error('Could not find the repositories of the user with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repositories;

    }//end getUserRepos()

     /** This function searches for all repositories with a publiccode or one repository
     *
     * @param array|null  $data          data set at the start of the handler
     * @param array|null  $configuration configuration of the action
     * @param string|null $repositoryId  The given repository id
     *
     * @return array|null dataset at the end of the handler
     * @throws Exception
     */
    public function findGithubRepositories(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');


        // If we have one repository.
        if ($repositoryId !== null) {

        }

        // If we have all repositories.
        if ($repositoryId === null) {
            // Get all the repositories with a publiccode/opencatalogi file.
            $repositories = $this->getGithubFiles();

            $response = [];
            foreach ($repositories as $repositoryArray) {

                if (key_exists('repository', $repositoryArray) === false
                    || key_exists('html_url', $repositoryArray['repository']) === false
                ) {
                    continue;
                }

                $repositorySync = $this->syncService->findSyncBySource($source, $repositorySchema, $repositoryArray['repository']['html_url']);

                if ($repositorySync->getObject() !== null){
                    $repository = $repositorySync->getObject();
                }

                if ($repositorySync->getObject() === null){
                    $this->entityManager->remove($repositorySync);
                    $this->entityManager->flush();
                    $repository = $this->getGithubRepository($repositoryArray['repository']['html_url'], $repositoryArray['repository']);
                }

                $response[] = $repository->toArray();
            }

            if (isset($response) === true) {
                $this->data['response'] = new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
            }
        }

        return $this->data;
    }//end findGithubRepositories()
}//end class
