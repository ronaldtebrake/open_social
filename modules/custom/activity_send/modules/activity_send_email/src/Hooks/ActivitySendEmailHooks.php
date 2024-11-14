<?php

declare(strict_types=1);

namespace Drupal\activity_send_email\Hooks;

use Drupal\hux\Attribute\Alter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

final class ActivitySendEmailHooks {

  #[Alter('form_user_form')]
  public function activitySendEmailFormUserFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\social_user\Entity\User $account */
    $account = $form_state->getFormObject()->getEntity();

    // Only expose these settings to existing users so it's not shown on the
    // user create form.
    if ($account->isNew()) {
      return;
    }

    $form['email_notifications'] = [
      '#type' => 'fieldset',
      '#title' => new TranslatableMarkup('Email notifications'),
      '#description' => new TranslatableMarkup('For each email notification below, you can choose to turn it off, receive it immediately or in a daily or weekly digest. Email notifications will only be sent when you are not active in the platform.'),
      '#tree' => TRUE,
      '#attributes' => [
        'class' => [
          'form-horizontal',
          'form-email-notification',
        ],
      ],
    ];

    $items = _activity_send_email_default_template_items();

    $email_message_templates = EmailActivityDestination::getSendEmailMessageTemplates();

    // Give other modules the chance to add their own email notifications or
    // change the title or order of the e-mail notifications on this form.
    // Copy templates so that they can't be altered (arrays are assigned by copy).
    $context = $email_message_templates;
    \Drupal::moduleHandler()->alter('activity_send_email_notifications', $items, $context);

    // Sort a list of email frequencies by weight.
    $email_frequencies = sort_email_frequency_options();

    $notification_options = [];

    // Place the sorted data in an actual form option.
    foreach ($email_frequencies as $option) {
      $notification_options[$option['id']] = $option['name'];
    }

    $user_email_settings = EmailActivityDestination::getSendEmailUserSettings($account);

    foreach ($items as $item_id => $item) {
      // Don't render the fieldset when there are no templates.
      if (empty($item['templates'])) {
        continue;
      }

      $form['email_notifications'][$item_id] = [
        '#type' => 'fieldset',
        '#title' => [
          'text' => [
            '#markup' => $item['title'],
          ],
          'icon' => [
            '#markup' => '<svg class="icon icon-expand_more"><use xlink:href="#icon-expand_more" /></svg>',
            '#allowed_tags' => ['svg', 'use'],
          ],
        ],
        '#attributes' => [
          'class' => ['form-fieldset'],
        ],
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#open' => TRUE,
      ];

      $mail_configs = Drupal::config('social_swiftmail.settings');
      $template_frequencies = $mail_configs->get('template_frequencies') ?: [];

      foreach ($item['templates'] as $template) {
        $default_frequency = $template_frequencies[$template] ?? FREQUENCY_IMMEDIATELY;
        $form['email_notifications'][$item_id][$template] = [
          '#type' => 'select',
          '#title' => $email_message_templates[$template],
          '#options' => $notification_options,
          '#default_value' => $user_email_settings[$template] ?? $default_frequency,
        ];
      }
    }

    // Submit function to save send email settings.
    $form['actions']['submit']['#submit'][] = '_activity_send_email_form_user_form_submit';

    // Attach library.
    $form['#attached']['library'][] = 'activity_send_email/admin';
  }
}
