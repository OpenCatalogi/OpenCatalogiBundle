<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use CommonGateway\CoreBundle\Service\GatewayResourceService;

class RatingListService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager.
     * @param GithubApiService       $githubApiService The github Api Service.
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the loger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
        $this->resourceService = $resourceService;
        $this->pluginLogger = $pluginLogger;
        $this->configuration = [];
        $this->data = [];
    }//end __construct()
    
    /**
     * Rates the name of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateName(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('name') !== null) {
            $ratingArray['results'][] = 'The name: '.$component->getValue('name').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the name because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateName()

    /**
     * Rates the url of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateUrl(ObjectEntity $component, array $ratingArray): array
    {
        if ($repository = $component->getValue('url')) {
            $url = $repository->getValue('url');
            if (!empty($url)) {
                $ratingArray['results'][] = 'The url: '.$url.' rated';
                $ratingArray['rating']++;

                $domain = \Safe\parse_url($url, PHP_URL_HOST);
                if ($domain !== 'github.com') {
                    $ratingArray['results'][] = 'Cannot rate the repository because it is not a valid github repository';
                } elseif ($this->githubApiService->checkPublicRepository($url)) {
                    $ratingArray['results'][] = 'Rated the repository because it is public';
                    $ratingArray['rating']++;
                } else {
                    $ratingArray['results'][] = 'Cannot rate the repository because it is private (or url is invalid)';
                }

                $ratingArray['maxRating']++;
            } elseif ($url === null) {
                $ratingArray['results'][] = 'Cannot rate the url because it is not set';
            } else {
                $ratingArray['results'][] = 'Cannot rate the repository because url is empty';
            }

            $ratingArray['maxRating']++;
        }//end if

        $ratingArray['maxRating'] = $ratingArray['maxRating'] + 2;

        return $ratingArray;
    }//end rateUrl()

    /**
     * Rates the landing url of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLandingUrl(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('landingURL') !== null) {
            $ratingArray['results'][] = 'The landingURL: '.$component->getValue('landingURL').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the landingURL because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateLandingUrl()

    /**
     * Rates the software version of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateSoftwareVersion(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('softwareVersion') !== null) {
            $ratingArray['results'][] = 'The softwareVersion: '.$component->getValue('softwareVersion').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the softwareVersion because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateSoftwareVersion()

    /**
     * Rates the release date of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateReleaseDate(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('releaseDate') !== null) {
            $ratingArray['results'][] = 'The releaseDate: '.$component->getValue('releaseDate').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the releaseDate because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateReleaseDate()

    /**
     * Rates the logo of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLogo(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('logo') !== null) {
            $ratingArray['results'][] = 'The logo: '.$component->getValue('logo').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the logo because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateLogo()

    /**
     * Rates the roadmap of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateRoadmap(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('roadmap') !== null) {
            $ratingArray['results'][] = 'The roadmap: '.$component->getValue('roadmap').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the roadmap because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateRoadmap()

    /**
     * Rates the development status of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateDevelopmentStatus(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('developmentStatus') !== null) {
            $ratingArray['results'][] = 'The developmentStatus: '.$component->getValue('developmentStatus').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the developmentStatus because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateDevelopmentStatus()

    /**
     * Rates the software type of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateSoftwareType(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('softwareType') !== null) {
            $ratingArray['results'][] = 'The softwareType: '.$component->getValue('softwareType').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the softwareType because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateSoftwareType()

    /**
     * Rates the platforms of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function ratePlatforms(ObjectEntity $component, array $ratingArray): array
    {
        if (count($component->getValue('platforms')) > 0) {
            $ratingArray['results'][] = 'The platforms are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the platforms because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end ratePlatforms()

    /**
     * Rates the categories of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateCategories(ObjectEntity $component, array $ratingArray): array
    {
        if (count($component->getValue('categories')) > 0) {
            $ratingArray['results'][] = 'The categories are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the categories because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateCategories()

    /**
     * Rates the localised name of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLocalisedName(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('localisedName') !== null) {
            $ratingArray['results'][] = 'The localisedName: '.$descriptionObject->getValue('localisedName').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the localisedName because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateLocalisedName()

    /**
     * Rates the short description of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateShortDescription(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('shortDescription') !== null) {
            $ratingArray['results'][] = 'The shortDescription: '.$descriptionObject->getValue('shortDescription').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the shortDescription because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateShortDescription()

    /**
     * Rates the long description of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLongDescription(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('longDescription') !== null) {
            $ratingArray['results'][] = 'The longDescription: '.$descriptionObject->getValue('longDescription').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the longDescription because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateLongDescription()

    /**
     * Rates the api documentation of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateApiDocumentation(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('apiDocumentation') !== null) {
            $ratingArray['results'][] = 'The apiDocumentation: '.$descriptionObject->getValue('apiDocumentation').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the apiDocumentation because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateApiDocumentation()

    /**
     * Rates the features of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateFeatures(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if (count($descriptionObject->getValue('features')) > 0) {
            $ratingArray['results'][] = 'The features are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the features because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateFeatures()

    /**
     * Rates the screenshots of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateScreenshots(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if (count($descriptionObject->getValue('screenshots')) > 0) {
            $ratingArray['results'][] = 'The screenshots are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the screenshots because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateScreenshots()

    /**
     * Rates the screenshots of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateVideos(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if (count($descriptionObject->getValue('videos')) > 0) {
            $ratingArray['results'][] = 'The videos are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the videos because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateVideos()

    /**
     * Rates the license of the legal object.
     *
     * @param ObjectEntity $legalObject The legal object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLicense(ObjectEntity $legalObject, array $ratingArray): array
    {
        if ($legalObject->getValue('license') !== null) {
            $ratingArray['results'][] = 'The license: '.$legalObject->getValue('license').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the license because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateLicense()

    /**
     * Rates the copy right owner.
     *
     * @param ObjectEntity $mainCopyrightOwnerObject The main copyright owner to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateCopyOwner(ObjectEntity $mainCopyrightOwnerObject, array $ratingArray): array
    {
        if ($mainCopyrightOwnerObject->getValue('mainCopyrightOwner') !== null) {
            $ratingArray['results'][] = 'The mainCopyrightOwner: '.$mainCopyrightOwnerObject->getValue('name').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the mainCopyrightOwner because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateCopyOwner()

    /**
     * Rates the copy right owner.
     *
     * @param ObjectEntity $mainCopyrightOwnerObject The main copyright owner to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateRepoOwner(ObjectEntity $mainCopyrightOwnerObject, array $ratingArray): array
    {
        if ($repoOwnerObject->getValue('repoOwner') !== null) {
            $ratingArray['results'][] = 'The repoOwner is rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the repoOwner because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateRepoOwner()

    /**
     * Rates the authors file of the legal object.
     *
     * @param ObjectEntity $legalObject The legal object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateAuthorsFile(ObjectEntity $legalObject, array $ratingArray): array
    {
        if ($legalObject->getValue('authorsFile') !== null) {
            $ratingArray['results'][] = 'The authorsFile: '.$legalObject->getValue('authorsFile').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the authorsFile because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateAuthorsFile()

    /**
     * Rates the type of the maintenace object.
     *
     * @param ObjectEntity $maintenanceObject The maintenance object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateType(ObjectEntity $maintenanceObject, array $ratingArray): array
    {
        if ($maintenanceObject->getValue('type') !== null) {
            $ratingArray['results'][] = 'The type: '.$maintenanceObject->getValue('type').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the type because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateType()

    /**
     * Rates the type of the maintenace object.
     *
     * @param ObjectEntity $maintenanceObject The maintenance object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateContractors(ObjectEntity $maintenanceObject, array $ratingArray): array
    {
        if (count($maintenanceObject->getValue('contractors')) > 0) {
            $ratingArray['results'][] = 'The contractors are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the contractors because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateContractors()

    /**
     * Rates the type of the maintenace object.
     *
     * @param ObjectEntity $maintenanceObject The maintenance object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateContacts(ObjectEntity $maintenanceObject, array $ratingArray): array
    {
        if (count($maintenanceObject->getValue('contacts')) > 0) {
            $ratingArray['results'][] = 'The contacts are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the contacts because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }//end rateContacts()
    
}//end class
