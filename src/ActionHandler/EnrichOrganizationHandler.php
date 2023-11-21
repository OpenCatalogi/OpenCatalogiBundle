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
                'organizationSchema',
            ],
            'properties'  => [
                'githubSource'       => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'organizationSchema' => [
                    'type'        => 'string',
                    'description' => 'The organisation schema.',
                    'example'     => 'https://opencatalogi.nl/oc.organization.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.organization.schema.json',
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

        // This comes from the GithubEvent or FormInput action.
        // We hava an organization in the response.
        $organizationId = null;
        if (key_exists('response', $data) === true
            && key_exists('_self', $data['response']) === true
            && key_exists('id', $data['response']['_self']) === true
        ) {
            $organizationId = $data['response']['_self']['id'];
        }//end if

        return $this->service->enrichOrganizationHandler($data, $configuration, $organizationId);

    }//end run()


}//end class
