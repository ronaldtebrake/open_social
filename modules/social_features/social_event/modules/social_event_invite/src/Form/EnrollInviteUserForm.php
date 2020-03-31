<?php

namespace Drupal\social_event_invite\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\social_core\Form\InviteUserBaseForm;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\social_event\EventEnrollmentInterface;

/**
 * Class EnrollInviteForm.
 */
class EnrollInviteUserForm extends InviteUserBaseForm {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'enroll_invite_user_form';
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form =  parent::buildForm($form, $form_state);
    $nid = $this->routeMatch->getRawParameter('node');

    $form['event'] = [
      '#type' => 'hidden',
      '#value' => $nid,
    ];

    $form['name'] = [
      '#type' => 'social_enrollment_entity_autocomplete',
      '#selection_handler' => 'social',
      '#selection_settings' => [],
      '#target_type' => 'user',
      '#tags' => TRUE,
      '#description' => $this->t('To add multiple members, separate each member with a comma ( , ).'),
      '#title' => $this->t('Select members to add'),
      '#weight' => -1,
    ];

    $form['actions']['submit_cancel'] = array (
      '#type' => 'submit',
      '#weight' => 999,
      '#value' =>  $this->t('Back to events'),
      '#submit' => [[$this, 'cancelForm']],
      '#limit_validation_errors' => [],
    );

    return $form;
  }

  /**
   * Cancel form taking you back to an event.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('view.event_manage_enrollments.page_manage_enrollments', [
      'node' => $this->routeMatch->getRawParameter('node')
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $users = $form_state->getValue('entity_id_new');
    $nid = $form_state->getValue('event');

    foreach ($users as $uid => $target_id) {
      // Default values.
      $fields = [
        'field_event' => $nid,
        'field_enrollment_status' => '0',
        'field_request_or_invite_status' => EventEnrollmentInterface::INVITE_PENDING_REPLY,
        'user_id' => $uid,
        'field_account' => $uid,
      ];

      // Clear the cache.
      $tags = [];
      $tags[] = 'enrollment:' . $nid . '-' . $uid;
      $tags[] = 'event_content_list:entity:' . $uid;
      Cache::invalidateTags($tags);

      // Create a new enrollment for the event.
      $enrollment = EventEnrollment::create($fields);
      // In order for the notifications to be sent correctly we're updating the
      // owner here. The account is still linked to the actual enrollee.
      // The owner is always used as the actor.
      // @see activity_creator_message_insert().
      $enrollment->setOwnerId(\Drupal::currentUser()->id());
      $enrollment->save();
    }
  }
}
