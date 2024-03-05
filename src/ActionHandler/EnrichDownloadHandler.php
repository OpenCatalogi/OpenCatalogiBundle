<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\DownloadObjectService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindRepositoryThroughOrganizationService;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;

/**
 * Executes a the EnrichOrganizationHandler and gets an organization from the response of the githubEventAction and formInputAction
 * and enriches the organization.
 */
class EnrichDownloadHandler implements ActionHandlerInterface
{

    private DownloadObjectService $service;


    /**
     * @param DownloadObjectService $service The EnrichOrganizationService
     */
    public function __construct(DownloadObjectService $service)
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
            'required'    => [],
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
        return $this->service->enrichDownloadObject($data, $configuration);

    }//end run()


}//end class
