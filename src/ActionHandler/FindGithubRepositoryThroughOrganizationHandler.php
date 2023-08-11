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
    private FindGithubRepositoryThroughOrganizationService $service;


    /**
     * @param FindGithubRepositoryThroughOrganizationService $service The findGithubRepositoryThroughOrganizationService
     */
    public function __construct(FindGithubRepositoryThroughOrganizationService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/FindGithubRepositoryThroughOrganizationHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'FindGithubRepositoryThroughOrganizationHandler',
            'description' => 'This handler finds the .github repository through organizations',
            'required'    => ['githubSource', 'organisationSchema', 'componentSchema', 'openCatalogiMapping'],
            'properties'  => [
                'githubSource' => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true
                ],
                'organisationSchema' => [
                    'type'        => 'string',
                    'description' => 'The organisation schema.',
                    'example'     => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'required'    => true
                ],
                'componentSchema' => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true
                ],
                'openCatalogiMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github openCatalogi.yaml to oc organisation.',
                    'example'     => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'required'    => true
                ]
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
        return $this->service->findGithubRepositoryThroughOrganizationHandler($data, $configuration);

    }//end run()


}//end class
