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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/GithubEventHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'GithubEventHandler',
            'description' => 'This handler gets the github event and creates or updates the repository',
            'required'    => [
                'githubSource',
                'repositorySchema',
                'repositoryMapping',
                'organisationSchema',
                'organisationMapping',
            ],
            'properties'  => [
                'githubSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'repositorySchema'    => [
                    'type'        => 'string',
                    'description' => 'The repository schema.',
                    'example'     => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'required'    => true,
                ],
                'repositoryMapping'   => [
                    'type'        => 'string',
                    'description' => 'The mapping for github repository to oc repository.',
                    'example'     => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'required'    => true,
                ],
                'organisationSchema'  => [
                    'type'        => 'string',
                    'description' => 'The organisation schema.',
                    'example'     => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'required'    => true,
                ],
                'organisationMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github organisation to oc organisation.',
                    'example'     => 'https://api.github.com/oc.githubOrganisation.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOrganisation.mapping.json',
                    'required'    => true,
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
