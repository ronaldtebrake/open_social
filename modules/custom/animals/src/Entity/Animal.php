<?php

namespace Drupal\animals\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Defines the Animal entity.
 *
 * @ContentEntityType(
 *   id = "animal",
 *   label = @Translation("Animal"),
 *   base_table = "animal",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid"
 *   },
 *   field_ui_base_route = "entity.animal.collection"
 * )
 */
class Animal extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Type'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'reptile' => 'Reptile',
          'bird' => 'Bird',
          'fish' => 'Fish',
          'amphibian' => 'Amphibian',
          'invertebrate' => 'Invertebrate',
        ],
      ]);

    return $fields;
  }

}
