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

class PubliccodeService
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
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param CallService            $callService     The Call Service
     * @param SynchronizationService $syncService     The Synchronisation Service
     * @param MappingService         $mappingService  The Mapping Service
     * @param RatingService          $ratingService   The Rating Service.
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
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
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->syncService     = $syncService;
        $this->mappingService  = $mappingService;
        $this->ratingService   = $ratingService;
        $this->pluginLogger    = $pluginLogger;
        $this->resourceService = $resourceService;

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
     * This function sets the contractors or contacts to the maintenance object and sets the maintenance to the component.
     *
     * @param ObjectEntity $component
     * @param array        $itemArray The contacts array or the contractor array.
     * @param string       $valueName The value that needs to be updated and set to the maintenance object.
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
            $maintenance       = new ObjectEntity($maintenanceSchema);
        }

        // Set the given value with the given array to the maintenance object.
        $maintenance->setValue($valueName, $itemArray);
        $this->entityManager->persist($maintenance);

        // Set the updated maintenance object to the component.
        $component->hydrate(['maintenance' => $maintenance]);
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        return $component;

    }//end handleMaintenaceObjects()


    /**
     * This function handles the contractor object and sets it to the component
     *
     * @param Source       $source     The github api source.
     * @param ObjectEntity $component  The component object.
     * @param array        $publiccode The publiccode file from the github api as array.
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
                // $contractorSchema = $this->resourceService->getSchema($this->configuration['contractorSchema'], 'open-catalogi/open-catalogi-bundle');
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
            }//end if
        }//end foreach

        // Add the contractors to the maintenance object and the maintenance to the component.
        return $this->handleMaintenaceObjects($component, $contractors, 'contractors');

    }//end handleContractors()


    /**
     * This function handles the contacts object and sets it to the component
     *
     * @param Source       $source     The github api source.
     * @param ObjectEntity $component  The component object.
     * @param array        $publiccode The publiccode file from the github api as array.
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
                // $contactSchema = $this->resourceService->getSchema($this->configuration['contactSchema'], 'open-catalogi/open-catalogi-bundle');
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
            }//end if
        }//end foreach

        // Add the contacts to the maintenance object and the maintenance to the component.
        return $this->handleMaintenaceObjects($component, $contacts, 'contacts');

    }//end handleContacts()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $component       The component object.
     * @param array        $publiccode      The publiccode file from the github api as array.
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
     * @param array       $publiccodeArray The mapped publiccode array from the github api.
     * @param Source      $source          The github api source or usercontent source.
     * @param string      $endpoint        The endpoint of the call that should be made. For the github api source is the endpoint format: /repos/{owner}/{repo}/contents/{path}. For the usercontent source is the endpoint format: the parsed logo url path.
     * @param string      $type            The type of the logo that is trying to be retrieved from the given source. (url = A github url / raw = a raw github url / relative = a relative path).
     * @param string|null $logoUrl         The given logo url from the publiccode file, only needed when the type is raw.
     *
     * @return string|null With type raw the logo from the publiccode file if valid, if not null is returned. With type url and relative the download_url from the reponse of the call.
     */
    public function getLogoFileContent(array $publiccodeArray, Source $source, string $endpoint, string $type, ?string $logoUrl=null, ?array $queryParam=[]): ?string
    {
        // The logo is as option 2, 3 or 4. Do a call via the callService to check if the logo can be found.
        // If the type is url or relative the endpoint is in the format: /repos/{owner}/{repo}/contents/{path} is given.
        // If the type is raw the endpoint the parsed url path: \Safe\parse_url($publiccodeArray['logo'])
        $errorCode = null;
        try {
            $response = $this->callService->call($source, $endpoint, 'GET', $queryParam);
        } catch (Exception $exception) {
            // Set the error code so there can be checked if the file cannot be found or that the rate limit is reached.
            $errorCode = $exception->getCode();

            // Create an error log for all the types (url, raw, avatar and relative).
            $this->pluginLogger->error('The logo with url: '.$publiccodeArray['logo'].' cannot be found from the source with reference: '.$source->getReference().' with endpoint: '.$endpoint);
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
        }//end if

        // Check if the file cannot be found, the $errorCode should be 404.
        // TODO: If there is made an assumption with the structure of the path we do a call with, then the code must be adjusted. (This is only relevant for option 3, the github url)
        if (isset($response) === false
            && $errorCode === 404
        ) {
            // Set the warning log of the url logo.
            if ($type === 'url') {
                $this->pluginLogger->warning('Cannot find the logo: '.$publiccodeArray['logo'].'. The call on source: '.$source->getName().' with endpoint: '.$endpoint.' went wrong. Or a wrong logo url was given from the user, or the assumption with the structure of the path is made. If an assumption has been made, the code must be adjusted. If there is no assumption made and this log does\'t appear, the checks, comments and logs can be removed or updated. The logo that was given in the publiccode file is being returned.');
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

            // GITHUB: Check if the key download_url exist in the logoFile response, if so return the download_url.
            // If the reponse couldn't be decoded there is no download_url key. If the response has been decoded, the GitHub API endpoint: /repos/{owner}/{repo}/contents/{path} always returns the download_url key.
            if (key_exists('download_url', $logoFile) === true) {
                return $logoFile['download_url'];
            }

            // GITLAB: Check if content is set, if so decode the content.
            if (key_exists('content', $logoFile) === true
                && key_exists('blob_id', $logoFile) === true
            ) {
                // Return the blob_id so that the raw url can be returned. /projects/:id/repository/blobs/:sha/raw
                return $logoFile['blob_id'];
            }

            // Return null if the logoFile response couldn't be decoded.
            return null;
        }//end if

        // If the code comes here the logo is not found, so null can be returned.
        return null;

    }//end getLogoFileContent()


    /**
     * This function handles a github url to where the logo is placed in a repository. (https://github.com/OpenCatalogi/web-app/blob/development/pwa/src/assets/images/5-lagen-visualisatie.png)
     * Option 3 of the handleLogo() function.
     *
     * @param array  $publiccodeArray The mapped publiccode array from the github api.
     * @param Source $source          The github api source.
     * @param array  $parsedLogo      The parsed logo that was given in the publiccode file. \Safe\parse_url($publiccodeArray['logo']);
     * @param string $repositoryName  The fullname of the repository. /{owner}/{repository}
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
        }//end if

        // Set the type param to url so that the response is being decoded and the correct error log is created.
        return $this->getLogoFileContent($publiccodeArray, $source, '/repos'.$repositoryName.'/contents'.$path, 'url');

    }//end handleLogoFromGithub()


    /**
     * This function handles a gitlab url to where the logo is placed in a repository. (https://gitlab.com/discipl/RON/regels.overheid.nl/-/blob/master/images/WORK_PACKAGE_ISSUE.png)
     * or the upload gitlab url (https://gitlab.com/uploads/-/system/project/avatar/33855802/760205.png)
     * Option 2 and 3 of the handleLogo() function.
     *
     * @param array  $publiccodeArray The mapped publiccode array from the github api.
     * @param Source $source          The github api source.
     * @param array  $parsedLogo      The parsed logo that was given in the publiccode file. \Safe\parse_url($publiccodeArray['logo']);
     * @param string $repositoryName  The fullname of the repository. /{owner}/{repository}
     *
     * @return string|null The updated logo with the download_url of the file contents with the path or null if not valid.
     */
    public function handleLogoFromGitlab(array $componentArray, Source $source, array $parsedLogo, string $repositoryName, string $repositoryId): ?string
    {
        // The url of the logo from the github repository always has /blob/{branch} or /uploads in the url.
        // TODO: Check if this is also the case. If not this will be logged. Delete this comment if the log and the check if the log never appears.
        $this->pluginLogger->warning('In this function we expect that a logo with host https://github.com always contains /blob/{branch} or /uploads in the URL. We need to think about whether we want to support this URL or whether we want to include it in the documentation (if it is not already the case)', ['open-catalogi/open-catalogi-bundle']);

        // Check if /blob/ is not in the explodedPath. If so create a warning log.
        if (str_contains($parsedLogo['path'], '/blob/') === false
            && str_contains($parsedLogo['path'], '/uploads/') === true
        ) {
            // TODO: validate the /uploads url.
            $this->pluginLogger->warning('TODO: The /uploads url isn\'t validated: '.$componentArray['logo'], ['open-catalogi/open-catalogi-bundle']);
            return $componentArray['logo'];
        }

        // Check if /blob/ is in the explodedPath.
        if (str_contains($parsedLogo['path'], '/uploads/') === false
            && str_contains($parsedLogo['path'], '/blob/') === true
        ) {
            $this->pluginLogger->warning('In this function we expect that a logo with host https://gitlab.com always contains /blob/{branch} in the URL.', ['open-catalogi/open-catalogi-bundle']);

            // Explode the logo path with the repositoryName so the organization an repository name will be removed from the path.
            $explodedPath = explode($repositoryName, $parsedLogo['path'])[1];

            // Explode the path with /.
            // The first three items in the array is always:
            // An empty string = 0, - = 1, blob = 2 and the branch = 3. This has to be removed from the path.
            $explodedPath = explode('/', $explodedPath);

            // Loop till the total amount of the explodedPath array.
            $path = null;
            // Set the branch default to main.
            $branch = 'main';
            for ($i = 0; $i < count($explodedPath); $i++) {
                // If i is 0, 1, 2, 3 then nothing is done.
                // The /-/blob/{branch} will not be added to the path.
                if ($i === 0
                    || $i === 1
                    || $i === 2
                ) {
                    continue;
                }

                // The {branch} will is needed for the ref query.
                if ($i === 3) {
                    $branch = $explodedPath[$i];
                    continue;
                }

                // If i is not 0, 1, 2, 3 then the $explodedPath item is set to the $path.
                $path .= '/'.$explodedPath[$i];
            }

            $path = trim($path, '/');

            // Set the query ref with the brach.
            $queryParam['query'] = ['ref' => $branch];

            // Set the type param to url so that the response is being decoded and the correct error log is created.
            $blobId = $this->getLogoFileContent($componentArray, $source, '/api/v4/projects/'.$repositoryId.'/repository/files/'.urlencode($path), 'url', $componentArray['logo'], $queryParam);

            // Return the raw url: /projects/:id/repository/blobs/:sha/raw
            return $parsedLogo['scheme'].'://'.$parsedLogo['host'].'/api/v4/projects/'.$repositoryId.'/repository/blobs/'.$blobId.'/raw';
        }//end if

        $this->pluginLogger->warning('In this function we expect that a logo with host https://github.com always contains /blob/{branch} or /uploads in the URL. The logo url we got doesn\'t: '.$componentArray['logo'], ['open-catalogi/open-catalogi-bundle']);

        return null;

    }//end handleLogoFromGitlab()


    /**
     * This function handles a raw github url of the logo. (https://raw.githubusercontent.com/OpenCatalogi/OpenCatalogiBundle/main/docs/live.svg)
     * And handles the github avatar url. (https://avatars.githubusercontent.com/u/106860777?v=4)
     * Option 2 of the handleLogo() function.
     *
     * TODO: Also validate the url of option 1 of the handleLogo() function.
     *
     * @param array  $publiccodeArray The mapped publiccode array from the github api.
     * @param Source $source          The given source. The github usercontent source or the gitub avatar source.
     * @param string $type            The type of the url. The type can be raw or avatar.
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

    }//end handleRawLogo()


    /**
     * This function handles the logo.
     *
     * GITHUB: The logo can be given in multiple ways for github. (what we have seen)
     * 1. An url to the logo. Here we don't validate the avatar url. TODO: validate the given avatar url. (https://avatars.githubusercontent.com/u/106860777?v=4)
     * 2. A raw github url of the logo. (https://raw.githubusercontent.com/OpenCatalogi/OpenCatalogiBundle/main/docs/live.svg)
     * 3. A github url to where the logo is placed in a repository. (https://github.com/OpenCatalogi/OpenCatalogiBundle/main/docs/live.svg)
     * 4. A relative path. From the root of the repository to the image. (/docs/live.svg)
     *
     * GITLAB: The logo can be given in multiple ways for gitlab. (what we have seen)
     * 5. An url to the logo. Here we don't validate the gravatar url. TODO: validate the given gravatar url. (https://www.gravatar.com/avatar/e64c7d89f26bd1972efa854d13d7dd61)
     * 6. A upload gitlab url of the logo. (https://gitlab.com/uploads/-/system/project/avatar/33855802/760205.png)
     * 7. A github url to where the logo is placed in a repository. (https://gitlab.com/discipl/RON/regels.overheid.nl/-/blob/master/images/WORK_PACKAGE_ISSUE.png)
     * 8. A relative path. From the root of the repository to the image. (/images/WORK_PACKAGE_ISSUE.png)
     *
     * @param array        $publiccodeArray The mapped publiccode array from the github api.
     * @param Source       $source          The github api source.
     * @param ObjectEntity $url             The url of the repository or organization object.
     *
     * @return string|null The logo from the publiccode
     */
    public function handleLogo(array $componentArray, Source $source, string $url, string $repositoryId): ?string
    {
        // Parse url to get the path (organization and repository) from the repository url.
        // The repositoryName is used for option 2, 3 and 4.
        $repositoryName = \Safe\parse_url($url)['path'];

        // The logo can be given in multiple ways. (what we have seen). Check the function tekst for explanation about the types we handle.
        // Check if the logo is a valid url.
        if (filter_var($componentArray['logo'], FILTER_VALIDATE_URL) !== false) {
            $this->pluginLogger->info('The logo is a valid url. Check whether the logo comes from source https://avatars.githubusercontent.com, https://www.gravatar.com or whether the logo must be retrieved from the github or gitlab api with the given logo URL.');

            // Parse url to get the host and path of the logo url.
            $parsedLogo = \Safe\parse_url($componentArray['logo']);

            // There should always be a host because we checked if it is a valid url.
            $domain = $parsedLogo['host'];
            switch ($domain) {
                // Check if the logo is as option 1, a logo from https://avatars.githubusercontent.com.
                // Check if the domain is https://avatars.githubusercontent.com. If so we don't have to do anything and return the publiccodeArray logo.
            case 'avatars.githubusercontent.com':
                // TODO: Validate the avatar url. Call the source with path and check is the status code is 200. The function handleRawLogo can be used for this.
                $this->pluginLogger->info('The logo from the publiccode file is from https://avatars.githubusercontent.com. Do nothing and return the url.');

                // Return the given avatar logo url.
                return $componentArray['logo'];
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
                return $this->handleRawLogo($componentArray, $usercontentSource, 'raw');
                    break;
                // Check if the domain is https://github.com, the key path exist in the parsed logo url and if the parsed logo url path is not null.
                // If so we need to get an url that the frontend can use.
            case 'github.com':
                if (key_exists('path', $parsedLogo) === true
                    && $parsedLogo['path'] !== null
                ) {
                    // Handle the logo if the logo is as option 3, the file fom github where the image can be found.
                    return $this->handleLogoFromGithub($componentArray, $source, $parsedLogo, $repositoryName);
                }
                break;
                // Check if the logo is as option 5, a logo from https://www.gravatar.com.
                // Check if the domain is https://www.gravatar.com. If so we don't have to do anything and return the publiccodeArray logo.
            case 'www.gravatar.com':
                // TODO: Validate the gravatar url. Call the source with path and check is the status code is 200. The function handleRawLogo can be used for this.
                $this->pluginLogger->info('The logo from the publiccode file is from https://www.gravatar.com. Do nothing and return the url.');

                // Return the given gravatar logo url.
                return $componentArray['logo'];
                    break;
                // Check if the domain is https://gitlab.com, the key path exist in the parsed logo url and if the parsed logo url path is not null.
                // If so we need to get an url that the frontend can use.
            case 'gitlab.com':
                if (key_exists('path', $parsedLogo) === true
                    && $parsedLogo['path'] !== null
                ) {
                    // For option 6 and 7:
                    // Handle the logo if the logo is as option 6 or 7, the file fom gitlab where the image can be found.
                    return $this->handleLogoFromGitlab($componentArray, $source, $parsedLogo, $repositoryName, $repositoryId);
                }
                break;
            default:
                $this->pluginLogger->warning('The domain: '.$domain.' is not valid. The logo url can be from https://avatars.githubusercontent.com, https://raw.githubusercontent.com and https://github.com. It can also be a relative path from the root of the repository from github can be given.', ['open-catalogi/open-catalogi-bundle']);
            }//end switch
        }//end if

        // Check if the logo is not a valid url. The logo is as option 4 a relative path.
        // A relative path of the logo should start from the root of the repository from github.
        if (filter_var($componentArray['logo'], FILTER_VALIDATE_URL) === false) {
            // GITLAB: option 8, uploads path from the root of the repository.
            if (str_contains($componentArray['logo'], '/uploads/') === true) {
                $this->pluginLogger->info('The logo is a gitlab uploads logo: '.$componentArray['logo'], ['open-catalogi/open-catalogi-bundle']);
                // TODO: validate the /uploads url.
                $this->pluginLogger->warning('TODO: The /uploads url isn\'t validated: '.$componentArray['logo'], ['open-catalogi/open-catalogi-bundle']);

                return 'https://gitlab.com'.$componentArray['logo'];
            }

            // TODO: get the logo file content from gitlab.
            $this->pluginLogger->warning('TODO: The relative path isn\'t implemented for gitlab: '.$componentArray['logo'], ['open-catalogi/open-catalogi-bundle']);

            // GITHUB: Set the type param to relative so that the correct error log is created.
            return $this->getLogoFileContent($componentArray, $source, '/repos'.$repositoryName.'/contents'.$componentArray['logo'], 'relative');
        }

        // Got an other type of url. If the url comes here we need to check if we handle all the ways we want to validate.
        $this->pluginLogger->warning('the logo is checked in 4 different ways. The specified logo does not match the 4 ways. Check if we need to add an extra option.', ['open-catalogi/open-catalogi-bundle']);

        // Return null, because the given url is not from avatars.githubusercontent.com/raw.githubusercontent.com or github.com.
        // Or the given url isn't a valid relative url.
        return null;

    }//end handleLogo()


    /**
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $publiccodeArray The publiccode array from the github api.
     * @param Source       $source          The github or gitlab api source.
     * @param ObjectEntity $repository      The repository object.
     *
     * @return ObjectEntity|null
     */
    public function handlePubliccodeFile(array $publiccodeArray, Source $source, ObjectEntity $repository, array $publiccode, string $sourceId, ?array $repositoryArray=[]): ?ObjectEntity
    {
        $publiccodeMapping = $this->resourceService->getMapping($this->configuration['publiccodeMapping'], 'open-catalogi/open-catalogi-bundle');
        $componentSchema   = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($publiccodeMapping instanceof Mapping === false
            || $componentSchema instanceof Entity === false
        ) {
            return $repository;
        }

        $this->pluginLogger->info('Map the publiccode file with path: '.$publiccodeArray['path'].' and source id: '.$sourceId);

        if ($publiccode !== null
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
        // if ($this->syncService->doesShaMatch($componentSync, $urlReference) === true) {
        // $componentSync->getObject()->hydrate(['url' => $repository]);
        //
        // $this->entityManager->persist($componentSync->getObject());
        // $this->entityManager->flush();
        //
        // $this->pluginLogger->info('The sha is the same as the sha from the component sync. The given sha (publiccode url from the github api)  is: '.$urlReference);
        //
        // return $repository;
        // }
        // Map the publiccode file.
        $componentArray = $dataArray = $this->mappingService->mapping($publiccodeMapping, $publiccode);

        // $componentArray['logo'] = 'https://www.gravatar.com/avatar/e64c7d89f26bd1972efa854d13d7dd61';
        // $componentArray['logo'] = 'https://gitlab.com/uploads/-/system/project/avatar/33855802/760205.png';
        // $componentArray['logo'] = 'https://gitlab.com/discipl/RON/regels.overheid.nl/-/blob/master/images/WORK_PACKAGE_ISSUE.png';
        // Check if the logo property is set and is not null.
        if (key_exists('logo', $componentArray) === true
            && $componentArray['logo'] !== null
        ) {
            $this->pluginLogger->info($componentArray['logo'].' is being handled.', ['open-catalogi/open-catalogi-bundle']);

            $componentArray['logo'] = $this->handleLogo($componentArray, $source, $repository->getValue('url'), $repositoryArray['id']);
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
        $component->hydrate(['url' => $repository, 'publiccodeUrl' => $sourceId]);
        $this->entityManager->persist($componentSync->getObject());
        $this->entityManager->flush();

        return $repository;

    }//end handlePubliccodeFile()


}//end class
