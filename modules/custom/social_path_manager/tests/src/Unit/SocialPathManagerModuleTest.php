<?php

declare(strict_types=1);

namespace Drupal\Tests\social_path_manager\Unit;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for Social Path Manager module procedural functions.
 *
 * Framework: PHPUnit with Drupal\Tests\UnitTestCase.
 */
final class SocialPathManagerModuleTest extends UnitTestCase {

  /**
   * Backup of global container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface|null
   */
  protected $originalContainer;

  protected function setUp(): void {
    parent::setUp();

    $this->originalContainer = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;

    $container = new ContainerBuilder();

    // Minimal translator that just interpolates placeholders.
    $translator = new class implements TranslationInterface {
      public function translate($string, array $args = [], array $options = []) {
        foreach ($args as $k => $v) {
          $string = str_replace($k, (string) $v, $string);
        }
        return $string;
      }
    };
    $container->set('string_translation', $translator);
    // Some code uses \Drupal::translation() instead of the service getter.
    // \Drupal::translation() proxies to the same TranslationInterface.
    if (method_exists('\Drupal', 'setTranslation')) {
      \Drupal::setTranslation($translator);
    }

    // Stub services used by functions under test with minimal behavior.
    // Most tests below target logic not requiring actual implementations.
    $container->set('path_alias.manager', new class {
      public function getAliasByPath(string $path): string {
        // Return a deterministic alias: replace '/group/ID' with '/group-alias/ID'
        return str_replace('/group/', '/group-alias/', $path);
      }
    });

    $container->set('pathauto.generator', new class {
      public function updateEntityAlias($entity, $op) {
        // Simulate returning ['alias' => '/foo', 'source' => '/group/123'].
        return [
          'alias' => '/group-alias/' . $entity->id(),
          'source' => '/group/' . $entity->id(),
        ];
      }
    });

    $container->set('entity_type.manager', new class {
      public function getStorage($entity_type_id) {
        // Return an object with a create() that records last created alias but is a no-op.
        return new class {
          public $created = [];
          public function create(array $values) {
            $this->created[] = $values;
            return new class($values) {
              private $values;
              public function __construct($values) { $this->values = $values; }
              public function save() { /* noop */ }
            };
          }
        };
      }
    });

    $container->set('path_alias.repository', new class {
      public function lookupByAlias($alias, $langcode = NULL) {
        // Return NULL to force creation path in tests.
        return NULL;
      }
    });

    $container->set('pathauto.alias_storage_helper', new class {
      public $deleted = [];
      public function deleteBySourcePrefix($prefix) {
        $this->deleted[] = $prefix;
      }
    });

    $container->set('cache_tags.invalidator', new class {
      public $tags = [];
      public function invalidateTags(array $tags) { $this->tags[] = $tags; }
    });

    $container->set('router.builder', new class {
      public $rebuilt = FALSE;
      public function rebuild() { $this->rebuilt = TRUE; }
    });

    $container->set('plugin.manager.menu.local_task', new class {
      public function getLocalTasksForRoute($route) {
        // Return a predictable set of local tasks including canonical and excluded one.
        // Format: [primary => [key => UrlLike]]
        $tasks = [
          'canonical' => new class {
            public function getRouteName() { return 'entity.group.canonical'; }
            public function getInternalPath() { return '/group/123'; }
          },
          // Will be included (route != canonical && != social_group.stream)
          'members' => new class {
            public function getRouteName() { return 'entity.group.members'; }
            public function getInternalPath() { return '/group/123/members'; }
          },
          // Excluded by code:
          'stream' => new class {
            public function getRouteName() { return 'social_group.stream'; }
            public function getInternalPath() { return '/group/123/stream'; }
          },
        ];
        return [ $tasks ];
      }
    });

    $container->set('module_handler', new class {
      public function alter($hook, &$data) {
        // No-op for tests unless explicitly mutated in a test.
      }
    });

    $container->set('messenger', new class {
      public $messages = [];
      public function addMessage($message) { $this->messages[] = $message; }
    });

    \Drupal::setContainer($container);

    // Define DRUPAL_ROOT if not already defined; needed for file path checks.
    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', sys_get_temp_dir());
    }

    // Include the module file if present to load procedural functions.
    // Path commonly: modules/custom/social_path_manager/social_path_manager.module
    $candidate = dirname(__DIR__, 4) . '/social_path_manager.module';
    if (is_readable($candidate)) {
      require_once $candidate;
    }
    else {
      // If module file is not accessible in unit context, dynamically define minimal
      // shims needed for Url::fromRoute() used by _social_path_manager_get_path_suffix().
      if (!class_exists('\Drupal\Core\Url')) {
        // Lightweight shim mimicking required methods for the specific usage.
        class Url {
          protected $route;
          protected $params;
          public static function fromRoute($route, array $parameters = []) {
            $inst = new self();
            $inst->route = $route;
            $inst->params = $parameters;
            return $inst;
          }
          public function getInternalPath() {
            // Simulate '/group/{group}/{suffix}' based on route name.
            $group = $this->params['group'] ?? '0';
            $map = [
              'entity.group.canonical' => "/group/$group",
              'entity.group.members'   => "/group/$group/members",
            ];
            return $map[$this->route] ?? "/group/$group/unknown";
          }
          public function getRouteName() {
            return $this->route;
          }
          public function toString() {
            return '/' . ltrim($this->getInternalPath(), '/');
          }
        }
        // Also expose in namespaced location to align with code references if any.
        class_alias(Url::class, '\Drupal\Core\Url');
      }
    }
  }

  protected function tearDown(): void {
    if ($this->originalContainer) {
      \Drupal::setContainer($this->originalContainer);
    }
    parent::tearDown();
  }

  /**
   * Helper to invoke the validator with a mocked FormStateInterface.
   *
   * @param mixed $pathValue
   *   Value returned for getValue('path').
   *
   * @return array
   *   [mock, errors], where errors are [ [name, message], ... ] captured.
   */
  private function invokeValidatorWithPath($pathValue): array {
    /** @var FormStateInterface&MockObject $formState */
    $formState = $this->createMock(FormStateInterface::class);

    $formState->method('getValue')
      ->with('path')
      ->willReturn($pathValue);

    $errors = [];
    $formState->method('setErrorByName')->willReturnCallback(function ($name, $message) use (&$errors) {
      $errors[] = [$name, $message];
      return NULL;
    });

    // The function under test expects array $form but doesn't use it directly.
    $form = [];
    _social_path_manager_path_alias_validate($form, $formState);
    return [$formState, $errors];
  }

  public function testPathAliasValidateReturnsEarlyWhenNoPathValue(): void {
    [, $errors] = $this->invokeValidatorWithPath(NULL);
    $this->assertSame([], $errors, 'No errors when path value is absent.');

    [, $errors] = $this->invokeValidatorWithPath([]);
    $this->assertSame([], $errors, 'No errors when path array is empty.');

    // Path array without alias.
    $pathValue = [['alias' => '']];
    [, $errors] = $this->invokeValidatorWithPath($pathValue);
    $this->assertSame([], $errors, 'No errors when alias string empty.');
  }

  public function testPathAliasValidateBlocksExistingFilePath(): void {
    $tempFile = DRUPAL_ROOT . '/existing-file.txt';
    file_put_contents($tempFile, 'x');
    try {
      $pathValue = [['alias' => '/existing-file.txt']];
      [, $errors] = $this->invokeValidatorWithPath($pathValue);

      $this->assertCount(1, $errors);
      $this->assertSame('path', $errors[0][0]);
      $this->assertStringContainsString('cannot point to an existing file', $errors[0][1]);
    }
    finally {
      @unlink($tempFile);
    }
  }

  public function reservedPathProvider(): array {
    return [
      ['admin'],
      ['user'],
      ['node'],
      ['taxonomy'],
      ['system'],
      ['comment'],
      ['modules'],
      ['themes'],
      ['libraries'],
      ['sites'],
      ['core'],
      ['profiles'],
      ['index.php'],
      ['robots.txt'],
      ['favicon.ico'],
    ];
  }

  /**
   * @dataProvider reservedPathProvider
   */
  public function testPathAliasValidateBlocksReservedPrefixes(string $prefix): void {
    // Construct various forms that begin with the reserved prefix:
    foreach ([
      "/$prefix",
      "/$prefix/child",
      "$prefix",           // without leading slash
      "$prefix/another",
      "//$prefix///deep",  // redundant slashes; code trims only for prefix check
    ] as $alias) {
      $pathValue = [['alias' => $alias]];
      [, $errors] = $this->invokeValidatorWithPath($pathValue);

      $this->assertNotEmpty($errors, "Alias '$alias' should be rejected.");
      $this->assertSame('path', $errors[0][0]);
      $this->assertStringContainsString('reserved', $errors[0][1]);
    }
  }

  public function testPathAliasValidateAllowsNonReservedNonExistingPaths(): void {
    foreach ([
      '/community',
      'community/events',
      '/group-alias/123/members',
    ] as $alias) {
      $pathValue = [['alias' => $alias]];
      [, $errors] = $this->invokeValidatorWithPath($pathValue);
      $this->assertSame([], $errors, "Alias '$alias' should be allowed.");
    }
  }

  public function testFormAlterHidesPathOnUserForms(): void {
    // Prepare form containing 'path' element.
    $form = [
      'path' => [
        '#type' => 'textfield',
      ],
    ];
    $form_id = 'user_form';

    // Mock a form state that is NOT an entity form => no validator injected by this criterion.
    $form_state = $this->createMock(FormStateInterface::class);
    // Because the code checks instanceof EntityFormInterface via $form_state->getFormObject(),
    // we can simply return null to bypass.
    $form_state->method('getFormObject')->willReturn(null);

    // Call the alter.
    social_path_manager_form_alter($form, $form_state, $form_id);

    // 'path' should be unset for user forms.
    $this->assertArrayNotHasKey('path', $form, 'Path element is removed for user form.');
  }

  public function testFormAlterAddsValidationForEntityFormContentEntity(): void {
    // Form that does not contain 'path' initially.
    $form = [];
    $form_id = 'some_other_form';

    // Build a minimal "EntityFormInterface" and "ContentEntityInterface" combo using anonymous classes.
    $contentEntity = new class implements \Drupal\Core\Entity\ContentEntityInterface {
      // Implement only methods required by instanceof; they won't be called.
      public function getEntityType() {}
      public function bundle() {}
      public function isNew() {}
      public function enforceIsNew($value = TRUE) {}
      public function id() {}
      public function uuid() {}
      public function language() {}
      public function getEntityTypeId() {}
      public function hasField($field_name) {}
      public function get($field_name) {}
      public function set($name, $value, $notify = TRUE) {}
      public function toArray() {}
      public function referencedEntities() {}
      public function save() {}
      public function delete() {}
      public function isDefaultTranslation() {}
      public function enableTranslation() {}
      public function addTranslation($langcode, array $values = []) {}
      public function removeTranslation($langcode) {}
      public function hasTranslation($langcode) {}
      public function getTranslation($langcode) {}
      public function getUntranslated() {}
      public function getFields($include_computed = FALSE) {}
      public function getFieldDefinition($name) {}
      public function getFieldDefinitions() {}
      public function hasLinkTemplate($rel) {}
      public function toUrl($rel = 'canonical', array $options = []) {}
      public function toLink($text = NULL, $rel = 'canonical', array $options = []) {}
      public static function preCreate(\Drupal\Core\Entity\EntityStorageInterface $storage, array &$values) {}
      public function label() {}
      public function uriRelationships() {}
      public function urlInfo($rel = 'canonical', array $options = []) {}
      public function link($text = NULL, $rel = 'canonical', array $options = []) {}
      public function access($operation, $account = NULL, $return_as_object = FALSE) {}
    };

    $entityFormObject = new class($contentEntity) implements \Drupal\Core\Entity\EntityFormInterface {
      private $entity;
      public function __construct($entity) { $this->entity = $entity; }
      public function getEntity() { return $this->entity; }
      public function getOperation() {}
      public function setEntity(\Drupal\Core\Entity\EntityInterface $entity) {}
      public function setOperation($operation) {}
      public function __sleep() {}
      public function __wakeup() {}
      public function getBaseFormId() {}
      public function getFormId() {}
      public function validateForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {}
      public function submitForm(array &$form, \Drupal\Core\Form\FormStateInterface $form_state) {}
      public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {}
    };

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getFormObject')->willReturn($entityFormObject);

    social_path_manager_form_alter($form, $form_state, $form_id);

    $this->assertArrayHasKey('#validate', $form, 'Validation callbacks injected.');
    $this->assertContains('_social_path_manager_path_alias_validate', $form['#validate'], 'Custom path alias validator added.');
  }

  public function testGetPathSuffixParsesLastUrlSegment(): void {
    // Build a minimal group with id() method.
    $group = new class {
      public function id() { return 123; }
    };

    // Expect suffix for members route.
    $suffix = _social_path_manager_get_path_suffix($group, 'entity.group.members');
    $this->assertSame('members', $suffix);

    // Canonical route should yield last segment (group id) as per shim, hence '123'.
    $suffixCanonical = _social_path_manager_get_path_suffix($group, 'entity.group.canonical');
    $this->assertSame('123', $suffixCanonical);
  }

  public function testModuleImplementsAlterReordersFormAlter(): void {
    $impl = [
      'other_module' => 'other_module',
      'social_path_manager' => 'social_path_manager',
      'another_module' => 'another_module',
    ];
    social_path_manager_module_implements_alter($impl, 'form_alter');

    // social_path_manager should be last.
    $keys = array_keys($impl);
    $this->assertSame('social_path_manager', end($keys));
  }

}