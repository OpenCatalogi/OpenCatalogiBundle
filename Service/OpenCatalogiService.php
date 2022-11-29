<?php

// src/Service/OpenCatalogiService.php

namespace CommonGateway\OpenCatalogiBundle\Service;

class OpenCatalogiService
{

    /*
     * Returns a welcoming string
     * 
     * @return array 
     */
    public function openCatalogiHandler(array $data, array $configuration): array
    {
        return ['response' => 'Hello. Your OpenCatalogiBundle works'];
    }
}
