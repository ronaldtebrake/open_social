<?php

namespace Drupal\animals\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the animal entity edit forms.
 */
class AnimalForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /* @var \Drupal\animals\Entity\Animal $entity */
    $form = parent::buildForm($form, $form_state);

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#default_value' => $this->entity->get('name')->value,
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#required' => TRUE,
      '#options' => [
        'reptile' => $this->t('Reptile'),
        'bird' => $this->t('Bird'),
        'fish' => $this->t('Fish'),
        'amphibian' => $this->t('Amphibian'),
        'invertebrate' => $this->t('Invertebrate'),
      ],
      '#default_value' => $this->entity->get('type')->value,
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
        $this->messenger()->addStatus($this->t('Created the %label Animal.', [
          '%label' => $this->entity->label(),
        ]));
        break;

      default:
        $this->messenger()->addStatus($this->t('Saved the %label Animal.', [
          '%label' => $this->entity->label(),
        ]));
    }

    $form_state->setRedirect('entity.animal.canonical', ['animal' => $this->entity->id()]);
  }

}
