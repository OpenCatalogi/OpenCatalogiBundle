<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\CatalogiService;
use OpenCatalogi\OpenCatalogiBundle\Service\ComponentenCatalogusService;
use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubPubliccodeService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class GithubFindPubliccodeHandler implements ActionHandlerInterface
{
    private GithubApiService $githubApiService;

    public function __construct(GithubApiService $githubApiService)
    {
        $this->githubApiService = $githubApiService;
    }

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'        => 'https://opencatalogi.nl/oc.githubapi.publiccode.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'ComponentenCatalogusApplicationToGatewayHandler',
            'description'=> 'This is a action to create objects from the fetched applications from the componenten catalogus.',
            'required'   => [],
            'properties' => [],
        ];
    }

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
        return $this->githubApiService->handleFindRepositoriesContainingPubliccode();
    }
}
