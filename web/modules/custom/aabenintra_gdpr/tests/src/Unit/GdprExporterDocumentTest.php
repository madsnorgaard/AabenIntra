<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenintra_gdpr\Unit;

use Drupal\aabenintra_gdpr\Collector\CollectorManager;
use Drupal\aabenintra_gdpr\Collector\GdprDataCollectorInterface;
use Drupal\aabenintra_gdpr\ErasureMode;
use Drupal\aabenintra_gdpr\GdprExporter;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Shape of the export document (schema version 1).
 */
#[CoversClass(GdprExporter::class)]
#[CoversClass(CollectorManager::class)]
#[Group('aabenintra_gdpr')]
final class GdprExporterDocumentTest extends UnitTestCase {

  private function subject(): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('42');
    $user->method('getAccountName')->willReturn('jane');
    $user->method('getEmail')->willReturn('jane@example.org');
    $user->method('getPreferredLangcode')->willReturn('da');
    $user->method('getCreatedTime')->willReturn(1700000000);
    $user->method('getLastAccessedTime')->willReturn(0);
    $user->method('getRoles')->willReturn(['editor']);
    $user->method('isActive')->willReturn(TRUE);
    return $user;
  }

  /**
   * @param list<array<string,mixed>> $items
   */
  private function collector(string $id, string $module, bool $applies, array $items): GdprDataCollectorInterface {
    return new class($id, $module, $applies, $items) implements GdprDataCollectorInterface {

      /**
       * @param list<array<string,mixed>> $items
       */
      public function __construct(
        private readonly string $id,
        private readonly string $module,
        private readonly bool $applies,
        private readonly array $items,
      ) {}

      public function id(): string {
        return $this->id;
      }

      public function label(): string {
        return ucfirst($this->id);
      }

      public function module(): string {
        return $this->module;
      }

      public function applies(): bool {
        return $this->applies;
      }

      public function count(UserInterface $subject): int {
        return count($this->items);
      }

      public function collect(UserInterface $subject): array {
        return $this->items;
      }

      public function erase(UserInterface $subject, ErasureMode $mode, bool $dryRun): int {
        return count($this->items);
      }

      public function erasureNote(ErasureMode $mode): string {
        return 'n/a';
      }

    };
  }

  private function exporter(CollectorManager $manager): GdprExporter {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700003600);
    return new GdprExporter(
      $manager,
      $this->createMock(FileSystemInterface::class),
      $time,
      $this->createMock(ConfigFactoryInterface::class),
      new NullLogger(),
    );
  }

  public function testDocumentShape(): void {
    $manager = new CollectorManager();
    $manager->addCollector($this->collector('read_receipts', 'aabenintra_compliance', TRUE, [
      ['nid' => 5, 'langcode' => 'da', 'created' => '2024-01-01T00:00:00+00:00'],
      ['nid' => 6, 'langcode' => 'da', 'created' => '2024-01-02T00:00:00+00:00'],
    ]));
    $manager->addCollector($this->collector('reactions', 'aabenintra_social', FALSE, [['x' => 1]]));
    $manager->addCollector($this->collector('profile', 'user', TRUE, []));

    $doc = $this->exporter($manager)->buildDocument($this->subject());

    $this->assertSame(['schema_version', 'exported_at', 'subject', 'sections'], array_keys($doc));
    $this->assertSame(1, $doc['schema_version']);
    $this->assertSame('2023-11-14T23:13:20+00:00', $doc['exported_at']);

    $this->assertSame([
      'uid' => 42,
      'name' => 'jane',
      'mail' => 'jane@example.org',
      'langcode' => 'da',
      'created' => '2023-11-14T22:13:20+00:00',
      'access' => NULL,
      'roles' => ['editor'],
      'status' => TRUE,
    ], $doc['subject']);

    // Inactive collectors (module not installed) do not appear at all.
    $this->assertSame(['read_receipts', 'profile'], array_keys($doc['sections']));
    $section = $doc['sections']['read_receipts'];
    $this->assertSame(['module', 'label', 'count', 'items'], array_keys($section));
    $this->assertSame('aabenintra_compliance', $section['module']);
    $this->assertSame(2, $section['count']);
    $this->assertSame(5, $section['items'][0]['nid']);
    $this->assertSame(0, $doc['sections']['profile']['count']);
    $this->assertSame([], $doc['sections']['profile']['items']);
  }

  public function testEncodeAndFileName(): void {
    $manager = new CollectorManager();
    $exporter = $this->exporter($manager);
    $json = $exporter->encode(['schema_version' => 1, 'name' => 'Søren Ærø/Ø']);
    $this->assertStringEndsWith("\n", $json);
    $this->assertStringContainsString('"Søren Ærø/Ø"', $json, 'Unicode and slashes are kept readable.');
    $decoded = json_decode($json, TRUE);
    $this->assertSame(1, $decoded['schema_version']);
    $this->assertSame('42-1700003600.json', $exporter->fileName(42));
  }

  public function testActiveCollectorsAreKeyedById(): void {
    $manager = new CollectorManager();
    $manager->addCollector($this->collector('a', 'x', TRUE, []));
    $manager->addCollector($this->collector('b', 'y', FALSE, []));
    $this->assertSame(['a'], array_keys($manager->active()));
    $this->assertCount(2, $manager->all());
  }

}
