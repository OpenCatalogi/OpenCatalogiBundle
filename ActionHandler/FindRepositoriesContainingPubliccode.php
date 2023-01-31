<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;

/**
 * Via de github organisatie de repro's vand eorganisatie ophalen
 */
class FindRepositoriesContainingPubliccode implements ActionHandlerInterface
{
    private GithubApiService $githubApiService;

    public function __construct(GithubApiService $githubApiService)
    {
        $this->githubApiService = $githubApiService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'Find repositories containing publiccode',
            'description'=> 'This handler finds repositories on github that contain an publiccode file',
            'required'   => [],
            'properties' => [],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->githubApiService->handleFindRepositoriesContainingPubliccode($data, $configuration);
    }
}
