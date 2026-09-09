<?php

declare(strict_types=1);

namespace JTranslate\I18n\Translator;

use Psr\Container\ContainerInterface;

use function is_array;
use function is_string;

/**
 * Builds {@see Translator} from the merged `translator` config key.
 *
 * The key is laminas-i18n's — `translation_file_patterns`, each entry a `type`,
 * `base_dir`, `pattern` and `text_domain` — and it is read unchanged, because every module
 * that ships catalogs declares itself that way and rewriting the convention would be a
 * change to every module for no gain. `locale` and `fallback_locale` are honoured too,
 * though on schoenstatt.link both are set later, by the host's translator delegator, from
 * the request's locale and the configured key locale.
 *
 * Entries whose type is not `phpArray` are dropped by the translator itself; see
 * {@see Translator::addTranslationFilePattern()}. Nothing here validates the config,
 * because a malformed pattern should cost a missing translation rather than a page.
 */
class TranslatorFactory
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = Translator::class,
        ?array $options = null
    ): Translator {
        /** @var mixed $config */
        $config     = $container->has('config') ? $container->get('config') : [];
        $config     = is_array($config) ? $config : [];
        $translator = new Translator();

        /** @var mixed $section */
        $section = $config['translator'] ?? [];
        if (! is_array($section)) {
            return $translator;
        }

        if (is_string($section['locale'] ?? null)) {
            $translator->setLocale($section['locale']);
        }
        if (is_string($section['fallback_locale'] ?? null)) {
            $translator->setFallbackLocale($section['fallback_locale']);
        }

        /** @var mixed $patterns */
        $patterns = $section['translation_file_patterns'] ?? [];
        if (! is_array($patterns)) {
            return $translator;
        }
        foreach ($patterns as $pattern) {
            if (! is_array($pattern)) {
                continue;
            }
            $type    = is_string($pattern['type'] ?? null) ? $pattern['type'] : '';
            $baseDir = is_string($pattern['base_dir'] ?? null) ? $pattern['base_dir'] : '';
            $file    = is_string($pattern['pattern'] ?? null) ? $pattern['pattern'] : '';
            $domain  = is_string($pattern['text_domain'] ?? null)
                ? $pattern['text_domain']
                : Translator::DEFAULT_TEXT_DOMAIN;
            if ('' === $baseDir || '' === $file) {
                continue;
            }
            $translator->addTranslationFilePattern($type, $baseDir, $file, $domain);
        }

        return $translator;
    }
}
