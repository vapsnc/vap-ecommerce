<?php

namespace Drupal\vap_icecat_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;

class ImportPreviewForm extends FormBase {

  public function getFormId() {
    return 'vap_icecat_import_preview_form';
  }

  protected function getAllSku() {
    $query = \Drupal::entityQuery('commerce_product_variation')
      ->accessCheck(FALSE)
      ->condition('type', 'default');
    $variation_ids = $query->execute();
    
    $skus = [];
    if ($variation_ids) {
      $variations = \Drupal::entityTypeManager()
        ->getStorage('commerce_product_variation')
        ->loadMultiple($variation_ids);
      foreach ($variations as $variation) {
        $skus[] = $variation->getSku();
      }
    }
    return $skus;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $preview_data = \Drupal::state()->get('vap_icecat_import.preview_data', []);
    if (empty($preview_data)) {
      $form['empty'] = [
        '#markup' => '<div class="messages warning">Nessun dato da importare.</div>',
      ];
      return $form;
    }

    // CSS e libreria JS
    $form['#attached']['library'][] = 'vap_icecat_import/icecat_checker';
    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#attached']['library'][] = 'vap_icecat_import/seleziona_tutto';

    $esistenti = $this->getAllSku();

    // Checkbox master sopra la tabella
    $form['select_all_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['select-all-container']],
    ];
    $form['select_all_container']['select_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Seleziona/Deseleziona tutto'),
      '#attributes' => [
        'id' => 'select-all-checkbox',
        'class' => ['select-all-checkbox'],
      ],
      '#default_value' => TRUE,
    ];

    // Intestazione della tabella
    $header = [
      $this->t('Importa/Aggiorna'),
      $this->t('EAN'),
      'Descrizione', 'Categoria', 'Sottocategoria', 'Prezzo', 'Titolo API', 'Marca', 'Immagine', 'Descrizione API', 'Note'
    ];

    $form['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#sticky' => TRUE,
      '#empty' => $this->t('Nessun prodotto da importare.'),
      '#attributes' => ['class' => ['import-preview-table']],
    ];

    foreach ($preview_data as $k => $item) {
      $is_existing = in_array($item['ean'], $esistenti);
      $row_style = $is_existing ? 'background: #fff3cd;' : '';

      $form['table'][$k]['importa'] = [
        '#type' => 'checkbox',
        '#title' => $is_existing ? $this->t('Aggiorna') : $this->t('Importa'),
        '#default_value' => TRUE,
        '#return_value' => 1,
        '#attributes' => [
          'class' => ['import-checkbox'],
          'style' => $row_style,
        ],
      ];

      foreach (['ean', 'descrizione', 'categoria', 'sottocategoria', 'prezzo', 'titolo', 'marca', 'immagine', 'descrizione_api', 'note'] as $field) {
        $form['table'][$k][$field] = [
          '#markup' => ($field == 'immagine' && $item[$field] && $item[$field] !== '-') ? 
            '<img src="' . htmlspecialchars($item[$field]) . '" style="max-width:80px;">' : 
            ($item[$field] ?? ''),
          '#attributes' => ['style' => $row_style],
        ];
      }
    }

    // Pulsante azioni
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Esegui importazione/aggiornamento'),
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $preview_data = \Drupal::state()->get('vap_icecat_import.preview_data', []);
    $import_values = $form_state->getValue(['table']) ?? [];
    if (!is_array($import_values)) {
      $import_values = [];
    }

    if (empty($import_values)) {
      \Drupal::messenger()->addWarning('Nessun prodotto selezionato.');
      return;
    }
    
    $importati = 0;
    $errori = [];

    foreach ($import_values as $k => $row) {
      if (empty($row['importa'])) {
        continue;
      }
      if (empty($preview_data[$k]['ean'])) {
        $errori[] = "Riga $k: EAN mancante";
        continue;
      }

      $variations = \Drupal::entityTypeManager()
        ->getStorage('commerce_product_variation')
        ->loadByProperties(['sku' => $preview_data[$k]['ean']]);

      if (!empty($variations)) {
        // AGGIORNA
        $variation = reset($variations);
        $variation->setTitle($preview_data[$k]['titolo']);
        $variation->set('price', [
          'number' => $preview_data[$k]['prezzo'],
          'currency_code' => 'EUR',
        ]);
        // Eventuale update immagine
        $variation->save();

        // Aggiorna Product usando l'ID della variation
        $products = \Drupal::entityTypeManager()
          ->getStorage('commerce_product')
          ->loadByProperties([
            'variations' => $variation->id(),
          ]);
        if ($products) {
          $product = reset($products);
          $product->setTitle($preview_data[$k]['titolo'] ?: $preview_data[$k]['descrizione']);
          $product->save();
        }
        $importati++;
        continue;
      }

      try {
        // Scarica immagine, se presente
        $image_fid = NULL;
        if (!empty($preview_data[$k]['immagine']) && $preview_data[$k]['immagine'] !== '-') {
          $image_fid = vap_import_download_image(
            $preview_data[$k]['immagine'],
            $preview_data[$k]['marca'],
            $preview_data[$k]['titolo'] ?: $preview_data[$k]['descrizione']
          );
        }

        $variation_fields = [
          'type' => 'default',
          'sku' => $preview_data[$k]['ean'],
          'price' => [
            'number' => $preview_data[$k]['prezzo'],
            'currency_code' => 'EUR',
          ],
          'title' => $preview_data[$k]['titolo'],
        ];

        if ($image_fid) {
          $variation_fields['field_images'] = [
            ['target_id' => $image_fid, 'alt' => $preview_data[$k]['titolo'] ?: $preview_data[$k]['descrizione']]
          ];
        }

        $variation = ProductVariation::create($variation_fields);
        $variation->save();

        // Crea product
        $product = Product::create([
          'type' => 'default',
          'title' => $preview_data[$k]['titolo'] ?: $preview_data[$k]['descrizione'],
          'sku' => $preview_data[$k]['ean'],
          'variations' => [$variation],
        ]);
        $product->save();

        $importati++;
      }
      catch (\Exception $e) {
        $errori[] = "Errore su EAN " . $preview_data[$k]['ean'] . ": " . $e->getMessage();
      }
    }

    if ($importati > 0) {
      \Drupal::messenger()->addStatus($this->t('Importati @num prodotti!', ['@num' => $importati]));
    }
    if ($errori) {
      foreach ($errori as $msg) {
        \Drupal::messenger()->addError($msg);
      }
    }

    $form_state->setRebuild();
  }
}

/**
 * Scarica una immagine da URL, la salva su public://product_images/ e ritorna il file ID.
 */
function vap_import_download_image($url, $brand, $model) {
  if ($url == '-' || !$url) {
    return NULL;
  }
  $brand = preg_replace('/\W+/', '-', strtolower($brand));
  $model = preg_replace('/\W+/', '-', strtolower($model));
  $filename = $brand . '-' . $model . '.jpg';
  $directory = 'public://product_images';
  \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

  // Scarica il contenuto
  $image_data = @file_get_contents($url);
  if (!$image_data) {
    return NULL;
  }
  $destination = $directory . '/' . $filename;
  $file = \Drupal\file\Entity\File::create([
    'uri' => $destination,
  ]);
  file_put_contents($destination, $image_data);
  $file->setPermanent();
  $file->save();
  if ($file) {
    return $file->id();
  }
  return NULL;
}
