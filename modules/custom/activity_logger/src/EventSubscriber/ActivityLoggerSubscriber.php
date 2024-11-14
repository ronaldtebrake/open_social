<?php

namespace Drupal\activity_logger\EventSubscriber;

use Drupal\hux\Event\FormAlterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for Activity Logger module.
 */
class ActivityLoggerSubscriber implements EventSubscriberInterface {

  /**
   * Handles form alter events.
   */
  public function onFormAlter(FormAlterEvent $event) {
    $form = &$event->getForm();
    $form_state = $event->getFormState();
    $form_id = $event->getFormId();
    if ($form_id === 'message_template_form') {
      activity_logger_form_message_template_form_alter($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      FormAlterEvent::ALTER => 'onFormAlter',
    ];
  }

}
