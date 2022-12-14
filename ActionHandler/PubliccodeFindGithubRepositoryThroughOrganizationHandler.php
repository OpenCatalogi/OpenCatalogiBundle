<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  OpenCatalogi\OpenCatalogiBundle\Service\PubliccodeService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

class PubliccodeFindGithubRepositoryThroughOrganizationHandler implements ActionHandlerInterface
{
    private PubliccodeService $publiccodeService;

    public function __construct(PubliccodeService $publiccodeService)
    {
        $this->publiccodeService = $publiccodeService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'PubliccodeFindGithubRepositoryThroughOrganizationHandler',
            'description'=> 'This handler finds the .github repository through organizations',
            'required'   => ['organisationEntityId'],
            'properties' => [
                'organisationEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the organisation entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/organisation.schema.json'
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->publiccodeService->enrichOrganizationWithCatalogi($data, $configuration);
    }
}
