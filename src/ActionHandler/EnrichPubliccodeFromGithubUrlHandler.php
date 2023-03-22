<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeFromGithubUrlService;

/**
 * Haalt publiccode bestanden op.
 */
class EnrichPubliccodeFromGithubUrlHandler implements ActionHandlerInterface
{
    /**
     * @var EnrichPubliccodeFromGithubUrlService
     */
    private EnrichPubliccodeFromGithubUrlService $service;

    /**
     * @param EnrichPubliccodeFromGithubUrlService $service The enrich Publiccode From Github Ur Service
     */
    public function __construct(EnrichPubliccodeFromGithubUrlService $service)
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
            '$id'        => 'https://opencatalogi.nl/ActionHandler/EnrichPubliccodeFromGithubUrlHandler.ActionHandler.json',
            '$schema'    => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'      => 'EnrichPubliccodeFromGithubUrlHandler',
            'description'=> 'This handler checks repositories for publuccode.yaml or publiccode.yml',
            'required'   => [],
            'properties' => [],
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
        return $this->service->enrichPubliccodeFromGithubUrlHandler($data, $configuration);
    }//end run()
}//end class
