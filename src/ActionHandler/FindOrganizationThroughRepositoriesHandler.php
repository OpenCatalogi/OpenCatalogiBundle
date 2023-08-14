<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FindOrganizationThroughRepositoriesService;

/**
 * Loops through repositories (https://opencatalogi.nl/oc.repository.schema.json) and updates it with fetched organization info.
 */
class FindOrganizationThroughRepositoriesHandler implements ActionHandlerInterface
{

    /**
     * @var FindOrganizationThroughRepositoriesService
     */
    private FindOrganizationThroughRepositoriesService $service;


    /**
     * @param FindOrganizationThroughRepositoriesService $service The findOrganizationThroughRepositoriesService
     */
    public function __construct(FindOrganizationThroughRepositoriesService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/FindOrganizationThroughRepositoriesHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'FindOrganizationThroughRepositoriesHandler',
            'description' => 'This handler finds organizations through repositories',
            'required'    => [
                'githubSource',
                'repositorySchema',
                'organisationSchema',
                'componentSchema',
                'organisationMapping',
                'repositoryMapping'
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
                'organisationSchema'  => [
                    'type'        => 'string',
                    'description' => 'The organisation schema.',
                    'example'     => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'required'    => true,
                ],
                'componentSchema'     => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true,
                ],
                'organisationMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github organisation to oc organisation.',
                    'example'     => 'https://api.github.com/oc.githubOrganisation.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOrganisation.mapping.json',
                    'required'    => true,
                ],
                'repositoryMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github repository to oc repository.',
                    'example'     => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'required'    => true,
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
        return $this->service->findOrganizationThroughRepositoriesHandler($data, $configuration);

    }//end run()


}//end class
