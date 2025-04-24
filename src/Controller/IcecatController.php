<?php

namespace Drupal\vap_icecat_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class IcecatController extends ControllerBase {

  public function check(Request $request) {
    $ean = $request->query->get('ean');
    if (!$ean || !preg_match('/^\d{8,14}$/', $ean)) {
      return new JsonResponse(['error' => 'EAN non valido.'], 400);
    }

    $api_key = '0839bf9a-9e06-42c5-a4f9-dd7abbc518da'; // ⚠️ Usa la tua API key
    $url = "https://api.icecat.biz/rest/products?ean_upc={$ean}&lang=it&app_key={$api_key}";

    try {
      $response = \Drupal::httpClient()->get($url, ['timeout' => 5]);
      $data = json_decode($response->getBody(), true);

      if (empty($data['data'])) {
        return new JsonResponse(['error' => 'Prodotto non trovato su Icecat.']);
      }

      $product = $data['data'];
      return new JsonResponse([
        'nome' => $product['generalInfo']['Title'] ?? 'Titolo non disponibile',
        'img' => $product['gallery'][0]['url'] ?? '',
        'brand' => $product['generalInfo']['Brand'] ?? '',
      ]);
    } catch (\Exception $e) {
      return new JsonResponse(['error' => 'Errore nella richiesta Icecat.'], 500);
    }
  }

}