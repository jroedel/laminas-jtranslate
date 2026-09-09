<?php

declare(strict_types=1);

namespace JTranslate;

/**
 * The module's config, and nothing else.
 *
 * `onBootstrap()` lived here until 2026-09 and configured the translator: fallback locale,
 * the missing-translation listener, `AbstractValidator::setDefaultTranslator()` and the
 * per-module catalog directories. It implemented `BootstrapListenerInterface`, which only
 * `Laminas\Mvc\Application::bootstrap()` ever triggered — and laminas-mvc was removed in
 * step 0 of the laminas exit, so nothing had called it since. It was dead code that read
 * as the live configuration path, which is worse than either.
 *
 * A host configures the translator itself now, and gets a lazy hook to do it in rather
 * than a bootstrap event: on schoenstatt.link that is `App\Laminas\TranslatorConfigurator`,
 * a delegator that runs the first time anything asks for a translator. The three things it
 * must not skip are named in its docblock, because each fails silently: the fallback
 * locale, the catalog directories, and the missing-translation listener without which no
 * phrase is ever discovered.
 */
class Module
{
    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return include __DIR__ . '/../config/module.config.php';
    }
}
