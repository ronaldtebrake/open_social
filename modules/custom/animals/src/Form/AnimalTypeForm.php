<?php

namespace Drupal\animals\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the animal type entity edit forms.
 */
class AnimalTypeForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /* @var \Drupal\animals\Entity\AnimalType $entity */
    $form = parent::buildForm($form, $form_state);

    $form['dog_breed'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dog Breed'),
      '#required' => TRUE,
      '#default_value' => $this->entity->get('dog_breed')->value,
    ];

    $form['size'] = [
      '#type' => 'select',
      '#title' => $this->t('Size'),
      '#required' => TRUE,
      '#options' => [
        'small' => $this->t('Small'),
        'medium' => $this->t('Medium'),
        'large' => $this->t('Large'),
      ],
      '#default_value' => $this->entity->get('size')->value,
    ];

    $form['temperament'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Temperament'),
      '#required' => TRUE,
      '#default_value' => $this->entity->get('temperament')->value,
    ];

    $form['lifespan'] = [
      '#type' => 'number',
      '#title' => $this->t('Lifespan'),
      '#required' => TRUE,
      '#default_value' => $this->entity->get('lifespan')->value,
    ];

    $form['origin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Origin'),
      '#required' => TRUE,
      '#default_value' => $this->entity->get('origin')->value,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#default_value' => $this->entity->get('description')->value,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('Created the %label Animal Type.', [
          '%label' => $this->entity->label(),
        ]));
        break;

      default:
        $this->messenger()->addStatus($this->t('Saved the %label Animal Type.', [
          '%label' => $this->entity->label(),
        ]));
    }

    $form_state->setRedirect('entity.animal_type.canonical', ['animal_type' => $this->entity->id()]);
  }

}
