<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  OpenCatalogi\OpenCatalogiBundle\Service\FindGitRepoThroughOrganizationService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

class FindGitRepoThroughOrganizationHandler implements ActionHandlerInterface
{
    private FindGitRepoThroughOrganizationService $findGitRepoThroughOrganizationService;

    public function __construct(FindGitRepoThroughOrganizationService $findGitRepoThroughOrganizationService)
    {
        $this->findGitRepoThroughOrganizationService = $findGitRepoThroughOrganizationService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'PubliccodeFindGithubRepositoryThroughOrganizationHandler',
            'description'=> 'This handler finds the .github repository through organizations',
            'required'   => ['source', 'organisationEntityId'],
            'properties' => [
                'source' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the Github API source',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    'name'        => 'GitHub API'  
                ],
                'organisationEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the organisation entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.organisation.schema.json'
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->findGitRepoThroughOrganizationService->findGitRepoThroughOrganizationHandler($data, $configuration);
    }
}
