<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubEventService;

/**
 * ...
 */
class GithubEventHandler implements ActionHandlerInterface
{
    private GithubEventService $githubEventService;

    public function __construct(GithubEventService $githubEventService)
    {
        $this->githubEventService = $githubEventService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://opencatalogi.nl/oc.githubEvent.action.json',
            '$schema'    => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'      => 'GithubEventHandler',
            'description'=> 'This handler gets the github event and creates or updates the repository',
            'required'   => ['repositoryEntityId'],
            'properties' => [
                'repositoryEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the repository entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.repository.schema.json',
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->githubEventService->updateRepositoryWithEventResponse($data, $configuration);
    }
}
