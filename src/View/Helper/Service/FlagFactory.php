<?php
namespace JTranslate\View\Helper\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use JTranslate\Model\CountriesInfo;
use JTranslate\View\Helper\Flag;

/**
 * Factory responsible of priming the Flag view helper
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class FlagFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var CountriesInfo $countries */
        $countries = $container->get(CountriesInfo::class);
		$obj = new Flag($countries);
		return $obj;
    }
}
