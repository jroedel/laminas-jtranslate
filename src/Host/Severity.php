<?php

declare(strict_types=1);

namespace JTranslate\Host;

/**
 * How loudly a message is meant to read.
 *
 * ## The values are the laminas strings, deliberately
 *
 * `Success->value === 'success'`, and so on for all five —
 * `Laminas\Mvc\Plugin\FlashMessenger::NAMESPACE_SUCCESS` and friends. That is what makes
 * the adapter on a laminas host a one-liner (`setNamespace($severity->value)`) and, more
 * importantly, what makes a message written by a Symfony-served controller readable by a
 * page that has not been ported yet.
 *
 * The second half is the one with teeth. A flash message crosses a redirect **in the
 * session**, so during a migration one front controller writes it and the other renders
 * it; a value that did not match the laminas namespace would land the message in a bucket
 * the layout never looks in. Nothing errors — the message is simply never shown, on
 * exactly the flows that redirect, which is all of the successful ones.
 *
 * ## Why this enum and `JUser\Host\Severity` are two files
 *
 * They are identical and will stay identical, and merging them would invert the
 * dependency: JUser requires this package (for `TranslatableMessage`, among other
 * things), so JTranslate must not require JUser. Two enums of five cases is the cheaper
 * of the two wrongs. The consuming application pins both against the laminas constants,
 * so a rename fails a test rather than silently dropping messages.
 */
enum Severity: string
{
    case Success = 'success';
    case Error   = 'error';
    case Warning = 'warning';
    case Info    = 'info';
    /** Nothing in this module uses it; it exists because a host layout may render it. */
    case Default = 'default';
}
