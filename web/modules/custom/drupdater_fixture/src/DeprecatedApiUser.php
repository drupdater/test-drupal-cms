<?php

declare(strict_types=1);

namespace Drupal\drupdater_fixture;

use Drupal\Core\Utility\DeprecationHelper;

/**
 * Holds code that Drupal-Rector is expected to rewrite.
 *
 * The drupdater image's Rector config (/opt/drupdater/rector.php) configures
 * DeprecationHelperRemoveRector with \Drupal::VERSION, so a
 * backwardsCompatibleCall() whose $deprecatedVersion is already below the
 * installed core version gets unwrapped to just its currentCallable. Pinning
 * the fixture to that rule rather than to some specific deprecated core API
 * means it keeps firing across core releases instead of going stale the moment
 * the API it referenced is removed.
 *
 * Nothing here is ever called: the point is that the code exists, not that it
 * runs. Keep it that way, so a rewrite can never break the site install.
 */
final class DeprecatedApiUser {

  /**
   * Returns a label, via a backwards-compatibility shim that is now redundant.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The formatted label.
   */
  public function label(string $value): string {
    return DeprecationHelper::backwardsCompatibleCall(
      currentVersion: \Drupal::VERSION,
      deprecatedVersion: '10.1.0',
      currentCallable: fn() => ucfirst($value),
      deprecatedCallable: fn() => ucwords($value),
    );
  }

}
