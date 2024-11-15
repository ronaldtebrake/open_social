<?php

namespace Drupal\animals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class AnimalTypeController.
 *
 * Provides route responses for the animal type entity.
 */
class AnimalTypeController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new AnimalTypeController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Lists all animal type entities.
   *
   * @return array
   *   A render array.
   */
  public function list() {
    $storage = $this->entityTypeManager->getStorage('animal_type');
    $entities = $storage->loadMultiple();

    $build = [
      '#theme' => 'animal_type_list',
      '#animal_types' => $entities,
    ];

    return $build;
  }

  /**
   * Views an animal type entity.
   *
   * @param int $animal_type
   *   The animal type entity ID.
   *
   * @return array
   *   A render array.
   */
  public function view($animal_type) {
    $storage = $this->entityTypeManager->getStorage('animal_type');
    $entity = $storage->load($animal_type);

    $build = [
      '#theme' => 'animal_type_view',
      '#animal_type' => $entity,
    ];

    return $build;
  }

  /**
   * Deletes an animal type entity.
   *
   * @param int $animal_type
   *   The animal type entity ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function delete($animal_type) {
    $storage = $this->entityTypeManager->getStorage('animal_type');
    $entity = $storage->load($animal_type);
    $entity->delete();

    $this->messenger()->addStatus($this->t('The animal type has been deleted.'));

    return new RedirectResponse($this->url('entity.animal_type.collection'));
  }

}
