<?php

namespace Drupal\vap_icecat_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Client;

class IcecatCheckController extends ControllerBase {

  public function check(Request $request) {
    $ean = $request->query->get('ean');
    
    if (empty($ean)) {
      return new JsonResponse(['error' => 'Codice EAN mancante']);
    }

    try {
      $startTime = microtime(true);
      $client = \Drupal::httpClient();
      
      // Log della richiesta con timestamp
      \Drupal::logger('vap_icecat_import')->debug('Inizio richiesta Icecat', [
        'ean' => strval($ean),
        'timestamp' => strval(time()),
        'memory_usage' => strval(memory_get_usage(true))
      ]);
      
      $response = $client->request('GET', 'https://live.icecat.biz/api/v1/openCatalog/IT/barcode/' . strval($ean), [
        'query' => [
          'lang' => 'it',
          'output' => 'json',
          'language_code' => 'it',
          'country_code' => 'IT'
        ],
        'headers' => [
          'Authorization' => 'Basic ' . strval(base64_encode('assistenze2011@gmail.com:Cicita01*')),
          'Accept' => 'application/json',
          'Accept-Language' => 'it-IT'
        ],
        'verify' => false,
        'timeout' => 30,
      ]);

      // Log della risposta HTTP con performance
      $requestTime = microtime(true) - $startTime;
      \Drupal::logger('vap_icecat_import')->debug('Risposta HTTP ricevuta', [
        'status' => strval($response->getStatusCode()),
        'headers' => array_map('strval', $response->getHeaders()),
        'request_time' => strval($requestTime)
      ]);
      
      $statusCode = $response->getStatusCode();
      $body = $response->getBody()->getContents();

      // Log dettagliato della risposta
      \Drupal::logger('vap_icecat_import')->notice('Risposta Icecat - Status: @status, Body: @body', [
        '@status' => strval($statusCode),
        '@body' => strval($body)
      ]);

      $data = json_decode($body, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Errore nel parsing JSON: ' . json_last_error_msg());
      }

      if (empty($data)) {
        throw new \Exception('Risposta vuota da Icecat');
      }

      if (!empty($data['data'])) {
        $productData = $data['data'];
        
        // Sanitizzazione dei dati
        $images = [];
        if (!empty($productData['images']) && is_array($productData['images'])) {
            foreach ($productData['images'] as $image) {
                $images[] = strval($image['url'] ?? $image);
            }
        }

        $description = '';
        if (!empty($productData['description'])) {
            $description = is_array($productData['description']) ? 
                          strval($productData['description']['value'] ?? '') : 
                          strval($productData['description']);
        }

        $jsonResponse = new JsonResponse([
          'nome' => strval($productData['title'] ?? $productData['name'] ?? 'Nome non trovato'),
          'brand' => strval($productData['brand']['name'] ?? 'Marca non trovata'),
          'descrizione' => $description,
          'immagini' => $images,
          'success' => true,
          'debug' => [
            'statusCode' => strval($statusCode),
            'responseSize' => strval(strlen($body))
          ]
        ]);
      } else {
        throw new \Exception('Dati prodotto non trovati nella risposta');
      }
      
      // Gestione cache
      $jsonResponse->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
      $jsonResponse->headers->set('Pragma', 'no-cache');
      $jsonResponse->headers->set('Expires', '0');
      
      return $jsonResponse;
    }
    catch (\Exception $e) {
      \Drupal::logger('vap_icecat_import')->error('Errore Icecat: @message', [
        '@message' => $e->getMessage()
      ]);
      return new JsonResponse([
        'error' => 'Errore durante la verifica: ' . $e->getMessage()
      ]);
    }
  }
}