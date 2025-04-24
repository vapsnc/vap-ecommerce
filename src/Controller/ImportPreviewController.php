<?php

namespace Drupal\vap_icecat_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;

/**
 * Controller per la preview dei prodotti e l'import selettivo su Commerce.
 */
class ImportPreviewController extends ControllerBase {

  public function preview() {
    $preview_data = \Drupal::state()->get('vap_icecat_import.preview_data', []);

    // Crea una form "a mano" (senza extender FormBase per stare nel controller)
    $form = [
      '#title' => $this->t('Preview prodotti da importare'),
      '#method' => 'post',
      '#attributes' => ['enctype' => 'multipart/form-data'],
      '#theme' => 'vap_icecat_import_preview', // opzionale: puoi usare table classica
      'products' => [
        '#type' => 'table',
        '#header' => [
          ['data' => $this->t('Importa'), 'class' => ['checkbox-col']],
          $this->t('EAN'),
          $this->t('Descrizione'),
          $this->t('Prezzo'),
          $this->t('Categoria'),
          $this->t('Titolo'),
          $this->t('Marca'),
          $this->t('Descrizione lunga'),
          $this->t('Immagine'),
        ],
        '#empty' => $this->t('Nessun prodotto da importare.'),
      ],
      'actions' => [
        '#type' => 'actions',
      ],
    ];

    foreach ($preview_data as $k => $item) {
      $form['products'][$k]['import'] = [
        '#type' => 'checkbox',
        '#default_value' => TRUE,
      ];
      $form['products'][$k]['ean'] = [
        '#markup' => $item['ean'],
      ];
      $form['products'][$k]['descrizione'] = [
        '#markup' => $item['descrizione'],
      ];
      $form['products'][$k]['prezzo'] = [
        '#markup' => $item['prezzo'],
      ];
      $form['products'][$k]['categoria'] = [
        '#markup' => $item['categoria'],
      ];
      $form['products'][$k]['titolo'] = [
        '#markup' => $item['titolo'],
      ];
      $form['products'][$k]['marca'] = [
        '#markup' => $item['marca'],
      ];
      $form['products'][$k]['descrizione_api'] = [
        '#markup' => $item['descrizione_api'],
      ];
      $form['products'][$k]['immagine'] = [
        '#markup' => $item['immagine'] !== '-' ? '<img src="' . $item['immagine'] . '" style="max-width:64px;">' : '-',
      ];
    }

    // Checkbox "seleziona tutto" (aggiungi js lato client, opzionale)
    $form['actions']['submit_import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Importa selezionati'),
      '#submit' => [[$this, 'importSelectedProducts']],
    ];

    return $form;
  }

  /**
   * Submit callback per importazione prodotti selezionati.
   */
  public function importSelectedProducts(array &$form, FormStateInterface $form_state) {
    $preview_data = \Drupal::state()->get('vap_icecat_import.preview_data', []);
    $values = $form_state->getValue('products');
    $importati = 0;
    $errori = [];

    foreach ($values as $k => $row) {
      if (empty($row['import'])) {
        continue; // Salta non selezionati
      }
      if (empty($preview_data[$k]['ean'])) {
        $errori[] = "Riga $k: EAN mancante";
        continue;
      }

      // Controlla se lo SKU/EAN esiste già
      $esistenti = \Drupal::entityTypeManager()
        ->getStorage('commerce_product')
        ->loadByProperties(['sku' => $preview_data[$k]['ean']]);
      if (!empty($esistenti)) {
        $errori[] = "EAN già presente: " . $preview_data[$k]['ean'];
        continue;
      }

      try {
        // Crea product variation (minimo: SKU, prezzo, titolo)
        $variation = ProductVariation::create([
          'type' => 'default',
          'sku' => $preview_data[$k]['ean'],
          'price' => [
            'number' => $preview_data[$k]['prezzo'],
            'currency_code' => 'EUR', // Adatta se serve
          ],
          'title' => $preview_data[$k]['titolo'],
        ]);
        $variation->save();

        // Crea product principale
        $product = Product::create([
          'type' => 'default',
          'title' => $preview_data[$k]['titolo'] ?: $preview_data[$k]['descrizione'],
          'sku' => $preview_data[$k]['ean'],
          'variations' => [$variation],
          // Mappa altri campi custom se servono (es: brand, descrizione_api ecc)
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
    // Refresh preview
    $form_state->setRebuild();
  }
}