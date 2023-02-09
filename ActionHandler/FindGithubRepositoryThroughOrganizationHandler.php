<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;

/**
 * Executes a the FindGithubRepositoryThroughOrganizationService that loops through organizations (https://opencatalogi.nl/oc.organisation.schema.json)
 * and tries to find a opencatalogi.yaml on github with its organization name to update the organization object with that fetched opencatalogi.yaml data.
 */
class FindGithubRepositoryThroughOrganizationHandler implements ActionHandlerInterface
{
    /**
     * @var FindGithubRepositoryThroughOrganizationService
     */
    private FindGithubRepositoryThroughOrganizationService $githubThroughOrgService;

    /**
     * @param FindGithubRepositoryThroughOrganizationService $githubThroughOrgService FindGithubRepositoryThroughOrganizationService
     */
    public function __construct(FindGithubRepositoryThroughOrganizationService $githubThroughOrgService)
    {
        $this->githubThroughOrgService = $githubThroughOrgService;
    }//end __construct()

    /**
     * This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array
     */
    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'FindGithubRepositoryThroughOrganizationHandler',
            'description'=> 'This handler finds the .github repository through organizations',
            'required'   => ['source', 'organisationEntityId'],
            'properties' => [
                'source' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the Github API source',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    'name'        => 'GitHub API',
                ],
                'organisationEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the organisation entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.organisation.schema.json',
                ],
            ],
        ];
    }//end getConfiguration()

    /**
     * This function runs the application to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->githubThroughOrgService->findGithubRepositoryThroughOrganizationHandler($data, $configuration);
    }//end run()
}
