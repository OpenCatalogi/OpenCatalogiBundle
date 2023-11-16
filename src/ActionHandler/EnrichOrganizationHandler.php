<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\EnrichOrganizationService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;

/**
 * Executes a the EnrichOrganizationHandler and gets an organization from the response of the githubEventAction and formInputAction
 * and enriches the organization.
 */
class EnrichOrganizationHandler implements ActionHandlerInterface
{

    /**
     * @var EnrichOrganizationService
     */
    private EnrichOrganizationService $service;


    /**
     * @param EnrichOrganizationService $service The EnrichOrganizationService
     */
    public function __construct(EnrichOrganizationService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/EnrichOrganizationHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'EnrichOrganizationHandler',
            'description' => 'This handler enriches the organizations',
            'required'    => [
                'githubSource',
                'usercontentSource',
                'repositorySchema',
                'repositoryMapping',
                'organisationSchema',
                'componentSchema',
                'openCatalogiMapping',
            ],
            'properties'  => [
                'githubSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'usercontentSource'   => [
                    'type'        => 'string',
                    'description' => 'The source of the developer overheid.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
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
                'componentSchema'     => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true,
                ],
                'openCatalogiMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github openCatalogi.yaml to oc organisation.',
                    'example'     => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the enrich organization service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->enrichOrganizationHandler($data, $configuration);

    }//end run()


}//end class
