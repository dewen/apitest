<?php

use Assert\Assertion;
use Assert\AssertionFailedException;

require __DIR__.'/vendor/autoload.php';

/**
 * Run
 *  >/tmp/play_cookie_jar.txt;php notes/playnotes.php > notes/playnotes.yml ; php play.php
 *
 * Required:
 *  - beberlei/assert
 *    read https://packagist.org/packages/beberlei/assert
 */

$playnotes = yaml_parse_file('notes/playnotes.yml');
$notes = $playnotes['notes'];
$notify = $playnotes['notification']['email'];
$assert_result = array();
assert_options(ASSERT_CALLBACK, 'play_callback');

foreach ($notes as $note) {
  play_assert($note);
}

function play_assert($note) {
  static $persist = array();
  global $assert_result;

  $res = curl_init();
  $url = $note['protocol'] . '://' . $note['host'] . '/' . $note['path'];
  $url .= ($note['qs']) ? '?' . $note['qs'] : '';

  curl_setopt($res, CURLOPT_URL, $url);

  // curl_setopt($res, CURLOPT_TIMEOUT, $this->_timeout);
  // curl_setopt($res, CURLOPT_MAXREDIRS, $this->_maxRedirects);
  // curl_setopt($res, CURLOPT_FOLLOWLOCATION, $this->_followlocation);
  // curl_setopt($res, CURLOPT_USERAGENT, $this->_useragent);
  // curl_setopt($res, CURLOPT_REFERER, $this->_referer);
  // curl_setopt($res,CURLOPT_NOBODY,true);

  curl_setopt($res, CURLOPT_COOKIEJAR, '/tmp/play_cookie_jar.txt');
  curl_setopt($res, CURLOPT_COOKIEFILE, '/tmp/play_cookie_jar.txt');
  curl_setopt($res, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($res, CURLINFO_HEADER_OUT, TRUE);
  curl_setopt($res, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($res, CURLOPT_HEADER, TRUE);

  if (isset($note['authentication']) && $note['authentication']){
    curl_setopt($res, CURLOPT_USERPWD, $note['authentication']['auth_name'].':'.$note['authentication']['auth_pass']);
  }

  if ($note['method'] == 'POST') {
    curl_setopt($res, CURLOPT_POST, true);
    $fields_string = (isset($note['fields'])) ? http_build_query($note['fields']) : '';
    curl_setopt($res, CURLOPT_POSTFIELDS, $fields_string);
  }


  if (isset($note['http_headers'])) {
    $headers = array();
    foreach ($note['http_headers'] as $n => $v) {
      if (preg_match('/{{([a-z0-9_]+)}}/i', $v, $_)) {
        $hv = $_[1];
        if (isset($persist[$hv])) {
          $headers[] = $n . ': ' . $persist[$hv];
        }
      }
    }

    curl_setopt($res, CURLOPT_HTTPHEADER, $headers);
  }

  $response = curl_exec($res);
  $header_text = substr($response, 0, strpos($response, "\r\n\r\n"));
  $body_text = substr($response, strpos($response, "\r\n\r\n") + 4);

  if (isset($note['persist'])) {
    foreach ($note['persist'] as $key => $pattern) {
      if (preg_match($pattern, $response, $m)) {
        $persist[$key] = $m[1];
      }
    }
  }
  if(!curl_errno($res)) {
    $info = curl_getinfo($res);
    // assertion
    if (isset($note['assertions']) && is_array($assertions = $note['assertions'])) {
      foreach ($assertions as $assertion) {
        /**
         * available types:
         *
         *    curl info keys:
         *                  url
         *                  content_type
         *                  http_code
         *                  header_size
         *                  request_size
         *                  filetime
         *                  ssl_verify_result
         *                  redirect_count
         *                  total_time
         *                  namelookup_time
         *                  connect_time
         *                  pretransfer_time
         *                  size_upload
         *                  size_download
         *                  speed_download
         *                  speed_upload
         *                  download_content_length
         *                  upload_content_length
         *                  starttransfer_time
         *                  redirect_time
         *                  redirect_url
         *                  primary_ip
         *                  certinfo
         *                  primary_port
         *                  local_ip
         *                  local_port
         *                  request_header
         *
         */

        $type = $assertion['type'];
        $method = isset($assertion['method']) ? $assertion['method'] : 'eq';
        if (isset($info[$type])) {
          $actual = $info[$type];
        }
        elseif (in_array($type, array('_response_body', '_response_header'))) {
          $actual = ($type == '_response_header') ? $header_text : $body_text;
        }
        else {
          continue;
        }

        try {
          switch ($method) {
            case 'eq':
              Assertion::eq($actual, $assertion['value'], $assertion['desc']);
              $assert_result[] = array(
                'title' => $note['name'],
                'message' => $assertion['desc'],
              );
              break;
            case 'regex':
              Assertion::regex($actual, $assertion['value'], $assertion['desc']);
              $assert_result[] = array(
                'title' => $note['name'],
                'message' => $assertion['desc'],
              );
              break;
          }
        }
        catch (AssertionFailedException $e) {
          $assert_result[] = array(
            'title' => $note['name'],
            'message' => 'FAILED >>> ' . $e->getMessage() . " actual: " . $e->getValue(),
          );
        }
      }
    }
  }
  else {
    echo 'Curl error: ' . curl_error($res);
  }
  if (isset($note['debug'])) {
    var_dump($response);
  }
  curl_close($res);
}

function play_shutdown() {
  global $notify, $assert_result;
  if (!$assert_result) {
    return;
  }
  $body = "IIN Play Notes Failed:\n";
  foreach ($assert_result as $_) {
    $body .= " - " . $_['title'] . ': ' . $_['message'] . "\n";
  }
  mail($notify, 'Playnotes report', $body);
  echo "Email sent\n";
  echo "$body\n";
}

register_shutdown_function('play_shutdown');
