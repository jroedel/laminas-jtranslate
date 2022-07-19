<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Model\CountriesInfo;
use Laminas\Json\Json;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

use function file_get_contents;

class CountriesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $countries = Json::decode(file_get_contents("vendor/mledoze/countries/dist/countries.json"));
        return new CountriesInfo($countries);
    }
}
