<?php

namespace Drupal\animals\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class AnimalController.
 *
 * Provides route responses for the animal entity.
 */
class AnimalController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new AnimalController.
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
   * Lists all animal entities.
   *
   * @return array
   *   A render array.
   */
  public function list() {
    $storage = $this->entityTypeManager->getStorage('animal');
    $entities = $storage->loadMultiple();

    $build = [
      '#theme' => 'animal_list',
      '#animals' => $entities,
    ];

    return $build;
  }

  /**
   * Views an animal entity.
   *
   * @param int $animal
   *   The animal entity ID.
   *
   * @return array
   *   A render array.
   */
  public function view($animal) {
    $storage = $this->entityTypeManager->getStorage('animal');
    $entity = $storage->load($animal);

    $build = [
      '#theme' => 'animal_view',
      '#animal' => $entity,
    ];

    return $build;
  }

  /**
   * Deletes an animal entity.
   *
   * @param int $animal
   *   The animal entity ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function delete($animal) {
    $storage = $this->entityTypeManager->getStorage('animal');
    $entity = $storage->load($animal);
    $entity->delete();

    $this->messenger()->addStatus($this->t('The animal has been deleted.'));

    return new RedirectResponse($this->url('entity.animal.collection'));
  }

}
