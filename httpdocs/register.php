<?php
function error($message) {
  # TODO: should publish `RSba` message.
  die("{\"error\":\"$message\"}");
}
function public_key($key) {
  $public_key = "-----BEGIN PUBLIC KEY-----\n";
  $l = strlen($key);
  for($i = 0; $i < $l; $i += 64)
    $public_key .= substr($key, $i, 64) . "\n";
  $public_key.= "-----END PUBLIC KEY-----";
  return $public_key;
}
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$publication = json_decode(file_get_contents("php://input"));
if (!$publication)
  error("Unable to parse JSON post");
if (!isset($publication->schema))
  error("Unable to read schema field");

$url = 'https://directdemocracy.vote/json-schema/';
if (substr($publication->schema, 0, 41) !== $url)
  error("Wrong schema URL: " . substr($publication->schema, 0, 41));

if (substr($publication->schema, -19) != '/ballot.schema.json')
  error("Wrong schema object" . substr($publication->schema, -19));

$directdemocracy_version = substr($publication->schema, 41, -19);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported version: $directdemocracy_version");

$now = floatval(microtime(true) * 1000);  # milliseconds
if ($publication->published > $now + 60000)  # allowing a 1 minute error
    error("Publication date in the future: $publication->published > $now");
if ($publication->expires < $now - 60000)  # allowing a 1 minute error
    error("Expiration date in the past: $publication->expires < $now");

$signature_copy = $publication->signature;
$citizen_key_copy = $publication->citizen->key;
$citizen_signature_copy = $publication->citizen->signature;
$publication->signature = '';
$publication->citizen->key = '';
$publication->citizen->signature = '';
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature_copy), public_key($publication->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong ballot signature");

$publication->signature = $signature_copy;
$publication->citizen->key = $citizen_key_copy;
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($citizen_signature_copy), public_key($citizen_key_copy), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong citizen signature");

# get trustee url from publisher
$publisher = 'https://publisher.directdemocracy.vote';
$trustee = file_get_contents("$publisher/trustee_url.php?referendum=" . urlencode($publication->referendum));

if (substr($trustee, 0, 8) !== 'https://')
  die("Cannot get referendum trustee: $trustee");

# check if citizen is allowed by trustee to vote to this referendum

$allowed = file_get_contents("$trustee/can_vote.php?referendum=" . urlencode($publication->referendum) .
                             "&citizen=" . urlencode($publication->citizen->key));
if ($allowed !== 'yes')
  die("Citizen is not allowed to vote to this referendum by trustee: $allowed");
$publication->citizen->key = '';
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature = '';
$private_key = openssl_get_privatekey("file://../id_rsa");
if ($private_key == FALSE)
  error("Failed to get private key.");
$success = openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
openssl_free_key($private_key);
if ($success === FALSE)
  error("Failed to sign ballot.");
$publication->station->signature = base64_encode($signature);

$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
die("{\"ballot\":$data, \"signature\":\"" . base64_encode($signature) . "\"}");

?>
