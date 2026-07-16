<?php

declare(strict_types=1);

namespace Drupal\azure_storage_browser\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Azure Storage Browser.
 */
class AzureStorageBrowserSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'azure_storage_browser_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['azure_storage_browser.settings'];
  }

    protected function isOverridden(string $key): bool {
    $editable = $this->config('azure_storage_browser.settings');
    $withOverrides = $this->configFactory()->get('azure_storage_browser.settings');

    return $withOverrides->get($key) !== $editable->getOriginal($key, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('azure_storage_browser.settings');

    $form['azure_credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure Storage Credentials'),
      '#open' => TRUE,
    ];

    $form['azure_credentials']['azure_account_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage Account Name'),
      '#description' => $this->t('The Azure Storage account name (e.g. <code>mystorageaccount</code>).'),
      '#default_value' => $config->get('azure_account_name'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['azure_credentials']['azure_account_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Storage Account Key'),
      '#description' => $this->t(
        'The primary or secondary access key for the storage account. '
        . 'Leave blank to keep the existing key. '
        . 'Store this in <code>settings.php</code> via config overrides for production.'
      ),
      '#default_value' => $config->get('azure_account_key'),
      '#maxlength' => 512,
      '#attributes' => ['autocomplete' => 'off'],
    ];

    // Show a masked indicator when a key is already stored.
    if ($config->get('azure_account_key')) {
      $form['azure_credentials']['key_stored'] = [
        '#type' => 'item',
        '#markup' => $this->t('<em>A storage account key is currently saved. Enter a new value above to replace it.</em>'),
      ];
    }

    $form['blob_storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Blob Storage'),
      '#open' => TRUE,
    ];

    $form['blob_storage']['azure_container_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Container Name'),
      '#description' => $this->t('The name of the Azure Blob Storage container to list files from.'),
      '#default_value' => $config->get('azure_container_name'),
      '#maxlength' => 63,
    ];

    $form['blob_storage']['azure_blob_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Blob Name Prefix (optional)'),
      '#description' => $this->t(
        'Only list blobs whose name starts with this prefix. '
        . 'Leave blank to list the entire container, e.g. <code>backups/production/</code>.'
      ),
      '#default_value' => $config->get('azure_blob_prefix'),
      '#maxlength' => 1024,
    ];

    $form['file_share'] = [
      '#type' => 'details',
      '#title' => $this->t('File Share'),
      '#open' => TRUE,
    ];

    $form['file_share']['azure_share_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File Share Name'),
      '#description' => $this->t('The name of the Azure Files (classic file share) to list files from.'),
      '#default_value' => $config->get('azure_share_name'),
      '#maxlength' => 63,
    ];

    $form['file_share']['azure_directory_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory Path (optional)'),
      '#description' => $this->t(
        'Only list files under this directory path within the share. '
        . 'Subdirectories are listed recursively. Leave blank to list the entire share, '
        . 'e.g. <code>backups/production</code>.'
      ),
      '#default_value' => $config->get('azure_directory_path'),
      '#maxlength' => 1024,
    ];

    $form['azure_storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Filtering'),
      '#open' => TRUE,
    ];

    $form['azure_storage']['allowed_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed File Extensions'),
      '#description' => $this->t(
        'Comma-separated list of extensions to display (e.g. <code>bak,sql,zip,gz</code>). '
        . 'Leave blank to show all files.'
      ),
      '#default_value' => $config->get('allowed_extensions'),
      '#maxlength' => 512,
    ];

    $form['download'] = [
      '#type' => 'details',
      '#title' => $this->t('Download Link Settings'),
      '#open' => TRUE,
    ];

    $form['download']['sas_token_expiry_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('SAS Token Expiry (minutes)'),
      '#description' => $this->t(
        'How long a generated Shared Access Signature download URL remains valid. '
        . 'Minimum 1, maximum 10080 (one week).'
      ),
      '#default_value' => $config->get('sas_token_expiry_minutes') ?? 60,
      '#min' => 1,
      '#max' => 10080,
      '#required' => TRUE,
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display Options'),
      '#open' => FALSE,
    ];

    $form['display']['page_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page Title'),
      '#default_value' => $config->get('page_title') ?? 'Available Files',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['display']['show_file_size'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show file size column'),
      '#default_value' => $config->get('show_file_size') ?? TRUE,
    ];

    $form['display']['show_last_modified'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show last modified column'),
      '#default_value' => $config->get('show_last_modified') ?? TRUE,
    ];

    $this->disableOverriddenFields($form);

    return parent::buildForm($form, $form_state);
  }

  protected function disableOverriddenFields(array &$form): void {
    $overriddenConfig = $this->configFactory()->get('azure_storage_browser.settings');

    $map = [
      'azure_account_name' => ['azure_credentials', 'azure_account_name'],
      'azure_account_key' => ['azure_credentials', 'azure_account_key'],
      'azure_container_name' => ['blob_storage', 'azure_container_name'],
      'azure_blob_prefix' => ['blob_storage', 'azure_blob_prefix'],
      'azure_share_name' => ['file_share', 'azure_share_name'],
      'azure_directory_path' => ['file_share', 'azure_directory_path'],
      'allowed_extensions' => ['azure_storage', 'allowed_extensions'],
      'sas_token_expiry_minutes' => ['download', 'sas_token_expiry_minutes'],
      'page_title' => ['display', 'page_title'],
      'show_file_size' => ['display', 'show_file_size'],
      'show_last_modified' => ['display', 'show_last_modified'],
    ];

    foreach ($map as $key => [$group, $element]) {
      if (!isset($form[$group][$element]) || !$this->isOverridden($key)) {
        continue;
      }

      $form[$group][$element]['#disabled'] = TRUE;
      $override_notice = $this->t('This value is set in settings.php and cannot be changed here.');

      if ($key == 'azure_account_key') {
        $form[$group][$element]['#default_value'] = $key;
      }
      else {
        $form[$group][$element]['#default_value'] = $overriddenConfig->get($key);
      }

      $existing = $form[$group][$element]['#description'] ?? NULL;
      $form[$group][$element]['#description'] = $existing
        ? $this->t('@existing<br><strong>@notice</strong>', ['@existing' => $existing, '@notice' => $override_notice])
        : $this->t('<strong>@notice</strong>', ['@notice'=> $override_notice]);
    }
  }
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($this->isOverridden('sas_token_expiry_minutes')) {
      return;
    }

    $expiry = (int) $form_state->getValue('sas_token_expiry_minutes');
    if ($expiry < 1 || $expiry > 10080) {
      $form_state->setErrorByName(
        'sas_token_expiry_minutes',
        $this->t('SAS token expiry must be between 1 and 10080 minutes.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('azure_storage_browser.settings');

    $set = function(string $key, $value) use ($config): void {
      if (!$this->isOverridden($key)) {
        $config->set($key, $value);
      }
    };

    $set('azure_account_name', trim($form_state->getValue('azure_account_name')));
    $set('azure_container_name', trim($form_state->getValue('azure_container_name')));
    $set('azure_blob_prefix', trim($form_state->getValue('azure_blob_prefix'), '/'));
    $set('azure_share_name', trim($form_state->getValue('azure_share_name')));
    $set('azure_directory_path', trim($form_state->getValue('azure_directory_path'), '/'));
    $set('allowed_extensions', trim($form_state->getValue('allowed_extensions')));
    $set('sas_token_expiry_minutes', (int) $form_state->getValue('sas_token_expiry_minutes'));
    $set('page_title', trim($form_state->getValue('page_title')));
    $set('show_file_size', (bool) $form_state->getValue('show_file_size'));
    $set('show_last_modified', (bool) $form_state->getValue('show_last_modified'));

    // Only overwrite the stored key when a new one is provided.
    $newKey = trim($form_state->getValue('azure_account_key'));
    if ($newKey !== '' && !$this->isOverridden('azure_account_key')) {
      $config->set('azure_account_key', $newKey);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
