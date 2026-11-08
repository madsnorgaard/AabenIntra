<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_dashboard\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Route-level behaviour of /dashboard on an installed site.
 */
#[Group('aabenintra_dashboard')]
final class DashboardAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'aabenintra_dashboard'];

  /**
   * Anonymous visitors are refused; an intranet user gets the dashboard.
   */
  public function testDashboardRequiresAccessContent(): void {
    $this->drupalCreateContentType(['type' => 'story', 'name' => 'Story']);
    $this->drupalCreateNode(['type' => 'story', 'title' => 'Velkommen til intranettet', 'status' => 1]);

    // The testing profile grants anonymous nothing, so /dashboard is denied.
    $this->drupalGet('/dashboard');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('/dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[data-ai-dashboard]');
    $this->assertSession()->pageTextContains('Velkommen til intranettet');
  }

}
