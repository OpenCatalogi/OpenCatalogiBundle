<?php
/**
 * This main class makes the bundle findable and useable.
 *
 * @author  Conduction BV <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Bundle
 */
// src/XxllncZGWBundle.php
namespace OpenCatalogi\OpenCatalogiBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class OpenCatalogiBundle extends Bundle
{


    /**
     * Returns the path the bundle is in.
     *
     * @return string
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);

    }//end getPath()


}//end class
