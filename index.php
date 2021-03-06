<?php
/**
 * Simple OAI-PMH 2.0 Data Provider
 * Copyright (C) 2005 Heinrich Stamerjohanns <stamer@uni-oldenburg.de>
 * Copyright (C) 2011 Jianfeng Li <jianfeng.li@adelaide.edu.au>
 * Copyright (C) 2013 Daniel Neis Araujo <danielneis@gmail.com>
 * Copyright (C) 2017 Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once('oai2config.php');
require_once('oai2server.php');

// Get all available records and their respective status and timestamps
$records = array();
$deleted = array();
$timestamps = array();
$earliest = time();

foreach($config['metadataFormats'] as $prefix => $uris) {
  $files = glob(rtrim($config['dataDirectory'], '/').'/'.$prefix.'/*.xml');
  foreach($files as $file) {
    $records[$prefix][pathinfo($file, PATHINFO_FILENAME)] = $file;
    $deleted[$prefix][pathinfo($file, PATHINFO_FILENAME)] = !filesize($file);
    $timestamps[$prefix][filemtime($file)][] = pathinfo($file, PATHINFO_FILENAME);
    if (filemtime($file) < $earliest) {
      $earliest = filemtime($file);
    }
  }
  ksort($records[$prefix]);
  reset($records[$prefix]);
  ksort($timestamps[$prefix]);
  reset($timestamps[$prefix]);
}

// Get current base URL
$baseURL = $_SERVER['HTTP_HOST'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') {
  $baseURL = 'https://'.$baseURL;
} else {
  $baseURL = 'http://'.$baseURL;
}

// Build the Identify response
$identifyResponse = array(
  'repositoryName' => $config['repositoryName'],
  'baseURL' => $baseURL,
  'protocolVersion' => '2.0',
  'adminEmail' => $config['adminEmail'],
  'earliestDatestamp' => gmdate('Y-m-d\TH:i:s\Z', $earliest),
  'deletedRecord' => $config['deletedRecord'],
  'granularity' => 'YYYY-MM-DDThh:mm:ssZ'
);

$oai2 = new OAI2Server(
  $baseURL,
  $_GET,
  $identifyResponse,
  array(
    'GetRecord' =>
    function($identifier, $metadataPrefix) {
      global $records, $deleted;
      if (empty($records[$metadataPrefix][$identifier])) {
        return array();
      } else {
        return array(
          'identifier' => $identifier,
          'timestamp' => filemtime($records[$metadataPrefix][$identifier]),
          'deleted' => $deleted[$metadataPrefix][$identifier],
          'metadata' => $records[$metadataPrefix][$identifier]
        );
      }
    },
    'ListRecords' =>
    function($metadataPrefix, $from = null, $until = null, $count = false, $deliveredRecords = 0, $maxItems = 100) {
      global $records, $deleted, $timestamps;
      $resultSet = array();
      foreach($timestamps[$metadataPrefix] as $timestamp => $identifiers) {
        if ((is_null($from) || $timestamp >= $from) && (is_null($until) || $timestamp <= $until)) {
          foreach($identifiers as $identifier) {
            $resultSet[] = array(
              'identifier' => $identifier,
              'timestamp' => filemtime($records[$metadataPrefix][$identifier]),
              'deleted' => $deleted[$metadataPrefix][$identifier],
              'metadata' => $records[$metadataPrefix][$identifier]
            );
          }
        }
      }
      if ($count) {
        return count($resultSet);
      } else {
        return array_slice($resultSet, $deliveredRecords, $maxItems);
      }
    },
    'ListMetadataFormats' =>
    function($identifier = '') {
      global $config, $records;
      if (!empty($identifier)) {
        $formats = array();
        foreach($records as $format => $record) {
          if (!empty($record[$identifier])) {
            $formats[$format] = $config['metadataFormats'][$format];
          }
        }
        if (!empty($formats)) {
          return $formats;
        } else {
          throw new OAI2Exception('idDoesNotExist');
        }
      } else {
        return $config['metadataFormats'];
      }
    }
  ),
  $config
);

$response = $oai2->response();

if (isset($return)) {
  return $response;
} else {
  $response->formatOutput = true;
  $response->preserveWhiteSpace = false;
  header('Content-Type: text/xml');
  echo $response->saveXML();
}
