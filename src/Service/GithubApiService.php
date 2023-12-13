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
     * @var GitlabApiService
     */
    private GitlabApiService $gitlabApiService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param CallService            $callService     The Call Service
     * @param SynchronizationService $syncService     The Synchronisation Service
     * @param MappingService         $mappingService  The Mapping Service
     * @param RatingService          $ratingService   The Rating Service.
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @param GitlabApiService $gitlabApiService The Gitlab API Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        RatingService $ratingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GitlabApiService $gitlabApiService
    ) {
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->syncService     = $syncService;
        $this->mappingService  = $mappingService;
        $this->ratingService   = $ratingService;
        $this->pluginLogger    = $pluginLogger;
        $this->resourceService = $resourceService;
        $this->gitlabApiService = $gitlabApiService;

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
     * This function gets a github repository and enriches it.
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

        // Set the github repository mapping to the sync object.
        $repositorySync->setMapping($repositoryMapping);

        if ($repositoryArray === null) {
            // Get the repository from the github api.
            $repositoryArray = $this->getRepository($repositoryUrl, $source);
        }

        if ($repositoryArray === null) {
            return null;
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
     * @param ObjectEntity $repository      The repository object.
     * @param array        $repositoryArray The repository array from the github api.
     * @param Source       $source          The github api source.
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
     * @param  array        $opencatalogiArray The opencatalogi array from the github api.
     * @param  array        $opencatalogi      The opencatalogi file as array.
     * @param  ObjectEntity $organization      The organization object.
     * @param  Source       $source            The github api source.
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

        // Find the sync with the source and organization html_url.
        $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationArray['html_url']);
        // Check the sha of the sync with the url reference in the array.
        if ($this->syncService->doesShaMatch($organizationSync, $urlReference) === true) {
            return $organizationSync->getObject();
        }

        // Check if the publiccodeYmlVersion is set otherwise this is not a valid file.
        if ($opencatalogi === null
            || $opencatalogi !== null
            && key_exists('publiccodeYmlVersion', $opencatalogi) === false
        ) {
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
     * This function sets the contractors or contacts to the maintenance object and sets the maintenance to the component.
     *
     * @param Source       $source          The github api source.
     * @param array $itemArray The contacts array or the contractor array.
     * @param string $valueName The value that needs to be updated and set to the maintenance object.
     *
     * @return ObjectEntity The updated component object.
     */
    public function handleMaintenaceObjects(ObjectEntity $component, array $itemArray, string $valueName): ObjectEntity
    {
        // Get the maintenance object.
        $maintenance = $component->getValue('maintenance');

        // Create a maintenance object if $maintenance is false.
        if ($maintenance === false) {
            $maintenanceSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.maintenance.schema.json', 'open-catalogi/open-catalogi-bundle');
            $maintenance = new ObjectEntity($maintenanceSchema);
        }

        // Set the given value with the given array to the maintenance object.
        $maintenance->setValue($valueName, $itemArray);
        $this->entityManager->persist($maintenance);

        // Set the updated maintenance object to the component.
        $component->hydrate(['maintenance' => $maintenance]);
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        return $component;
    }

    /**
     * This function handles the contractor object and sets it to the component
     *
     * @param Source       $source          The github api source.
     * @param ObjectEntity $component      The component object.
     * @param array $publiccode The publiccode file from the github api as array.
     *
     * @return ObjectEntity
     */
    public function handleContractors(Source $source, ObjectEntity $component, array $publiccode): ObjectEntity
    {
        // Loop through the contractors of the publiccode file.
        $contractors = [];
        foreach ($publiccode['maintenance']['contractors'] as $contractor) {

            // The name and until properties are mandatory, so only set the contractor if this is given.
            if (key_exists('name', $contractor) === true
                && key_exists('until', $contractor) === true
            ) {
                $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
                // TODO: get the contractor reference from the configuration array.
//              $contractorSchema = $this->resourceService->getSchema($this->configuration['contractorSchema'], 'open-catalogi/open-catalogi-bundle');
                $contractorSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.contractor.schema.json', 'open-catalogi/open-catalogi-bundle');

                // Find the contractor organization sync by source so we don't make duplicates.
                // Set the type of the organisation to Contractor.
                $contractorOrgSync = $this->syncService->findSyncBySource($source, $organizationSchema, $contractor['name']);
                // TODO: add and use a mapping object.
                $email = null;
                if (key_exists('email', $contractor) === true) {
                    $email = $contractor['email'];
                }
                $website = null;
                if (key_exists('website', $contractor) === true) {
                    $website = $contractor['website'];
                }
                $contractorOrgSync = $this->syncService->synchronize($contractorOrgSync, ['name' => $contractor['name'], 'email' => $email, 'website' => $website, 'type' => 'Contractor']);

                // Find the contractor sync by source.
                $contractorSync = $this->syncService->findSyncBySource($source, $contractorSchema, $contractor['name']);
                $contractorSync = $this->syncService->synchronize($contractorSync, ['until' => $contractor['until'], 'organisation' => $contractorOrgSync->getObject()]);

                // Set the contractor object to the contractors array.
                $contractors[] = $contractorSync->getObject();
            }
        }

        // Add the contractors to the maintenance object and the maintenance to the component.
        return $this->handleMaintenaceObjects($component, $contractors, 'contractors');
    }

    /**
     * This function handles the contacts object and sets it to the component
     *
     * @param Source       $source          The github api source.
     * @param ObjectEntity $component      The component object.
     * @param array $publiccode The publiccode file from the github api as array.
     *
     * @return ObjectEntity
     */
    public function handleContacts(Source $source, ObjectEntity $component, array $publiccode): ObjectEntity
    {
        // Loop through the contacts of the publiccode file.
        $contacts = [];
        foreach ($publiccode['maintenance']['contacts'] as $contact) {

            // The name property is mandatory, so only set the contact if this is given.
            if (key_exists('name', $contact) === true
            ) {
                $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
//                        $contactSchema = $this->resourceService->getSchema($this->configuration['contactSchema'], 'open-catalogi/open-catalogi-bundle');
                $contactSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.contact.schema.json', 'open-catalogi/open-catalogi-bundle');

                // TODO: add and use a mapping object.
                $email = null;
                if (key_exists('email', $contact) === true) {
                    $email = $contact['email'];
                }
                $phone = null;
                if (key_exists('phone', $contact) === true) {
                    $phone = $contact['phone'];
                }
                $affiliation = null;
                if (key_exists('affiliation', $contact) === true) {
                    $phone = $contact['affiliation'];
                }

                // Find the contact sync by source.
                $contactSync = $this->syncService->findSyncBySource($source, $contactSchema, $contact['name']);
                $contactSync = $this->syncService->synchronize($contactSync, ['name' => $contact['name'], 'email' => $email, 'phone' => $phone, 'affiliation' => $affiliation]);

                // Set the contact object to the contacts array.
                $contacts[] = $contactSync->getObject();
            }
        }

        // Add the contacts to the maintenance object and the maintenance to the component.
        return $this->handleMaintenaceObjects($component, $contacts, 'contacts');
    }


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $component      The component object.
     * @param array $publiccode The publiccode file from the github api as array.
     *
     * @return ObjectEntity
     */
    public function handlePubliccodeSubObjects(array $publiccodeArray, Source $source, ObjectEntity $component, array $publiccode): ObjectEntity
    {
        // Check of the maintenance is set in the publiccode file.
        if (key_exists('maintenance', $publiccode) === true) {

            // Check if the maintenance contractors is set in the publiccode file and if the contractors is an array.
            if (key_exists('contractors', $publiccode['maintenance']) === true
                && is_array($publiccode['maintenance']['contractors']) === true
            ) {
                $component = $this->handleContractors($source, $component, $publiccode);
            }

            // Check if the maintenance contacts is set in the publiccode file and if the contacts is an array.
            if (key_exists('contacts', $publiccode['maintenance']) === true
                && is_array($publiccode['maintenance']['contacts']) === true
            ) {
                $component = $this->handleContacts($source, $component, $publiccode);
            }
        }

        // If the legal repoOwner and/or the legal mainCopyrightOwner is set, find sync by source so there are no duplicates.
        if (key_exists('legal', $publiccodeArray) === true) {
            $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');

            if (key_exists('repoOwner', $publiccodeArray) === true
                && key_exists('name', $publiccodeArray['repoOwner']) === true
                && is_string($publiccodeArray['repoOwner']['name']) === true
            ) {
                $repoOwnerSync = $this->syncService->findSyncBySource($source, $organizationSchema, $publiccodeArray['repoOwner']['name']);
                $repoOwnerSync = $this->syncService->synchronize($repoOwnerSync, ['name' => $publiccodeArray['repoOwner']['name'], 'type' => 'Owner']);

                $component->hydrate(['repoOwner' => $repoOwnerSync->getObject()]);
            }

            if (key_exists('mainCopyrightOwner', $publiccodeArray) === true
                && key_exists('name', $publiccodeArray['mainCopyrightOwner']) === true
                && is_string($publiccodeArray['mainCopyrightOwner']['name']) === true
            ) {
                $mainCopyrightOwnerSync = $this->syncService->findSyncBySource($source, $organizationSchema, $publiccodeArray['mainCopyrightOwner']['name']);
                $mainCopyrightOwnerSync = $this->syncService->synchronize($mainCopyrightOwnerSync, ['name' => $publiccodeArray['mainCopyrightOwner']['name'], 'type' => 'Owner']);

                $component->hydrate(['mainCopyrightOwner' => $mainCopyrightOwnerSync->getObject()]);
            }
        }//end if

        if (key_exists('applicationSuite', $publiccodeArray) === true
            && is_string($publiccodeArray['applicationSuite']) === true
        ) {
            $applicationSchema = $this->resourceService->getSchema($this->configuration['applicationSchema'], 'open-catalogi/open-catalogi-bundle');

            $applicationSuiteSync = $this->syncService->findSyncBySource($source, $applicationSchema, $publiccodeArray['applicationSuite']);
            $applicationSuiteSync = $this->syncService->synchronize($applicationSuiteSync, ['name' => $publiccodeArray['applicationSuite']]);

            $component->hydrate(['applicationSuite' => $applicationSuiteSync->getObject()]);
        }

        return $component;

    }//end handlePubliccodeSubObjects()

    /**
     * This function does a call to the given source with the given endpoint.
     *
     * There are 4 types that can be given. url, raw, avatar and relative. So we know how to handle the response and set the text of the logs that are being created.
     * * Url and relative types calls to the github api source with a given endpoint with format /repos/{owner}/{repo}/contents/{path}. The response is being decoded and the download_url property is being returned.
     * * Raw type does a call to the github usercontent and avatar type does a call to the github avatar source with the parsed logo path as endpoint. The response status code is being checked for a 200 response, then the url is valid and can be returned.
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source or usercontent source.
     * @param string $endpoint      The endpoint of the call that should be made. For the github api source is the endpoint format: /repos/{owner}/{repo}/contents/{path}. For the usercontent source is the endpoint format: the parsed logo url path.
     * @param string $type The type of the logo that is trying to be retrieved from the given source. (url = A github url / raw = a raw github url / relative = a relative path).
     * @param string|null $logoUrl The given logo url from the publiccode file, only needed when the type is raw.
     *
     * @return string|null With type raw the logo from the publiccode file if valid, if not null is returned. With type url and relative the download_url from the reponse of the call.
     */
    public function getLogoFileContent(array $publiccodeArray, Source $source, string $endpoint, string $type, ?string $logoUrl = null): ?string
    {
        // The logo is as option 2, 3 or 4. Do a call via the callService to check if the logo can be found.
        // If the type is url or relative the endpoint is in the format: /repos/{owner}/{repo}/contents/{path} is given.
        // If the type is raw the endpoint the parsed url path: \Safe\parse_url($publiccodeArray['logo'])
        $errorCode = null;
        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $exception) {
            // Set the error code so there can be checked if the file cannot be found or that the rate limit is reached.
            $errorCode = $exception->getCode();

            // Create an error log for all the types (url, raw, avatar and relative).
            $this->pluginLogger->error('The logo with url: '.$publiccodeArray['logo'].' cannot be found from the source with reference: '.$source->getReference(). ' with endpoint: '.$endpoint);
        }

        // If the response is not set return the logo from the publiccode file from the github api.
        // Check if that the rate limit is reached, the $errorCode should be 403.
        // And check if the call is unauthorized. The github api key is probably not valid anymore.
        // TODO: How do we handle both errors? If the file cannot be found the image is probably removed. Or that the assumption of the structure of the path we make the call with is wrong.
        if (isset($response) === false
            && $errorCode === 403
            || isset($response) === false
            && $errorCode === 401
        ) {
            if ($errorCode === 401) {
                $this->pluginLogger->warning('Cannot find the logo: '.$publiccodeArray['logo'].' because the call to the github api is unauthorized (status code: 401), the key is probabbly invalid. Return null.');

                // If the errorCode is 401 null is being returned.
                // TODO: Do we want to return null or return the given publiccode url?
                return null;
            }

            // The ratelimit is reached.
            if ($errorCode === 403) {
                $this->pluginLogger->warning('Cannot find the logo: '.$publiccodeArray['logo'].' because the rate limit of the github api source is reached (status code: 403). The logo that was given in the publiccode file is being returned.');

                // Return the given logo from the publiccode file.
                // The error is a 403 error, the server understands the request but refuses to authorize it, so the given logo url is valid.
                // The logo will be updated in a seperate action.
                return $publiccodeArray['logo'];
            }

            // TODO: The logo will only be updated again if the publiccode file is being changed. Trigger an action to update the url if something went wrong. This should be a seperate action.
        }

        // Check if the file cannot be found, the $errorCode should be 404.
        // TODO: If there is made an assumption with the structure of the path we do a call with, then the code must be adjusted. (This is only relevant for option 3, the github url)
        if (isset($response) === false
            && $errorCode === 404
        ) {
            // Set the warning log of the url logo.
            if ($type === 'url') {
                $this->pluginLogger->warning('Cannot find the logo: '.$publiccodeArray['logo'].'. The call on source: '. $source->getName(). ' with endpoint: '.$endpoint.' went wrong. Or a wrong logo url was given from the user, or the assumption with the structure of the path is made. If an assumption has been made, the code must be adjusted. If there is no assumption made and this log does\'t appear, the checks, comments and logs can be removed or updated. The logo that was given in the publiccode file is being returned.');
            }

            // Set the warning log of the relative path logo.
            // If the file cannot be found the image is probably removed or wrong given by the user.
            if ($type === 'relative') {
                $this->pluginLogger->warning('Cannot find the logo: '.$publiccodeArray['logo'].'. The relative path to the logo should start at the root of the github repository or check if the location of the logo is correct.');
            }

            // Return null because the given url or path is not valid.
            return null;
        }

        // If the url type is raw and the response is given and the status code is 200 the raw url is valid and can be returned.
        if ($type === 'raw'
            && isset($response) === true
            && $response->getStatusCode() === 200
        ) {
            $this->pluginLogger->info('Got a 200 response code from the call to the source with reference: '.$source->getReference().' to get the '.$type.' logo url. The given url is valid and is being returned.');

            // Return the given logo from the publiccode file, the url is validated.
            return $logoUrl;
        }

        // If the response is given decode the response from the github api.
        if (isset($response) === true) {
            $logoFile = $this->callService->decodeResponse($source, $response, 'application/json');

            // Check if the key download_url exist in the logoFile response, if so return the download_url.
            // If the reponse couldn't be decoded there is no download_url key. If the response has been decoded, the GitHub API endpoint: /repos/{owner}/{repo}/contents/{path} always returns the download_url key.
            if (key_exists('download_url', $logoFile) === true) {
                return $logoFile['download_url'];
            }

            // Return null if the logoFile response couldn't be decoded.
            return null;
        }

        // If the code comes here the logo is not found, so null can be returned.
        return null;
    }

    /**
     * This function handles a github url to where the logo is placed in a repository. (https://github.com/OpenCatalogi/web-app/blob/development/pwa/src/assets/images/5-lagen-visualisatie.png)
     * Option 3 of the handleLogo() function.
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param array $parsedLogo      The parsed logo that was given in the publiccode file. \Safe\parse_url($publiccodeArray['logo']);
     * @param string $repositoryName The fullname of the repository. /{owner}/{repository}
     *
     * @return string|null The updated logo with the download_url of the file contents with the path or null if not valid.
     */
    public function handleLogoFromGithub(array $publiccodeArray, Source $source, array $parsedLogo, string $repositoryName): ?string
    {
        // Explode the logo path with the repositoryName so the organization an repository name will be removed from the path.
        $explodedPath = explode($repositoryName, $parsedLogo['path'])[1];

        // The url of the logo from the github repository always has /blob/{branch} in the url.
        // TODO: Check if this is also the case. If not this will be logged. Delete this comment if the log and the check if the log never appears.

        // Check if /blob/ is not in the explodedPath. If so create a warning log.
        if (str_contains($explodedPath, '/blob/') === false) {
            $this->pluginLogger->warning('In this function we expect that a logo with host https://github.com always contains /blob/{branch} in the URL. We need to think about whether we want to support this URL or whether we want to include it in the documentation (if it is not already the case)', ['open-catalogi/open-catalogi-bundle']);
        }

        // Check if /blob/ is in the explodedPath.
        if (str_contains($explodedPath, '/blob/') === true) {
            // Explode the path with /.
            // The first three items in the array is always:
            // An empty string = 0, blob = 1 and the branch = 2. This has to be removed from the path.
            $explodedPath = explode('/', $explodedPath);

            // Loop till the total amount of the explodedPath array.
            $path = null;
            for ($i = 0; $i < count($explodedPath); $i++) {
                // If i is 0, 1, 2 then nothing is done.
                // The /blob/{branch} will not be added to the path.
                if ($i === 0
                    || $i === 1
                    || $i === 2
                ) {
                    continue;
                }

                // If i is not 0, 1, 2 then the $explodedPath item is set to the $path.
                $path .= '/'.$explodedPath[$i];
            }
        }

        // Set the type param to url so that the response is being decoded and the correct error log is created.
        return $this->getLogoFileContent($publiccodeArray, $source, '/repos'.$repositoryName.'/contents'.$path, 'url');
    }

    /**
     * This function handles a raw github url of the logo. (https://raw.githubusercontent.com/OpenCatalogi/OpenCatalogiBundle/main/docs/live.svg)
     * And handles the github avatar url. (https://avatars.githubusercontent.com/u/106860777?v=4)
     * Option 2 of the handleLogo() function.
     *
     * TODO: Also validate the url of option 1 of the handleLogo() function.
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source $source The given source. The github usercontent source or the gitub avatar source.
     * @param string $type The type of the url. The type can be raw or avatar.
     *
     * @return string|null The valid given logo from the publiccode file or null if not valid
     */
    public function handleRawLogo(array $publiccodeArray, Source $source, string $type): ?string
    {
        // Parse url to get the path from the given https://raw.githubusercontent.com url.
        $parsedRawLogo = \Safe\parse_url($publiccodeArray['logo']);

        // Check if there is not a path in the parsedRawLogo or if the parsedRawLogo path is null, then the given is not valid.
        if (key_exists('path', $parsedRawLogo) === false
            || $parsedRawLogo['path'] === null
        ) {
            // Return null so that the invalid logo isn't set.
            return null;
        }

        // Set the type param to raw so that the correct error log is created.
        return $this->getLogoFileContent($publiccodeArray, $source, $parsedRawLogo['path'], $type, $publiccodeArray['logo']);
    }

    /**
     * This function handles the logo.
     *
     * The logo can be given in multiple ways. (what we have seen)
     * 1. An url to the logo. Here we don't validate the avatar url. TODO: validate the given avatar url. (https://avatars.githubusercontent.com/u/106860777?v=4)
     * 2. A raw github url of the logo. (https://raw.githubusercontent.com/OpenCatalogi/OpenCatalogiBundle/main/docs/live.svg)
     * 3. A github url to where the logo is placed in a repository. (https://github.com/OpenCatalogi/OpenCatalogiBundle/main/docs/live.svg)
     * 4. A relative path. From the root of the repository to the image. (/docs/live.svg)
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $repository      The repository object.
     *
     * @return string|null The logo from the publiccode
     */
    public function handleLogo(array $publiccodeArray, Source $source, ObjectEntity $repository): ?string
    {
        // Parse url to get the path (organization and repository) from the repository url.
        // The repositoryName is used for option 2, 3 and 4.
        $repositoryName = \Safe\parse_url($repository->getValue('url'))['path'];

        // The logo can be given in multiple ways. (what we have seen). Check the function tekst for explanation about the types we handle.
        // Check if the logo is a valid url.
        if(filter_var($publiccodeArray['logo'], FILTER_VALIDATE_URL) !== false) {
            $this->pluginLogger->info('The logo is a valid url. Check whether the logo comes from source https://avatars.githubusercontent.com or whether the logo must be retrieved from the github api with the given logo URL.');

            // Parse url to get the host and path of the logo url.
            $parsedLogo = \Safe\parse_url($publiccodeArray['logo']);

            // There should always be a host because we checked if it is a valid url.
            $domain = $parsedLogo['host'];
            switch ($domain) {
                // Check if the logo is as option 1, a logo from https://avatars.githubusercontent.com.
                // Check if the domain is https://avatars.githubusercontent.com. If so we don't have to do anything and return the publiccodeArray logo.
                case 'avatars.githubusercontent.com':
                    // TODO: Validate the avatar url. Call the source with path and check is the status code is 200. The function handleRawLogo can be used for this.
                    $this->pluginLogger->info('The logo from the publiccode file is from https://avatars.githubusercontent.com. Do nothing and return the url.');

                    // Return the given avatar logo url.
                    return $publiccodeArray['logo'];
                    break;
                // Check if the logo is as option 2, a logo from https://raw.githubusercontent.com.
                // Check if the domain is https://raw.githubusercontent.com. If so, the user content source must be called with the path of the given logo URL as endpoint.
                case 'raw.githubusercontent.com':
                    // Get the usercontent source.
                    $usercontentSource = $this->resourceService->getSource($this->configuration['usercontentSource'], 'open-catalogi/open-catalogi-bundle');
                    // Check if the given source is not an instance of a Source return null and create a log.
                    if ($usercontentSource instanceof Source === false) {
                        $this->pluginLogger->error('The source with reference: '.$usercontentSource->getReference().' cannot be found.', ['open-catalogi/open-catalogi-bundle']);

                        // Cannot validate the raw usercontent url if the source cannot be found.
                        return null;
                    }

                    // Handle the logo if the logo is as option 2, the raw github link for the logo.
                    return $this->handleRawLogo($publiccodeArray, $usercontentSource, 'raw');
                    break;
                // Check if the domain is https://github.com, the key path exist in the parsed logo url and if the parsed logo url path is not null.
                // If so we need to get an url that the frontend can use.
                case 'github.com':
                    if (key_exists('path', $parsedLogo) === true
                        && $parsedLogo['path'] !== null
                    ) {
                        // Handle the logo if the logo is as option 3, the file fom github where the image can be found.
                        return $this->handleLogoFromGithub($publiccodeArray, $source, $parsedLogo, $repositoryName);
                    }
                    break;
                default:
                    $this->pluginLogger->warning('The domain: '.$domain.' is not valid. The logo url can be from https://avatars.githubusercontent.com, https://raw.githubusercontent.com and https://github.com. It can also be a relative path from the root of the repository from github can be given.', ['open-catalogi/open-catalogi-bundle']);
            }
        }

        // Check if the logo is not a valid url. The logo is as option 4 a relative path.
        // A relative path of the logo should start from the root of the repository from github.
        if(filter_var($publiccodeArray['logo'], FILTER_VALIDATE_URL) === false) {

            // Set the type param to relative so that the correct error log is created.
            return $this->getLogoFileContent($publiccodeArray, $source, '/repos'.$repositoryName.'/contents'.$publiccodeArray['logo'], 'relative');
        }

        // Got an other type of url. If the url comes here we need to check if we handle all the ways we want to validate.
        $this->pluginLogger->warning('the logo is checked in 4 different ways. The specified logo does not match the 4 ways. Check if we need to add an extra option.', ['open-catalogi/open-catalogi-bundle']);

        // Return null, because the given url is not from avatars.githubusercontent.com/raw.githubusercontent.com or github.com.
        // Or the given url isn't a valid relative url.
        return null;
    }


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

            $this->pluginLogger->info('The sha is the same as the sha from the component sync. The given sha (publiccode url from the github api)  is: '.$urlReference);

            return $repository;
        }

        // Map the publiccode file.
        $componentArray = $dataArray = $this->mappingService->mapping($publiccodeMapping, $publiccode);

        // Check if the logo property is set and is not null.
        if (key_exists('logo', $componentArray) === true
            && $componentArray['logo'] !== null
        ) {
            $this->pluginLogger->info($componentArray['logo'] . ' is being handled.', ['open-catalogi/open-catalogi-bundle']);

            $componentArray['logo'] = $this->handleLogo($componentArray, $source, $repository);
        }

        // Unset these values so we don't make duplicates.
        // The objects will be set in the handlePubliccodeSubObjects function.
        unset($componentArray['legal']['repoOwner']);
        unset($componentArray['legal']['mainCopyrightOwner']);
        unset($componentArray['applicationSuite']);

        // Find the sync with the source and publiccode url.
        $componentSync = $this->syncService->synchronize($componentSync, $componentArray, true);

        // Handle the sub objects of the array.
        $component = $this->handlePubliccodeSubObjects($dataArray, $source, $componentSync->getObject(), $publiccode);

        // Set the repository and publiccodeUrl to the component object.
        $component->hydrate(['url' => $repository, 'publiccodeUrl' => $publiccodeUrl]);
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
    public function getFileFromGithubApi(string $gitUrl): ?array
    {
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return null;
        }

        // Get the path from the git url to make the call.
        $endpoint = \Safe\parse_url($gitUrl)['path'];

        try {
            $response = $this->callService->call($source, $endpoint);
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
            ) {
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

        if (isset($dataArray) === false) {
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


    /**
     * This function searches for all repositories with a publiccode or one repository
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

        $source           = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
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

                if ($repositorySync->getObject() !== null) {
                    $repository = $repositorySync->getObject();
                }

                if ($repositorySync->getObject() === null) {
                    $this->entityManager->remove($repositorySync);
                    $this->entityManager->flush();
                    $repository = $this->getGithubRepository($repositoryArray['repository']['html_url'], $repositoryArray['repository']);
                }

                if ($repository !== null) {
                    $response[] = $repository->toArray();
                }
            }//end foreach

            if (isset($response) === true) {
                $this->data['response'] = new Response(json_encode($response), 200, ['Content-Type' => 'application/json']);
            }
        }//end if

        return $this->data;

    }//end findGithubRepositories()


}//end class
