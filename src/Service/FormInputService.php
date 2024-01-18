<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Exception\GatewayException;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\ComponentException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * This class handles the form input.
 *
 * This service handles the incoming form input and creates a repository.
 *
 * @Author Sarai Misidjan <sarai@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class FormInputService
{

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GitlabApiService
     */
    private GitlabApiService $gitlabApiService;

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
     * @param GithubApiService $githubApiService The Github Api Service.
     * @param GitlabApiService $gitlabApiService The Gitlab Api Service.
     * @param LoggerInterface  $pluginLogger     The plugin version of the logger interface.
     */
    public function __construct(
        GithubApiService $githubApiService,
        GitlabApiService $gitlabApiService,
        LoggerInterface $pluginLogger
    ) {
        $this->githubApiService = $githubApiService;
        $this->gitlabApiService = $gitlabApiService;
        $this->pluginLogger     = $pluginLogger;
        $this->configuration    = [];
        $this->data             = [];

    }//end __construct()


    /**
     * This function creates/updates the repository with the github event response.
     *
     * @param ?array $data          Data set at the start of the handler.
     * @param ?array $configuration Configuration of the action.
     *
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError|\Exception
     *
     * @return array|null The data with the repository in the response array.
     */
    public function updateRepositoryWithFormInput(?array $data=[], ?array $configuration=[]): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        $formInput = $this->data['body'];

        if (key_exists('repository', $formInput) === false
            || key_exists('html_url', $formInput['repository']) === false
        ) {
            $this->pluginLogger->error('The repository html_url is not given.');
        }

        $parsedUrl = \Safe\parse_url($formInput['repository']['html_url']);
        if (key_exists('host', $parsedUrl) === false) {
            return null;
        }

        $domain = $parsedUrl['host'];
        switch ($domain) {
        case 'github.com':
            $this->githubApiService->setConfiguration($this->configuration);
            $repository = $this->githubApiService->getGithubRepository($formInput['repository']['html_url']);
            break;
        case 'gitlab.com':
            $this->gitlabApiService->setConfiguration($this->configuration);
            $repository = $this->gitlabApiService->getGitlabRepository($formInput['repository']['html_url']);
            break;
        default:
            break;
        }

        if (isset($repository) === false
            || $repository === null
        ) {
            $this->data['response'] = new Response('Repository is not created. See logs for more detail.', 404, ['Content-Type' => 'application/json']);

            return $this->data;
        }

        $this->data['response'] = new Response(json_encode($repository->getValue('organisation')->toArray()), 200, ['Content-Type' => 'application/json']);

        return $this->data;

    }//end updateRepositoryWithFormInput()


}//end class
