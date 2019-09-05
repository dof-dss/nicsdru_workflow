<?php

namespace Drupal\nicsdru_workflow\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

/**
 * Implements admin form to allow setting of audit text.
 */
class AuditSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'nicsdru_workflow.auditsettings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'audit_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('nicsdru_workflow.auditsettings');

    $form['audit_button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Audit button text'),
      '#description' => $this->t('Text to be displayed on the button that the editor presses to audit the content.'),
      '#default_value' => $config->get('audit_button_text'),
    ];

    $form['audit_button_hover_text'] = [
      '#type' => 'textfield',
      '#size' => 130,
      '#title' => $this->t('Audit button hover text'),
      '#description' => $this->t('Text to be displayed when the editor hovers their mouse over the audit button.'),
      '#default_value' => $config->get('audit_button_hover_text'),
    ];

    $form['audit_confirmation_text'] = [
      '#type' => 'textfield',
      '#size' => 130,
      '#title' => $this->t('Audit confirmation text'),
      '#description' => $this->t('Ask the editor to confirm that they have audited the content.'),
      '#default_value' => $config->get('audit_confirmation_text'),
    ];

    // Get a list of all content types.
    $options = [];
    $all_content_types = NodeType::loadMultiple();
    foreach ($all_content_types as $machine_name => $content_type) {
      if (!in_array($machine_name, ['mas_rss', 'webform'])) {
        $options[$machine_name] = $content_type->label();
      }
    }

    $form['audit_content_types'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#title' => $this->t('Content types to be audited'),
      '#default_value'=> $config->get('audit_content_types')
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Check to see if any new content types have been selected.
    $config = $this->config('nicsdru_workflow.auditsettings');
    $old_content_type_list = $config->get('audit_content_types');
    $new_content_type_list = $form_state->getValue('audit_content_types');
    // Find content types that have just been added.
    foreach($new_content_type_list as $this_type) {
      if (!$this_type) continue;
      if (!$old_content_type_list[$this_type]) {
        $this->addAuditField($this_type);
      }
    }
    // Find content types that have just been removed.
    foreach($old_content_type_list as $this_type) {
      if (!$this_type) continue;
      if (!$new_content_type_list[$this_type]) {
        $this->removeAuditField($this_type);
      }
    }

    $this->config('nicsdru_workflow.auditsettings')
      ->set('audit_button_text', $form_state->getValue('audit_button_text'))
      ->set('audit_button_hover_text', $form_state->getValue('audit_button_hover_text'))
      ->set('audit_confirmation_text', $form_state->getValue('audit_confirmation_text'))
      ->set('audit_content_types', $form_state->getValue('audit_content_types'))
      ->save();
  }

  public function removeAuditField($type) {
    // Remove audit field from this content type.
    $field = FieldConfig::loadByName('node', $type, 'field_next_audit_due');
    if (!empty($field)) {
      $field->delete();
    }

    // Log it.
    $message = "Content auditing disabled for " . $type;
    \Drupal::logger('nicsdru_workflow')->notice(t($message));
  }

  public function addAuditField($type) {
    // Add an audit field to the content type.
    $field_storage = FieldStorageConfig::loadByName('node', 'field_next_audit_due');
    $field = FieldConfig::loadByName('node', $type, 'field_next_audit_due');
    if (empty($field)) {
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => $type,
        'label' => 'Next audit due',
        'settings' => ['display_summary' => TRUE],
        'description' => t('The date when this item is due for audit'),
      ]);
      $field->save();

      $display_repository = \Drupal::service('entity_display.repository');

      // Assign widget settings for the default form mode.
      if (method_exists($display_repository, 'getFormDisplay')) {
        $form_display = $display_repository->getFormDisplay('node', $type);
        if (isset($form_display)) {
          $form_display->setComponent('field_next_audit_due', [
            'type' => 'datetime_default',
          ])->save();
        }
      }

      // Assign display settings for the 'default' and 'teaser' view mode.
      if (method_exists($display_repository, 'getViewDisplay')) {
        $display_repository->getViewDisplay('node', $type)
          ->setComponent('field_next_audit_due', [
            'label' => 'hidden',
            'type' => 'text_default',
          ])
          ->save();
      }

      // Log it.
      $message = "Content auditing enabled for " . $type;
      \Drupal::logger('nicsdru_workflow')->notice(t($message));
    }
  }

}
