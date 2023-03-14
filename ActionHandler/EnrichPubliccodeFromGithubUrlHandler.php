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
    private EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService;

    /**
     * @param EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService The enrich Publiccode From Github Ur Service
     */
    public function __construct(EnrichPubliccodeFromGithubUrlService $enrichPubliccodeFromGithubUrlService)
    {
        $this->enrichPubliccodeFromGithubUrlService = $enrichPubliccodeFromGithubUrlService;
    }//end __construct()

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'EnrichPubliccodeFromGithubUrlHandler',
            'description'=> 'This handler checks repositories for publuccode.yaml or publiccode.yml',
            'required'   => ['repositoryEntityId', 'componentEntityId', 'descriptionEntityId'],
            'properties' => [
                'repositoryEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the repository entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.repository.schema.json',
                ],
                'componentEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the component entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.catalogi.schema.json',
                ],
                'descriptionEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the description entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.description.schema.json',
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->enrichPubliccodeFromGithubUrlService->enrichPubliccodeFromGithubUrlHandler($data, $configuration);
    }//end run()
}//end class
