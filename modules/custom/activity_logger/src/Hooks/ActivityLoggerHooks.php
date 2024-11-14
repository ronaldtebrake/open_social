<?php

declare(strict_types=1);

namespace Drupal\activity_logger\Hooks;

use Drupal\hux\Attribute\Alter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\message\MessageTemplateInterface;

final class ActivityLoggerHooks {

  #[Alter('form_message_template_form')]
  public function activityLoggerFormMessageTemplateFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\message\MessageTemplateInterface $message_template */
    $message_template = $form_state->getFormObject()->getEntity();

    $form['email_subject'] = [
      '#title' => t('Subject line for email notifications'),
      '#required' => FALSE,
      '#type' => 'textfield',
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'email_subject', t('Notification from [site:name]')),
      '#description' => t('The subject used when sending email notifications.'),
    ];

    $wrapper_id = 'edit-activity-entity-condition-ajax-wrapper';

    $config_entity_bundles = $this->_activity_logger_get_content_entities();
    $form['activity_bundle_entities'] = [
      '#type' => 'select',
      '#required' => TRUE,
      '#multiple' => TRUE,
      '#title' => t('The entities that are affected for this message'),
      '#description' => t('Select a entity bundle type to for this message.'),
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'activity_bundle_entities', NULL),
      '#options' => $config_entity_bundles,
      '#ajax' => [
        'callback' => [$this, '_activity_logger_form_message_template_entity_ajax_callback'],
        'wrapper' => $wrapper_id,
      ],
    ];

    $form['activity_entity_condition_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $wrapper_id],
    ];

    $activity_bundle_entities = $form_state->getValue('activity_bundle_entities');
    if (empty($activity_bundle_entities)) {
      $activity_bundle_entities = $message_template->getThirdPartySetting('activity_logger', 'activity_bundle_entities', []);
    }

    $activity_entity_condition_options = \Drupal::service('plugin.manager.activity_entity_condition.processor');
    $activity_entity_condition_options = $activity_entity_condition_options->getOptionsList($activity_bundle_entities);

    if (empty($activity_entity_condition_options)) {
      $activity_entity_condition_value = NULL;
    }
    else {
      $activity_entity_condition_value = $message_template->getThirdPartySetting('activity_logger', 'activity_entity_condition', NULL);
    }

    $form['activity_entity_condition_wrapper']['activity_entity_condition'] = [
      '#type' => 'select',
      '#title' => t('The entity condition that are affected for this message'),
      '#description' => t('Select a entity condition for this message.'),
      '#default_value' => $activity_entity_condition_value,
      '#options' => $activity_entity_condition_options,
      '#access' => !empty($activity_entity_condition_options),
    ];

    $activity_actions = \Drupal::service('plugin.manager.activity_action.processor');
    $activity_actions = $activity_actions->getOptionsList();

    $form['activity_action'] = [
      '#type' => 'select',
      '#required' => TRUE,
      '#title' => t('The activity actions for this message'),
      '#description' => t('Select a action for when to display this message.'),
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'activity_action', NULL),
      '#options' => $activity_actions,
    ];

    $activity_recipient_manager = \Drupal::service('plugin.manager.activity_context.processor');
    $context_options = $activity_recipient_manager->getOptionsList();

    $form['activity_context'] = [
      '#type' => 'select',
      '#required' => TRUE,
      '#title' => t('The activity context for this message'),
      '#description' => t('Select a context where to display this message.'),
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'activity_context', NULL),
      '#options' => $context_options,
    ];

    $activity_recipient_manager = \Drupal::service('plugin.manager.activity_destination.processor');
    $destination_options = $activity_recipient_manager->getOptionsList();

    $form['activity_destinations'] = [
      '#type' => 'select',
      '#title' => t('The activity destinations for this message'),
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#description' => t('Select destinations where to display this message.'),
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'activity_destinations', NULL),
      // @todo activity_creator allowed_values function overlap (should be plugin)
      '#options' => $destination_options,
    ];

    $form['activity_create_direct'] = [
      '#type' => 'checkbox',
      '#required' => FALSE,
      '#title' => t('Create activity items direct instead of in Queue'),
      '#description' => t('Select if items should be created directly instead of in the queue. Warning: performance implications!'),
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'activity_create_direct', NULL),
    ];

    $form['#entity_builders'][] = [$this, 'activity_logger_form_message_template_form_builder'];
    $form['activity_aggregate'] = [
      '#type' => 'checkbox',
      '#required' => FALSE,
      '#title' => t('Aggregate activity'),
      '#description' => t('Select if items should be aggregated instead of displaying separately.'),
      '#default_value' => $message_template->getThirdPartySetting('activity_logger', 'activity_aggregate', NULL),
    ];

    // Add titles to output text fields for clarity.
    if (isset($form['text'][0])) {
      $form['text'][0]['#title'] = t('Output text for normal activities in activity stream');
    }
    if (isset($form['text'][1])) {
      $form['text'][1]['#title'] = t('Output text for aggregated items in activity stream');
    }
    if (isset($form['text'][2])) {
      $form['text'][2]['#title'] = t('Output text for email notifications');
    }
  }

  private function _activity_logger_get_content_entities(): array {
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $options = [];
    foreach ($entity_type_manager->getDefinitions() as $entity_id => $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface
        && $entity_id !== 'activity'
        && $entity_id !== 'message'
        && $entity_id !== 'message_template') {
        $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');
        $config_entity_bundles = $entity_type_bundle_info->getBundleInfo($entity_type->id());
        foreach ($config_entity_bundles as $key => $value) {
          // Dot character in key names is not allowed in config, so we use "-".
          $options[$entity_id . '-' . $key] = $entity_type->getLabel() . ': ' . $value['label'];
        }
      }
    }
    return $options;
  }

  public function activity_logger_form_message_template_form_builder($entity_type, MessageTemplateInterface $message_template, &$form, FormStateInterface $form_state): void {
    $message_template->setThirdPartySetting('activity_logger', 'activity_bundle_entities', $form_state->getValue('activity_bundle_entities'));
    $message_template->setThirdPartySetting('activity_logger', 'activity_action', $form_state->getValue('activity_action'));
    $message_template->setThirdPartySetting('activity_logger', 'activity_context', $form_state->getValue('activity_context'));
    $message_template->setThirdPartySetting('activity_logger', 'activity_destinations', $form_state->getValue('activity_destinations'));
    $message_template->setThirdPartySetting('activity_logger', 'activity_create_direct', $form_state->getValue('activity_create_direct'));
    $message_template->setThirdPartySetting('activity_logger', 'activity_aggregate', $form_state->getValue('activity_aggregate'));
    $message_template->setThirdPartySetting('activity_logger', 'activity_entity_condition', $form_state->getValue('activity_entity_condition'));
    $message_template->setThirdPartySetting('activity_logger', 'email_subject', $form_state->getValue('email_subject'));
  }

  public function _activity_logger_form_message_template_entity_ajax_callback(array &$form, FormStateInterface $form_state): array {
    return $form['activity_entity_condition_wrapper'];
  }
}
