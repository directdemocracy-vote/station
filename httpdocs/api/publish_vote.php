<?php
require_once(__DIR__.'/../../php/database.php');

function error($message) {
  die("{\"error\":\"$message\"}");
}

function stripped_key($public_key) {
  $stripped = str_replace("-----BEGIN PUBLIC KEY-----", "", $public_key);
  $stripped = str_replace("-----END PUBLIC KEY-----", "", $stripped);
  $stripped = str_replace(array("\r", "\n", '='), '', $stripped);
  return substr($stripped, 44, -6);
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");

$version = 2;
$notary = 'https://notary.directdemocracy.vote';

$public_key_file = fopen(__DIR__."/../../id_rsa.pub", "r") or error("unable to open public key file");
$k = fread($public_key_file, filesize("../../id_rsa.pub"));
fclose($public_key_file);
$key = stripped_key($k);
$private_key = openssl_get_privatekey("file://".__DIR__."/../../id_rsa");
if ($private_key == FALSE)
  error("failed to read private key");

$query = "SELECT REPLACE(REPLACE(TO_BASE64(signature), '\\n', ''), '=', '') AS signature, UNIX_TIMESTAMP(deadline) AS deadline FROM referendum WHERE deadline <= NOW()";
$result = $mysqli->query($query) or die($mysqli->error);
$output = [];
while ($row = $result->fetch_assoc()) {
  $referendum = $row['signature'];
  $deadline = intval($row['deadline']);
  $output += [$referendum => 0];
  $query = "SELECT id, "
          ."REPLACE(REPLACE(TO_BASE64(appKey), '\\n', ''), '=', '') AS appKey, "
          ."REPLACE(REPLACE(TO_BASE64(appSignature), '\\n', ''), '=', '') AS appSignature, "
          ."number, "
          ."REPLACE(TO_BASE64(ballot), '\\n', '') AS ballot, "
          ."answer FROM vote WHERE referendum=FROM_BASE64('$referendum==')";
  $r = $mysqli->query($query) or error($mysqli->error);
  while($vote = $r->fetch_assoc()) {
    $vote = array(
      'schema' => "https://directdemocracy.vote/json-schema/$version/vote.schema.json",
      'key' => $key,
      'signature' => '',
      'published' => $deadline,
      'appKey' => $vote['appKey'],
      'appSignature' => $vote['appSignature'],
      'referendum' => $referendum,
      'number' => $vote['number'],
      'ballot' => $vote['ballot'],
      'answer' => $vote['answer']
    );
    $data = json_encode($vote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature = '';
    $success = openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
    if ($success === FALSE)
      error("failed to sign vote");
    $vote['signature'] = substr(base64_encode($signature), 0, -2);
    $data = json_encode($vote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $options = array('http' => array('method' => 'POST',
                                     'content' => $data,
                                     'header' => "Content-Type: application/json\r\n" .
                                                 "Accept: application/json\r\n"));
    $response = file_get_contents("$notary/api/publish.php", false, stream_context_create($options));
    die($data);
    $json = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE)
      error($response);
    if (isset($json->error))
      error($json->error);
    $mysqli->query("DELETE FROM vote WHERE id=$r[id]") or error($mysqli->error);
    $output[$referendum]++;
  }
  $r->free();
  $mysqli->query("DELETE FROM referendum WHERE signature=FROM_BASE64('$referendum==')") or error($mysqli->error);
}
$result->free();
$list = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
die("{\"published\": \"$list\"}");
?>
