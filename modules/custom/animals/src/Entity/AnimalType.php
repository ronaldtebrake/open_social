<?php

namespace Drupal\animals\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Defines the AnimalType entity.
 *
 * @ContentEntityType(
 *   id = "animal_type",
 *   label = @Translation("Animal Type"),
 *   base_table = "animal_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid"
 *   },
 *   field_ui_base_route = "entity.animal_type.collection"
 * )
 */
class AnimalType extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['dog_breed'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Dog Breed'))
      ->setRequired(TRUE);

    $fields['size'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Size'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'small' => 'Small',
          'medium' => 'Medium',
          'large' => 'Large',
        ],
      ]);

    $fields['temperament'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Temperament'))
      ->setRequired(TRUE);

    $fields['lifespan'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Lifespan'))
      ->setRequired(TRUE);

    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Origin'))
      ->setRequired(TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setRequired(TRUE);

    return $fields;
  }

}
