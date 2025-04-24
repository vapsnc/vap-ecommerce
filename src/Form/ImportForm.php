<?php

namespace Drupal\vap_icecat_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;

class ImportForm extends FormBase {
  public function getFormId() {
    return 'vap_icecat_import_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $directory = 'public://import/';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $files = \Drupal::service('file_system')->scanDirectory($directory, '/.*\.csv$/');

    $options = [];
    foreach ($files as $uri => $file) {
      $filename = basename($uri);
      $options[$filename] = $filename;
    }

    $form['csv_file'] = [
      '#type' => 'select',
      '#title' => $this->t('Seleziona file CSV'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Importa prodotti'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $filename = $form_state->getValue('csv_file');
    $file_path = 'public://import/' . $filename;
    $real_path = \Drupal::service('file_system')->realpath($file_path);

    if (!file_exists($real_path)) {
      \Drupal::messenger()->addError($this->t('File non trovato.'));
      return;
    }

    \Drupal::messenger()->addStatus($this->t('File trovato: @file', ['@file' => $real_path]));

    $handle = fopen($real_path, 'r');
    if (!$handle) {
      \Drupal::messenger()->addError($this->t('Errore nell\'apertura del file.'));
      return;
    }

    $header = fgetcsv($handle, 4000, ';');
    $normalized_header = [];
    foreach ($header as $key) {
      $normalized_header[] = trim(str_replace("\xEF\xBB\xBF", '', $key));
    }

    $preview_data = [];
    while (($data = fgetcsv($handle, 4000, ';')) !== FALSE) {
      if (count($data) !== count($normalized_header)) {
        continue;
      }

      $row = array_combine($normalized_header, $data);

      $ean = trim($row['EAN'] ?? '');
      $descrizione = trim($row['Descrizione'] ?? '');
      $categoria = trim($row['Categoria'] ?? '');
      $prezzo_raw = trim($row['Listino 1'] ?? '');

      $prezzo = (float) str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $prezzo_raw));
      if (!$descrizione || !$prezzo) {
        continue;
      }

      // 🔍 Richiesta a UPCitemdb
      $api_url = 'https://api.upcitemdb.com/prod/trial/lookup?upc=' . urlencode($ean);
      $context = stream_context_create(['http' => ['timeout' => 5]]);
      $response = file_get_contents($api_url, false, $context);

      $title = $brand = $image = $description = '-';
      if ($response) {
        $json = json_decode($response, true);
        if (!empty($json['items'][0])) {
          $item = $json['items'][0];
          $title = $item['title'] ?? '-';
          $brand = $item['brand'] ?? '-';
          $image = $item['images'][0] ?? '-';
          $description = $item['description'] ?? '-';
        }
      }

      $preview_data[] = [
        'ean' => $ean,
        'descrizione' => $descrizione,
        'prezzo' => $prezzo,
        'categoria' => $categoria,
        'titolo' => $title,
        'marca' => $brand,
        'immagine' => $image,
        'descrizione_api' => $description,
      ];
    }

    fclose($handle);
    \Drupal::state()->set('vap_icecat_import.preview_data', $preview_data);
    \Drupal::messenger()->addStatus($this->t('Importazione completata.'));
    $form_state->setRedirect('vap_icecat_import.import_preview');
  }
}
