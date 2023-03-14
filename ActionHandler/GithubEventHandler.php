<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubEventService;

/**
 * ...
 */
class GithubEventHandler implements ActionHandlerInterface
{
    /**
     * @var GithubEventService
     */
    private GithubEventService $service;

    /**
     * @param GithubEventService $service The $githubEventService
     */
    public function __construct(GithubEventService $service)
    {
        $this->service = $service;
    }//end __construct()

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration()
    {
        return [
            '$id'        => 'https://opencatalogi.nl/oc.githubEvent.action.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
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
    }//end getConfiguration()

    /**
     * This function runs the email service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->updateRepositoryWithEventResponse($data, $configuration);
    }//end run()
}//end class
