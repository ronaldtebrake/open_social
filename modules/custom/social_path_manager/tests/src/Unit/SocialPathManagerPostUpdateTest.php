<?php
declare(strict_types=1);

namespace Drupal\Tests\social_path_manager\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for social_path_manager_post_update_0001_fix_corrupted_stream_alias().
 *
 * Note on framework:
 * - These tests are written for PHPUnit, which is the standard testing framework
 *   used for Drupal modules' Unit tests (tests/src/Unit).
 *
 * Approach:
 * - The function under test directly references \Drupal static methods and procedural
 *   t(), which are difficult to mock using typical dependency injection.
 * - To keep this as a pure unit test, we provide light-weight stubs for the minimal
 *   surface the function uses: \Drupal::database(), \Drupal::messenger(), \Drupal::logger(),
 *   \Drupal::service() for path_alias.manager and cache.data, and a minimal t() shim.
 * - The database and query builder behavior is emulated with small stub classes that allow
 *   us to prescribe returned rows, existing alias lookups, and to record update/delete calls.
 *
 * These tests focus on:
 * - Happy path updates and deletions.
 * - Zero results path.
 * - Execution failure path.
 * - Exception handling within the per-alias processing loop.
 * - Cache clearing and messaging/logging side-effects.
 */

// ---------------------------------------------------------------------------
// Conditional stubs for \Drupal static facade and t() translation helper.
// They will only be defined if not already present in the runtime (e.g. if
// Drupal core is not bootstrapped for unit tests).
// ---------------------------------------------------------------------------

namespace {
  // Minimal renderable-like object returned by t().
  if (!class_exists('Drupal\\Tests\\social_path_manager\\Unit\\_TestTranslatableString', false)) {
    class _TestTranslatableString {
      private string $message;
      private array $args;
      public function __construct(string $message, array $args = []) {
        $this->message = $message;
        $this->args = $args;
      }
      public function render(): string {
        $out = $this->message;
        foreach ($this->args as $k => $v) {
          $out = str_replace($k, (string) $v, $out);
        }
        return $out;
      }
      public function __toString(): string {
        return $this->render();
      }
    }
  }

  if (!function_exists('t')) {
    /**
     * Very small t() shim returning an object with ->render().
     */
    function t($string, array $args = []) {
      return new _TestTranslatableString((string) $string, $args);
    }
  }

  if (!class_exists('\\Drupal')) {
    /**
     * Minimal \Drupal facade for unit testing.
     */
    class Drupal {
      /** @var mixed */
      public static $db;
      /** @var mixed */
      public static $messenger;
      /** @var mixed */
      public static $logger;
      /** @var array<string,mixed> */
      public static $services = [];

      public static function database() {
        return self::$db;
      }
      public static function messenger() {
        return self::$messenger;
      }
      public static function logger($_channel = NULL) {
        (void) $_channel;
        // Ignore channel; return single channel stub.
        return self::$logger;
      }
      public static function service($id) {
        return self::$services[$id] ?? null;
      }
      // Allow tests to inject stubs in a Drupal-like way.
      public static function setService($id, $service): void {
        self::$services[$id] = $service;
      }
    }
  }

  // -------------------------------------------------------------------------
  // DB/query stubs
  // -------------------------------------------------------------------------

  /**
   * Represents a single alias row record.
   */
  class _AliasRow {
    public int $id;
    public string $path;
    public string $alias;
    public string $langcode;
    public function __construct(int $id, string $path, string $alias, string $langcode = 'en') {
      $this->id = $id;
      $this->path = $path;
      $this->alias = $alias;
      $this->langcode = $langcode;
    }
  }

  class _StubResult {
    /** @var array<int,object> */
    private array $rows;
    /** @var ?object */
    private $single;

    public function __construct(?array $rows = null, $single = null) {
      $this->rows = $rows ?? [];
      $this->single = $single;
    }
    public function fetchAll() {
      return $this->rows;
    }
    public function fetch() {
      return $this->single;
    }
  }

  class _StubDeleteQuery {
    private $_table;
    private $_conditions = [];
    private $_recorder;

    public function __construct(string $table, callable $recorder) {
      $this->_table = $table;
      $this->_recorder = $recorder;
    }
    public function condition($field, $value, $operator = '=') {
      $this->_conditions[] = [$field, $value, $operator];
      return $this;
    }
    public function execute() {
      ($this->_recorder)('delete', [
        'table' => $this->_table,
        'conditions' => $this->_conditions,
      ]);
      return 1;
    }
  }

  class _StubUpdateQuery {
    private $_table;
    private $_fields = [];
    private $_conditions = [];
    private $_recorder;

    public function __construct(string $table, callable $recorder) {
      $this->_table = $table;
      $this->_recorder = $recorder;
    }
    public function fields(array $fields) {
      $this->_fields = $fields;
      return $this;
    }
    public function condition($field, $value, $operator = '=') {
      $this->_conditions[] = [$field, $value, $operator];
      return $this;
    }
    public function execute() {
      ($this->_recorder)('update', [
        'table' => $this->_table,
        'fields' => $this->_fields,
        'conditions' => $this->_conditions,
      ]);
      return 1;
    }
  }

  class _StubSelectQuery {
    private $_table;
    private $_alias;
    private $_fields = [];
    private $_conditions = [];
    private $_orderBy = [];
    private $_db;

    public function __construct($db, string $table, string $alias) {
      $this->_db = $db;
      $this->_table = $table;
      $this->_alias = $alias;
    }
    public function fields($_table_alias, array $fields) {
      (void) $_table_alias;
      $this->_fields = $fields;
      return $this;
    }
    public function condition($field, $value, $operator = '=') {
      $this->_conditions[] = [$field, $value, $operator];
      return $this;
    }
    public function orderBy($field, $direction = 'ASC') {
      $this->_orderBy[] = [$field, $direction];
      return $this;
    }
    public function execute() {
      // Determine which select this is by inspecting conditions.
      // Initial search uses LIKE '%/stream/stream%'.
      foreach ($this->_conditions as [$field, $value, $operator]) {
        if ($field === 'pa.alias' && $operator === 'LIKE' && is_string($value) && strpos($value, '/stream/stream') !== false) {
          if ($this->_db->failInitialExecute) {
            return false;
          }
          return new _StubResult($this->_db->initialRows);
        }
      }
      // Else, it's the existence check for a given fixed alias/langcode with id != current.
      $alias = null;
      $langcode = null;
      $notId = null;
      foreach ($this->_conditions as [$field, $value, $operator]) {
        if ($field === 'pa.alias' && $operator === '=') {
          $alias = $value;
        }
        if ($field === 'pa.langcode' && $operator === '=') {
          $langcode = $value;
        }
        if ($field === 'pa.id' && $operator === '!=') {
          $notId = $value;
        }
      }
      $existing = $this->_db->findExisting($alias, $langcode, $notId);
      return new _StubResult(null, $existing);
    }
  }

  class _StubDatabase {
    /** @var array<int,object> */
    public array $initialRows = [];
    /** @var bool */
    public bool $failInitialExecute = false;
    /** @var array<string,array{alias:string,id:int,langcode:string,path:string}> */
    public array $existingByAliasLang = [];
    /** @var array<int,array<string,mixed>> */
    public array $mutations = []; // Records of 'update'/'delete' actions.
    /** @var ?\Exception */
    public $throwOnMutation = null;

    public function select($table, $alias) {
      return new _StubSelectQuery($this, (string) $table, (string) $alias);
    }
    public function delete($table) {
      return new _StubDeleteQuery((string) $table, function ($type, $payload) {
        if ($this->throwOnMutation instanceof \Exception) {
          throw $this->throwOnMutation;
        }
        $this->mutations[] = ['type' => $type, 'payload' => $payload];
      });
    }
    public function update($table) {
      return new _StubUpdateQuery((string) $table, function ($type, $payload) {
        if ($this->throwOnMutation instanceof \Exception) {
          throw $this->throwOnMutation;
        }
        $this->mutations[] = ['type' => $type, 'payload' => $payload];
      });
    }
    public function findExisting(?string $alias, ?string $langcode, $notId) {
      if ($alias === null || $langcode === null) {
        return null;
      }
      $key = $alias . '//' . $langcode;
      if (isset($this->existingByAliasLang[$key])) {
        $row = $this->existingByAliasLang[$key];
        if ((int) $row['id'] !== (int) $notId) {
          return (object) ['id' => $row['id'], 'path' => $row['path']];
        }
      }
      return null;
    }
  }

  // -------------------------------------------------------------------------
  // Messenger and Logger stubs, plus path alias manager + cache data services.
  // -------------------------------------------------------------------------

  class _StubMessenger {
    public array $errors = [];
    public array $statuses = [];
    public function addError($message) { $this->errors[] = (string) $message; }
    public function addStatus($message) { $this->statuses[] = (string) $message; }
  }

  class _StubLogger {
    public array $infos = [];
    public array $errors = [];
    public function info($message, array $context = []) { $this->infos[] = [$message, $context]; }
    public function error($message, array $context = []) { $this->errors[] = [$message, $context]; }
  }

  class _StubPathAliasManager {
    public int $clearCount = 0;
    public function cacheClear() { $this->clearCount++; }
  }

  class _StubCacheData {
    public int $deleteAllCount = 0;
    public function deleteAll() { $this->deleteAllCount++; }
  }
}

namespace Drupal\Tests\social_path_manager\Unit {

  /**
   * @covers ::social_path_manager_post_update_0001_fix_corrupted_stream_alias
   */
  class SocialPathManagerPostUpdateTest extends TestCase {

    protected function setUp(): void {
      parent::setUp();

      // Install stubs into \Drupal facade.
      \Drupal::$db = new \_StubDatabase();
      \Drupal::$messenger = new \_StubMessenger();
      \Drupal::$logger = new \_StubLogger();
      \Drupal::setService('path_alias.manager', new \_StubPathAliasManager());
      \Drupal::setService('cache.data', new \_StubCacheData());

      // Attempt to include the module's post_update file if present and function not yet defined.
      if (!function_exists('\social_path_manager_post_update_0001_fix_corrupted_stream_alias')) {
        $candidate = dirname(__DIR__, 3) . '/social_path_manager.post_update.php';
        if (is_file($candidate)) {
          require_once $candidate;
        } else {
          // If the file does not exist in this layout, try module root relative to repo root.
          $alt = __DIR__ . '/../../../../social_path_manager.post_update.php';
          if (is_file($alt)) {
            require_once $alt;
          }
        }
      }
    }

    public function testQueryExecutionFailure(): void {
      $db = \Drupal::$db;
      $db->failInitialExecute = true;

      $result = \social_path_manager_post_update_0001_fix_corrupted_stream_alias();

      $this->assertSame('Failed to execute database query for corrupted aliases.', $result);
      $this->assertContains('Failed to execute database query for corrupted aliases.', \Drupal::$messenger->errors);
      $this->assertNotEmpty(\Drupal::$logger->errors, 'Error should be logged.');
    }

    public function testNoCorruptedAliasesFound(): void {
      $db = \Drupal::$db;
      $db->initialRows = []; // fetchAll() returns empty array.

      $result = \social_path_manager_post_update_0001_fix_corrupted_stream_alias();

      $expected = 'No corrupted aliases with duplicate /stream patterns found.';
      $this->assertSame($expected, $result);
      $this->assertContains($expected, \Drupal::$messenger->statuses);
      $this->assertNotEmpty(\Drupal::$logger->infos, 'Info should be logged.');
      // No cache clears when nothing to fix? The function still clears caches only after loop when total>0.
      $this->assertSame(0, \Drupal::service('path_alias.manager')->clearCount);
      $this->assertSame(0, \Drupal::service('cache.data')->deleteAllCount);
    }

    public function testFixesByUpdatingAliasWhenNoExistingFixedAlias(): void {
      $db = \Drupal::$db;
      // Provide a corrupted alias record needing fix.
      $db->initialRows = [
        new \_AliasRow(101, '/node/101', '/group/alpha/stream/stream', 'en'),
        new \_AliasRow(102, '/node/102', '/group/beta/stream/stream/stream', 'en'),
      ];
      // No existing fixed aliases; ensure existence lookups return null.
      $db->existingByAliasLang = [];

      $result = \social_path_manager_post_update_0001_fix_corrupted_stream_alias();

      // Expect two fixes out of two.
      $this->assertSame('Fixed 2 out of 2 corrupted aliases with duplicate /stream patterns.', $result);

      // Verify updates performed with correct new aliases.
      $updates = array_values(array_filter($db->mutations, fn($m) => $m['type'] === 'update'));
      $this->assertCount(2, $updates, 'Two update mutations should occur.');
      // Extract updated alias values.
      $updatedAliases = array_map(fn($u) => $u['payload']['fields']['alias'], $updates);
      $this->assertContains('/group/alpha/stream', $updatedAliases);
      $this->assertContains('/group/beta/stream', $updatedAliases);

      // Verify cache clearing occurred once each.
      $this->assertSame(1, \Drupal::service('path_alias.manager')->clearCount);
      $this->assertSame(1, \Drupal::service('cache.data')->deleteAllCount);

      // Verify messenger and logs include success message.
      $this->assertContains('Fixed 2 out of 2 corrupted aliases with duplicate /stream patterns.', \Drupal::$messenger->statuses);
      // Check that at least one info log about found count exists, and final message logged.
      $infoTexts = array_map(fn($x) => $x[0], \Drupal::$logger->infos);
      $this->assertTrue(
        (bool) array_filter($infoTexts, fn($m) => is_string($m) && (strpos($m, 'Found') !== false || strpos($m, 'Fixed 2 out of 2') !== false)),
        'Expected info logs about found/fixed.'
      );
    }

    public function testFixesByDeletingWhenExistingFixedAliasExists(): void {
      $db = \Drupal::$db;
      // One corrupted record that, when fixed, matches an existing alias entry.
      $db->initialRows = [
        new \_AliasRow(201, '/node/201', '/group/gamma/stream/stream', 'en'),
      ];
      $fixedAlias = '/group/gamma/stream';
      // Simulate that alias already exists with a different id (not 201).
      $db->existingByAliasLang[$fixedAlias . '//en'] = [
        'id' => 999,
        'alias' => $fixedAlias,
        'langcode' => 'en',
        'path' => '/node/999',
      ];

      $result = \social_path_manager_post_update_0001_fix_corrupted_stream_alias();

      $this->assertSame('Fixed 1 out of 1 corrupted aliases with duplicate /stream patterns.', $result);

      // Verify a delete, not an update, took place.
      $deletes = array_values(array_filter($db->mutations, fn($m) => $m['type'] === 'delete'));
      $updates = array_values(array_filter($db->mutations, fn($m) => $m['type'] === 'update'));
      $this->assertCount(1, $deletes, 'One delete mutation should occur.');
      $this->assertCount(0, $updates, 'No updates should occur when the correct alias already exists.');
      // Check delete condition targeted the corrupted row id (201).
      $deleteConds = $deletes[0]['payload']['conditions'];
      $idConds = array_values(array_filter($deleteConds, fn($c) => $c[0] === 'id'));
      $this->assertSame(201, $idConds[0][1]);

      // Cache clears and messaging.
      $this->assertSame(1, \Drupal::service('path_alias.manager')->clearCount);
      $this->assertSame(1, \Drupal::service('cache.data')->deleteAllCount);
      $this->assertContains('Fixed 1 out of 1 corrupted aliases with duplicate /stream patterns.', \Drupal::$messenger->statuses);
    }

    public function testExceptionDuringFixIsLoggedAndDoesNotIncrementFixedCount(): void {
      $db = \Drupal::$db;
      $db->initialRows = [
        new \_AliasRow(301, '/node/301', '/group/delta/stream/stream', 'en'),
      ];
      // Cause mutations to throw exception.
      $db->throwOnMutation = new \Exception('DB failed during mutation');

      $result = \social_path_manager_post_update_0001_fix_corrupted_stream_alias();

      // Even though there was 1 corrupted alias, the fix failed, so fixed count should be 0.
      $this->assertSame('Fixed 0 out of 1 corrupted aliases with duplicate /stream patterns.', $result);

      // Ensure error was logged and no successful mutation recorded.
      $this->assertNotEmpty(\Drupal::$logger->errors, 'Error should be logged when exception occurs.');
      $this->assertCount(0, $db->mutations, 'No mutations recorded due to thrown exception.');

      // Cache clearing still occurs after processing loop.
      $this->assertSame(1, \Drupal::service('path_alias.manager')->clearCount);
      $this->assertSame(1, \Drupal::service('cache.data')->deleteAllCount);
    }

    public function testRegexConsolidatesMultipleStreamSuffixes(): void {
      $db = \Drupal::$db;
      $db->initialRows = [
        new \_AliasRow(401, '/node/401', '/g/one/stream/stream/stream', 'en'),
        new \_AliasRow(402, '/node/402', '/g/two/stream/stream', 'en'),
        new \_AliasRow(403, '/node/403', '/g/three/stream', 'en'), // Already fine; should be no-op.
      ];

      $result = \social_path_manager_post_update_0001_fix_corrupted_stream_alias();

      // 2 out of 3 need fixing; the third stays unchanged so only 2 updates expected.
      $this->assertSame('Fixed 2 out of 3 corrupted aliases with duplicate /stream patterns.', $result);

      $updates = array_values(array_filter($db->mutations, fn($m) => $m['type'] === 'update'));
      $this->assertCount(2, $updates);

      // Verify each updated alias ends with a single '/stream'.
      foreach ($updates as $u) {
        $newAlias = $u['payload']['fields']['alias'];
        $this->assertMatchesRegularExpression('#/stream$#', $newAlias);
        $this->assertStringNotContainsString('/stream/stream', $newAlias);
      }
    }
  }
}